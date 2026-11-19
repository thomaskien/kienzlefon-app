"""Verify and publish one Kienzlefon audio delivery. Called by the SSH fetcher."""

from __future__ import annotations

from contextlib import contextmanager
import argparse
import base64
import fcntl
import hashlib
import io
import json
import os
import re
import shutil
import stat
import sys
import tempfile
import wave
from pathlib import Path
from zipfile import BadZipFile, ZIP_STORED, ZipFile

from cryptography.exceptions import InvalidTag, UnsupportedAlgorithm
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM


PACKAGE = re.compile(r"([A-Za-z0-9_-]{1,64})\.(kienzlepaket|json\.enc)")
WAV_PATH = re.compile(r"audio/[A-Za-z0-9_-]+\.wav")
IDENTIFIER = re.compile(r"[A-Za-z0-9_-]{1,64}")
LIMIT = 48 * 1024 * 1024
HEADER = {"typ": "kienzlefon-paket-v1+jwe", "cty": "application/zip",
          "alg": "RSA-OAEP-256", "enc": "A256GCM"}
ERROR = {"schema_version": 1, "status": "fehler", "fehlercode": "audio_uebergabe_fehlgeschlagen"}


def read_limited(path: Path) -> bytes:
    with path.open("rb") as handle:
        data = handle.read(LIMIT + 1)
    if len(data) > LIMIT:
        raise ValueError("Lieferdatei zu gross")
    return data


def base64url(part: bytes) -> bytes:
    if not re.fullmatch(b"[A-Za-z0-9_-]+", part):
        raise ValueError("Ungueltiges JWE")
    return base64.b64decode(part + b"=" * (-len(part) % 4), altchars=b"-_", validate=True)


def open_package(encrypted: bytes, private_key: Path) -> bytes:
    protected, wrapped, nonce, ciphertext, tag = encrypted.split(b".")
    if json.loads(base64url(protected)) != HEADER:
        raise ValueError("Unbekanntes Paketformat")
    nonce, tag = base64url(nonce), base64url(tag)
    if len(nonce) != 12 or len(tag) != 16:
        raise ValueError("Ungueltige JWE-Parameter")
    try:
        key = serialization.load_pem_private_key(private_key.read_bytes(), password=None)
    except (TypeError, ValueError, UnsupportedAlgorithm) as error:
        raise OSError("Lokaler Empfängerschlüssel ist nicht verwendbar") from error
    if not isinstance(key, rsa.RSAPrivateKey) or key.key_size < 2048:
        raise OSError("Ungueltiger lokaler RSA-Schluessel")
    content_key = key.decrypt(
        base64url(wrapped),
        padding.OAEP(mgf=padding.MGF1(hashes.SHA256()), algorithm=hashes.SHA256(), label=None),
    )
    if len(content_key) != 32:
        raise ValueError("Ungueltiger AES-Schluessel")
    return AESGCM(content_key).decrypt(nonce, base64url(ciphertext) + tag, protected)


def validate_record(record: dict, identifier: str) -> tuple[dict, set[str]]:
    payload = record["payload"]
    meta = payload["_kienzlefon"]
    if meta["vorgang_id"] != identifier or not isinstance(meta["audio"], list):
        raise ValueError("Vorgangs-ID oder Audioangaben stimmen nicht")
    ids = set()
    if record["typ"] == "mehrere_anliegen" or payload.get("typ") == "mehrere_anliegen":
        items = payload.get("anliegen_liste")
        if record["typ"] != "mehrere_anliegen" or payload.get("typ") != "mehrere_anliegen" or (
            type(payload.get("anliegen_schema_version")) is not int or payload["anliegen_schema_version"] != 1
            or not isinstance(items, list) or len(items) < 2
        ):
            raise ValueError("Ungueltige Anliegenliste")
        for item in items:
            item_id, kind = item["anliegen_id"], item["typ"]
            if not isinstance(item_id, str) or not IDENTIFIER.fullmatch(item_id) or item_id in ids or (
                not isinstance(kind, str) or not kind.strip()
            ):
                raise ValueError("Ungueltiges Teilanliegen")
            ids.add(item_id)
    return meta, ids


