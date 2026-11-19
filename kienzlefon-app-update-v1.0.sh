#!/usr/bin/env bash
# kienzlefon-app-update-v1.0.sh
#
# Version 1.0 (2026-09-29)
# Changelog:
# - 19.11.2026: Beim Aufruf aus dem Modul die mitgelieferte Paketquelle verwenden.
# - 19.11.2026: Vorhandene Abrufkanäle mit Audio-Helfer, Konfigurationserhalt und Rollback aktualisieren.
# - SMS-Empfangsmodule im selben Update wie Worker und Webapp pruefen und installieren.
# - App 3.7: Telefoniedateien ausliefern und bestehende App-Optionen erhalten.
# - Initialer, eigenstaendiger Updater mit lokaler/Online-Quelle, sicherer
#   Konfigurationsuebernahme, privaten Backups, Dienstkoordination und Rollback.

set -euo pipefail

if ! command -v python3 >/dev/null 2>&1; then
    echo "Fehler: python3 ist erforderlich." >&2
    exit 1
fi

package_dir="$(CDPATH= cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
if [[ -f "$package_dir/docs/INSTALLER.md" || -f "$package_dir/scripts/pack.sh" ]]; then
    use_package=1
    for option in "$@"; do
        case "$option" in --ref|--ref=*|--source-dir|--source-dir=*) use_package=0 ;; esac
    done
    if (( use_package )); then set -- --source-dir "$package_dir" "$@"; fi
fi

# Keep stdin available for the operator's confirmation.
exec python3 /dev/fd/3 "$@" 3<<'PY'
from __future__ import annotations

import argparse
from contextlib import contextmanager, ExitStack
import datetime as dt
import fcntl
import hashlib
import json
import os
import re
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from pathlib import Path

VERSION = "1.0"
REPOSITORY = "thomaskien/IONOS_AI_Receptionist_Interface_Arztpraxis"
WEB_FILES = (
    "telepraxis-app.php",
    "telepraxis-sms.php",
    "sms-config.php",
    "telepraxis-sms-queue.php",
    "telepraxis-telephony.php",
    "telepraxis-telephony.js",
    "telepraxis-telephony-schema.json",
)
FETCH_TEMPLATE = "telepraxis_fetch_and_decrypt.sh"
FETCH_HELPER = "telepraxis-audio-delivery.py"
FETCH_SETTINGS = ("REMOTE_HOST", "REMOTE_USER", "REMOTE_DIR", "LOCAL_PULL_DIR", "LOCAL_OUT_DIR",
                  "PRIVATE_KEY_FILE", "SSH_IDENTITY_FILE", "SSH_PORT", "SSH_EXTRA_OPTS",
                  "REMOTE_FILE_PATTERN", "RUN_FOREVER", "SLEEP_SECONDS",
                  "DELETE_REMOTE_ON_SUCCESS", "DELETE_LOCAL_ENC_ON_SUCCESS")
WORKER_CORE_FILES = (
    "telepraxis-sms-worker.php",
    "telepraxis-sms.php",
    "telepraxis-sms-queue.php",
)
WORKER_FILES = WORKER_CORE_FILES + (
    "telepraxis-sms-journal.php",
    "telepraxis-sms-receive-store.php",
    "telepraxis-sms-receive.php",
)
APP_CONSTANTS = (
    "TELEPRAXIS_INBOX_DIR",
    "TELEPRAXIS_ADMIN_PASSWORD",
    "TELEPRAXIS_POLL_INTERVAL_MS",
    "TELEPRAXIS_DEFAULT_TIMEZONE",
    "TELEPRAXIS_WORKPLACE_MAXLEN",
)
APP_OPTIONAL_CONSTANTS = (
    "TELEPRAXIS_USER_MAXLEN", "TELEPRAXIS_T2MED_ENABLED", "TELEPRAXIS_T2MED_BASE_URL",
    "TELEPRAXIS_RECORDINGS_BASE_URL", "TELEPRAXIS_AUDIO_RETENTION_DAYS",
)
SMS_CONSTANTS = ("TP_SMS_CREDENTIALS_FILE",)
CONFIG_CONSTANTS = ("TP_SMS_CREDENTIALS_FILE", "TP_SMS_CONFIG_ADMIN_PASSWORD")
PHP_MODULES = ("curl", "dom", "simplexml", "pdo", "pdo_sqlite", "tokenizer", "json", "openssl")


class UpdateError(RuntimeError):
    pass


class UpdateInterrupted(UpdateError):
    pass


