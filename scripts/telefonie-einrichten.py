"""Kienzlefon-App Telefonie-Setup 1.1 – externe Rufgruppenkopplung und bestehende Platzfreigaben übernehmen."""
import argparse
import fcntl
import getpass
import hashlib
import http.client
import json
import os
import pwd
import re
import stat
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from urllib.parse import urlsplit

PACKAGE = Path(__file__).resolve().parents[1]
DIRECTORY = Path("/etc/kienzlefon-app")
CONFIG = DIRECTORY / "telephony-config.php"
PROXY = Path("/var/www/html/telepraxis-telephony.php")
UNIT = "kienzlefon-app-asterisk.service"
SNAPSHOT = "/run/kienzlefon-app-asterisk/overview.json"


def root_directory(path):
    try:
        path.mkdir(mode=0o755)
    except FileExistsError:
        pass
    else:
        # Der Universalinstaller verwendet umask 077. Die Dienstbenutzer müssen diesen Pfad betreten können.
        path.chmod(0o755)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise ValueError(f"Ungeschütztes Installationsverzeichnis: {path}")


def atomic_file(path, data, mode, uid=0):
    if path.is_symlink() or (path.exists() and not path.is_file()):
        raise ValueError(f"Reguläre Zieldatei erforderlich: {path}")
    with tempfile.NamedTemporaryFile(dir=path.parent, delete=False) as out:
        temp = Path(out.name)
        try:
            out.write(data)
            out.flush()
            os.fchown(out.fileno(), uid, -1)
            os.fchmod(out.fileno(), mode)
            os.replace(temp, path)
        finally:
            temp.unlink(missing_ok=True)


def config_bytes(mode, data):
    literal = json.dumps({"enabled": True, "clients": [], **data}, ensure_ascii=True)
    literal = literal.replace("\\", "\\\\").replace("'", "\\'")
    return f"<?php\n// Kienzlefon Installer: {mode}\nreturn json_decode('{literal}', true, 512, JSON_THROW_ON_ERROR);\n".encode()


def check(config=None):
    code = '$_GET["action"]="status"; $_SERVER["DOCUMENT_ROOT"]=dirname($argv[1]); require $argv[1];'
    env = ["env", "KIENZLEFON_TELEPHONY_CONFIG=" + str(config)] if config else []
    result = subprocess.run(["runuser", "-u", "www-data", "--", *env, "php", "-r", code, str(PROXY)],
                            capture_output=True, timeout=12)
    try:
        data = json.loads(result.stdout)
    except ValueError:
        data = {}
    if (result.returncode or data.get("schema_version") != 1 or data.get("source") != "live"
            or not isinstance(data.get("extensions"), list) or not data["extensions"]):
        raise ValueError("Die App erhält keine gültige Nebenstellenliste. " + str(data.get("error", {}).get("code", "")))
    return len(data["extensions"])


def set_source(mode, config):
    with tempfile.NamedTemporaryFile(dir=DIRECTORY, suffix=".php") as candidate:
        candidate.write(config_bytes(mode, config))
        candidate.flush()
        os.fchmod(candidate.fileno(), 0o644)
        count = check(candidate.name)
        atomic_file(CONFIG, Path(candidate.name).read_bytes(), 0o644)
    return count


