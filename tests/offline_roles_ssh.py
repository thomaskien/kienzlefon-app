"""Verify both first-install menus from a built archive, using OpenSSH on loopback."""
import argparse
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import stat
import subprocess
import tarfile
import tempfile
import time
from zoneinfo import ZoneInfo

from offline_audio_package import load_producer, publish_case
from test_app_package import CHANNEL, COMPONENT, TARGET, invoke_installer


@contextmanager
def ssh_server(root, port, user, authorized_keys):
    host_key = root / "host-key"
    subprocess.run(["ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-f", str(host_key)], check=True)
    known_hosts = root / "known_hosts"
    known_hosts.write_text(f"[127.0.0.1]:{port} " + " ".join(host_key.with_suffix(".pub").read_text().split()[:2]) + "\n")
    config = root / "sshd_config"
    config.write_text(f"""ListenAddress 127.0.0.1
Port {port}
HostKey "{host_key}"
PidFile "{root}/sshd.pid"
AuthorizedKeysFile "{authorized_keys}"
StrictModes yes
AuthenticationMethods publickey
PasswordAuthentication no
KbdInteractiveAuthentication no
UsePAM no
DisableForwarding yes
PermitTTY no
PermitUserRC no
AllowUsers {user}
Subsystem sftp internal-sftp
""")
    with (root / "sshd.log").open("w+") as log:
        daemon = subprocess.Popen([shutil.which("sshd") or "/usr/sbin/sshd", "-D", "-e", "-f", str(config)],
                                  stdin=subprocess.DEVNULL, stdout=log, stderr=log)
        try:
            for _ in range(100):
                if (root / "sshd.pid").is_file():
                    break
                if daemon.poll() is not None:
                    log.seek(0)
                    raise RuntimeError(log.read())
                time.sleep(0.05)
            else:
                raise TimeoutError("Der lokale Test-sshd ist nicht bereit.")
            yield known_hosts
        finally:
            daemon.terminate()
            daemon.wait(timeout=10)


def install(program, host, user, answers):
    (host / "units").mkdir(parents=True)
    result = invoke_installer(program, host, ["--user", user], answers, terminal=True)
    assert result.returncode == 0, result.stdout + result.stderr
    events = (host / "events").read_text()
    assert "curl" not in events and "wget" not in events
    return result.stderr


def check_app(host, identifier, wav, expected):
    inbox = host / "srv/praxis/inbox"
    assert (inbox / identifier / "audio" / wav.name).read_bytes() == expected
    record = json.loads((inbox / (identifier + ".json")).read_bytes())
    assert record["payload"]["_kienzlefon"]["vorgang_id"] == identifier
    query = """$_SERVER['REQUEST_METHOD']='GET'; $_GET=$_REQUEST=['ajax'=>'list'];
session_save_path($argv[2]); require $argv[1];"""
    response = json.loads(subprocess.check_output(["php", "-r", query, str(host / "web/telepraxis-app.php"), str(host)]))
    assert response["ok"] and [entry["file"] for entry in response["entries"]] == [identifier + ".json"]


