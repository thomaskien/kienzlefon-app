"""Prüfe die Paketschnittstellen mit lokalen Schlüssel-, Web- und Kanalverzeichnissen."""
import os
import pty
import shutil
import termios
from pathlib import Path
import stat
import subprocess
import tarfile
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
COMPONENT = "kienzlefon-app"
TARGET = "zielserver-vorbereiten-v1.8.sh"
CHANNEL = "quellserver-benutzer-erzeugen-v1.8.sh"
UPDATER = "kienzlefon-app-update-v1.0.sh"
WEB = {
    "telepraxis-app.php", "sms-config.php", "telepraxis-sms.php", "telepraxis-sms-queue.php",
    "telepraxis-telephony.php", "telepraxis-telephony.js", "telepraxis-telephony-schema.json",
}
PAYLOAD = WEB | {
    TARGET, CHANNEL, UPDATER, "quellserver-vorbereiten-nginx-v1.2.sh", "telepraxis_fetch_and_decrypt.sh",
    "telepraxis-audio-delivery.py", "telepraxis-receive.php", "kontakt.php", "telepraxis-sms-worker.php", "telepraxis-sms-inspect.php",
    "telepraxis-sms-journal.php", "telepraxis-sms-receive.php", "telepraxis-sms-receive-store.php",
    "config/sms-queue-web.example.json", "config/sms-queue-worker.example.json",
    "config/telephony-config.example.php", "config/telepraxis-sms-worker.service",
    "scripts/pack.sh", "docs/INSTALLER.md", "docs/APP-UPDATE.md",
}

# Run the normal installer main with OS accounts, packages and services simulated.
# Both the project files and all destination paths stay inside the fixture.
HARNESS = r'''
source "$1"
shift
BASE_ROOT="$SANDBOX/srv"
WEBROOT_DIR="$SANDBOX/web"
BIN_DIR="$SANDBOX/bin"
UNIT_DIR="$SANDBOX/units"
WEB_ROOT="$SANDBOX/web"
STATE_ROOT="$BASE_ROOT/state"
event() { echo "$*" >> "$SANDBOX/events"; }
require_root() { :; }
ensure_group() { event group "$@"; }
ensure_user() { event user "$@"; }
install_php_extension_packages() { event packages "$@"; }
systemctl() {
  event systemctl "$@"
  if [[ -x "$SANDBOX/systemctl" ]]; then "$SANDBOX/systemctl" "$@"; fi
}
usermod() { event usermod "$@"; }
chown() { :; }
setfacl() { :; }
runuser() { [[ $1 == -u && $3 == -- ]]; shift 3; "$@"; }
flock() { [[ $1 == --exclusive && -d $2 ]]; shift 2; "$@"; }
install() {
  local args=()
  while (( $# )); do
    case "$1" in
      -o|-g) shift 2 ;;
      *) args+=("$1"); shift ;;
    esac
  done
  command install "${args[@]}"
}
curl() {
  event curl
  [[ -n "${TEST_UPSTREAM:-}" && $1 == -fsSL && $2 == "$UPSTREAM/"* && $3 == -o ]] || return 21
  cp "$TEST_UPSTREAM/${2##*/}" "$4"
}
wget() { event wget; return 21; }
main "$@"
'''


def invoke_installer(program, host, args, answers, *, terminal=False, upstream=""):
    command = ["bash", "-c", HARNESS, "setup-test", str(program), *map(str, args)]
    env = {**os.environ, "SANDBOX": str(host), "TEST_UPSTREAM": str(upstream)}
    if not terminal:
        return subprocess.run(command, cwd=host, env=env, input=answers, text=True,
                              capture_output=True, timeout=45)
    master, slave = pty.openpty()
    try:
        settings = termios.tcgetattr(slave)
        settings[3] &= ~termios.ECHO
        termios.tcsetattr(slave, termios.TCSANOW, settings)
        with subprocess.Popen(command, cwd=host, env=env, stdin=slave, text=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE) as process:
            try:
                os.write(master, answers.encode())
                stdout, stderr = process.communicate(timeout=45)
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait()
            return subprocess.CompletedProcess(command, process.returncode, stdout, stderr)
    finally:
        os.close(slave)
        os.close(master)