def inspect_archive(plaintext: bytes, identifier: str) -> tuple[dict, dict[str, bytes]]:
    with ZipFile(io.BytesIO(plaintext)) as archive:
        entries = archive.infolist()
        names = {entry.filename for entry in entries}
        if len(names) != len(entries) or "vorgang.json" not in names:
            raise ValueError("Fehlender oder doppelter Dateieintrag")
        for entry in entries:
            kind = stat.S_IFMT(entry.external_attr >> 16)
            valid_name = entry.filename == "vorgang.json" or WAV_PATH.fullmatch(entry.filename)
            if (not valid_name or entry.orig_filename != entry.filename or entry.flag_bits & 1
                    or entry.compress_type != ZIP_STORED or kind not in (0, stat.S_IFREG)):
                raise ValueError("Unzulaessiger Dateieintrag")
        if sum(entry.file_size for entry in entries) > LIMIT:
            raise ValueError("Archiv zu gross")
        record = json.loads(archive.read("vorgang.json").decode("utf-8"))
        meta, ids = validate_record(record, identifier)
        wavs = {}
        for segment in meta["audio"]:
            relative = segment["datei"]
            if not WAV_PATH.fullmatch(relative) or relative not in names or relative in wavs or (
                "anliegen_id" in segment and segment["anliegen_id"] not in ids
            ):
                raise ValueError("Ungueltiger WAV-Verweis")
            data = archive.read(relative)
            with wave.open(io.BytesIO(data), "rb") as audio:
                size = audio.getnframes() * audio.getnchannels() * audio.getsampwidth()
                if size == 0 or len(audio.readframes(audio.getnframes())) != size:
                    raise ValueError("Unvollstaendige WAV")
            wavs[relative] = data
        if not wavs or names != {"vorgang.json", *wavs}:
            raise ValueError("Unvollstaendige Audio-Beilage")
        return record, wavs


def sync_dir(directory: Path) -> None:
    descriptor = os.open(directory, os.O_RDONLY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def atomic_write(path: Path, data: bytes, mode: int = 0o660) -> None:
    # All callers hold the inbox lock. A retry reclaims an interrupted write to this exact target.
    tag = hashlib.sha256(os.fsencode(path.name)).hexdigest()
    temporary = path.with_name(".delivery-tmp-" + tag)
    temporary.unlink(missing_ok=True)
    try:
        with os.fdopen(os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), "wb") as handle:
            handle.write(data)
            handle.flush()
            os.fchmod(handle.fileno(), mode)
            os.fsync(handle.fileno())
        os.replace(temporary, path)
        sync_dir(path.parent)
    finally:
        temporary.unlink(missing_ok=True)


def encode(document: dict) -> bytes:
    return (json.dumps(document, ensure_ascii=False, indent=2) + "\n").encode()


def delivery_ack(filename: str, digest: str, status: str) -> dict:
    return {"v": 1, "vorgang_id": filename.removesuffix(".kienzlepaket").removesuffix(".json.enc"), "datei": filename,
            "sha256": digest, "status": status}


def add_audio_error(path: Path, identifier: str) -> None:
    document = json.loads(path.read_bytes())
    meta = document["payload"]["_kienzlefon"]
    if meta["vorgang_id"] != identifier:
        raise ValueError("Falsche Vorgangs-ID am Ziel")
    meta["audio_beilage"] = ERROR.copy()
    atomic_write(path, encode(document))


@contextmanager
def inbox_lock(inbox: Path):
    descriptor = os.open(inbox / ".telepraxis-inbox.lock", os.O_CREAT | os.O_NOFOLLOW | os.O_RDWR, 0o660)
    with os.fdopen(descriptor, "r+b") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        yield


def journal_state(journal: Path) -> dict | None:
    try:
        document = json.loads(journal.read_bytes())
        if not isinstance(document["complete"], bool) or not isinstance(document["quittung"], dict):
            raise ValueError
        # Also retry a directory fsync that failed after a previous successful rename.
        sync_dir(journal.parent)
        return document
    except FileNotFoundError:
        return None
    except (ValueError, KeyError, TypeError) as error:
        raise OSError("Lokaler Importnachweis ist nicht lesbar") from error