def run(archive, producer_repo, root):
    with tarfile.open(archive) as tar:
        tar.extractall(root, filter="data")
    package = root / COMPONENT
    load_producer(producer_repo, root)
    from kienzlefon.crypto import TelepraxisEncryptor
    from kienzlefon.delivery import Delivery

    timezone = ZoneInfo("Europe/Berlin")
    direct, praxis, fon = (root / name for name in ("direct", "praxis", "fon"))
    prompts = install(package / TARGET, direct, "praxis", "2\nTestApp\nTestSMS\n")
    assert "Quellserver Hostname" not in prompts and "auf dem Quellsystem" not in prompts
    direct_home = direct / "srv/praxis"
    assert {p.name for p in direct_home.iterdir()} == {"inbox", "config"}
    assert not any((direct / "units").iterdir()) and not (direct / "bin").exists()
    delivery = Delivery(TelepraxisEncryptor(None, direct_home / "inbox", timezone, demo_mode=True))
    call, wav, expected = publish_case(root, timezone, "rollen-direkt", delivery)
    check_app(direct, call.call_id, wav, expected)
    print("OK: Menü ohne Abholer, lokale JSON und WAV aus Kienzlefon 2.6.0 in der installierten App", flush=True)

    # sshd authenticates the invoking OS account, using only the fixture's authorized_keys.
    user = pwd.getpwuid(os.getuid()).pw_name
    with socket.socket() as available:
        available.bind(("127.0.0.1", 0))
        port = available.getsockname()[1]
    install(package / TARGET, praxis, "praxis", f"1\n127.0.0.1\n{user}\n{port}\nTestApp\nTestSMS\n")
    home = praxis / "srv/praxis"
    public = (home / ".ssh/telepraxis_fetch_key.pub").read_text()
    prompts = install(package / CHANNEL, fon, user, "2\n" + public)
    assert "IONOS-Secret" not in prompts and not (fon / "web").exists() and not (fon / "srv/state").exists()
    channel = fon / "srv" / user
    authorized = channel / ".ssh/authorized_keys"
    assert authorized.read_text() == public
    for path, mode in ((channel, 0o710), (channel / ".ssh", 0o700), (authorized, 0o600),
                       (channel / "inbox", 0o2770), (channel / "inbox/.kienzlefon-ack", 0o2770)):
        assert stat.S_IMODE(path.stat().st_mode) == mode, path
    remote = channel / "inbox"
    producer = Delivery(TelepraxisEncryptor(home / "telepraxis_decrypt_public.pem", remote, timezone))
    call, wav, expected = publish_case(root, timezone, "rollen-ssh", producer)
    packet = remote / (call.call_id + ".kienzlepaket")
    digest = hashlib.sha256(packet.read_bytes()).hexdigest()
    fetch = praxis / "bin/telepraxis_fetch_and_decrypt_praxis.sh"
    with ssh_server(root, port, user, authorized) as known:
        # Supply the isolated server's spool, pinned host key and one-shot polling.
        script = fetch.read_text()
        assert f'REMOTE_DIR="/srv/telepraxis/{user}/inbox"' in script, [
            line for line in script.splitlines() if line.startswith(("REMOTE_DIR=", "REMOTE_HOST=", "REMOTE_USER=", "SSH_PORT="))]
        for name, value in {"REMOTE_DIR": remote, "RUN_FOREVER": 0,
                            "SSH_EXTRA_OPTS": f"-F /dev/null -o BatchMode=yes -o StrictHostKeyChecking=yes "
                                              f"-o IdentitiesOnly=yes -o UserKnownHostsFile={known}"}.items():
            script, count = re.subn(rf'^{name}=".*"$', lambda _: f'{name}="{value}"', script, flags=re.M)
            assert count == 1
        fetch.write_text(script)
        env = {key: value for key, value in os.environ.items()
               if key not in ("TELEPRAXIS_AUDIO_HELPER", "TELEPRAXIS_AUDIO_STATE", "SSH_AUTH_SOCK")}
        for _ in range(2):
            fetched = subprocess.run(["bash", str(fetch)], text=True, capture_output=True, timeout=45, env=env)
            assert fetched.returncode == 0, fetched.stdout + fetched.stderr
            check_app(praxis, call.call_id, wav, expected)
            receipt = producer.receipts / f"{call.call_id}.{digest}.abgelegt.ack.json"
            assert json.loads(receipt.read_bytes()) == {
                "v": 1, "vorgang_id": call.call_id, "datei": packet.name, "sha256": digest, "status": "abgelegt"}
        producer.receive_ack(call)
        assert not packet.exists() and not wav.exists() and not receipt.exists()
    print("OK: Menü mit Abholer, reiner Fon-Kanal, OpenSSH/SCP, Audio, Hash-Quittung, Wiederholung und Quellbereinigung")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--archive", type=Path, required=True)
    parser.add_argument("--producer-repo", type=Path, required=True)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix="kienzlefon-roles-") as scratch:
        run(args.archive, args.producer_repo, Path(scratch).resolve())
