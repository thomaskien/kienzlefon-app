"""PHP/API integration tests for the version 3.6 user/workplace split."""

from __future__ import annotations

import copy
import json
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php")
OMIT = object()


@unittest.skipUnless(PHP, "PHP CLI required")
class TelepraxisAppV36Tests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.site = self.root / "site"
        self.site.mkdir()
        (self.site / "inbox").mkdir()
        (self.root / "sessions").mkdir()
        (self.root / "queue").mkdir(mode=0o770)

        for name in ("telepraxis-app.php", "telepraxis-sms.php", "telepraxis-sms-queue.php"):
            shutil.copyfile(ROOT / name, self.site / name)
        app = self.site / "telepraxis-app.php"
        source = app.read_text(encoding="utf-8")
        source, replacements = re.subn(
            r"const TELEPRAXIS_ADMIN_PASSWORD = .*?;",
            "const TELEPRAXIS_ADMIN_PASSWORD = 'integration-admin';",
            source,
            count=1,
        )
        self.assertEqual(replacements, 1)
        source = source.replace(
            "const TELEPRAXIS_RECORDINGS_BASE_URL = '';",
            "const TELEPRAXIS_RECORDINGS_BASE_URL = 'recordings';",
        )
        app.write_text(source, encoding="utf-8")

        self.queue_database = self.root / "queue" / "outbox.sqlite"
        settings = {
            "default_provider": "queue",
            "sms": {"max_text_length": 160},
            "queue": {
                "database_path": str(self.queue_database),
                "delivery_provider": "fritz",
            },
        }
        (self.site / "sms-credentials.json").write_text(
            json.dumps(settings), encoding="utf-8"
        )

        self.card = self.site / "inbox" / "example.json"
        self.base_entry = {
            "received_at": "2026-10-12T09:00:00+02:00",
            "typ": "sonstiges",
            "payload": {
                "typ": "sonstiges",
                "telefon": "+491700000000",
                "anliegen": "Integrationstest",
            },
            "app": {
                "status": "in_bearbeitung",
                "dringend": True,
                "deleted": False,
                "status_updated_at": "2026-10-12T09:01:00+02:00",
                "status_updated_benutzer": "Dr. Müller",
                "status_updated_arbeitsplatz": "platz-a",
                "comments": [
                    {
                        "text": "Alter Kommentar",
                        "workplace": "AltAutor",
                        "created_at": "2026-10-12T09:02:00+02:00",
                    },
                    {
                        "text": "Neuer Kommentar",
                        "benutzer": "Dr. Müller",
                        "workplace": "platz-a",
                        "created_at": "2026-10-12T09:03:00+02:00",
                    },
                ],
            },
        }
        self.write_card(self.base_entry)

        self.driver = self.root / "request.php"
        self.driver.write_text(
            """<?php
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
chdir($argv[1]);
$_SERVER['DOCUMENT_ROOT'] = $argv[1];
$_SERVER['REQUEST_METHOD'] = isset($input['action']) ? 'POST' : 'GET';
$_POST = $input;
$_GET = isset($input['action']) ? [] : ['ajax' => 'list'];
$_REQUEST = array_merge($_GET, $_POST);
session_save_path($argv[2]);
session_id('telepraxis-v36-integration-test');
register_shutdown_function(static function () {
    fwrite(STDERR, 'HTTP:' . (http_response_code() ?: 200));
});
require './telepraxis-app.php';
""",
            encoding="utf-8",
        )
        self.csrf = self.request({})[1]["csrf"]

    def write_card(self, entry):
        self.card.write_text(
            json.dumps(entry, ensure_ascii=False, separators=(",", ":")),
            encoding="utf-8",
        )

    def saved_card(self):
        return json.loads(self.card.read_text(encoding="utf-8"))

    def request(self, fields):
        result = subprocess.run(
            [
                PHP,
                "-d",
                "display_errors=stderr",
                str(self.driver),
                str(self.site),
                str(self.root / "sessions"),
            ],
            input=json.dumps(fields, ensure_ascii=False),
            text=True,
            capture_output=True,
            timeout=5,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertRegex(result.stderr, r"^HTTP:\d{3}$")
        return int(result.stderr.removeprefix("HTTP:")), json.loads(result.stdout)

    def action(
        self,
        action,
        *,
        benutzer="Dr. Müller",
        workplace="platz-a",
        **fields,
    ):
        request = {
            "action": action,
            "csrf": self.csrf,
            "file": self.card.name,
            "workplace": workplace,
            **fields,
        }
        if benutzer is not OMIT:
            request["benutzer"] = benutzer
        return self.request(request)

    def card_view(self):
        code, response = self.request({})
        self.assertEqual(code, 200)
        return next(entry for entry in response["entries"] if entry["file"] == self.card.name)

    def login_admin(self):
        code, response = self.request(
            {
                "action": "admin_login",
                "csrf": self.csrf,
                "password": "integration-admin",
            }
        )
        self.assertEqual(code, 200, response)

    def test_recordings_follow_audio_metadata_with_medication_indices(self):
        call_id = "20261107_143025_123456"
        self.card = self.site / "inbox" / (call_id + ".json")
        entry = copy.deepcopy(self.base_entry)
        entry["payload"]["_kienzlefon"] = {
            "vorgang_id": call_id,
            "audio": [
                {"feld": "vorname", "datei": "audio/vorname.wav", "status": "transcribed", "datei_vorhanden": True},
                {"feld": "medikamente", "datei": "audio/medikament-01.wav", "index": 1, "status": "transcribed", "datei_vorhanden": True},
                {"feld": "medikamente", "datei": "audio/medikament-02.wav", "index": 2, "status": "transcribed", "datei_vorhanden": True},
                {"feld": "medikamente", "datei": "audio/medikament-02.wav", "index": 2, "status": "transcribed", "datei_vorhanden": True},
                {"feld": "grund", "datei": "audio/grund.wav", "status": "empty", "datei_vorhanden": True},
                {"feld": "telefon", "datei": "audio/telefon.wav", "status": "transcribed", "datei_vorhanden": False},
                {"feld": "grund", "datei": "audio/../call.json", "status": "transcribed", "datei_vorhanden": True},
                {"feld": "grund", "datei": "audio/../../outside.wav", "status": "transcribed", "datei_vorhanden": True},
            ],
        }
        self.write_card(entry)
        recordings = self.card_view()["recordings"]
        self.assertEqual(
            [r["label"] for r in recordings],
            ["Vorname", "Medikament 1", "Medikament 2"],
        )
        self.assertEqual(
            [r["url"] for r in recordings],
            [
                f"recordings/{call_id}/audio/vorname.wav",
                f"recordings/{call_id}/audio/medikament-01.wav",
                f"recordings/{call_id}/audio/medikament-02.wav",
            ],
        )
        entry["payload"]["_kienzlefon"]["vorgang_id"] = "20261107_143025_654321"
        self.write_card(entry)
        self.assertEqual(self.card_view()["recordings"], [])

    def test_recordings_missing_metadata_keeps_legacy_app_unchanged(self):
        self.assertEqual(self.card_view()["recordings"], [])

    def test_user_and_workplace_are_stored_and_returned_separately(self):
        user = "Dr. " + ("Ä" * 60)
        self.assertEqual(len(user), 64)
        code, response = self.action(
            "set_status",
            status="abgeschlossen",
            benutzer=user,
            workplace="behandlung-02",
        )
        self.assertEqual(code, 200, response)

        saved = self.saved_card()["app"]
        self.assertEqual(saved["status"], "abgeschlossen")
        self.assertEqual(saved["status_updated_benutzer"], user)
        self.assertEqual(saved["status_updated_arbeitsplatz"], "behandlung-02")
        self.assertEqual(response["entry"]["last_user"], user)
        self.assertEqual(response["entry"]["last_workplace"], "behandlung-02")

    def test_unicode_comment_and_legacy_comment_survive_later_changes(self):
        old_comment = copy.deepcopy(self.base_entry["app"]["comments"][0])
        code, response = self.action(
            "add_comment",
            comment_text="Rückfrage mit Umlaut",
            benutzer="Dr. Müller",
            workplace="sprechzimmer-2",
        )
        self.assertEqual(code, 200, response)
        self.assertEqual(self.action(
            "toggle_urgent", benutzer="Dr. Müller", workplace="empfang-1"
        )[0], 200)

        saved_comments = self.saved_card()["app"]["comments"]
        self.assertEqual(saved_comments[0]["text"], old_comment["text"])
        self.assertEqual(saved_comments[0]["created_at"], old_comment["created_at"])
        self.assertEqual(saved_comments[0]["workplace"], old_comment["workplace"])
        self.assertEqual(saved_comments[-1]["text"], "Rückfrage mit Umlaut")
        self.assertEqual(saved_comments[-1]["benutzer"], "Dr. Müller")
        self.assertEqual(saved_comments[-1]["workplace"], "sprechzimmer-2")

        comments = self.card_view()["comments"]
        old_view = next(comment for comment in comments if comment["text"] == old_comment["text"])
        new_view = next(comment for comment in comments if comment["text"] == "Rückfrage mit Umlaut")
        self.assertEqual(old_view["benutzer"], "AltAutor")
        self.assertEqual(old_view["created_at"], old_comment["created_at"])
        self.assertEqual(new_view["benutzer"], "Dr. Müller")
        self.assertEqual(new_view["workplace"], "sprechzimmer-2")

    def test_legacy_last_user_and_post_without_user_remain_compatible(self):
        legacy = copy.deepcopy(self.base_entry)
        legacy["app"].pop("status_updated_benutzer")
        legacy["app"]["status_updated_arbeitsplatz"] = "AltBearbeiter"
        self.write_card(legacy)
        self.assertEqual(self.card_view()["last_user"], "AltBearbeiter")

        code, response = self.action(
            "set_status",
            status="abgeschlossen",
            benutzer=OMIT,
            workplace="legacy-client",
        )
        self.assertEqual(code, 200, response)
        saved = self.saved_card()["app"]
        self.assertEqual(saved["status_updated_benutzer"], "legacy-client")
        self.assertEqual(saved["status_updated_arbeitsplatz"], "legacy-client")
        self.assertEqual(response["entry"]["last_user"], "legacy-client")
        self.assertEqual(response["entry"]["last_workplace"], "legacy-client")

    def test_empty_explicit_user_rejects_without_file_change(self):
        before = self.card.read_bytes()
        code, response = self.action(
            "set_status",
            status="abgeschlossen",
            benutzer="",
            workplace="platz-a",
        )
        self.assertEqual(code, 400, response)
        self.assertFalse(response["ok"])
        self.assertEqual(self.card.read_bytes(), before)

    def test_sms_ownership_uses_user_and_queue_exposes_user_as_author(self):
        code, response = self.action(
            "send_sms",
            benutzer="Dr. Müller",
            workplace="platz-b",
            sms_text="Bitte rufen Sie uns zurück.",
            sms_request_id="a" * 32,
        )
        self.assertEqual(code, 202, response)
        self.assertTrue(response["queued"])

        with sqlite3.connect(self.queue_database) as database:
            jobs = database.execute(
                "SELECT workplace, source_file, status FROM sms_queue"
            ).fetchall()
        self.assertEqual(jobs, [("Dr. Müller", self.card.name, "pending")])
        queue_comment = next(
            comment for comment in self.card_view()["comments"] if comment.get("sms_job_id")
        )
        self.assertEqual(queue_comment["benutzer"], "Dr. Müller")

        code, rejected = self.action(
            "send_sms",
            benutzer="Dr. Anders",
            workplace="platz-a",
            sms_text="Dieser Auftrag darf nicht entstehen.",
            sms_request_id="b" * 32,
        )
        self.assertEqual(code, 400, rejected)
        self.assertFalse(rejected["ok"])
        with sqlite3.connect(self.queue_database) as database:
            self.assertEqual(database.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0], 1)

    def test_soft_delete_and_restore_preserve_comments_status_and_attribution(self):
        before = self.card_view()
        expected_comments = [
            (comment["text"], comment["created_at"], comment["benutzer"], comment["workplace"])
            for comment in before["comments"]
        ]

        code, deleted = self.action(
            "soft_delete", benutzer="Lösch Benutzer", workplace="archiv-1"
        )
        self.assertEqual(code, 200, deleted)
        deleted_app = self.saved_card()["app"]
        self.assertTrue(deleted_app["deleted"])
        self.assertEqual(deleted_app["deleted_benutzer"], "Lösch Benutzer")
        self.assertEqual(deleted_app["deleted_arbeitsplatz"], "archiv-1")
        self.assertEqual(deleted_app["status"], before["status"])
        self.assertEqual(deleted_app["dringend"], before["urgent"])

        self.login_admin()
        code, restored = self.action(
            "restore", benutzer="Admin Nutzer", workplace="verwaltung-1"
        )
        self.assertEqual(code, 200, restored)
        self.assertFalse(restored["entry"]["deleted"])
        self.assertEqual(restored["entry"]["last_user"], "Admin Nutzer")
        self.assertEqual(restored["entry"]["last_workplace"], "verwaltung-1")
        self.assertEqual(restored["entry"]["status"], before["status"])
        self.assertEqual(restored["entry"]["urgent"], before["urgent"])
        actual_comments = [
            (comment["text"], comment["created_at"], comment["benutzer"], comment["workplace"])
            for comment in restored["entry"]["comments"]
        ]
        self.assertEqual(actual_comments, expected_comments)


if __name__ == "__main__":
    unittest.main()
