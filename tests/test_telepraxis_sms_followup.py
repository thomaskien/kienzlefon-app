#!/usr/bin/env python3
"""Integration tests for SMS follow-up assignment (no real transports)."""

from __future__ import annotations

import fcntl
import json
import sqlite3
import subprocess
import tempfile
import textwrap
import time
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
STORE = ROOT / "telepraxis-sms-receive-store.php"


class SmsFollowupTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="tp-sms-followup-")
        self.base = Path(self.temp.name)
        self.inbox = self.base / "inbox"
        self.inbox.mkdir(mode=0o770)
        self.db = self.base / "state" / "queue.sqlite"
        self.db.parent.mkdir(mode=0o770)
        self.device = "ab" * 32

    def tearDown(self) -> None:
        self.temp.cleanup()

    def prelude(self) -> str:
        return f"""<?php
        declare(strict_types=1);
        define('TELEPRAXIS_APP', true);
        require_once {json.dumps(str(STORE))};
        $settings = [
            'queue' => [
                'database_path' => {json.dumps(str(self.db))},
                'delivery_provider' => 'fritz',
                'busy_timeout_ms' => 3000,
            ],
            'sms' => ['max_text_length' => 612],
            'auto_reply' => ['messages' => ['Antwort eins', 'Antwort zwei']],
            'fritzbox' => ['host' => 'fritz.test'],
            'receive' => [
                'enabled' => true,
                'output_mode' => 'target-local',
                'inbox_path' => {json.dumps(str(self.inbox))},
            ],
        ];
        $device = {json.dumps(self.device)};
        """

    def php(self, body: str, *, check: bool = True) -> subprocess.CompletedProcess[str]:
        result = subprocess.run(
            ["php"],
            input=textwrap.dedent(self.prelude() + body),
            text=True,
            cwd=ROOT,
            capture_output=True,
            timeout=20,
        )
        if check and result.returncode != 0:
            self.fail(
                f"PHP failed ({result.returncode})\nstdout:\n{result.stdout}\nstderr:\n{result.stderr}"
            )
        return result

    def entry(
        self,
        uid: str,
        *,
        phone: str = "+491701234567",
        text: str = "Nachricht",
        date: str = "2026-09-29T10:00:00+02:00",
        answerable: bool = True,
    ) -> str:
        value = {
            "uid": uid,
            "kind": "received",
            "status": 0,
            "date": date,
            "phone": phone,
            "text": text,
            "answerable": answerable,
            "raw": {"uid": uid, "sender": phone, "text": text},
            "problem": None,
        }
        return f"json_decode({json.dumps(json.dumps(value, ensure_ascii=False))}, true, 512, JSON_THROW_ON_ERROR)"

    def write_document(self, name: str, value: dict) -> Path:
        path = self.inbox / name
        path.write_text(json.dumps(value, ensure_ascii=False), encoding="utf-8")
        path.chmod(0o660)
        return path

    def rows(self, sql: str, parameters: tuple[object, ...] = ()) -> list[sqlite3.Row]:
        connection = sqlite3.connect(self.db)
        connection.row_factory = sqlite3.Row
        try:
            return connection.execute(sql, parameters).fetchall()
        finally:
            connection.close()

    def test_latest_open_case_gets_chronological_followups_and_one_reply_batch(self) -> None:
        phone = "+49 (0) 170 / 123-4567"
        self.write_document(
            "web-old.json",
            {
                "received_at": "2026-09-29T07:00:00Z",
                "payload": {"telefon": "0170 1234567"},
                "app": {"status": "neu", "deleted": False, "comments": []},
            },
        )
        target = self.write_document(
            "web-new.json",
            {
                "received_at": "2026-09-29T09:00:00Z",
                "payload": {"id": "0049-170-1234567", "name": "Erhalten"},
                "app": {
                    "status": "in_bearbeitung",
                    "deleted": False,
                    "dringend": True,
                    "status_updated_arbeitsplatz": "Platz 4",
                    "comments": [
                        {"text": "vorher", "created_at": "2026-09-29T08:00:00Z", "workplace": "P4"}
                    ],
                },
                "foreign": {"nested": [1, 2, 3]},
            },
        )
        self.write_document(
            "closed.json",
            {
                "received_at": "2026-09-29T12:00:00Z",
                "payload": {"telefon": "+491701234567"},
                "app": {"status": "abgeschlossen", "deleted": False},
            },
        )
        self.write_document(
            "deleted.json",
            {
                "received_at": "2026-09-29T13:00:00Z",
                "payload": {"anrufer_id": "+491701234567"},
                "app": {"status": "neu", "deleted": True},
            },
        )
        first_text = "  Erste Folge 🌍\nmit Leerzeile\n "
        second_text = "Zweite Folge äöü"
        first = self.entry(
            "follow-1", phone=phone, text=first_text, date="2026-09-29T10:30:00+02:00"
        )
        second = self.entry(
            "follow-2", phone="0170-1234567", text=second_text, date="2026-09-29T10:15:00+01:00"
        )
        result = self.php(
            f"""
            $first = {first}; $second = {second};
            tp_sms_receive_store($settings, $device, [$second, $first]);
            $out = [];
            for ($i = 0; $i < 2; $i++) {{
                $out[] = [tp_sms_receive_export_one($settings), tp_sms_receive_reply_one($settings)];
            }}
            echo json_encode($out, JSON_THROW_ON_ERROR);
            """
        )
        exports = json.loads(result.stdout)
        self.assertEqual([item[0]["state"] for item in exports], ["exported", "exported"])
        self.assertEqual([item[1]["reply_status"] for item in exports], ["queued", "skipped"])
        document = json.loads(target.read_text(encoding="utf-8"))
        self.assertEqual(document["foreign"], {"nested": [1, 2, 3]})
        self.assertEqual(document["payload"]["name"], "Erhalten")
        self.assertTrue(document["app"]["dringend"])
        self.assertEqual(document["app"]["status"], "in_bearbeitung")
        comments = document["app"]["comments"]
        self.assertEqual([comment["text"] for comment in comments], ["vorher", first_text, second_text])
        self.assertEqual([comment.get("kind") for comment in comments[1:]], ["sms_received"] * 2)
        self.assertEqual([comment["workplace"] for comment in comments[1:]], ["SMS", "SMS"])
        jobs = self.rows("SELECT message, source_file FROM sms_queue ORDER BY enqueue_order")
        self.assertEqual([row["message"] for row in jobs], ["Antwort eins", "Antwort zwei"])
        self.assertEqual({row["source_file"] for row in jobs}, {"web-new.json"})
        journal = self.rows(
            "SELECT export_file, reply_first FROM sms_receive_journal ORDER BY message_date"
        )
        self.assertEqual({row["export_file"] for row in journal}, {"web-new.json"})
        self.assertEqual(sorted(row["reply_first"] for row in journal), [0, 1])
        self.assertEqual(json.loads((self.inbox / "closed.json").read_text())["app"]["status"], "abgeschlossen")

    def test_phone_fields_foreign_numbers_and_unmatchable_senders(self) -> None:
        cases = [
            ("by-telefon.json", "telefon", "+49 (0) 30 / 123-456", "030123456", "a"),
            ("by-id.json", "id", "+43 (664) 123-4567", "0043 664 1234567", "b"),
            ("by-anrufer.json", "anrufer_id", "0049 171 222-3333", "+49 171 2223333", "c"),
        ]
        for index, (name, field, stored, _incoming, _uid) in enumerate(cases):
            self.write_document(
                name,
                {
                    "received_at": f"2026-09-29T0{index + 1}:00:00Z",
                    "payload": {field: stored},
                },
            )
        entries = [
            self.entry(uid, phone=incoming, text=uid)
            for _name, _field, _stored, incoming, uid in cases
        ]
        entries.extend(
            [
                self.entry("new", phone="+491609998887", text="new"),
                self.entry("alpha", phone="Service 2202", text="alpha"),
                self.entry("short", phone="2202", text="short"),
            ]
        )
        self.php(
            f"""
            tp_sms_receive_store($settings, $device, [{','.join(entries)}]);
            for ($i = 0; $i < 6; $i++) tp_sms_receive_export_one($settings);
            """
        )
        for name, _field, _stored, _incoming, uid in cases:
            document = json.loads((self.inbox / name).read_text())
            self.assertEqual(document["app"]["comments"][-1]["text"], uid)
        generated = list(self.inbox.glob("sms-*.json"))
        self.assertEqual(len(generated), 3)
        self.assertEqual(
            sorted(json.loads(path.read_text())["payload"]["anliegen"] for path in generated),
            ["alpha", "new", "short"],
        )
        normalized = self.php(
            """
            echo json_encode([
                tp_sms_receive_normalize_phone('+49 (0) 170 / 123-4567'),
                tp_sms_receive_normalize_phone('0043 (664) 123-4567'),
                tp_sms_receive_normalize_phone('Info +491701234567'),
                tp_sms_receive_normalize_phone('2202')
            ], JSON_THROW_ON_ERROR);
            """
        )
        self.assertEqual(
            json.loads(normalized.stdout),
            ["+491701234567", "+436641234567", None, None],
        )

    def test_existing_sms_markers_suppress_reply_across_processes(self) -> None:
        original = self.write_document(
            "original-sms.json",
            {
                "received_at": "2026-09-29T09:00:00Z",
                "payload": {"telefon": "+491701234567"},
                "sms": {"received_key": "old-key", "device_key": self.device},
                "app": {"status": "neu", "deleted": False, "comments": []},
            },
        )
        self.php(f"tp_sms_receive_store($settings, $device, [{self.entry('later')}]);")
        self.php("tp_sms_receive_export_one($settings);")
        reply = self.php("echo json_encode(tp_sms_receive_reply_one($settings));")
        self.assertEqual(json.loads(reply.stdout)["reply_status"], "skipped")
        document = json.loads(original.read_text())
        self.assertEqual(document["app"]["comments"][-1]["kind"], "sms_received")
        self.assertEqual(self.rows("SELECT COUNT(*) AS n FROM sms_queue")[0]["n"], 0)

        # Auch die dauerhafte Journalzuordnung gilt ohne Dateimarker nach einem Neustart.
        document.pop("sms")
        document["app"]["comments"] = []
        original.write_text(json.dumps(document), encoding="utf-8")
        self.php(f"tp_sms_receive_store($settings, $device, [{self.entry('still-later')}]);")
        self.php("tp_sms_receive_export_one($settings);")
        again = self.php("echo json_encode(tp_sms_receive_reply_one($settings));")
        self.assertEqual(json.loads(again.stdout)["reply_status"], "skipped")

        # Der neu geschriebene Kommentar-Marker unterdrueckt ebenfalls weitere Antworten.
        self.php(f"tp_sms_receive_store($settings, $device, [{self.entry('comment-marker')}]);")
        self.php("tp_sms_receive_export_one($settings);")
        comment_marker = self.php("echo json_encode(tp_sms_receive_reply_one($settings));")
        self.assertEqual(json.loads(comment_marker.stdout)["reply_status"], "skipped")
        self.assertEqual(self.rows("SELECT COUNT(*) AS n FROM sms_queue")[0]["n"], 0)

    def test_absolute_comment_order_is_stable_and_filename_breaks_case_time_ties(self) -> None:
        base = {
            "received_at": "2026-09-29T08:00:00Z",
            "payload": {"telefon": "+491701234567"},
            "app": {"status": "neu", "deleted": False, "comments": []},
        }
        self.write_document("a-case.json", base)
        selected = json.loads(json.dumps(base))
        selected["app"]["comments"] = [
            {"text": "A", "created_at": "2026-09-29T10:00:00+02:00", "workplace": "P1"},
            {"text": "B", "created_at": "2026-09-29T09:00:00+01:00", "workplace": "P2"},
        ]
        target = self.write_document("z-case.json", selected)
        equal = self.entry(
            "equal", text="C", date="2026-09-29T08:00:00Z"
        )
        earlier = self.entry(
            "earlier", text="  D\n ", date="2026-09-29T09:59:59+02:00"
        )
        self.php(
            f"""
            tp_sms_receive_store($settings, $device, [{equal}, {earlier}]);
            tp_sms_receive_export_one($settings);
            tp_sms_receive_export_one($settings);
            """
        )
        comments = json.loads(target.read_text())["app"]["comments"]
        self.assertEqual([comment["text"] for comment in comments], ["  D\n ", "A", "B", "C"])
        self.assertEqual(json.loads((self.inbox / "a-case.json").read_text())["app"]["comments"], [])

    def test_first_sms_on_web_case_replies_but_invalid_followup_config_is_skipped(self) -> None:
        self.write_document(
            "web.json",
            {"received_at": "2026-09-29T08:00:00Z", "payload": {"telefon": "01701234567"}},
        )
        self.php(f"tp_sms_receive_store($settings, $device, [{self.entry('first')}]);")
        self.php("tp_sms_receive_export_one($settings);")
        first = self.php("echo json_encode(tp_sms_receive_reply_one($settings));")
        self.assertEqual(json.loads(first.stdout)["reply_status"], "queued")

        self.php(
            f"""
            $settings['auto_reply']['messages'] = [str_repeat('x', 71)];
            tp_sms_receive_store($settings, $device, [{self.entry('second')}]);
            tp_sms_receive_export_one($settings);
            """
        )
        second = self.php("echo json_encode(tp_sms_receive_reply_one($settings));")
        self.assertEqual(json.loads(second.stdout)["reply_status"], "skipped")
        self.assertEqual(self.rows("SELECT COUNT(*) AS n FROM sms_queue")[0]["n"], 2)

    def test_additive_migration_keeps_queue_schema_three_and_old_defaults(self) -> None:
        self.php(f"tp_sms_receive_store($settings, $device, [{self.entry('old-row')}]);")
        connection = sqlite3.connect(self.db)
        try:
            connection.execute("ALTER TABLE sms_receive_journal DROP COLUMN export_file")
            connection.execute("ALTER TABLE sms_receive_journal DROP COLUMN reply_first")
            connection.commit()
        finally:
            connection.close()
        result = self.php(
            """
            $pdo = tp_sms_receive_open($settings);
            $row = $pdo->query("SELECT export_file, reply_first FROM sms_receive_journal WHERE uid='old-row'")->fetch();
            echo json_encode([
                (int)$pdo->query('PRAGMA user_version')->fetchColumn(),
                array_column($pdo->query('PRAGMA table_info(sms_receive_journal)')->fetchAll(), 'name'),
                $row
            ], JSON_THROW_ON_ERROR);
            """
        )
        version, columns, old_row = json.loads(result.stdout)
        self.assertEqual(version, 3)
        self.assertIn("export_file", columns)
        self.assertIn("reply_first", columns)
        self.assertIsNone(old_row["export_file"])
        self.assertEqual(old_row["reply_first"], 1)

    def test_exporting_recovery_confirms_comment_once_and_missing_target_is_held(self) -> None:
        first = self.entry("recover", phone="+491701111111")
        self.php(f"tp_sms_receive_store($settings, $device, [{first}]);")
        key = self.rows("SELECT record_key FROM sms_receive_journal")[0]["record_key"]
        self.write_document(
            "case.json",
            {
                "received_at": "2026-09-29T08:00:00Z",
                "payload": {"telefon": "+491701111111"},
                "app": {
                    "comments": [
                        {
                            "text": "Nachricht",
                            "created_at": "2026-09-29T10:00:00+02:00",
                            "workplace": "SMS",
                            "kind": "sms_received",
                            "sms_received_key": key,
                            "sms_sender": "+491701111111",
                        }
                    ]
                },
            },
        )
        connection = sqlite3.connect(self.db)
        try:
            connection.execute(
                "UPDATE sms_receive_journal SET export_status='exporting', export_file='case.json', reply_first=1"
            )
            connection.commit()
        finally:
            connection.close()
        recovered = self.php(
            "echo json_encode([tp_sms_receive_export_one($settings), tp_sms_receive_reply_one($settings), tp_sms_receive_export_one($settings)]);"
        )
        exported, reply, duplicate = json.loads(recovered.stdout)
        self.assertEqual(exported["state"], "exported")
        self.assertEqual(reply["reply_status"], "queued")
        self.assertIsNone(duplicate)
        self.assertEqual(len(json.loads((self.inbox / "case.json").read_text())["app"]["comments"]), 1)

        # Recovery also recognizes an already published comment after the case was moved.
        archived = self.inbox / "papierkorb" / "case.json"
        archived.parent.mkdir()
        (self.inbox / "case.json").rename(archived)
        with sqlite3.connect(self.db) as database:
            database.execute("UPDATE sms_receive_journal SET export_status='exporting' WHERE uid='recover'")
        recovered = self.php("echo json_encode(tp_sms_receive_export_one($settings));")
        self.assertEqual(json.loads(recovered.stdout)["state"], "exported")
        self.assertFalse((self.inbox / "case.json").exists())
        self.assertEqual(len(json.loads(archived.read_text())["app"]["comments"]), 1)

        missing = self.entry("missing", phone="+491709999999")
        self.php(f"tp_sms_receive_store($settings, $device, [{missing}]);")
        connection = sqlite3.connect(self.db)
        try:
            connection.execute(
                "UPDATE sms_receive_journal SET export_status='exporting', export_file='purged.json', reply_first=1 "
                "WHERE uid='missing'"
            )
            connection.commit()
        finally:
            connection.close()
        held = self.php(
            "echo json_encode([tp_sms_receive_export_one($settings), tp_sms_receive_reply_one($settings)]);"
        )
        export_result, reply_result = json.loads(held.stdout)
        self.assertEqual(export_result["failure_code"], "output_missing_after_interruption")
        self.assertIsNone(reply_result)
        self.assertFalse((self.inbox / "purged.json").exists())

    def test_legacy_export_mapping_prevents_reconfirmation_without_file_marker(self) -> None:
        self.php(f"tp_sms_receive_store($settings, $device, [{self.entry('legacy')}]); tp_sms_receive_export_one($settings); tp_sms_receive_reply_one($settings);")
        row = self.rows("SELECT record_key FROM sms_receive_journal")[0]
        path = self.inbox / ("sms-" + row["record_key"] + ".json")
        document = json.loads(path.read_text())
        document.pop("sms")
        path.write_text(json.dumps(document))
        with sqlite3.connect(self.db) as database:
            database.execute("UPDATE sms_receive_journal SET export_file=NULL")
        self.php(f"tp_sms_receive_store($settings, $device, [{self.entry('legacy-followup')}]); tp_sms_receive_export_one($settings);")
        result = self.php("echo json_encode(tp_sms_receive_reply_one($settings));")
        self.assertEqual(json.loads(result.stdout)["reply_status"], "skipped")
        self.assertEqual(len(list(self.inbox.glob("*.json"))), 1)
        self.assertEqual(self.rows("SELECT COUNT(*) AS n FROM sms_queue")[0]["n"], 2)

    def test_closed_deleted_and_malformed_cases_stay_unchanged(self) -> None:
        states = [{"status": "abgeschlossen"}, {"status": "neu", "deleted": True},
                  {"status": "neu", "deleted": 1}, {"status": "neu", "comments": "ungueltig"}]
        for index, state in enumerate(states):
            phone = "+49170123456" + str(index)
            original = {"received_at": "2026-09-29T08:00:00Z", "payload": {"telefon": phone}, "app": state}
            path = self.write_document(f"prior-{index}.json", original)
            message = self.entry(f"new-{index}", phone=phone)
            result = self.php(f"tp_sms_receive_store($settings, $device, [{message}]); echo json_encode([tp_sms_receive_export_one($settings),tp_sms_receive_reply_one($settings)]);")
            exported, reply = json.loads(result.stdout)
            self.assertEqual(reply["reply_status"], "queued")
            self.assertEqual(json.loads(path.read_text()), original)
            new_path = self.inbox / ("sms-" + exported["record_key"] + ".json")
            self.assertTrue(new_path.exists())
            second = self.entry(f"second-{index}", phone=phone)
            result = self.php(f"tp_sms_receive_store($settings, $device, [{second}]); tp_sms_receive_export_one($settings); echo json_encode(tp_sms_receive_reply_one($settings));")
            self.assertEqual(json.loads(result.stdout)["reply_status"], "skipped")
            self.assertEqual(len(json.loads(new_path.read_text())["app"]["comments"]), 1)
        self.assertEqual(self.rows("SELECT COUNT(*) AS n FROM sms_queue")[0]["n"], 8)

    def test_second_process_waits_for_global_inbox_lock(self) -> None:
        self.php(f"tp_sms_receive_store($settings, $device, [{self.entry('locked')}]);")
        lock_path = self.inbox / ".telepraxis-inbox.lock"
        lock_path.touch(mode=0o660)
        lock_path.chmod(0o660)
        with lock_path.open("r+b") as lock_handle:
            fcntl.flock(lock_handle, fcntl.LOCK_EX)
            process = subprocess.Popen(
                ["php"],
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                cwd=ROOT,
            )
            assert process.stdin is not None
            process.stdin.write(textwrap.dedent(self.prelude() + "echo json_encode(tp_sms_receive_export_one($settings));"))
            process.stdin.close()
            time.sleep(0.35)
            self.assertIsNone(process.poll(), "export process did not wait for the inbox lock")
            fcntl.flock(lock_handle, fcntl.LOCK_UN)
            stdout = process.stdout.read() if process.stdout is not None else ""
            stderr = process.stderr.read() if process.stderr is not None else ""
            return_code = process.wait(timeout=10)
            if process.stdout is not None:
                process.stdout.close()
            if process.stderr is not None:
                process.stderr.close()
        self.assertEqual(return_code, 0, stderr)
        self.assertEqual(json.loads(stdout)["state"], "exported")


if __name__ == "__main__":
    unittest.main(verbosity=2)