def interrupt_update(_signum, _frame):
    raise UpdateInterrupted("Update durch Signal unterbrochen.")


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(
        prog="kienzlefon-app-update-v1.0.sh",
        description="Konservativer Updater fuer die kienzlefon app",
    )
    result.add_argument("--webroot", default="/var/www/html", help="Webroot (Standard: /var/www/html)")
    result.add_argument("--source-dir", help="Lokales Verzeichnis mit dem neuen Quellstand")
    result.add_argument("--ref", default="main", help="Git-Referenz fuer den Online-Abruf (Standard: main)")
    result.add_argument("--backup-dir", default="/var/backups/kienzlefon-app", help="Basis fuer private Sicherungen")
    result.add_argument("--check", action="store_true", help="Nur vorbereiten und pruefen, nichts installieren")
    result.add_argument("--yes", action="store_true", help="Rueckfrage ueberspringen")
    result.add_argument("--web-service", help="Expliziter php-fpm- oder Apache-systemd-Dienst")
    result.add_argument("--fetch-dir", help="Verzeichnis der installierten kanalbezogenen Abrufskripte (Standard /usr/local/bin)")
    result.add_argument("--worker-dir", help="Verzeichnis einer vorhandenen SMS-Worker-Installation")
    result.add_argument("--worker-service", default="telepraxis-sms-worker.service", help="systemd-Dienst des SMS-Workers")
    result.add_argument("--no-service", action="store_true", help="Wartungsmodus wird extern sichergestellt")
    result.add_argument("--version", action="version", version=f"%(prog)s {VERSION}")
    return result


def safe_resolve(path: Path) -> Path:
    try:
        return path.expanduser().resolve(strict=False)
    except (OSError, RuntimeError) as exc:
        raise UpdateError("Ein angegebener Pfad konnte nicht sicher aufgeloest werden.") from exc


def overlaps(first: Path, second: Path) -> bool:
    first = safe_resolve(first)
    second = safe_resolve(second)
    return first == second or first in second.parents or second in first.parents


def require_real_directory(path: Path, label: str) -> Path:
    if path.is_symlink() or not path.is_dir():
        raise UpdateError(f"{label} fehlt, ist kein Verzeichnis oder ist ein Symlink: {path}")
    return path.resolve()


def require_regular_file(path: Path, label: str) -> None:
    if path.is_symlink() or not path.is_file():
        raise UpdateError(f"{label} fehlt, ist keine regulaere Datei oder ist ein Symlink: {path}")


def validate_service_name(name: str, label: str) -> str:
    if not re.fullmatch(r"[A-Za-z0-9_.:@-]+", name) or name.startswith("-"):
        raise UpdateError(f"{label} ist ungueltig.")
    return name


def check_runtime() -> str:
    php = shutil.which("php")
    if php is None:
        raise UpdateError("PHP CLI ist erforderlich.")
    version = subprocess.run(
        [php, "-r", "echo PHP_VERSION_ID;"],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        check=False,
    )
    if version.returncode != 0 or not version.stdout.strip().isdigit():
        raise UpdateError("Die PHP-CLI-Version konnte nicht geprueft werden.")
    if int(version.stdout.strip()) < 80100:
        raise UpdateError("PHP CLI >= 8.1 ist erforderlich.")
    modules = subprocess.run(
        [php, "-m"],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        check=False,
    )
    if modules.returncode != 0:
        raise UpdateError("Die PHP-CLI-Module konnten nicht geprueft werden.")
    available = {line.strip().lower() for line in modules.stdout.splitlines()}
    missing = [name for name in PHP_MODULES if name.lower() not in available]
    if missing:
        raise UpdateError("Erforderliche PHP-CLI-Module fehlen: " + ", ".join(missing))
    return php


PHP_TOKENIZER = r'''
$source = stream_get_contents(STDIN);
try {
    $tokens = token_get_all($source, TOKEN_PARSE);
} catch (Throwable $error) {
    fwrite(STDOUT, json_encode(["ok" => false]));
    exit(2);
}
$result = [];
foreach ($tokens as $token) {
    if (is_array($token)) {
        $result[] = ["id" => token_name($token[0]), "text" => $token[1]];
    } else {
        $result[] = ["id" => null, "text" => $token];
    }
}
fwrite(STDOUT, json_encode(["ok" => true, "tokens" => $result], JSON_UNESCAPED_UNICODE));
'''


def php_tokens(php: str, source: str, label: str) -> list[dict[str, object]]:
    run = subprocess.run(
        [php, "-d", "display_errors=0", "-r", PHP_TOKENIZER],
        input=source,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        check=False,
    )
    try:
        result = json.loads(run.stdout)
    except (json.JSONDecodeError, TypeError) as exc:
        raise UpdateError(f"{label}: PHP-Quelltext konnte nicht sicher tokenisiert werden.") from exc
    if run.returncode != 0 or not result.get("ok") or not isinstance(result.get("tokens"), list):
        raise UpdateError(f"{label}: PHP-Syntax ist ungueltig.")
    return result["tokens"]