def existing_case(inbox: Path, identifier: str, *, former_name: str | None = None,
                  numbered: bool = False) -> Path | None:
    trash = inbox / "papierkorb"
    if trash.is_symlink():
        raise OSError("Ungueltiger Papierkorb")
    candidates = [directory / (identifier + ".json") for directory in (inbox, trash)]
    if former_name and former_name != identifier + ".json":
        if former_name != Path(former_name).name or not former_name.endswith(".json"):
            raise OSError("Ungültiger Dateiname im Importnachweis")
        candidates.extend(directory / former_name for directory in (inbox, trash))
    if numbered:
        # Only an outstanding replacement for an already filed JSON needs this lookup.
        candidates.extend(path for directory in (inbox, trash) for path in directory.glob(identifier + "-*.json")
                          if re.fullmatch(re.escape(identifier) + r"-[0-9]+\.json", path.name))
    for candidate in candidates:
        if candidate.is_symlink() or (candidate.exists() and not candidate.is_file()):
            raise OSError("Ungueltige Vorgangsablage")
        if candidate.is_file():
            try:
                existing = json.loads(candidate.read_bytes())
                local_id = existing.get("payload", {}).get("_kienzlefon", {}).get("vorgang_id")
            except (ValueError, AttributeError) as error:
                raise OSError("Vorhandener Vorgang ist nicht lesbar") from error
            if local_id == identifier or (local_id is None and candidate.name == identifier + ".json"):
                return candidate
    return None


def discard_stage(inbox: Path, identifier: str) -> None:
    directory = inbox / identifier
    stage = directory / ".delivery-audio"
    if directory.is_symlink() or stage.is_symlink():
        raise OSError("Ungültiger Audiopfad")
    if stage.exists():
        shutil.rmtree(stage)
        sync_dir(directory)
    (inbox / ("." + identifier + ".delivery-pending")).unlink(missing_ok=True)
    sync_dir(inbox)


def complete_pending(inbox: Path, journal: Path, current: dict) -> dict | None:
    # The staged JSON is only consumed by rename after all WAVs and the intent are durable.
    # Its absence after a crash is evidence of publication even after trash/purge.
    identifier = current["quittung"]["vorgang_id"]
    pending = inbox / ("." + identifier + ".delivery-pending")
    if pending.is_symlink():
        raise OSError("Ungültige vorbereitete Vorgangsdatei")
    if pending.exists():
        if existing_case(inbox, identifier) is None:
            target = inbox / (identifier + ".json")
            if target.exists():
                raise OSError("Dateiname ist durch einen anderen Vorgang belegt")
            if current.get("art") == "paket":
                directory = inbox / identifier
                stage = directory / ".delivery-audio"
                audio = directory / "audio"
                if directory.is_symlink() or stage.is_symlink() or audio.is_symlink():
                    raise OSError("Ungültiger Audiopfad")
                if stage.exists():
                    if audio.exists():
                        raise OSError("Audioziel bereits belegt")
                    os.rename(stage, audio)
                if not audio.is_dir():
                    raise OSError("Vorbereitete Aufnahmen fehlen")
                sync_dir(directory)
            os.replace(pending, target)
        else:
            # A direct delivery won while the fetcher was down. Never attest its audio as ours.
            journal.unlink()
            sync_dir(journal.parent)
            discard_stage(inbox, identifier)
            return None
    sync_dir(inbox)
    current = {**current, "complete": True}
    atomic_write(journal, encode(current), 0o600)
    return current


def prior_receipts(current: dict, ack: dict) -> list[dict]:
    original = current["quittung"]
    return [original] if original == ack else [original, {**ack, "status": "ueberholt"}]


