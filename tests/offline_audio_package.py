"""Offline compatibility run against an unpacked App archive and the pinned Kienzlefon producer."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tarfile
import tempfile
import wave
from zoneinfo import ZoneInfo

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa


ROOT = Path(__file__).resolve().parents[1]
PRODUCER = "73651ee"
FETCH = "telepraxis_fetch_and_decrypt.sh"


def load_producer(producer_repo, root):
    snapshot = root / "producer"
    snapshot.mkdir()
    source = subprocess.check_output(["git", "-C", str(producer_repo), "archive", PRODUCER, "kienzlefon"])
    with tarfile.open(fileobj=io.BytesIO(source)) as tar:
        tar.extractall(snapshot, filter="data")
    sys.path.insert(0, str(snapshot))
    from kienzlefon import __version__
    assert __version__ == "2.6.0"


def publish_case(root, timezone, identifier, emitter, *, attach=True, truncated_wav=False):
    from kienzlefon.spool import WorkingCall
    call = WorkingCall(root / "spool" / identifier, timezone)
    call.audio_directory.mkdir(parents=True)
    payload = json.loads((ROOT / "docs/vorgang-v1/beispiele/mehrere-anliegen.json").read_text())["payload"]
    meta = payload["_kienzlefon"]
    meta["vorgang_id"] = identifier
    wav = call.path / meta["audio"][0]["datei"]
    with wave.open(str(wav), "wb") as writer:
        writer.setparams((1, 2, 16000, 0, "NONE", ""))
        writer.writeframes(b"\x00\x01" * 160)
    if truncated_wav:
        wav.write_bytes(wav.read_bytes()[:-2])
    expected = wav.read_bytes()
    call.save(payload)
    emitter.publish(call, payload, attach=attach)
    return call, wav, expected


def run(archive, producer_repo, root):
    with tarfile.open(archive) as tar:
        tar.extractall(root, filter="data")
    component = root / "kienzlefon-app"
    load_producer(producer_repo, root)
    from kienzlefon.crypto import TelepraxisEncryptor
    from kienzlefon.delivery import Delivery

    remote, inbox, pull, commands = (root / name for name in ("source", "inbox", "pull", "bin"))
    for directory in (remote, inbox, pull, commands):
        directory.mkdir()
    private = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    key = root / "private.pem"
    key.write_bytes(private.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8,
                                          serialization.NoEncryption()))
    key.chmod(0o600)
    public = root / "public.pem"
    public.write_bytes(private.public_key().public_bytes(serialization.Encoding.PEM,
                                                         serialization.PublicFormat.SubjectPublicKeyInfo))
    ssh_key = root / "ssh"
    subprocess.run(["ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-f", str(ssh_key)], check=True)
    timezone = ZoneInfo("Europe/Berlin")
    producer = Delivery(TelepraxisEncryptor(public, remote, timezone))
    (commands / "ssh").write_text("""#!/usr/bin/env bash
set -eu
[[ ${@: -2:1} == fixture@sender.invalid ]] || exit 98
cmd=${@: -1}
[[ ! -e "$FIXTURE/disconnected" ]] || exit 255
if [[ $cmd == "sha256sum --"* && -e "$FIXTURE/no-hash" ]]; then exit 255; fi
if [[ $cmd == *".ack.XXXXXX"* && -e "$FIXTURE/lose-ack" ]]; then head -c 20 | /bin/sh -c "$cmd"; exit $?; fi
exec /bin/sh -c "$cmd"
""")
    (commands / "scp").write_text("""#!/usr/bin/env bash
set -eu
source=${@: -2:1}
[[ $source == "fixture@sender.invalid:$FIXTURE/source/"* ]] || exit 98
[[ ! -e "$FIXTURE/disconnected" ]] || exit 255
target=${@: -1}
cp -- "${source#*:}" "$target"
if [[ -e "$FIXTURE/incomplete" ]]; then printf 'partial' > "$target"; exit 1; fi
if [[ -e "$FIXTURE/corrupt" && $source == *.kienzlepaket ]]; then
    python3 - "$target" <<'TAG'