def constant_spans(php: str, source: str, names: tuple[str, ...], label: str,
                   optional: tuple[str, ...] = ()) -> dict[str, tuple[int, int]]:
    tokens = php_tokens(php, source, label)
    offsets: list[tuple[int, int]] = []
    position = 0
    for token in tokens:
        text = token.get("text")
        if not isinstance(text, str):
            raise UpdateError(f"{label}: Unerwartete Token-Daten.")
        offsets.append((position, position + len(text)))
        position += len(text)
    if position != len(source):
        raise UpdateError(f"{label}: Token-Grenzen konnten nicht sicher bestimmt werden.")

    insignificant = {"T_WHITESPACE", "T_COMMENT", "T_DOC_COMMENT"}
    found: dict[str, list[tuple[int, int]]] = {name: [] for name in (*names, *optional)}
    curly_depth = 0
    index = 0
    while index < len(tokens):
        token = tokens[index]
        token_id = token.get("id")
        token_text = token.get("text")
        if (token_id is None and token_text == "{") or token_id in ("T_CURLY_OPEN", "T_DOLLAR_OPEN_CURLY_BRACES"):
            curly_depth += 1
        elif token_id is None and token_text == "}":
            curly_depth = max(0, curly_depth - 1)
        if token_id != "T_CONST" or curly_depth != 0:
            index += 1
            continue

        cursor = index + 1
        while cursor < len(tokens) and tokens[cursor].get("id") in insignificant:
            cursor += 1
        if cursor >= len(tokens) or tokens[cursor].get("id") != "T_STRING":
            raise UpdateError(f"{label}: Eine const-Deklaration ist nicht sicher interpretierbar.")
        name = tokens[cursor].get("text")
        cursor += 1
        while cursor < len(tokens) and tokens[cursor].get("id") in insignificant:
            cursor += 1
        if cursor >= len(tokens) or tokens[cursor].get("text") != "=":
            raise UpdateError(f"{label}: Eine const-Deklaration ist nicht sicher interpretierbar.")
        expression_start = offsets[cursor][1]
        cursor += 1
        round_depth = square_depth = inner_curly = 0
        semicolon = None
        unsafe_multiple = False
        while cursor < len(tokens):
            text = tokens[cursor].get("text")
            raw_character = tokens[cursor].get("id") is None
            if raw_character:
                if text == "(":
                    round_depth += 1
                elif text == ")":
                    round_depth -= 1
                elif text == "[":
                    square_depth += 1
                elif text == "]":
                    square_depth -= 1
                elif text == "{":
                    inner_curly += 1
                elif text == "}":
                    inner_curly -= 1
                elif text == "," and round_depth == square_depth == inner_curly == 0:
                    unsafe_multiple = True
                elif text == ";" and round_depth == square_depth == inner_curly == 0:
                    semicolon = offsets[cursor][0]
                    break
            if min(round_depth, square_depth, inner_curly) < 0:
                break
            cursor += 1
        if semicolon is None or unsafe_multiple:
            raise UpdateError(f"{label}: Eine const-Deklaration ist nicht sicher interpretierbar.")
        if isinstance(name, str) and name in found:
            found[name].append((expression_start, semicolon))
        index = cursor + 1

    result: dict[str, tuple[int, int]] = {}
    for name, spans in found.items():
        if not spans and name in optional:
            continue
        if len(spans) != 1:
            raise UpdateError(f"{label}: Konstante {name} fehlt oder ist mehrfach deklariert.")
        result[name] = spans[0]
    return result


def patch_constants(php: str, candidate: Path, installed: Path, names: tuple[str, ...],
                    optional: tuple[str, ...] = ()) -> None:
    try:
        new_source = candidate.read_text(encoding="utf-8")
        old_source = installed.read_text(encoding="utf-8")
    except (OSError, UnicodeError) as exc:
        raise UpdateError("Eine PHP-Datei konnte nicht als UTF-8 gelesen werden.") from exc
    old_spans = constant_spans(php, old_source, names, f"Bestehende Datei {installed.name}", optional)
    new_spans = constant_spans(php, new_source, names, f"Neue Datei {candidate.name}", optional)
    missing = old_spans.keys() - new_spans.keys()
    if missing:
        raise UpdateError(f"{candidate.name}: Quellstand kann bestehende Einstellungen nicht uebernehmen: "
                          + ", ".join(sorted(missing)))
    replacements = []
    for name in old_spans:
        old_start, old_end = old_spans[name]
        new_start, new_end = new_spans[name]
        replacements.append((new_start, new_end, old_source[old_start:old_end]))
    for start, end, expression in sorted(replacements, reverse=True):
        new_source = new_source[:start] + expression + new_source[end:]
    try:
        candidate.write_text(new_source, encoding="utf-8")
    except OSError as exc:
        raise UpdateError("Ein vorbereiteter PHP-Kandidat konnte nicht geschrieben werden.") from exc


def lint_php(php: str, path: Path) -> None:
    run = subprocess.run(
        [php, "-l", str(path)],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
    )
    if run.returncode != 0:
        raise UpdateError(f"PHP-Syntaxpruefung fehlgeschlagen: {path.name}")


def read_local_source(source_dir: Path, needed: set[str]) -> dict[str, bytes]:
    source_dir = require_real_directory(source_dir, "Quellverzeichnis")
    result: dict[str, bytes] = {}
    for name in sorted(needed):
        path = source_dir / name
        require_regular_file(path, "Quelldatei")
        try:
            result[name] = path.read_bytes()
        except OSError as exc:
            raise UpdateError(f"Quelldatei konnte nicht gelesen werden: {name}") from exc
    return result