class AppPackageTests(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name).resolve()
        self.archive = self.root / "kienzlefon-app.tar.gz"
        self.source = self.root / COMPONENT
        self.host = self.root / "host"
        (self.host / "units").mkdir(parents=True)
        self.pack(ROOT)
        with tarfile.open(self.archive) as archive:
            self.assertEqual(set(archive.getnames()), {COMPONENT} | {f"{COMPONENT}/{name}" for name in PAYLOAD})
            for info in archive.getmembers():
                if info.name == COMPONENT:
                    self.assertTrue(info.isdir())
                else:
                    self.assertTrue(info.isfile())
            archive.extractall(self.root)

    def pack(self, source, success=True):
        result = subprocess.run(
            ["/bin/bash", str(source / "scripts/pack.sh"), "--output", str(self.archive)],
            cwd=self.root, text=True, capture_output=True, timeout=30,
        )
        self.assertEqual(result.returncode == 0, success, result.stderr)
        return result

    def setup_host(self, script, *args, stdin="", success=True, upstream="", terminal=False):
        result = invoke_installer(self.source / script, self.host, args, stdin, terminal=terminal, upstream=upstream)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result

    def test_archive_uses_only_selected_working_files_and_publishes_atomically(self):
        js = "telepraxis-telephony.js"
        self.assertEqual((self.source / js).read_bytes(), (ROOT / js).read_bytes())
        (self.source / js).write_text("// unpublished working copy\n")
        for name in ("inbox/case.json", "private.pem", "sms-credentials.json", "queue.sqlite",
                     "alte-versionen/app.php", "config/telephony-config.php", ".env"):
            path = self.source / name
            path.parent.mkdir(exist_ok=True)
            path.write_text("synthetic data")
        self.pack(self.source)
        with tarfile.open(self.archive) as archive:
            self.assertEqual(set(archive.getnames()), {COMPONENT} | {f"{COMPONENT}/{name}" for name in PAYLOAD})
            self.assertEqual(archive.extractfile(f"{COMPONENT}/{js}").read(), (self.source / js).read_bytes())
        previous = self.archive.read_bytes()
        (self.source / js).unlink()
        self.assertIn(js, self.pack(self.source, success=False).stderr)
        (self.source / js).symlink_to(self.source / "private.pem")
        self.assertIn(js, self.pack(self.source, success=False).stderr)
        self.assertEqual(self.archive.read_bytes(), previous)

    def test_both_installers_reject_incomplete_or_linked_sources_before_changes(self):
        for script, name in ((TARGET, "telepraxis_fetch_and_decrypt.sh"), (CHANNEL, "kontakt.php")):
            with self.subTest(script=script):
                file = self.source / name
                file.unlink()
                for linked in (False, True):
                    if linked:
                        file.symlink_to(ROOT / name)
                    result = self.setup_host(script, success=False)
                    self.assertIn(name, result.stderr)
                    self.assertEqual(list(self.host.iterdir()), [self.host / "units"])

    def test_key_preparation_reuse_then_install_and_check_from_extracted_package(self):
        args = ("--user", "kanal1")
        self.setup_host(TARGET, "--prepare-keys", *args)
        base = self.host / "srv/kanal1"
        rsa = base / "telepraxis_decrypt_private.pem"
        rsa_pub = base / "telepraxis_decrypt_public.pem"
        ssh = base / ".ssh/telepraxis_fetch_key"
        ssh_pub = ssh.with_suffix(".pub")
        saved = {p: p.read_bytes() for p in (rsa, rsa_pub, ssh, ssh_pub)}
        self.assertTrue(saved[rsa_pub].startswith(b"-----BEGIN PUBLIC KEY-----"))
        self.assertTrue(saved[ssh_pub].startswith(b"ssh-ed25519 "))
        self.assertFalse((base / "inbox").exists())
        self.assertFalse((self.host / "web").exists())
        self.assertNotIn("packages", (self.host / "events").read_text())
        for path in (rsa, ssh):
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)

        rsa_pub.unlink()
        ssh_pub.unlink()
        self.setup_host(TARGET, "--prepare-keys", *args)
        self.assertEqual({p: p.read_bytes() for p in saved}, saved)
        self.setup_host(TARGET, "--source-dir", self.source, *args,
                        stdin="source.example\nabruf1\n2222\nTestApp\nTestSMS\n")
        self.assertEqual({p: p.read_bytes() for p in saved}, saved)
        web = self.host / "web"
        self.assertEqual({p.name for p in web.iterdir()}, WEB)
        self.assertIn(str(base / "inbox"), (web / "telepraxis-app.php").read_text())
        for name in ("telepraxis-telephony.js", "telepraxis-telephony.php", "telepraxis-telephony-schema.json"):
            self.assertEqual((web / name).read_bytes(), (self.source / name).read_bytes())
        fetch = (self.host / "bin/telepraxis_fetch_and_decrypt_kanal1.sh").read_text()
        helper = self.host / "bin/telepraxis-audio-delivery.py"
        self.assertEqual(helper.read_bytes(), (self.source / helper.name).read_bytes())
        for setting in ('REMOTE_HOST="source.example"', 'REMOTE_USER="abruf1"', 'SSH_PORT="2222"',
                        f'PRIVATE_KEY_FILE="{rsa}"', f'SSH_IDENTITY_FILE="{ssh}"'):
            self.assertIn(setting, fetch)
        unit = (self.host / "units/telepraxis-fetch-and-decrypt-kanal1.service").read_text()
        self.assertIn("User=kanal1\n", unit)
        before = {p: p.read_bytes() for p in web.iterdir()}
        backup = self.root / "backups"
        checked = subprocess.run(
            ["bash", str(self.source / UPDATER), "--webroot", str(web),
             "--backup-dir", str(backup), "--check"], text=True, capture_output=True, timeout=30,
        )
        self.assertEqual(checked.returncode, 0, checked.stderr)
        self.assertEqual({p: p.read_bytes() for p in web.iterdir()}, before)
        self.assertFalse(backup.exists())

        # Upgrade two old channel scripts in place. No service or system paths are used here.
        bin_dir = helper.parent
        helper.unlink()
        old_fetch = fetch.split("list_remote_files()")[0] + "\n# previous fetcher\n"
        old_fetch = "\n".join(line for line in old_fetch.splitlines() if not line.startswith("AUDIO_")) + "\n"
        first = bin_dir / "telepraxis_fetch_and_decrypt_kanal1.sh"
        second = bin_dir / "telepraxis_fetch_and_decrypt_kanal2.sh"
        first.write_text(old_fetch)
        second.write_text(old_fetch.replace("abruf1", "anderer-kanal").replace('SSH_PORT="2222"', 'SSH_PORT="2200"'))
        previous_second = second.read_bytes()
        app = web / "telepraxis-app.php"
        app.write_text(app.read_text().replace("TELEPRAXIS_AUDIO_RETENTION_DAYS = 0", "TELEPRAXIS_AUDIO_RETENTION_DAYS = 21"))
        update = ["bash", str(self.source / UPDATER), "--webroot", str(web), "--source-dir", str(self.source),
                  "--backup-dir", str(backup), "--fetch-dir", str(bin_dir), "--no-service"]
        for mode in ("--check", "--yes"):
            result = subprocess.run(update + [mode], text=True, capture_output=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stderr)
            if mode == "--check":
                self.assertEqual(second.read_bytes(), previous_second)
                self.assertFalse(helper.exists())
        self.assertEqual(helper.read_bytes(), (self.source / helper.name).read_bytes())
        for script in (first, second):
            self.assertIn("process_one_remote_file()", script.read_text())
            self.assertIn(f'PRIVATE_KEY_FILE="{rsa}"', script.read_text())
            self.assertIn(f'LOCAL_OUT_DIR="{base / "inbox"}"', script.read_text())
        self.assertIn('REMOTE_USER="anderer-kanal"', second.read_text())
        self.assertIn('SSH_PORT="2200"', second.read_text())
        self.assertEqual(next(backup.glob("*/fetch/" + second.name)).read_bytes(), previous_second)
        self.assertIn("TELEPRAXIS_AUDIO_RETENTION_DAYS = 21", app.read_text())
        self.assertEqual({p: p.read_bytes() for p in saved}, saved)

        self.setup_host(CHANNEL, terminal=True,
                        stdin="\nabruf1\n" + ssh_pub.read_text() + rsa_pub.read_text() + "M\nTestPSK\n")
        receiver = (web / "telepraxis-receive-abruf1.php").read_text()
        contact = (web / "kontakt-abruf1.php").read_text()
        self.assertIn(rsa_pub.read_text(), receiver)
        self.assertIn("TestPSK", receiver)
        for template in (receiver, contact):
            self.assertIn(str(self.host / "srv/state/abruf1/otp.sqlite"), template)
        self.assertIn('"/telepraxis-receive-abruf1.php"', contact)
        self.assertEqual((self.host / "srv/abruf1/.ssh/authorized_keys").read_bytes(), saved[ssh_pub])
        self.assertNotIn("curl", (self.host / "events").read_text())
        self.assertNotIn("wget", (self.host / "events").read_text())

        rsa.unlink()
        self.setup_host(TARGET, "--prepare-keys", *args, success=False)
        self.assertEqual(rsa_pub.read_bytes(), saved[rsa_pub])
        self.assertFalse(rsa.exists())

    def test_praxis_menu_local_only(self):
        (self.source / "telepraxis-audio-delivery.py").unlink()
        result = self.setup_host(TARGET, stdin="9\n2\npraxis\nTestApp\nTestSMS\n", terminal=True)
        base = self.host / "srv/praxis"
        self.assertIn("Bitte 1 oder 2", result.stderr)
        self.assertNotIn("Quellserver Hostname", result.stderr)
        self.assertNotIn("SSH-Benutzer auf dem Quellsystem", result.stderr)
        self.assertNotIn("SSH-Port", result.stderr)
        self.assertEqual({p.name for p in base.iterdir()}, {"inbox", "config"})
        self.assertEqual({p.name for p in (self.host / "web").iterdir()}, WEB)
        self.assertIn(str(base / "inbox"), (self.host / "web/telepraxis-app.php").read_text())
        self.assertFalse((self.host / "bin").exists())
        self.assertEqual(list((self.host / "units").iterdir()), [])
        events = (self.host / "events").read_text()
        self.assertIn("packages 0", events)
        for action in ("daemon-reload", "enable --now", "curl", "wget"):
            self.assertNotIn(action, events)

    def test_no_fetch_option_skips_remote_setup_and_keeps_web_dependencies(self):
        result = self.setup_host(TARGET, "--source-dir", self.source, "--user", "praxis", "--no-fetch",
                                 stdin="TestApp\nTestSMS\n")
        self.assertNotIn("Auswahl", result.stdout + result.stderr)
        self.assertEqual(list((self.host / "units").iterdir()), [])
        self.assertFalse((self.host / "srv/praxis/.ssh").exists())
        # Simulate only apt itself, exercising the installer's actual package selections.
        probe = 'source "$1"; apt-get() { echo "$*"; }; install_php_extension_packages "$2"'
        for mode in ("0", "1"):
            with self.subTest(mode=mode):
                out = subprocess.check_output(["bash", "-c", probe, "probe", str(self.source / TARGET), mode], text=True)
                packages = out.splitlines()[-1].split()[2:]
                self.assertEqual(set(packages), {"php-curl", "php-xml", "php-sqlite3"} |
                                 ({"jq", "openssh-client", "openssl", "python3-cryptography"} if mode == "1" else set()))

    def test_web_restarts_after_group_assignment_and_propagates_failures(self):
        # A long unfiltered inventory used to trigger SIGPIPE on the early Apache match.
        inventory = self.host / "services"
        lines = ["apache2.service loaded active running Apache"]
        lines.extend(f"test-{i}.service loaded active running Test" for i in range(10000))
        lines.extend(["nginx.service loaded inactive dead nginx",
                      "php8.3-fpm.service loaded active running PHP",
                      "php8.4-fpm.service loaded reloading reload PHP"])
        inventory.write_text("\n".join(lines) + "\n")
        systemctl = self.host / "systemctl"
        systemctl.write_text(r"""#!/bin/bash
set -eu
case "$1" in
  list-unit-files) cat "$SANDBOX/services" ;;
  list-units)
    [[ ! -e "$SANDBOX/fail-list" ]] || exit 1
    shift
    while [[ "$1" == --* ]]; do
      case "$1" in --state=*) states="${1#--state=}" ;; esac
      shift
    done
    while read -r unit load state rest; do
      [[ ",$states," == *",$state,"* ]] || continue
      for pattern in "$@"; do
        if [[ "$unit" == $pattern ]]; then
          printf '%s %s %s %s\n' "$unit" "$load" "$state" "$rest"
          break
        fi
      done
    done < "$SANDBOX/services"
    ;;
  try-restart) [[ ! -e "$SANDBOX/fail-$2" ]] ;;
  *) exit 2 ;;
esac
""")
        systemctl.chmod(0o755)
        args = (TARGET, "--user", "praxis", "--no-fetch")
        events = self.host / "events"
        for failure in ("", "fail-apache2.service", "fail-list"):
            with self.subTest(failure=failure):
                events.write_text("")
                if failure:
                    (self.host / failure).touch()
                result = self.setup_host(*args, stdin="TestApp\nTestSMS\n", success=not failure)
                log = events.read_text().splitlines()
                restarts = [entry.split()[-1] for entry in log if entry.startswith("systemctl try-restart ")]
                if failure == "fail-list":
                    self.assertIn("konnten nicht ermittelt werden", result.stderr)
                    self.assertEqual(restarts, [])
                else:
                    self.assertEqual(restarts, ["apache2.service", "php8.3-fpm.service", "php8.4-fpm.service"])
                    self.assertLess(log.index("usermod -a -G tp-praxis praxis"),
                                    log.index("systemctl try-restart apache2.service"))
                if failure:
                    if failure == "fail-apache2.service":
                        self.assertIn("Neustart von apache2.service fehlgeschlagen", result.stderr)
                    self.assertNotIn("Fertig.", result.stdout)
                    (self.host / failure).unlink()

    def test_fon_channel_menu_without_forms(self):
        (self.source / "kontakt.php").unlink()
        pub = "ssh-ed25519 AAAAtest Synthetic"
        result = self.setup_host(CHANNEL, stdin=f"2\nfon\n{pub}\n", terminal=True)
        self.assertNotIn("IONOS-Secret", result.stdout + result.stderr)
        self.assertNotIn("PEM-Block", result.stdout + result.stderr)
        self.setup_host(CHANNEL, "--ssh-only", "--user", "fon", stdin=pub + "\n")
        home = self.host / "srv/fon"
        self.assertEqual((home / ".ssh/authorized_keys").read_text(), pub + "\n")
        self.assertTrue((home / "inbox/.kienzlefon-ack").is_dir())
        self.assertFalse((self.host / "web").exists())
        self.assertFalse((self.host / "srv/state").exists())
        events = (self.host / "events").read_text()
        for action in ("curl", "wget", "systemctl", "www-data"):
            self.assertNotIn(action, events)

    def test_standalone_scripts_keep_online_template_source(self):
        standalone = self.root / "standalone"
        standalone.mkdir()
        for script in (TARGET, CHANNEL):
            shutil.copy(self.source / script, standalone / script)
        self.setup_host(standalone / TARGET, upstream=self.source, stdin="ziel1\nsource.example\nabruf1\n22\nTestApp\nTestSMS\n")
        base = self.host / "srv/ziel1"
        public_input = ((base / ".ssh/telepraxis_fetch_key.pub").read_text()
                        + (base / "telepraxis_decrypt_public.pem").read_text())
        self.setup_host(standalone / CHANNEL, upstream=self.source, stdin="abruf1\n" + public_input + "A\n")
        self.assertEqual((self.host / "events").read_text().splitlines().count("curl"), len(WEB) + 4)
        self.assertTrue((self.host / "web/telepraxis-app.php").is_file())
        self.assertTrue((self.host / "web/telepraxis-receive-abruf1.php").is_file())
        self.assertTrue((self.host / "web/kontakt-abruf1.php").is_file())


if __name__ == "__main__":
    unittest.main(verbosity=2)