from pathlib import Path
import sys
file = Path(sys.argv[1])
body, tag = file.read_bytes().rsplit(b".", 1)
file.write_bytes(body + b"." + (b"A" if tag[:1] != b"A" else b"B") + tag[1:])
TAG
fi
""")
    for executable in commands.iterdir():
        executable.chmod(0o700)
    source = (component / FETCH).read_text()
    settings = dict(REMOTE_HOST="sender.invalid", REMOTE_USER="fixture", REMOTE_DIR=remote, LOCAL_PULL_DIR=pull,
                    LOCAL_OUT_DIR=inbox, PRIVATE_KEY_FILE=key, SSH_IDENTITY_FILE=ssh_key, RUN_FOREVER=0)
    for name, value in settings.items():
        source, count = re.subn(rf'^{name}=".*"$', lambda _: f'{name}="{value}"', source, flags=re.M)
        assert count == 1, name
    script = component / FETCH
    script.write_text(source)
    env = {**os.environ, "PATH": str(commands) + ":" + str(Path(sys.executable).parent) + ":" + os.environ["PATH"],
           "FIXTURE": str(root), "PYTHONDONTWRITEBYTECODE": "1"}
    for name in ("TELEPRAXIS_AUDIO_HELPER", "TELEPRAXIS_AUDIO_STATE"):
        env.pop(name, None)

    def fetch(ok=True):
        result = subprocess.run(["bash", str(script)], env=env, capture_output=True, text=True, timeout=30)
        assert (result.returncode == 0) == ok, (result.returncode, result.stdout, result.stderr)
        assert {p.name for p in pull.iterdir()} <= {".vorgang-v1"}
        assert not (pull / ".vorgang-v1/.fetch-work").exists()
        assert not list(inbox.glob(".*.delivery-pending"))
        assert not list(remote.rglob(".ack.*"))

    def case(identifier, attach=True, emitter=producer, truncated_wav=False):
        return publish_case(root, timezone, identifier, emitter, attach=attach, truncated_wav=truncated_wav)

    def receipt(file, status):
        identifier = file.name.split(".")[0]
        digest = hashlib.sha256(file.read_bytes()).hexdigest()
        path = producer.receipts / f"{identifier}.{digest}.{status}.ack.json"
        assert json.loads(path.read_bytes()) == dict(v=1, vorgang_id=identifier, datei=file.name,
                                                     sha256=digest, status=status)
        return path

    # Drive the packaged PHP API as well as the actual fetcher, with synthetic data only.
    driver = root / "request.php"
    php = component / "telepraxis-app.php"
    patched, n = re.subn(r"const TELEPRAXIS_INBOX_DIR = [^;]*;", lambda _: f"const TELEPRAXIS_INBOX_DIR = '{inbox}';", php.read_text())
    assert n == 1
    php.write_text(patched)
    driver.write_text("""<?php