def download_source(ref: str, needed: set[str]) -> dict[str, bytes]:
    if not ref or any(character in ref for character in ("\0", "\r", "\n")):
        raise UpdateError("Die Git-Referenz ist ungueltig.")
    encoded_ref = urllib.parse.quote(ref, safe="")
    url = f"https://codeload.github.com/{REPOSITORY}/zip/{encoded_ref}"
    request = urllib.request.Request(url, headers={"User-Agent": f"kienzlefon-app-updater/{VERSION}"})
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            if response.geturl().split(":", 1)[0].lower() != "https":
                raise UpdateError("Der Archiv-Abruf wurde auf ein unsicheres Protokoll umgeleitet.")
            chunks = []
            total = 0
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                total += len(chunk)
                if total > 100 * 1024 * 1024:
                    raise UpdateError("Das Quellarchiv ist unerwartet gross.")
                chunks.append(chunk)
    except UpdateError:
        raise
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        raise UpdateError("Das Quellarchiv konnte nicht per HTTPS geladen werden.") from exc

    import io
    try:
        with zipfile.ZipFile(io.BytesIO(b"".join(chunks))) as archive:
            if len(archive.infolist()) > 5000:
                raise UpdateError("Das Quellarchiv enthaelt unerwartet viele Eintraege.")
            selected: dict[str, tuple[str, bytes]] = {}
            archive_root = None
            for member in archive.infolist():
                parts = Path(member.filename).parts
                if len(parts) != 2 or parts[1] not in needed:
                    continue
                mode = (member.external_attr >> 16) & 0xFFFF
                file_type = stat.S_IFMT(mode)
                if member.is_dir() or file_type == stat.S_IFLNK or file_type not in (0, stat.S_IFREG):
                    raise UpdateError("Das Quellarchiv enthaelt eine unzulaessige Quelldatei.")
                if member.file_size > 20 * 1024 * 1024:
                    raise UpdateError("Eine Quelldatei im Archiv ist unerwartet gross.")
                if parts[1] in selected:
                    raise UpdateError("Das Quellarchiv enthaelt eine Quelldatei mehrfach.")
                if archive_root is None:
                    archive_root = parts[0]
                elif archive_root != parts[0]:
                    raise UpdateError("Das Quellarchiv besitzt keine eindeutige Wurzel.")
                selected[parts[1]] = (parts[0], archive.read(member))
    except UpdateError:
        raise
    except (zipfile.BadZipFile, OSError, RuntimeError) as exc:
        raise UpdateError("Das geladene Quellarchiv ist ungueltig.") from exc
    missing = sorted(needed - selected.keys())
    if missing:
        raise UpdateError("Im Quellarchiv fehlen erforderliche Dateien: " + ", ".join(missing))
    return {name: selected[name][1] for name in needed}


def command_systemctl(arguments: list[str], allowed=(0,)) -> subprocess.CompletedProcess[str]:
    run = subprocess.run(
        ["systemctl", *arguments],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        check=False,
    )
    if run.returncode not in allowed:
        raise UpdateError("systemctl konnte die angeforderte Dienstaktion nicht ausfuehren.")
    return run


def is_service_active(name: str) -> bool:
    state = command_systemctl(["show", "--property=ActiveState", "--value", name]).stdout.strip()
    if state not in ("active", "inactive", "failed"):
        raise UpdateError(f"Dienstzustand von {name} ist unklar oder noch im Uebergang.")
    return state == "active"


def require_loaded_service(name: str) -> None:
    state = command_systemctl(["show", "--property=LoadState", "--value", name]).stdout.strip()
    if state != "loaded":
        raise UpdateError(f"Dienst {name} ist nicht geladen; bitte den Dienstnamen pruefen.")


def stop_service(name: str) -> None:
    command_systemctl(["stop", name])
    if is_service_active(name):
        raise UpdateError(f"Dienst {name} laeuft trotz Stopp weiter.")


def start_service(name: str) -> None:
    command_systemctl(["start", name])
    if not is_service_active(name):
        raise UpdateError(f"Dienst {name} wurde nach dem Start nicht aktiv.")


def detect_web_service(explicit: str | None) -> tuple[str, bool]:
    if shutil.which("systemctl") is None:
        raise UpdateError("systemctl ist erforderlich; alternativ --no-service verwenden.")
    if explicit:
        name = validate_service_name(explicit, "Webdienst")
        if not re.fullmatch(r"(?:php(?:[0-9]+(?:\.[0-9]+)?)?-fpm|apache2)(?:@[A-Za-z0-9_.-]+)?\.service", name):
            raise UpdateError("--web-service muss einen php-fpm- oder apache2-Dienst benennen.")
        require_loaded_service(name)
        return name, is_service_active(name)
    listing = command_systemctl(
        ["list-units", "--type=service", "--state=running", "--no-legend", "--no-pager"]
    )
    matches = []
    for line in listing.stdout.splitlines():
        fields = line.split()
        if not fields:
            continue
        name = fields[0]
        if re.fullmatch(r"(?:php(?:[0-9]+(?:\.[0-9]+)?)?-fpm|apache2)\.service", name):
            matches.append(name)
    matches = sorted(set(matches))
    if len(matches) != 1:
        raise UpdateError("Es wurde nicht genau ein laufender php-fpm-/apache2-Dienst gefunden; --web-service angeben.")
    return matches[0], True