def prepare_audio(directory: Path, wavs: dict[str, bytes]) -> None:
    if directory.is_symlink():
        raise OSError("Ungültiges Begleitverzeichnis")
    directory.mkdir(mode=0o770, exist_ok=True)
    stage = directory / ".delivery-audio"
    destination = directory / "audio"
    if stage.is_symlink() or destination.is_symlink():
        raise OSError("Ungültiges Audioverzeichnis")
    expected = {Path(name).name: data for name, data in wavs.items()}
    if destination.exists():
        if {p.name for p in destination.iterdir()} != expected.keys():
            raise OSError("Vorhandenes Audio gehört zu einer anderen Ablage")
        for name, data in expected.items():
            path = destination / name
            if path.is_symlink() or not path.is_file() or path.stat().st_size != len(data) or path.read_bytes() != data:
                raise OSError("Vorhandene Aufnahme stimmt nicht überein")
        sync_dir(destination)
    else:
        stage.mkdir(mode=0o770, exist_ok=True)
        for name, data in expected.items():
            atomic_write(stage / name, data)
        if {p.name for p in stage.iterdir()} != expected.keys():
            raise OSError("Unvollständige Audioablage")
        sync_dir(stage)
    sync_dir(directory)
    sync_dir(directory.parent)


def publish(inbox: Path, state: Path, ack: dict, record: dict, wavs: dict[str, bytes], *,
            kind: str, plaintext: bytes | None = None) -> list[dict]:
    identifier = ack["vorgang_id"]
    state.mkdir(mode=0o700, exist_ok=True)
    sync_dir(state.parent)
    with inbox_lock(inbox):
        journal = state / (identifier + ".json")
        current = journal_state(journal)
        target = inbox / (identifier + ".json")
        receipt = {"complete": True, "quittung": ack, "art": kind}
        if current and not current["complete"] and current.get("staged"):
            current = complete_pending(inbox, journal, current)
        if current and current["complete"] and not (current.get("art") == "json" and kind in ("ersatz", "paket")):
            return prior_receipts(current, ack)
        transport = current["quittung"]["datei"] if current else ack["datei"]
        former_name = transport[:-4] if transport.endswith(".json.enc") else None
        existing = existing_case(inbox, identifier, former_name=former_name)
        if existing is None and current and current.get("art") == "json" and kind == "ersatz":
            existing = existing_case(inbox, identifier, numbered=True)
        if current and (current["complete"] or ("staged" not in current and existing is not None)):
            if current.get("art") == "json" and kind == "ersatz":
                if existing is not None:
                    add_audio_error(existing, identifier)
                atomic_write(journal, encode(receipt), 0o600)
                return [ack]
            if current.get("art") == "json" and kind == "paket":
                raise ValueError("Vorgang wurde bereits als JSON abgelegt")
            atomic_write(journal, encode({**current, "complete": True}), 0o600)
            return prior_receipts(current, ack)
        if current and current["quittung"] != ack:
            if current.get("staged") is False and current["art"] == "paket" and kind == "ersatz":
                # No WAV directory or visible JSON has been published by this transaction.
                discard_stage(inbox, identifier)
            else:
                raise OSError("Eine andere Lieferung wartet auf Ablage")
        if existing is not None:
            if wavs:
                raise ValueError("Vorgang ohne Audio-Importnachweis ist bereits vorhanden")
            if kind == "ersatz":
                add_audio_error(existing, identifier)
            with existing.open("rb") as saved:
                os.fsync(saved.fileno())
            sync_dir(existing.parent)
            atomic_write(journal, encode(receipt), 0o600)
        else:
            if target.exists():
                raise OSError("Dateiname ist durch einen anderen Vorgang belegt")
            # Commit the entire audio set before the presence of the case becomes observable.
            # Only this channel owns the unadvertised files until the JSON is published.
            atomic_write(journal, encode({**receipt, "complete": False, "staged": False}), 0o600)
            if wavs:
                prepare_audio(inbox / identifier, wavs)
            pending = inbox / ("." + identifier + ".delivery-pending")
            atomic_write(pending, plaintext if plaintext is not None else encode(record))
            prepared = {**receipt, "complete": False, "staged": True}
            atomic_write(journal, encode(prepared), 0o600)
            if complete_pending(inbox, journal, prepared) is None:
                raise OSError("Vorgang wurde zwischenzeitlich lokal abgelegt")
        return [ack]