def read_private_token(token_file):
    with os.fdopen(os.open(token_file, os.O_NOFOLLOW | os.O_RDONLY | os.O_NONBLOCK), "rb") as src:
        info = os.fstat(src.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o077:
            raise ValueError("Die Schlüsseldatei muss privat und regulär sein.")
        token = src.read(67).decode("ascii").rstrip("\r\n")
    if not re.fullmatch(r"[0-9a-fA-F]{64}", token):
        raise ValueError("Ungültiger Dienstschlüssel.")
    return token


def external_routing_policy(url, token):
    policy = {}
    if CONFIG.is_file():
        code = ("$c=require $argv[1]; echo json_encode(array_intersect_key($c, "
                "array_flip(['clients','workplace_mode','pbx_id'])), JSON_THROW_ON_ERROR);")
        saved = subprocess.check_output(["runuser", "-u", "www-data", "--", "php", "-r", code, str(CONFIG)], timeout=5)
        policy = dict(json.loads(saved))
    target = urlsplit(url)
    connection = http.client.HTTPSConnection if target.scheme == "https" else http.client.HTTPConnection
    conn = connection(target.hostname, target.port, timeout=4)
    try:
        conn.request("GET", "/api/v1/extension-routing/workplaces", headers={"Authorization": "Bearer " + token})
        response = conn.getresponse()
        if response.status != 200:
            return policy  # An overview-only or older middleware may have no pairing.
        scope = json.loads(response.read(2 * 1024 * 1024 + 1))
        if not isinstance(scope, dict) or scope.get("schema_version") != 1:
            raise ValueError("Ungültige Arbeitsplatzfreigabe der Middleware.")
        pbx = scope.get("pbx_id")
        if not isinstance(pbx, str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{0,63}", pbx):
            raise ValueError("Ungültige Anlagenkennung in der Arbeitsplatzfreigabe.")
        if policy.get("pbx_id") and policy["pbx_id"] != pbx:
            raise ValueError("Die Middleware gehört zu einer anderen als der eingerichteten Telefonanlage.")
        policy["pbx_id"] = pbx
        return policy
    except (OSError, http.client.HTTPException):
        return policy
    finally:
        conn.close()


def external(url, token_file):
    url = (url or input("Private Basis-URL der T2med-Middleware: ")).strip().rstrip("/")
    u = urlsplit(url)
    if (u.scheme not in ("http", "https") or not u.hostname or u.username is not None or u.password is not None
            or u.path or u.fragment or u.query or any(c.isspace() for c in url)):
        raise ValueError("Eine HTTP(S)-Basisadresse im Praxisnetz oder VPN ist erforderlich.")
    if token_file:
        token = read_private_token(token_file)
    else:
        if not sys.stdin.isatty():
            raise ValueError("Interaktives Terminal oder --token-file für den Dienstschlüssel erforderlich.")
        token = getpass.getpass("App-Dienstschlüssel der Middleware (Eingabe verdeckt): ").strip()
    if not re.fullmatch(r"[0-9a-fA-F]{64}", token):
        raise ValueError("Der App-Dienstschlüssel muss 64 Hexzeichen enthalten.")
    keys = {"": token.encode()}
    if token_file:
        for role in (".lookup", ".workplace"):
            source = Path(str(token_file) + role)
            if source.exists() or source.is_symlink():
                keys[role] = read_private_token(source).encode()
    if len(set(keys.values())) != len(keys):
        raise ValueError("Die Dienstzugänge benötigen unterschiedliche Schlüssel.")
    # Bind the set of scopes to this configuration; publish the config last.
    digest = hashlib.sha256(b"|".join(keys.values())).hexdigest()[:24]
    path = DIRECTORY / ("external-" + digest + ".token")
    created = []
    try:
        for suffix, data in keys.items():
            dest = Path(str(path) + suffix)
            if not dest.exists():
                created.append(dest)
            atomic_file(dest, data, 0o600, pwd.getpwnam("www-data").pw_uid)
        policy = external_routing_policy(url, token)
        return set_source("extern", dict(middleware_url=url, token_file=str(path), **policy))
    except BaseException:
        for dest in created:
            dest.unlink(missing_ok=True)
        raise


def local_asterisk():
    if not PROXY.is_file() or b"asterisk_snapshot" not in PROXY.read_bytes():
        raise ValueError("Bitte zuerst das App-Paket mit lokaler Nebenstellenanzeige installieren.")
    subprocess.run(["systemctl", "is-active", "--quiet", "asterisk.service"], check=True)
    socket = Path("/run/asterisk/asterisk.ctl").stat()
    if not stat.S_ISSOCK(socket.st_mode):
        raise ValueError("Lokaler Asterisk-Steuersocket fehlt.")
    script = Path("/usr/local/lib/kienzlefon-app/asterisk-nebenstellen.py")
    root_directory(script.parent)
    unit_text = (PACKAGE / "config" / UNIT).read_text()
    unit_text = unit_text.replace("@SOCKET_UID@", str(socket.st_uid)).replace("@SOCKET_GID@", str(socket.st_gid))
    unit_path = Path("/etc/systemd/system") / UNIT
    updates = {script: (PACKAGE / "scripts" / script.name).read_bytes(), unit_path: unit_text.encode()}
    previous = {}
    for path in updates:
        if path.is_symlink() or (path.exists() and not path.is_file()):
            raise ValueError(f"Reguläre Zieldatei erforderlich: {path}")
        previous[path] = (path.read_bytes(), stat.S_IMODE(path.stat().st_mode)) if path.exists() else None
    was_enabled = subprocess.run(["systemctl", "is-enabled", "--quiet", UNIT]).returncode == 0
    was_active = subprocess.run(["systemctl", "is-active", "--quiet", UNIT]).returncode == 0
    try:
        for path, content in updates.items():
            atomic_file(path, content, 0o644)
        subprocess.run(["systemctl", "daemon-reload"], check=True)
        subprocess.run(["systemctl", "enable", UNIT], check=True)
        subprocess.run(["systemctl", "restart", UNIT], check=True)
        for attempt in range(10):
            try:
                return set_source("asterisk", dict(asterisk_snapshot=SNAPSHOT))
            except ValueError:
                if attempt == 9:
                    raise
                time.sleep(1)
    except BaseException:
        if not was_active:
            subprocess.run(["systemctl", "stop", UNIT])
        if not was_enabled:
            subprocess.run(["systemctl", "disable", UNIT])
        for path, backup in previous.items():
            if backup is None:
                path.unlink(missing_ok=True)
            else:
                atomic_file(path, *backup)
        subprocess.run(["systemctl", "daemon-reload"], check=True)
        if was_active:
            subprocess.run(["systemctl", "restart", UNIT], check=True)
        raise


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("asterisk", "extern", "pruefen"))
    parser.add_argument("--url")
    parser.add_argument("--token-file", type=Path)
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.error("Als root auf dem App-Server starten.")
    if args.mode == "pruefen":
        return check()
    with open("/run/kienzlefon-app-telephony.lock", "a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        root_directory(DIRECTORY)
        if args.mode == "asterisk":
            return local_asterisk()
        was_local = CONFIG.is_file() and b"// Kienzlefon Installer: asterisk" in CONFIG.read_bytes().splitlines()[:2]
        count = external(args.url, args.token_file)
        if was_local:
            subprocess.run(["systemctl", "disable", "--now", UNIT], check=True)
        return count


if __name__ == "__main__":
    try:
        print(f"Nebenstellenanzeige geprüft: {main()} Nebenstellen.")
    except (ValueError, OSError, subprocess.SubprocessError) as error:
        raise SystemExit(str(error)) from None