def manual_worker_running(worker_file: Path) -> bool:
    pgrep = shutil.which("pgrep")
    if pgrep is None:
        raise UpdateError("Ein manueller SMS-Worker kann nicht geprueft werden; --no-service nur bei externem Wartungsmodus verwenden.")
    run = subprocess.run(
        [pgrep, "-f", re.escape(str(worker_file))],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
    )
    if run.returncode not in (0, 1):
        raise UpdateError("Laufende SMS-Worker konnten nicht sicher geprueft werden.")
    return run.returncode == 0


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def target_fingerprints(paths: list[Path]) -> dict[Path, tuple | None]:
    result = {}
    for path in paths:
        if path.exists() or path.is_symlink():
            require_regular_file(path, "Zieldatei")
            info = path.stat()
            result[path] = (sha256(path), info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
        else:
            result[path] = None
    return result


def create_backup(base: Path, targets: list[tuple[str, Path]]) -> tuple[Path, dict[str, dict[str, object]]]:
    try:
        base.mkdir(mode=0o700, parents=True, exist_ok=True)
        info = base.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
            raise UpdateError("Das Sicherungsverzeichnis muss dem ausfuehrenden Benutzer gehoeren und Modus 0700 haben.")
        timestamp = dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        backup = Path(tempfile.mkdtemp(prefix=f"{timestamp}-", dir=base))
        os.chmod(backup, 0o700)
    except OSError as exc:
        raise UpdateError("Das private Sicherungsverzeichnis konnte nicht erstellt werden.") from exc
    manifest: dict[str, dict[str, object]] = {}
    try:
        for key, target in targets:
            entry: dict[str, object] = {"path": str(target), "existed": target.exists()}
            if target.exists():
                info = target.stat()
                destination = backup / key
                destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                shutil.copyfile(target, destination)
                os.chmod(destination, 0o600)
                entry.update({
                    "backup": key,
                    "sha256": sha256(destination),
                    "uid": info.st_uid,
                    "gid": info.st_gid,
                    "mode": stat.S_IMODE(info.st_mode),
                })
            manifest[key] = entry
        manifest_path = backup / "manifest.json"
        descriptor = os.open(manifest_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump({"updater_version": VERSION, "files": manifest}, handle, indent=2, sort_keys=True)
            handle.write("\n")
    except OSError as exc:
        raise UpdateError(f"Die Sicherung konnte nicht vollstaendig erstellt werden; Teilverzeichnis bleibt erhalten: {backup}") from exc
    return backup, manifest


def atomic_copy(source: Path, target: Path, metadata: os.stat_result) -> None:
    # The parent stays private even after the payload receives its public file mode.
    with tempfile.TemporaryDirectory(prefix=".kienzlefon-update-", dir=target.parent) as temporary_dir:
        temporary = Path(temporary_dir) / "payload.php"
        with temporary.open("xb") as output, source.open("rb") as input_file:
            shutil.copyfileobj(input_file, output)
            output.flush()
            os.fsync(output.fileno())
        os.chown(temporary, metadata.st_uid, metadata.st_gid)
        os.chmod(temporary, stat.S_IMODE(metadata.st_mode))
        os.replace(temporary, target)


def restore_backup(backup: Path, manifest: dict[str, dict[str, object]]) -> list[str]:
    errors = []
    for key, entry in manifest.items():
        target = Path(str(entry["path"]))
        try:
            if entry["existed"]:
                backup_file = backup / str(entry["backup"])
                if sha256(backup_file) != entry["sha256"]:
                    raise UpdateError("Pruefsumme der Sicherung stimmt nicht.")
                metadata = os.stat_result((int(entry["mode"]), 0, 0, 1,
                                           int(entry["uid"]), int(entry["gid"]), 0, 0, 0, 0))
                atomic_copy(backup_file, target, metadata)
            elif target.exists() or target.is_symlink():
                target.unlink()
        except (OSError, UpdateError):
            errors.append(str(target))
    return errors


def install_metadata(target: Path, fallback: Path) -> os.stat_result:
    return target.stat() if target.exists() else fallback.stat()


@contextmanager
def acquire_lock(directory: Path):
    descriptor = None
    try:
        descriptor = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError as exc:
        if descriptor is not None:
            os.close(descriptor)
        raise UpdateError(f"Fuer {directory} laeuft bereits ein Updater.") from exc
    except OSError as exc:
        if descriptor is not None:
            os.close(descriptor)
        raise UpdateError("Die Updater-Sperre konnte nicht angelegt werden.") from exc
    try:
        yield
    finally:
        os.close(descriptor)


def print_plan(webroot: Path, worker_dir: Path | None, source_label: str, check_only: bool) -> None:
    print("Update-Plan:")
    print(f"  Quelle: {source_label}")
    print(f"  Webroot: {webroot}")
    print("  Webdateien: " + ", ".join(WEB_FILES))
    if worker_dir is not None:
        print(f"  SMS-Worker: {worker_dir}")
        print("  Worker-Dateien: " + ", ".join(WORKER_FILES))
    else:
        print("  SMS-Worker: nicht vorhanden")
    print("  Modus: nur Pruefung" if check_only else "  Modus: Installation mit privater Sicherung")


def prepare_fetch(template: Path, installed: Path, candidate: Path) -> None:
    # Copy administrator settings as shell source; never evaluate the channel script as root.
    source = template.read_text()
    previous = installed.read_text()
    for setting in FETCH_SETTINGS + ("AUDIO_STATE",):
        pattern = rf"^{setting}=.+$"
        values = re.findall(pattern, previous, re.MULTILINE)
        if not values and setting == "AUDIO_STATE":
            continue
        if len(values) != 1:
            raise UpdateError(f"{installed.name}: Einstellung {setting} fehlt oder ist mehrdeutig.")
        source, count = re.subn(pattern, lambda _: values[0], source, flags=re.MULTILINE)
        if count != 1:
            raise UpdateError(f"Unvollständige Abholervorlage: {setting}")
    candidate.write_text(source)
    if subprocess.run(["bash", "-n", str(candidate)], capture_output=True).returncode:
        raise UpdateError(f"Shell-Prüfung fehlgeschlagen: {installed.name}")


def main() -> int:
    args = parser().parse_args()
    signal.signal(signal.SIGTERM, interrupt_update)
    signal.signal(signal.SIGINT, interrupt_update)
    webroot_input = Path(args.webroot)
    webroot = require_real_directory(webroot_input, "Webroot")
    if Path(args.backup_dir).is_symlink():
        raise UpdateError("Das Sicherungsverzeichnis darf kein Symlink sein.")
    backup_base = safe_resolve(Path(args.backup_dir))
    if overlaps(backup_base, webroot):
        raise UpdateError("Das Sicherungsverzeichnis muss ausserhalb des Webroots liegen.")

    source_dir = None
    if args.source_dir is not None:
        if not args.source_dir:
            raise UpdateError("Quellverzeichnis fehlt.")
        source_dir = require_real_directory(Path(args.source_dir), "Quellverzeichnis")
        if overlaps(source_dir, webroot):
            raise UpdateError("Quellverzeichnis und Webroot duerfen sich nicht ueberschneiden.")
        if overlaps(source_dir, backup_base):
            raise UpdateError("Quell- und Sicherungsverzeichnis duerfen sich nicht ueberschneiden.")

    explicit_worker = args.worker_dir is not None
    worker_candidate = Path(args.worker_dir) if explicit_worker else Path("/opt/telepraxis-sms")
    worker_dir: Path | None = None
    if explicit_worker or (worker_candidate / "telepraxis-sms-worker.php").exists() or (worker_candidate / "telepraxis-sms-worker.php").is_symlink():
        worker_dir = require_real_directory(worker_candidate, "SMS-Worker-Verzeichnis")
        if overlaps(worker_dir, webroot) or overlaps(worker_dir, backup_base):
            raise UpdateError("SMS-Worker, Webroot und Sicherungsverzeichnis muessen getrennt sein.")
        if source_dir is not None and overlaps(worker_dir, source_dir):
            raise UpdateError("SMS-Worker und Quellverzeichnis duerfen sich nicht ueberschneiden.")

    installed_web = {name: webroot / name for name in WEB_FILES}
    for name in WEB_FILES[:3]:
        require_regular_file(installed_web[name], "Bestehende Webdatei")
    for name in WEB_FILES[3:]:
        target = installed_web[name]
        if target.exists() or target.is_symlink():
            require_regular_file(target, "Bestehende Zusatzdatei")

    installed_worker: dict[str, Path] = {}
    if worker_dir is not None:
        installed_worker = {name: worker_dir / name for name in WORKER_FILES}
        for name, path in installed_worker.items():
            if name in WORKER_CORE_FILES or path.exists() or path.is_symlink():
                require_regular_file(path, "Bestehende SMS-Worker-Datei")

    fetch_dir = Path(args.fetch_dir or "/usr/local/bin")
    if args.fetch_dir or fetch_dir.exists():
        fetch_dir = require_real_directory(fetch_dir, "Fetch-Verzeichnis")
    fetch_scripts = sorted(fetch_dir.glob("telepraxis_fetch_and_decrypt_*.sh"))
    if args.fetch_dir and not fetch_scripts:
        raise UpdateError("Keine kanalbezogenen Abrufskripte im angegebenen Fetch-Verzeichnis.")
    fetch_targets = []
    fetch_services = []
    if fetch_scripts:
        if any(overlaps(fetch_dir, path) for path in (webroot, backup_base, source_dir, worker_dir) if path):
            raise UpdateError("Fetch-Verzeichnis und andere Updatepfade müssen getrennt sein.")
        for path in fetch_scripts:
            match = re.fullmatch(r"telepraxis_fetch_and_decrypt_([a-z_][a-z0-9_-]*)\.sh", path.name)
            if not match:
                raise UpdateError(f"Unbekannter Kanalname: {path.name}")
            require_regular_file(path, "Kanal-Abholer")
            fetch_services.append("telepraxis-fetch-and-decrypt-" + match[1] + ".service")
        fetch_targets = [fetch_dir / FETCH_HELPER, *fetch_scripts]
        for command in ("bash", "jq", "ssh", "scp", "openssl", "od"):
            if not shutil.which(command):
                raise UpdateError(f"Laufzeitabhängigkeit des Abholers fehlt: {command}")
    php = check_runtime()
    needed = set(WEB_FILES)
    if fetch_scripts:
        needed.update((FETCH_TEMPLATE, FETCH_HELPER))
    if worker_dir is not None:
        needed.update(WORKER_FILES)
    source_label = str(source_dir) if source_dir is not None else f"GitHub {REPOSITORY}@{args.ref}"

    with ExitStack() as stack:
        for directory in sorted({webroot} | ({worker_dir} if worker_dir else set()) | ({fetch_dir} if fetch_scripts else set())):
            stack.enter_context(acquire_lock(directory))
        target_paths = list(installed_web.values()) + list(installed_worker.values()) + fetch_targets
        initial_fingerprints = target_fingerprints(target_paths)
        temporary_text = stack.enter_context(tempfile.TemporaryDirectory(prefix="kienzlefon-app-update-"))
        temporary = Path(temporary_text)
        os.chmod(temporary, 0o700)
        source_data = read_local_source(source_dir, needed) if source_dir is not None else download_source(args.ref, needed)

        source_files = temporary / "source"
        source_files.mkdir(mode=0o700)
        for name, data in source_data.items():
            if not data.strip():
                raise UpdateError(f"Leere Quelldatei: {name}")
            path = source_files / name
            descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(descriptor, "wb") as handle:
                handle.write(data)

        prepared_web = temporary / "web"
        prepared_web.mkdir(mode=0o700)
        for name in WEB_FILES:
            shutil.copyfile(source_files / name, prepared_web / name)
        patch_constants(php, prepared_web / "telepraxis-app.php", installed_web["telepraxis-app.php"],
                        APP_CONSTANTS, APP_OPTIONAL_CONSTANTS)
        patch_constants(php, prepared_web / "telepraxis-sms.php", installed_web["telepraxis-sms.php"], SMS_CONSTANTS)
        patch_constants(php, prepared_web / "sms-config.php", installed_web["sms-config.php"], CONFIG_CONSTANTS)
        for name in WEB_FILES:
            if name.endswith(".php"):
                lint_php(php, prepared_web / name)
            elif name.endswith(".json"):
                try:
                    json.loads((prepared_web / name).read_bytes())
                except (ValueError, UnicodeError) as exc:
                    raise UpdateError(f"JSON-Syntaxpruefung fehlgeschlagen: {name}") from exc

        prepared_worker: Path | None = None
        if worker_dir is not None:
            prepared_worker = temporary / "worker"
            prepared_worker.mkdir(mode=0o700)
            for name in WORKER_FILES:
                shutil.copyfile(source_files / name, prepared_worker / name)
            patch_constants(
                php,
                prepared_worker / "telepraxis-sms.php",
                installed_worker["telepraxis-sms.php"],
                SMS_CONSTANTS,
            )
            for name in WORKER_FILES:
                lint_php(php, prepared_worker / name)

        prepared_fetch = temporary / "fetch"
        if fetch_scripts:
            prepared_fetch.mkdir(mode=0o700)
            helper = prepared_fetch / FETCH_HELPER
            shutil.copyfile(source_files / FETCH_HELPER, helper)
            helper.chmod(0o644)
            if subprocess.run([sys.executable, str(helper), "--help"], capture_output=True).returncode:
                raise UpdateError("Audio-Helfer benötigt Python 3 mit cryptography (Debian/Ubuntu: python3-cryptography).")
            for path in fetch_scripts:
                prepare_fetch(source_files / FETCH_TEMPLATE, path, prepared_fetch / path.name)
                print(f"  Abrufkanal: {path.name}")
        print_plan(webroot, worker_dir, source_label, args.check)
        if args.check:
            print("Pruefung erfolgreich; es wurden keine Zieldateien, Dienste oder Sicherungen veraendert.")
            return 0

        web_service: tuple[str, bool] | None = None
        worker_service: tuple[str, bool] | None = None
        fetch_service_states = []
        if not args.no_service:
            for service in fetch_services:
                require_loaded_service(service)
                fetch_service_states.append((service, is_service_active(service)))
            web_service = detect_web_service(args.web_service)
            if worker_dir is not None:
                if shutil.which("systemctl") is None:
                    raise UpdateError("systemctl ist fuer den SMS-Worker erforderlich.")
                worker_name = validate_service_name(args.worker_service, "Worker-Dienst")
                require_loaded_service(worker_name)
                worker_active = is_service_active(worker_name)
                if not worker_active and manual_worker_running(installed_worker["telepraxis-sms-worker.php"]):
                    raise UpdateError("Ein manueller/fremder SMS-Worker laeuft; der Betreiber muss ihn vorher stoppen.")
                worker_service = (worker_name, worker_active)

        if args.no_service:
            print("  Dienste: externe Wartung (--no-service); PHP, SMS-Worker und vorhandene Abholer muessen gestoppt sein.")
        else:
            for service in (web_service, worker_service, *fetch_service_states):
                if service is not None:
                    name, active = service
                    print(f"  Dienst: {name} ({'aktiv, wird neu gestartet' if active else 'gestoppt, bleibt gestoppt'})")
            if web_service is not None and worker_service is not None and web_service[0] == worker_service[0]:
                raise UpdateError("Web- und SMS-Worker-Dienst muessen verschieden sein.")

        if not args.yes:
            if not sys.stdin.isatty():
                raise UpdateError("Interaktive Bestaetigung nicht moeglich; gegebenenfalls --yes verwenden.")
            answer = input("Update jetzt installieren? [j/N] ").strip().lower()
            if answer not in ("j", "ja", "y", "yes"):
                print("Abgebrochen; keine Installation vorgenommen.")
                return 0

        targets: list[tuple[str, Path]] = [(f"web/{name}", installed_web[name]) for name in WEB_FILES]
        if worker_dir is not None:
            targets.extend((f"worker/{name}", installed_worker[name]) for name in WORKER_FILES)
        if target_fingerprints(target_paths) != initial_fingerprints:
            raise UpdateError("Installierte Dateien wurden seit der Vorbereitung geaendert; bitte erneut pruefen.")
        targets.extend((f"fetch/{path.name}", path) for path in fetch_targets)
        backup, manifest = create_backup(backup_base, targets)
        print(f"Sicherung: {backup}")

        original_services = []
        if web_service is not None and web_service[1]:
            original_services.append(web_service[0])
        if worker_service is not None and worker_service[1]:
            original_services.insert(0, worker_service[0])
        original_services[:0] = [service for service, active in fetch_service_states if active]
        files_started = False

        try:
            for service in original_services:
                stop_service(service)
            if worker_service is not None and worker_service[1] and manual_worker_running(installed_worker["telepraxis-sms-worker.php"]):
                raise UpdateError("Der SMS-Worker lief nach dem geordneten Dienststopp weiter.")

            files_started = True
            for name in WEB_FILES:
                target = installed_web[name]
                fallback = installed_web["telepraxis-sms.php"]
                atomic_copy(prepared_web / name, target, install_metadata(target, fallback))
            if worker_dir is not None and prepared_worker is not None:
                for name in WORKER_FILES:
                    target = installed_worker[name]
                    atomic_copy(prepared_worker / name, target, install_metadata(target, installed_worker["telepraxis-sms.php"]))

            for target in fetch_targets:
                candidate = prepared_fetch / target.name
                atomic_copy(candidate, target, install_metadata(target, candidate))

            start_order = []
            if web_service is not None and web_service[1]:
                start_order.append(web_service[0])
            if worker_service is not None and worker_service[1]:
                start_order.append(worker_service[0])
            start_order.extend(service for service, active in fetch_service_states if active)
            for service in start_order:
                start_service(service)
        except BaseException as exc:
            # Finish recovery even if a second interrupt arrives during cleanup.
            signal.signal(signal.SIGTERM, signal.SIG_IGN)
            signal.signal(signal.SIGINT, signal.SIG_IGN)
            stop_errors = []
            if files_started:
                for service in original_services:
                    try:
                        stop_service(service)
                    except (UpdateError, OSError):
                        stop_errors.append(service)
            rollback_errors = restore_backup(backup, manifest) if files_started and not stop_errors else []
            service_errors = []
            if not stop_errors and not rollback_errors:
                for service in reversed(original_services):
                    try:
                        start_service(service)
                    except (UpdateError, OSError):
                        service_errors.append(service)
            details = []
            if stop_errors:
                details.append("Rollback blockiert, Dienste konnten nicht gestoppt werden: " + ", ".join(stop_errors))
            if rollback_errors:
                details.append("Rollback unvollstaendig fuer: " + ", ".join(rollback_errors))
            if service_errors:
                details.append("Dienstzustand nicht wiederhergestellt fuer: " + ", ".join(service_errors))
            suffix = (" " + " ".join(details)) if details else (
                " Originaldateien wurden wiederhergestellt." if files_started else " Programmdateien unveraendert.")
            if isinstance(exc, UpdateError):
                raise UpdateError(str(exc) + suffix) from exc
            raise UpdateError("Installation fehlgeschlagen." + suffix) from exc
        print("Update erfolgreich installiert.")
        print("Hinweis: Der Austausch erfolgt atomar je Datei; ein Stromausfall ist keine atomare Gesamttransaktion.")
        return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except UpdateError as error:
        print(f"Fehler: {error}", file=sys.stderr)
        raise SystemExit(1)
    except KeyboardInterrupt:
        print("Fehler: Update unterbrochen.", file=sys.stderr)
        raise SystemExit(130)
    except Exception:
        print("Fehler: Unerwarteter interner Fehler; es wurden keine vertraulichen Details ausgegeben.", file=sys.stderr)
        raise SystemExit(1)
PY