def failed_attempt(inbox: Path, state: Path, filename: str, digest: str) -> list[dict]:
    ack = delivery_ack(filename, digest, "abgelegt")
    identifier = ack["vorgang_id"]
    with inbox_lock(inbox):
        journal = state / (identifier + ".json")
        current = journal_state(journal)
        if current and not current["complete"] and current.get("staged"):
            current = complete_pending(inbox, journal, current)
        if current and current["complete"] and current.get("art") != "json":
            return prior_receipts(current, ack)
        # An inaccessible destination is a local transport fault, not a failed package.
        with tempfile.TemporaryFile(dir=inbox) as probe:
            probe.write(b"1")
            probe.flush()
            os.fsync(probe.fileno())
        counter = state / (identifier + "." + digest + ".failures")
        count = int(counter.read_text()) if counter.exists() else 0
        if count < 3:
            count += 1
            atomic_write(counter, str(count).encode(), 0o600)
        if count < 3:
            raise OSError("Weitere Abrufversuche stehen aus")
        return [{**ack, "status": "ersatz_json_noetig"}]


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--inbox", type=Path, required=True)
    parser.add_argument("--state", type=Path, required=True)
    parser.add_argument("--key", type=Path)
    parser.add_argument("--plain-json", type=Path)
    parser.add_argument("--failure-hash")
    parser.add_argument("file", type=Path)
    args = parser.parse_args()
    filename = args.file.name
    package = filename.endswith(".kienzlepaket")
    if not (PACKAGE.fullmatch(filename) or re.fullmatch(r"[A-Za-z0-9_-][A-Za-z0-9._-]*\.json\.enc", filename)):
        return 2
    try:
        if args.failure_hash is not None:
            if not package or not re.fullmatch("[a-f0-9]{64}", args.failure_hash):
                return 2
            print(json.dumps(failed_attempt(args.inbox, args.state, filename, args.failure_hash)))
            return 0
        encrypted = read_limited(args.file)
        plaintext = None
        ack = delivery_ack(filename, hashlib.sha256(encrypted).hexdigest(), "abgelegt")
        identifier = ack["vorgang_id"]
        if package:
            counter = args.state / (identifier + "." + ack["sha256"] + ".failures")
            if counter.is_file() and counter.read_text().strip() == "3":
                print(json.dumps(failed_attempt(args.inbox, args.state, filename, ack["sha256"])))
                return 0
            document, wavs = inspect_archive(open_package(encrypted, args.key), identifier)
            kind = "paket"
        else:
            plaintext = read_limited(args.plain_json)
            document = json.loads(plaintext)
            meta = document.get("payload", {}).get("_kienzlefon", {})
            if "audio_beilage" in meta:
                meta, _ = validate_record(document, identifier)
                error = meta["audio_beilage"]
                if (not PACKAGE.fullmatch(filename) or meta["audio"] or not isinstance(error, dict)
                        or type(error.get("schema_version")) is not int
                        or any(error.get(key) != value for key, value in ERROR.items())):
                    return 2
                kind = "ersatz"
            else:
                kind = "json"
                # A PHP channel can assign its own random transport filename.
                if "vorgang_id" in meta:
                    if not IDENTIFIER.fullmatch(meta["vorgang_id"]):
                        return 2
                    ack["vorgang_id"] = meta["vorgang_id"]
            wavs = {}
        receipts = publish(args.inbox, args.state, ack, document, wavs, kind=kind, plaintext=plaintext)
        print(json.dumps([] if kind == "json" else receipts))
        return 0
    except OSError:
        print("Lokale Ablage oder Transport derzeit nicht verfügbar.", file=sys.stderr)
        return 3
    except (BadZipFile, InvalidTag, ValueError, TypeError, KeyError, AttributeError, EOFError, NotImplementedError, wave.Error):
        print("Lieferdatei ist ungültig.", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