$data = json_decode(stream_get_contents(STDIN), true);
$_SERVER['REQUEST_METHOD'] = isset($data['action']) ? 'POST' : 'GET';
$_POST = isset($data['action']) ? $data : [];
$_GET = $_POST ? [] : ['ajax'=>'list'];
$_REQUEST = $_POST ?: $_GET;
session_save_path($argv[2]);
session_id('offlineaudio');
require $argv[1];
""")
    def api(**values):
        result = subprocess.run(["php", str(driver), str(php), str(root)], input=json.dumps(values),
                                text=True, capture_output=True, check=True)
        response = json.loads(result.stdout)
        assert response["ok"], response
        return response

    csrf = api()["csrf"]
    def act(identifier, action, **params):
        return api(action=action, file=identifier+".json", csrf=csrf, benutzer="Testperson", workplace="Testplatz", **params)

    identifier = "offline-erfolg"
    call, wav, wav_bytes = case(identifier)
    packet = remote / (identifier + ".kienzlepaket")
    encrypted = packet.read_bytes()
    target = inbox / (identifier + ".json")
    local_wav = inbox / identifier / "audio" / wav.name
    (root / "lose-ack").touch()
    fetch(False)
    assert local_wav.read_bytes() == wav_bytes and wav.exists() and packet.exists()
    assert not list(producer.receipts.glob("*.ack.json"))
    act(identifier, "set_status", status="in_bearbeitung")
    act(identifier, "set_anliegen", anliegen_id="a-001", done="1")
    edited = target.read_bytes()
    (root / "lose-ack").unlink()
    fetch()
    assert target.read_bytes() == edited
    assert len(api()["entries"]) == 1 and api()["entries"][0]["status"] == "in_bearbeitung"
    receipt(packet, "abgelegt")
    immutable_fallback = producer._without_audio(call.load(), failed=True)
    late_file = producer.encryptor.write_payload(immutable_fallback, identifier)
    fetch()
    receipt(late_file, "ueberholt")
    assert edited == target.read_bytes()
    late_file.unlink()
    for item in ("a-002", "a-003"):
        act(identifier, "set_anliegen", anliegen_id=item, done="1")
    act(identifier, "set_status", status="abgeschlossen")
    finished = target.read_bytes()
    fetch()
    assert finished == target.read_bytes() and local_wav.is_file()
    act(identifier, "soft_delete")
    archived = inbox / "papierkorb" / target.name
    saved_trash = archived.read_bytes()
    fetch()
    assert archived.read_bytes() == saved_trash and not local_wav.exists() and not target.exists()
    producer.receive_ack(call)
    assert not wav.exists() and not packet.exists()
    archived.unlink()  # irreversible purge in this isolated synthetic fixture
    packet.write_bytes(encrypted)
    fetch()
    assert not target.exists() and not local_wav.exists()
    receipt(packet, "abgelegt")
    packet.unlink()
    print("OK: Kienzlefon 2.6.0 JWE, WAV/JSON, verlorene Quittung, Bearbeiter, Häkchen, Abschluss, Papierkorb, Purge")

    identifier = "offline-ersatz"
    call, wav, _ = case(identifier)
    packet = remote / (identifier + ".kienzlepaket")
    target = inbox / (identifier + ".json")
    (root / "disconnected").touch()
    fetch(False)
    (root / "disconnected").unlink()
    assert not list((pull / ".vorgang-v1").glob(identifier + "*.failures"))
    stale = pull / ".vorgang-v1/.fetch-work"
    stale.mkdir()
    (stale / "alter-teilabruf").write_bytes(b"unvollstaendig")
    (root / "incomplete").touch()
    (root / "no-hash").touch()
    fetch(False)
    (root / "no-hash").unlink()
    (root / "incomplete").unlink()
    assert not list((pull / ".vorgang-v1").glob(identifier + "*.failures"))
    moved = inbox.rename(root / "unavailable")
    inbox.touch()
    fetch(False)
    inbox.unlink()
    moved.rename(inbox)
    assert not list((pull / ".vorgang-v1").glob(identifier + "*.failures"))
    (root / "incomplete").touch()
    fetch(False)
    (root / "incomplete").unlink()
    (root / "corrupt").touch()
    fetch(False)
    assert not list(producer.receipts.glob(identifier + ".*"))
    fetch()
    receipt(packet, "ersatz_json_noetig")
    assert not target.exists() and wav.exists()
    (root / "corrupt").unlink()
    fetch()
    assert not target.exists()
    producer.receive_ack(call)
    fallback = remote / (identifier + ".json.enc")
    assert fallback.is_file() and packet.is_file() and wav.is_file()
    fetch()
    receipt(fallback, "abgelegt")
    assert api()["entries"][0]["audio_failed"] and json.loads(target.read_bytes())["payload"]["_kienzlefon"]["audio"] == []
    original = target.read_bytes()
    fetch()
    receipt(packet, "ueberholt")
    assert target.read_bytes() == original and not (inbox / identifier / "audio").exists()
    producer.receive_ack(call)
    assert not any(p.exists() for p in (packet, fallback, wav))
    print("OK: Verbindungsabbruch, Inbox-Ausfall, drei Abruf-/Validierungsfehler, Ersatzlieferung mit eigenem Hash, spätes Paket")

    call, wav, _ = case("offline-wav-fehler", truncated_wav=True)
    packet = remote / (call.call_id + ".kienzlepaket")
    for attempt in range(1, 4):
        fetch(ok=attempt == 3)
    receipt(packet, "ersatz_json_noetig")
    producer.receive_ack(call)
    fetch()
    receipt(remote / (call.call_id + ".json.enc"), "abgelegt")
    producer.receive_ack(call)
    assert not wav.exists() and not (inbox / call.call_id / "audio").exists()
    print("OK: Authentisches Erzeugerpaket mit unvollständiger WAV abgewiesen und durch Fehler-JSON ersetzt")

    case("offline-json", attach=False)
    fetch()
    assert not (remote / "offline-json.json.enc").exists()
    normal = json.loads((inbox / "offline-json.json").read_bytes())
    assert normal["user_agent"] == "kienzlefon/2.6.0" and "audio_beilage" not in normal["payload"]["_kienzlefon"]
    old = {"typ": "termin", "grund": "Älteres Formular"}
    producer.encryptor.write_payload(old, "20260101_120000_123456")
    fetch()
    assert json.loads((inbox / "20260101_120000_123456.json").read_bytes())["payload"] == old
    direct = Delivery(TelepraxisEncryptor(None, inbox, timezone, demo_mode=True))
    call, wav, expected = case("offline-direkt", emitter=direct)
    assert (inbox / call.call_id / "audio" / wav.name).read_bytes() == expected and not wav.exists()
    assert any(entry["file"] == "offline-direkt.json" for entry in api()["entries"])
    print("OK: JSON-only v1 mit und ohne Vorgangs-ID, bisherige Quellbereinigung, Direktablage im Praxisbetrieb")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument("--archive", type=Path, required=True)
    parser.add_argument("--producer-repo", type=Path, required=True)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix="kienzlefon-audio-offline-") as scratch:
        run(args.archive, args.producer_repo, Path(scratch).resolve())
