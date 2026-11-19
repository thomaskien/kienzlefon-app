#!/usr/bin/env python3
"""Integrationstests fuer kienzlefon-app-update-v1.0.sh."""

from __future__ import annotations

import hashlib
import io
import json
import os
import pty
import shutil
import sqlite3
import stat
import subprocess
import tempfile
import types
import unittest
import urllib.error
import zipfile
from unittest import mock
from pathlib import Path


REPOSITORY = Path(__file__).resolve().parents[1]
UPDATER = REPOSITORY / "kienzlefon-app-update-v1.0.sh"
RECEIVE_MODULES = ("telepraxis-sms-journal.php", "telepraxis-sms-receive-store.php", "telepraxis-sms-receive.php")


def load_updater():
    module = types.ModuleType("updater_under_test")
    source = UPDATER.read_text(encoding="utf-8").split("<<'PY'\n", 1)[1].rsplit("\nPY", 1)[0]
    exec(compile(source, str(UPDATER), "exec"), module.__dict__)
    return module


OLD_APP = r"""<?php
declare(strict_types=1);
const TELEPRAXIS_APP_NAME = 'old app';
const TELEPRAXIS_APP_VERSION = '1.0';
const TELEPRAXIS_INBOX_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'in;box\\name';
const TELEPRAXIS_ADMIN_PASSWORD = 'Very;Secret\\path\'quote';
const TELEPRAXIS_POLL_INTERVAL_MS = 4000 + 250;
const TELEPRAXIS_DEFAULT_TIMEZONE = 'Europe/' . 'Berlin';
const TELEPRAXIS_WORKPLACE_MAXLEN = 32 + 32;
"""

NEW_APP = r"""<?php
declare(strict_types=1);
const TELEPRAXIS_APP_NAME = 'kienzlefon app';
const TELEPRAXIS_APP_VERSION = '3.4.2';
const TELEPRAXIS_INBOX_DIR = __DIR__ . '/inbox-new';
const TELEPRAXIS_ADMIN_PASSWORD = 'default-must-not-survive';
const TELEPRAXIS_POLL_INTERVAL_MS = 5000;
const TELEPRAXIS_DEFAULT_TIMEZONE = 'UTC';
const TELEPRAXIS_WORKPLACE_MAXLEN = 99;
const SOURCE_WEB_MARKER = 'new-web';
"""

OLD_SMS = r"""<?php
declare(strict_types=1);
const TP_SMS_CREDENTIALS_FILE = __DIR__ . '/private/sms;cred\\name.json';
const SMS_VERSION_MARKER = 'old';
"""

NEW_SMS = r"""<?php
declare(strict_types=1);
const TP_SMS_CREDENTIALS_FILE = 'default.json';
const SMS_VERSION_MARKER = 'new';
"""

OLD_CONFIG = r"""<?php
declare(strict_types=1);
const TP_SMS_CREDENTIALS_FILE = __DIR__ . '/../config/sms;credentials.json';
const TP_SMS_CONFIG_ADMIN_PASSWORD = 'Config;Secret\\value\'quoted';
const CONFIG_VERSION_MARKER = 'old';
"""

NEW_CONFIG = r"""<?php
declare(strict_types=1);
const TP_SMS_CREDENTIALS_FILE = 'default.json';
const TP_SMS_CONFIG_ADMIN_PASSWORD = 'unsafe-default';
const CONFIG_VERSION_MARKER = 'new';
"""

NEW_QUEUE = """<?php
declare(strict_types=1);
const TP_SMS_QUEUE_SCHEMA_VERSION = 7;
const QUEUE_VERSION_MARKER = 'new';
"""

NEW_WORKER = """#!/usr/bin/env php
<?php
declare(strict_types=1);
const WORKER_VERSION_MARKER = 'new';
"""

OLD_WORKER = """#!/usr/bin/env php
<?php
declare(strict_types=1);
const WORKER_VERSION_MARKER = 'old';
"""


def write(path: Path, content: str, mode: int = 0o640) -> None:
    path.write_text(content, encoding="utf-8")
    path.chmod(mode)


def digest_tree(root: Path) -> dict[str, tuple[str, int]]:
    result = {}
    for path in sorted(root.rglob("*")):
        relative = str(path.relative_to(root))
        if path.is_symlink():
            result[relative] = ("symlink:" + os.readlink(path), 0)
        elif path.is_file():
            result[relative] = (hashlib.sha256(path.read_bytes()).hexdigest(), stat.S_IMODE(path.stat().st_mode))
        elif path.is_dir():
            result[relative] = ("directory", stat.S_IMODE(path.stat().st_mode))
    return result


class AppUpdateTests(unittest.TestCase):
    maxDiff = None

    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="kienzlefon-updater-test-")
        self.root = Path(self.temporary.name)
        self.webroot = self.root / "web"
        self.source = self.root / "source"
        self.backups = self.root / "backups"
        self.webroot.mkdir()
        self.source.mkdir()
        write(self.webroot / "telepraxis-app.php", OLD_APP, 0o644)
        write(self.webroot / "telepraxis-sms.php", OLD_SMS, 0o640)
        write(self.webroot / "sms-config.php", OLD_CONFIG, 0o600)
        write(self.source / "telepraxis-app.php", NEW_APP)
        write(self.source / "telepraxis-sms.php", NEW_SMS)
        write(self.source / "sms-config.php", NEW_CONFIG)
        write(self.source / "telepraxis-sms-queue.php", NEW_QUEUE)
        write(self.source / "telepraxis-sms-worker.php", NEW_WORKER, 0o750)
        for name in RECEIVE_MODULES:
            shutil.copyfile(REPOSITORY / name, self.source / name)
        for name in ("telepraxis-telephony.php", "telepraxis-telephony.js", "telepraxis-telephony-schema.json"):
            shutil.copyfile(REPOSITORY / name, self.source / name)

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def run_update(self, *extra: str, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
        command = [
            str(UPDATER),
            "--webroot", str(self.webroot),
            "--source-dir", str(self.source),
            "--backup-dir", str(self.backups),
            *extra,
        ]
        return subprocess.run(
            command,
            cwd=REPOSITORY,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            timeout=30,
        )

    def assert_failed_without_web_mutation(self, result: subprocess.CompletedProcess[str], before: dict[str, tuple[str, int]]) -> None:
        self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(digest_tree(self.webroot), before)

    def test_success_preserves_expressions_data_and_permissions(self) -> None:
        write(self.webroot / "sms-credentials.json", '{"password":"untouched"}\n', 0o600)
        inbox = self.webroot / "inbox"
        inbox.mkdir()
        write(inbox / "case.json", '{"id":1}\n', 0o660)
        write(self.webroot / "private.key", "PRIVATE KEY DATA\n", 0o600)
        unrelated_before = {
            name: (self.webroot / name).read_bytes()
            for name in ("sms-credentials.json", "private.key")
        }
        case_before = (inbox / "case.json").read_bytes()

        result = self.run_update("--no-service", "--yes")

        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        app = (self.webroot / "telepraxis-app.php").read_text(encoding="utf-8")
        sms = (self.webroot / "telepraxis-sms.php").read_text(encoding="utf-8")
        config = (self.webroot / "sms-config.php").read_text(encoding="utf-8")
        self.assertIn("const TELEPRAXIS_APP_VERSION = '3.4.2';", app)
        self.assertIn("const SOURCE_WEB_MARKER = 'new-web';", app)
        self.assertIn("const TELEPRAXIS_INBOX_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'in;box\\\\name';", app)
        self.assertIn("const TELEPRAXIS_ADMIN_PASSWORD = 'Very;Secret\\\\path\\'quote';", app)
        self.assertIn("const TELEPRAXIS_POLL_INTERVAL_MS = 4000 + 250;", app)
        self.assertIn("const TELEPRAXIS_DEFAULT_TIMEZONE = 'Europe/' . 'Berlin';", app)
        self.assertIn("const TELEPRAXIS_WORKPLACE_MAXLEN = 32 + 32;", app)
        self.assertIn("const TP_SMS_CREDENTIALS_FILE = __DIR__ . '/private/sms;cred\\\\name.json';", sms)
        self.assertIn("const TP_SMS_CREDENTIALS_FILE = __DIR__ . '/../config/sms;credentials.json';", config)
        self.assertIn("const TP_SMS_CONFIG_ADMIN_PASSWORD = 'Config;Secret\\\\value\\'quoted';", config)
        self.assertIn("const TP_SMS_QUEUE_SCHEMA_VERSION = 7;", (self.webroot / "telepraxis-sms-queue.php").read_text())
        self.assertEqual(stat.S_IMODE((self.webroot / "telepraxis-app.php").stat().st_mode), 0o644)
        self.assertEqual(stat.S_IMODE((self.webroot / "telepraxis-sms-queue.php").stat().st_mode), 0o640)
        for name, content in unrelated_before.items():
            self.assertEqual((self.webroot / name).read_bytes(), content)
        self.assertEqual((inbox / "case.json").read_bytes(), case_before)
        combined = result.stdout + result.stderr
        self.assertNotIn("Very;Secret", combined)
        self.assertNotIn("Config;Secret", combined)

    def test_check_makes_no_target_service_or_backup_changes(self) -> None:
        before = digest_tree(self.webroot)
        result = self.run_update("--check", "--web-service", "should-not-be-called.service")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(digest_tree(self.webroot), before)
        self.assertFalse(self.backups.exists())
        self.assertIn("keine Zieldateien, Dienste oder Sicherungen", result.stdout)

    def test_missing_source_fails_without_exchange(self) -> None:
        (self.source / "telepraxis-sms-queue.php").unlink()
        before = digest_tree(self.webroot)
        result = self.run_update("--no-service", "--yes")
        self.assert_failed_without_web_mutation(result, before)
        self.assertFalse(self.backups.exists())

    def test_source_syntax_error_fails_without_exchange(self) -> None:
        write(self.source / "telepraxis-app.php", "<?php const BROKEN = ;\n")
        before = digest_tree(self.webroot)
        result = self.run_update("--no-service", "--yes")
        self.assert_failed_without_web_mutation(result, before)
        self.assertFalse(self.backups.exists())

    def test_missing_required_constant_fails_without_exchange(self) -> None:
        write(self.source / "telepraxis-app.php", NEW_APP.replace("const TELEPRAXIS_ADMIN_PASSWORD = 'default-must-not-survive';\n", ""))
        before = digest_tree(self.webroot)
        result = self.run_update("--no-service", "--yes")
        self.assert_failed_without_web_mutation(result, before)
        self.assertFalse(self.backups.exists())

    def test_source_must_support_existing_optional_settings(self) -> None:
        write(self.webroot / "telepraxis-app.php", OLD_APP + "const TELEPRAXIS_T2MED_ENABLED = true;\n")
        before = digest_tree(self.webroot)
        result = self.run_update("--check")
        self.assert_failed_without_web_mutation(result, before)
        self.assertIn("TELEPRAXIS_T2MED_ENABLED", result.stderr)

    def test_duplicate_required_constant_fails_without_exchange(self) -> None:
        write(self.source / "telepraxis-sms.php", NEW_SMS + "const TP_SMS_CREDENTIALS_FILE = 'duplicate';\n")
        before = digest_tree(self.webroot)
        result = self.run_update("--no-service", "--yes")
        self.assert_failed_without_web_mutation(result, before)
        self.assertFalse(self.backups.exists())

    def test_private_backup_is_outside_webroot_with_manifest_and_originals(self) -> None:
        result = self.run_update("--no-service", "--yes")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        backup_runs = [path for path in self.backups.iterdir() if path.is_dir()]
        self.assertEqual(len(backup_runs), 1)
        backup = backup_runs[0]
        self.assertNotIn(self.webroot.resolve(), backup.resolve().parents)
        self.assertEqual(stat.S_IMODE(self.backups.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
        manifest_path = backup / "manifest.json"
        self.assertEqual(stat.S_IMODE(manifest_path.stat().st_mode), 0o600)
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        app_entry = manifest["files"]["web/telepraxis-app.php"]
        self.assertEqual(app_entry["sha256"], hashlib.sha256(OLD_APP.encode()).hexdigest())
        self.assertEqual((backup / app_entry["backup"]).read_text(encoding="utf-8"), OLD_APP)
        self.assertFalse(manifest["files"]["web/telepraxis-sms-queue.php"]["existed"])

    def test_symlink_source_and_target_are_rejected(self) -> None:
        real_source = self.source / "real-queue.php"
        (self.source / "telepraxis-sms-queue.php").rename(real_source)
        (self.source / "telepraxis-sms-queue.php").symlink_to(real_source)
        before = digest_tree(self.webroot)
        result = self.run_update("--no-service", "--yes")
        self.assert_failed_without_web_mutation(result, before)

        (self.source / "telepraxis-sms-queue.php").unlink()
        real_source.rename(self.source / "telepraxis-sms-queue.php")
        real_sms = self.webroot / "real-sms.php"
        (self.webroot / "telepraxis-sms.php").rename(real_sms)
        (self.webroot / "telepraxis-sms.php").symlink_to(real_sms)
        before = digest_tree(self.webroot)
        result = self.run_update("--no-service", "--yes")
        self.assert_failed_without_web_mutation(result, before)

    def test_unsafe_path_overlap_is_rejected(self) -> None:
        before = digest_tree(self.webroot)
        result = subprocess.run(
            [
                str(UPDATER), "--webroot", str(self.webroot),
                "--source-dir", str(self.source),
                "--backup-dir", str(self.webroot / "backups"),
                "--no-service", "--yes",
            ],
            cwd=REPOSITORY,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            timeout=30,
        )
        self.assert_failed_without_web_mutation(result, before)

    def test_failed_service_start_rolls_back_and_hides_secrets(self) -> None:
        stub_dir = self.root / "bin"
        stub_dir.mkdir()
        state = self.root / "service-state"
        starts = self.root / "start-count"
        log = self.root / "systemctl.log"
        state.write_text("active\n", encoding="utf-8")
        starts.write_text("0\n", encoding="utf-8")
        systemctl = stub_dir / "systemctl"
        write(systemctl, """#!/usr/bin/env bash
set -eu
printf '%s\\n' "$*" >> "$SYSTEMCTL_LOG"
case "$1" in
  show)
    if [ "$2" = "--property=LoadState" ]; then
      printf 'loaded\\n'
    else
      cat "$SYSTEMCTL_STATE"
    fi
    ;;
  is-active)
    grep -q '^active$' "$SYSTEMCTL_STATE"
    ;;
  stop)
    printf 'inactive\\n' > "$SYSTEMCTL_STATE"
    ;;
  start)
    count=$(cat "$SYSTEMCTL_STARTS")
    count=$((count + 1))
    printf '%s\\n' "$count" > "$SYSTEMCTL_STARTS"
    if [ "$count" -eq 1 ]; then
      exit 1
    fi
    printf 'active\\n' > "$SYSTEMCTL_STATE"
    ;;
  *) exit 2 ;;
esac
""", 0o755)
        before = digest_tree(self.webroot)
        environment = os.environ.copy()
        environment.update({
            "PATH": str(stub_dir) + os.pathsep + environment.get("PATH", ""),
            "SYSTEMCTL_LOG": str(log),
            "SYSTEMCTL_STATE": str(state),
            "SYSTEMCTL_STARTS": str(starts),
        })

        result = self.run_update("--web-service", "php8.3-fpm.service", "--yes", env=environment)

        self.assert_failed_without_web_mutation(result, before)
        self.assertEqual(state.read_text(encoding="utf-8").strip(), "active")
        self.assertEqual(starts.read_text(encoding="utf-8").strip(), "2")
        self.assertIn("stop php8.3-fpm.service", log.read_text(encoding="utf-8"))
        combined = result.stdout + result.stderr
        self.assertNotIn("Very;Secret", combined)
        self.assertNotIn("Config;Secret", combined)
        self.assertIn("wiederhergestellt", combined)

    def test_optional_worker_and_libraries_update_together(self) -> None:
        worker = self.root / "worker"
        worker.mkdir()
        write(worker / "telepraxis-sms-worker.php", OLD_WORKER, 0o750)
        worker_sms = OLD_SMS.replace(
            "__DIR__ . '/private/sms;cred\\\\name.json'",
            "__DIR__ . '/worker/private;credentials.json'",
        )
        write(worker / "telepraxis-sms.php", worker_sms, 0o600)
        write(worker / "telepraxis-sms-queue.php", NEW_QUEUE.replace("'new'", "'old'"), 0o600)
        # Frueherer Empfangsstand mit einer fehlenden Zusatzdatei.
        for name in RECEIVE_MODULES[:2]:
            write(worker / name, "<?php // previous revision\n", 0o640)
        write(worker / "worker.json", '{"receive":{"enabled":true}}', 0o600)
        db = sqlite3.connect(worker / "outbox.sqlite")
        try:
            db.execute("CREATE TABLE messages (status TEXT)")
            db.execute("INSERT INTO messages VALUES ('pending')")
            db.commit()
        finally:
            db.close()
        before = digest_tree(worker)

        updater = load_updater()
        events = []

        def start(service):
            events.append(("start", service))
            if service == "telepraxis-sms-worker.service":
                for name in RECEIVE_MODULES:
                    self.assertEqual((worker / name).read_bytes(), (self.source / name).read_bytes())
                    self.assertFalse((self.webroot / name).exists())

        find_command = shutil.which
        with mock.patch.object(updater, "detect_web_service", return_value=("php-fpm.service", True)), \
             mock.patch.object(updater.shutil, "which", side_effect=lambda name: "/mock/systemctl" if name == "systemctl" else find_command(name)), \
             mock.patch.object(updater, "require_loaded_service"), \
             mock.patch.object(updater, "is_service_active", return_value=True), \
             mock.patch.object(updater, "manual_worker_running", return_value=False), \
             mock.patch.object(updater, "stop_service", side_effect=lambda service: events.append(("stop", service))), \
             mock.patch.object(updater, "start_service", side_effect=start):
            self.assertEqual(self.run_in_process(updater, "--worker-dir", str(worker)), 0)
        self.assertEqual(events, [("stop", "telepraxis-sms-worker.service"), ("stop", "php-fpm.service"),
                                  ("start", "php-fpm.service"), ("start", "telepraxis-sms-worker.service")])
        after = digest_tree(worker)
        for name in ("worker.json", "outbox.sqlite"):
            self.assertEqual(before[name], after[name])
        for name in RECEIVE_MODULES[:2]:
            self.assertEqual(before[name][1], after[name][1])
        self.assertEqual(after[RECEIVE_MODULES[2]][1], 0o600)
        self.assertIn("const WORKER_VERSION_MARKER = 'new';", (worker / "telepraxis-sms-worker.php").read_text())
        updated_sms = (worker / "telepraxis-sms.php").read_text(encoding="utf-8")
        self.assertIn("const SMS_VERSION_MARKER = 'new';", updated_sms)
        self.assertIn("const TP_SMS_CREDENTIALS_FILE = __DIR__ . '/worker/private;credentials.json';", updated_sms)
        self.assertIn("const QUEUE_VERSION_MARKER = 'new';", (worker / "telepraxis-sms-queue.php").read_text())
        web_sms = (self.webroot / "telepraxis-sms.php").read_text(encoding="utf-8")
        self.assertIn("const TP_SMS_CREDENTIALS_FILE = __DIR__ . '/private/sms;cred\\\\name.json';", web_sms)

    def test_missing_receive_source_prevents_check_and_install(self) -> None:
        worker = self.root / "worker"
        worker.mkdir()
        for name in ("telepraxis-sms-worker.php", "telepraxis-sms.php", "telepraxis-sms-queue.php", *RECEIVE_MODULES):
            shutil.copyfile(self.source / name, worker / name)
        (self.source / RECEIVE_MODULES[1]).unlink()
        before_web, before_worker = digest_tree(self.webroot), digest_tree(worker)
        for action in ("--check", "--yes"):
            result = self.run_update("--worker-dir", str(worker), action)
            self.assert_failed_without_web_mutation(result, before_web)
            self.assertIn(RECEIVE_MODULES[1], result.stderr)
            self.assertEqual(digest_tree(worker), before_worker)
            self.assertFalse(self.backups.exists())

    def test_explicit_worker_requires_complete_existing_installation(self) -> None:
        worker = self.root / "worker"
        worker.mkdir()
        write(worker / "telepraxis-sms-worker.php", OLD_WORKER)
        before = digest_tree(self.webroot)
        result = self.run_update("--worker-dir", str(worker), "--no-service", "--yes")
        self.assert_failed_without_web_mutation(result, before)
        self.assertFalse(self.backups.exists())

    def test_interactive_confirmation_accepts_and_declines(self) -> None:
        before = digest_tree(self.webroot)
        for answer in (b"n\n", b"j\n"):
            with self.subTest(answer=answer):
                master, slave = pty.openpty()
                try:
                    process = subprocess.Popen(
                        [str(UPDATER), "--webroot", str(self.webroot),
                         "--source-dir", str(self.source), "--backup-dir", str(self.backups), "--no-service"],
                        stdin=slave, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
                    )
                    os.write(master, answer)
                    try:
                        stdout, stderr = process.communicate(timeout=30)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.communicate()
                        self.fail("Interaktive Bestaetigung haengt.")
                    self.assertEqual(process.returncode, 0, stdout + stderr)
                    self.assertIn("Update jetzt installieren?", stdout)
                    if answer == b"n\n":
                        self.assertEqual(digest_tree(self.webroot), before)
                        self.assertFalse(self.backups.exists())
                    else:
                        self.assertIn("Update erfolgreich", stdout)
                finally:
                    os.close(master)
                    os.close(slave)

    def test_unsafe_existing_backup_directory_is_not_chmodded(self) -> None:
        self.backups.mkdir(mode=0o755)
        before = digest_tree(self.webroot)
        result = self.run_update("--no-service", "--yes")
        self.assert_failed_without_web_mutation(result, before)
        self.assertEqual(stat.S_IMODE(self.backups.stat().st_mode), 0o755)
        self.assertEqual(list(self.backups.iterdir()), [])

    def test_source_and_installed_php_are_never_executed(self) -> None:
        marker = "\nfile_put_contents(__DIR__ . '/executed', 'unwanted');\n"
        write(self.webroot / "telepraxis-app.php", OLD_APP + marker)
        write(self.source / "telepraxis-app.php", NEW_APP + marker)
        result = self.run_update("--no-service", "--yes")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse(list(self.root.rglob("executed")))

    def test_actual_repository_source_passes_check_and_update(self) -> None:
        for name in ("telepraxis-app.php", "telepraxis-sms.php", "sms-config.php", "telepraxis-sms-queue.php"):
            shutil.copyfile(REPOSITORY / name, self.source / name)
        old_app = self.webroot / "telepraxis-app.php"
        with old_app.open("a", encoding="utf-8") as handle:
            handle.write("const TELEPRAXIS_T2MED_ENABLED = true;\n"
                         "const TELEPRAXIS_T2MED_BASE_URL = 'http://middleware.example:8765';\n"
                         "const TELEPRAXIS_RECORDINGS_BASE_URL = 'recordings';\n"
                         "const TELEPRAXIS_AUDIO_RETENTION_DAYS = 14;\n")
        before = digest_tree(self.webroot)
        checked = self.run_update("--check")
        self.assertEqual(checked.returncode, 0, checked.stdout + checked.stderr)
        self.assertEqual(digest_tree(self.webroot), before)
        result = self.run_update("--no-service", "--yes")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        content = (self.webroot / "telepraxis-app.php").read_text()
        self.assertIn("const TELEPRAXIS_APP_NAME = 'kienzlefon app';", content)
        self.assertIn("const TELEPRAXIS_APP_VERSION = '3.7';", content)
        self.assertIn("const TELEPRAXIS_POLL_INTERVAL_MS = 4000 + 250;", content)
        self.assertIn("const TELEPRAXIS_T2MED_ENABLED = true;", content)
        self.assertIn("const TELEPRAXIS_T2MED_BASE_URL = 'http://middleware.example:8765';", content)
        self.assertIn("const TELEPRAXIS_RECORDINGS_BASE_URL = 'recordings';", content)
        self.assertIn("const TELEPRAXIS_AUDIO_RETENTION_DAYS = 14;", content)

    def run_in_process(self, updater, *extra):
        args = [str(UPDATER), "--webroot", str(self.webroot), "--source-dir", str(self.source),
                "--backup-dir", str(self.backups), "--yes", *extra]
        with mock.patch.object(updater.sys, "argv", args), \
             mock.patch.object(updater.signal, "signal"), \
             mock.patch("sys.stdout", new_callable=io.StringIO):
            return updater.main()

    def test_atomic_copy_keeps_public_payload_in_private_directory(self) -> None:
        updater = load_updater()
        replace = updater.os.replace
        observed = []

        def inspect_then_replace(source, destination):
            parent = Path(source).parent
            self.assertNotEqual(parent, self.webroot)
            self.assertEqual(parent.parent, self.webroot)
            self.assertEqual(stat.S_IMODE(parent.stat().st_mode), 0o700)
            self.assertEqual(parent.stat().st_uid, os.geteuid())
            observed.append(parent)
            replace(source, destination)

        target = self.webroot / "telepraxis-app.php"
        with mock.patch.object(updater.os, "replace", side_effect=inspect_then_replace):
            updater.atomic_copy(self.source / target.name, target, target.stat())
        self.assertTrue(observed)
        self.assertTrue(all(not parent.exists() for parent in observed))

    def test_mid_install_failure_and_signal_restore_originals(self) -> None:
        for interrupted in (False, True):
            with self.subTest(interrupted=interrupted):
                updater = load_updater()
                before = digest_tree(self.webroot)
                copy = updater.atomic_copy
                count = 0

                def fail_second_copy(*args):
                    nonlocal count
                    count += 1
                    if count == 2:
                        if interrupted:
                            updater.interrupt_update(None, None)
                        raise OSError("simulierter Schreibfehler")
                    return copy(*args)

                with mock.patch.object(updater, "atomic_copy", side_effect=fail_second_copy):
                    with self.assertRaises(updater.UpdateError):
                        self.run_in_process(updater, "--no-service")
                self.assertGreater(count, 2)
                self.assertEqual(digest_tree(self.webroot), before)

    def test_failed_stop_prevents_rollback_under_running_service(self) -> None:
        updater = load_updater()
        with mock.patch.object(updater, "detect_web_service", return_value=("php8.2-fpm.service", True)), \
             mock.patch.object(updater, "stop_service", side_effect=[None, updater.UpdateError("stop failed")]), \
             mock.patch.object(updater, "start_service", side_effect=updater.UpdateError("start failed")), \
             mock.patch.object(updater, "restore_backup", wraps=updater.restore_backup) as restore:
            with self.assertRaisesRegex(updater.UpdateError, "Rollback blockiert"):
                self.run_in_process(updater)
            restore.assert_not_called()
        self.assertIn("new-web", (self.webroot / "telepraxis-app.php").read_text())
        self.assertTrue(list(self.backups.glob("*/manifest.json")))

    def test_already_stopped_service_is_not_started(self) -> None:
        updater = load_updater()
        with mock.patch.object(updater, "detect_web_service", return_value=("php8.2-fpm.service", False)), \
             mock.patch.object(updater, "stop_service") as stop, \
             mock.patch.object(updater, "start_service") as start:
            self.assertEqual(self.run_in_process(updater), 0)
            stop.assert_not_called()
            start.assert_not_called()

    def test_unknown_and_transitional_service_states_are_rejected(self) -> None:
        updater = load_updater()
        with mock.patch.object(updater, "command_systemctl",
                               return_value=subprocess.CompletedProcess([], 0, "not-found\n")):
            with self.assertRaises(updater.UpdateError):
                updater.require_loaded_service("php8.2-fpm.service")
        for state in ("activating", "deactivating", "", "unknown"):
            with mock.patch.object(updater, "command_systemctl",
                                   return_value=subprocess.CompletedProcess([], 0, state)):
                with self.assertRaises(updater.UpdateError):
                    updater.is_service_active("php8.2-fpm.service")

    def test_target_change_during_preparation_is_preserved(self) -> None:
        updater = load_updater()
        read = updater.read_local_source
        modified = OLD_APP + "\n// concurrently changed\n"

        def change_target(*args):
            write(self.webroot / "telepraxis-app.php", modified)
            return read(*args)

        with mock.patch.object(updater, "read_local_source", side_effect=change_target):
            with self.assertRaisesRegex(updater.UpdateError, "seit der Vorbereitung geaendert"):
                self.run_in_process(updater, "--no-service")
        self.assertEqual((self.webroot / "telepraxis-app.php").read_text(), modified)
        self.assertFalse(self.backups.exists())

    def test_directory_lock_prevents_parallel_update(self) -> None:
        updater = load_updater()
        before = digest_tree(self.webroot)
        with updater.acquire_lock(self.webroot):
            result = self.run_update("--no-service", "--yes")
        self.assert_failed_without_web_mutation(result, before)
        self.assertIn("bereits ein Updater", result.stderr)

    def test_online_archive_download_and_validation_without_network(self) -> None:
        updater = load_updater()
        needed = set(updater.WEB_FILES)
        for variant in ("valid", "missing", "symlink", "bad-zip", "http"):
            with self.subTest(variant=variant):
                data = io.BytesIO()
                with zipfile.ZipFile(data, "w") as archive:
                    for name in sorted(needed):
                        if variant == "missing" and name == "sms-config.php":
                            continue
                        info = zipfile.ZipInfo("repository-main/" + name)
                        info.external_attr = (stat.S_IFLNK | 0o777 if variant == "symlink" else stat.S_IFREG | 0o644) << 16
                        archive.writestr(info, (self.source / name).read_bytes())
                response = io.BytesIO(b"bad zip" if variant == "bad-zip" else data.getvalue())
                response.geturl = lambda: ("http" if variant == "http" else "https") + "://codeload.github.com/archive"
                with mock.patch.object(updater.urllib.request, "urlopen", return_value=response) as download:
                    if variant == "valid":
                        files = updater.download_source("release/v1", needed)
                        self.assertEqual(set(files), needed)
                        self.assertEqual(files["telepraxis-app.php"], NEW_APP.encode())
                        self.assertIn("/zip/release%2Fv1", download.call_args.args[0].full_url)
                    else:
                        with self.assertRaises(updater.UpdateError):
                            updater.download_source("main", needed)
                    download.assert_called_once()
        with mock.patch.object(updater.urllib.request, "urlopen", side_effect=urllib.error.URLError("offline")):
            with self.assertRaises(updater.UpdateError):
                updater.download_source("main", needed)


if __name__ == "__main__":
    unittest.main(verbosity=2)
