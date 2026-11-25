"""Real PHP request/queue integration in temp directories; no router or network."""

from __future__ import annotations

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
SMS_TEXT = "Ihre SMS wurde an die Praxis weitergeleitet. Testbetrieb!"
LONG_SMS_TEXT = (
    "Ihre SMS wurde an die Praxis weitergeleitet. Testbetrieb! Fehlen Ihre persönlichen "
    "Daten, senden Sie bitte eine komplette SMS mit diesen Angaben. Vielen Dank!"
)


@unittest.skipUnless(PHP, "PHP CLI required")
class SmsWebQueueTests(unittest.TestCase):
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
        app.write_text(re.sub(r"const TELEPRAXIS_ADMIN_PASSWORD = .*?;",
                              "const TELEPRAXIS_ADMIN_PASSWORD = 'integration-admin';", app.read_text()))
        self.settings = {
            "default_provider": "queue",
            "sms": {"max_text_length": 160},
            "queue": {
                "database_path": str(self.root / "queue" / "outbox.sqlite"),
                "delivery_provider": "fritz",
            },
        }
        self.save_settings()
        self.card = self.site / "inbox" / "example.json"
        self.original = {
            "received_at": "2026-09-29T12:00:00+02:00", "typ": "sonstiges",
            "payload": {"typ": "sonstiges", "telefon": "+491700000000", "anliegen": "Test"},
            "app": {"status": "in_bearbeitung", "status_updated_arbeitsplatz": "testplatz",
                    "comments": [{"text": "Vorhandener Kommentar", "workplace": "testplatz",
                                  "created_at": "2026-09-29T12:01:00+02:00"}]},
        }
        self.card.write_text(json.dumps(self.original), encoding="utf-8")
        self.driver = self.root / "request.php"
        self.driver.write_text("""<?php
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
chdir($argv[1]);
$_SERVER['DOCUMENT_ROOT'] = $argv[1];
$_SERVER['REQUEST_METHOD'] = isset($input['action']) ? 'POST' : 'GET';
$_POST = $input;
$_GET = isset($input['action']) ? [] : ['ajax' => 'list'];
$_REQUEST = array_merge($_GET, $_POST);
session_save_path($argv[2]);
session_id('telepraxis-queue-integration-test');
register_shutdown_function(static function () {
    fwrite(STDERR, 'HTTP:' . (http_response_code() ?: 200));
});
require './telepraxis-app.php';
""", encoding="utf-8")
        self.csrf = self.request({})[1]["csrf"]

    def save_settings(self):
        (self.site / "sms-credentials.json").write_text(json.dumps(self.settings), encoding="utf-8")

    def request(self, fields):
        result = subprocess.run(
            [PHP, "-d", "display_errors=stderr", str(self.driver), str(self.site), str(self.root / "sessions")],
            input=json.dumps(fields), text=True, capture_output=True, timeout=5,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertRegex(result.stderr, r"^HTTP:\d{3}$")
        return int(result.stderr.removeprefix("HTTP:")), json.loads(result.stdout)

    def send(self, request_id="a" * 32, text=SMS_TEXT, **overrides):
        return self.request({
            "action": "send_sms", "csrf": self.csrf, "file": "example.json",
            "workplace": "testplatz", "sms_text": text, "sms_request_id": request_id,
            **overrides,
        })

    def card_view(self):
        code, response = self.request({})
        self.assertEqual(code, 200)
        return response["entries"][0]

    def action(self, action, **fields):
        return self.request({"action": action, "csrf": self.csrf, "file": "example.json",
                             "workplace": "testplatz", **fields})

    def test_received_export_is_a_regular_card_with_reply_history(self):
        # Different phone: this test specifically exercises creation of a new card.
        self.original["payload"]["telefon"] = "+491700000001"
        self.card.write_text(json.dumps(self.original), encoding="utf-8")
        from test_telepraxis_sms_journal import envelope, received
        from test_telepraxis_sms_receive import LONG_MESSAGE

        settings = {**self.settings, "fritzbox": {"host": "synthetic.test"},
                    "receive": {"output_mode": "target-local", "inbox_path": str(self.site / "inbox")}}
        script = """
define('TELEPRAXIS_SMS_CONFIG',true);
require 'telepraxis-sms-receive.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$s=tp_sms_merge_settings(tp_sms_default_settings(),$in['settings']);
tp_sms_receive_store($s,str_repeat('ab',32),tp_sms_journal_parse($in['journal']));
echo json_encode([tp_sms_receive_export_one($s),tp_sms_receive_reply_one($s)]);
"""
        result = subprocess.run([PHP, "-r", script], cwd=ROOT, text=True, capture_output=True, timeout=5,
                                input=json.dumps({"settings": settings,
                                                  "journal": envelope([received(text=LONG_MESSAGE)])}))
        self.assertEqual(result.returncode, 0, result.stderr)
        exported, reply = json.loads(result.stdout)
        self.assertEqual(reply["reply_status"], "queued")
        filename = "sms-" + exported["record_key"] + ".json"
        code, response = self.request({})
        self.assertEqual(code, 200)
        card = next(entry for entry in response["entries"] if entry["file"] == filename)
        self.assertEqual(card["body"], LONG_MESSAGE)
        self.assertEqual(card["telephone_raw"], "+491700000000")
        self.assertEqual(card["status"], "neu")
        self.assertFalse(card["deleted"])
        self.assertEqual(len(card["comments"]), 2)
        for action in ("soft_delete", "restore"):
            if action == "restore":
                self.assertEqual(self.action("admin_login", password="integration-admin")[0], 200)
            status, _ = self.request({"action": action, "csrf": self.csrf, "file": filename, "workplace": "Test"})
            self.assertEqual(status, 200)
        self.assertEqual(json.loads((self.site / "inbox" / filename).read_text())["sms"]["received_key"], exported["record_key"])

    def receive_messages(self, *messages):
        from test_telepraxis_sms_journal import envelope, received
        settings = {**self.settings, "fritzbox": {"host": "synthetic.test"},
                    "receive": {"output_mode": "target-local", "inbox_path": str(self.site / "inbox")}}
        script = """
define('TELEPRAXIS_SMS_CONFIG',true);
require 'telepraxis-sms-receive.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$s=tp_sms_merge_settings(tp_sms_default_settings(),$in['settings']);
tp_sms_receive_store($s,str_repeat('ab',32),tp_sms_journal_parse($in['journal']));
$exports=[]; $replies=[];
for ($i=0;$i<count($in['journal']['data']['smsListData']['messages']);$i++) {
    $exports[]=tp_sms_receive_export_one($s);
}
while (($reply=tp_sms_receive_reply_one($s))!==null) { $replies[]=$reply; }
echo json_encode([$exports,$replies]);
"""
        result = subprocess.run([PHP, "-r", script], cwd=ROOT, text=True, capture_output=True, timeout=5,
                                input=json.dumps({"settings": settings,
                                                  "journal": envelope([received(**m) for m in messages])}))
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_followup_sms_threads_preserve_comments_and_only_first_reply(self):
        text = "  Nachtrag <script>alert(1)</script> 🌍\nmit Leerraum  "
        exports, replies = self.receive_messages(
            {"uid": "101", "text": "Erste SMS", "date": "2026-09-29T12:00:30+02:00"},
            {"uid": "102", "text": text, "date": "2026-09-29T10:02:00+00:00"},
        )
        self.assertEqual([r["reply_status"] for r in replies], ["queued", "skipped"])
        self.assertEqual(list((self.site / "inbox").glob("*.json")), [self.card])
        view = self.card_view()
        self.assertEqual(view["body"], "Test")
        self.assertEqual(view["status"], "in_bearbeitung")
        self.assertEqual(view["last_workplace"], "testplatz")
        actual = [c for c in view["comments"] if not c.get("sms_job_id")]
        self.assertEqual([c["text"] for c in actual], ["Erste SMS", "Vorhandener Kommentar", text])
        self.assertEqual(actual[2]["kind"], "sms_received")
        self.assertEqual(actual[2]["sms_received_key"], exports[1]["record_key"])
        self.assertEqual(len([c for c in view["comments"] if c.get("sms_job_id")]), 2)
        self.assertEqual(self.action("toggle_urgent")[0], 200)
        self.assertEqual(self.action("add_comment", comment_text="Praxisnotiz")[0], 200)
        _, replies = self.receive_messages({"uid": "103", "text": "Dritte SMS"})
        self.assertEqual(replies[0]["reply_status"], "skipped")
        saved = json.loads(self.card.read_text())
        incoming = [c for c in saved["app"]["comments"] if c.get("kind") == "sms_received"]
        self.assertEqual(len(incoming), 3)
        self.assertIn(text, [c["text"] for c in incoming])
        self.assertEqual(self.action("set_status", status="abgeschlossen")[0], 200)
        self.assertEqual(self.action("soft_delete")[0], 200)
        self.assertEqual(self.action("admin_login", password="integration-admin")[0], 200)
        self.assertEqual(self.action("restore")[0], 200)
        self.assertEqual(len([c for c in self.card_view()["comments"] if c.get("kind") == "sms_received"]), 3)
        with sqlite3.connect(self.settings["queue"]["database_path"]) as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0], 2)

    def test_app_write_waits_for_worker_lock_and_preserves_incoming_sms(self):
        import fcntl
        import os
        import time
        marker = {"text": "  Neue SMS  ", "created_at": "2026-09-29T12:02:00+02:00",
                  "workplace": "SMS", "kind": "sms_received", "sms_received_key": "d" * 64,
                  "sms_sender": "+491700000000"}
        lockpath = self.site / "inbox" / ".telepraxis-inbox.lock"
        with lockpath.open("a+b") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            process = subprocess.Popen(
                [PHP, "-d", "display_errors=stderr", str(self.driver), str(self.site), str(self.root / "sessions")],
                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
            )
            process.stdin.write(json.dumps({"action": "add_comment", "csrf": self.csrf,
                                           "file": self.card.name, "workplace": "Test", "benutzer": "testplatz",
                                           "comment_text": "Parallelnotiz"}))
            process.stdin.close()
            process.stdin = None
            try:
                time.sleep(0.2)
                self.assertIsNone(process.poll(), "App mutation did not respect shared inbox lock")
                self.original["app"]["comments"].append(marker)
                temp = self.card.with_suffix(".tmp")
                temp.write_text(json.dumps(self.original))
                os.replace(temp, self.card)
            finally:
                fcntl.flock(lock, fcntl.LOCK_UN)
                stdout, stderr = process.communicate(timeout=5)
        self.assertEqual(process.returncode, 0, stderr)
        self.assertEqual(stderr, "HTTP:200")
        self.assertTrue(json.loads(stdout)["ok"])
        saved = json.loads(self.card.read_text())
        self.assertIn(marker, saved["app"]["comments"])
        self.assertEqual(saved["app"]["comments"][-1]["text"], "Parallelnotiz")
        self.assertFalse(list((self.site / "inbox").glob("*.tmp")))

    @unittest.skipUnless(shutil.which("node"), "Node required")
    def test_sms_rendering_card_table_plaintext_and_polling_focus(self):
        source = (self.site / "telepraxis-app.php").read_text()
        names = ["escapeHtml", "createCommentItems", "createReceivedSmsHistory", "createCard",
                 "createCardActions", "createHeader", "createPhoneActions", "patientButtons", "patientMatches", "createSelectionControl", "createTable",
                 "tableActionButtons", "selectionChecked", "buildCardText", "captureTransientState", "restoreTransientState",
                 "createRecordings", "createCategoryTags", "createAnliegenBody"]
        functions = []
        for name in names:
            start = source.index("    function " + name + "(")
            end = re.search(r"^    (?:async )?function ", source[start + 1:], re.M)
            self.assertIsNotNone(end)
            functions.append(source[start:start + 1 + end.start()])
        program = "\n".join(functions) + r'''
const assert = require('node:assert/strict');
const t2medEnabled = false, patientIntegration = false;
const selected = {middle:new Set(),right:new Set(),trash:new Set()};
const openRecordingFiles = new Set();
const comment = {kind:'sms_received',text:'<script>unsafe</script>\nSMS Text',
    created_at_display:'29.09.2026 12:02',sms_sender:'<img src=x onerror=evil()>'};
const entry = {file:'example.json',status:'neu',body:'Hauptanliegen',comments:[comment]};
for (const html of [createCard(entry,'middle'),createTable('middle',[entry])]) {
    assert.ok(html.includes('comment-item-sms'));
    assert.ok(html.includes('Kommentare'));
    assert.ok(html.includes('SMS eingegangen'));
    assert.ok(html.includes('&lt;script&gt;unsafe&lt;/script&gt;'));
    assert.ok(!html.includes('<script>'));
    assert.ok(!html.includes('<img'));
}
assert.ok(buildCardText(entry).includes('SMS eingegangen · 29.09.2026 12:02 · von'));
assert.ok(buildCardText(entry).includes(comment.text));
let focused = false; let selection = null;
const input = {value:'Unfertiger Kommentar',selectionStart:3,selectionEnd:8,
    closest:()=>null, matches:s=>s==='[data-comment-input]',getAttribute:()=>entry.file,
    focus:()=>{focused=true;},setSelectionRange:(a,b)=>{selection=[a,b];}};
const document = {activeElement:input,querySelector:()=>input};
const CSS = {escape:x=>x};
const els = {leftColumn:{scrollTop:18},middleBody:{scrollTop:25}};
const state = captureTransientState();
els.leftColumn.scrollTop = 0;
restoreTransientState(state);
assert.equal(input.value,'Unfertiger Kommentar');
assert.equal(focused,true);
assert.deepEqual(selection,[3,8]);
assert.equal(els.leftColumn.scrollTop,18);
'''
        result = subprocess.run([shutil.which("node"), "-e", program], text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertRegex(source, r"\.comment-item\.comment-item-sms\s*\{[^}]*background:")
        # Parse all actual inline JavaScript, including template literals.
        rendered = subprocess.run([PHP, "-r", "session_save_path($argv[1]); require $argv[2];",
                                   str(self.root / "sessions"), str(self.site / "telepraxis-app.php")],
                                  text=True, capture_output=True)
        self.assertEqual(rendered.returncode, 0, rendered.stderr)
        script = re.search(r"<script>(.*?)</script>", rendered.stdout, re.S).group(1)
        syntax = subprocess.run([shutil.which("node"), "--check", "-"], input=script, text=True, capture_output=True)
        self.assertEqual(syntax.returncode, 0, syntax.stderr)

    @unittest.skipUnless(shutil.which("node"), "Node required")
    def test_incoming_sms_polling_plays_existing_tone_once_and_respects_mute(self):
        source = (self.site / "telepraxis-app.php").read_text()
        functions = []
        for name in ("ensureAudioContext", "playNotificationTone", "trashIsOpen", "refresh"):
            match = re.search(r"^    (?:async )?function " + name + r"\(", source, re.M)
            start = match.start()
            end = re.search(r"^    (?:async )?function ", source[start + 1:], re.M)
            functions.append(source[start:start + 1 + end.start()])
        program = "\n".join(functions) + r'''
const assert = require('node:assert/strict');
let lastSeenIds = new Set();
const seenIncomingSmsKeys = new Set();
let initialized = false, currentCsrf = '', isAdmin = false;
let latestRefresh = 0;
let refreshInProgress = false;
let nextResponse, responseOk = true;
const errors = [], tones = [];
const els = {soundToggle:{checked:true}};
const window = {location:{pathname:'/telepraxis-app.php'}};
const fetch = async () => ({ok:responseOk,json:async()=>nextResponse});
const render = () => {};
const showMessage = text => errors.push(text);
let audioContext = {state:'running',currentTime:0,destination:{},
    createGain:()=>({gain:{setValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){}}),
    createOscillator:()=>{const tone={}; tones.push(tone); return {
        frequency:{setValueAtTime(){}},connect(){},start(t){tone.start=t;},stop(t){tone.stop=t;}
    };}
};
const sms = key => ({kind:'sms_received',sms_received_key:key.repeat(64),text:'SMS '+key});
const card = comments => ({file:'open.json',status:'in_bearbeitung',deleted:false,comments});
const poll = async entries => {
    nextResponse = {ok:responseOk,entries:JSON.parse(JSON.stringify(entries))};
    await refresh();
};
(async()=>{
    await poll([card([sms('a')])]);
    assert.equal(tones.length,0,'Initial history must remain silent');
    let entries = [card([sms('a'),sms('b')])];
    await poll(entries);
    assert.equal(tones.length,4,'Follow-up SMS must use the existing four-tone signal');
    assert.deepEqual(tones.map(t=>Number((t.stop-t.start).toFixed(2))),[0.08,0.08,0.08,0.24]);
    await poll(entries);
    assert.equal(tones.length,4,'Identical polling must not repeat the sound');
    entries[0].comments.push({text:'Praxisnotiz'}, {text:'Antwort',sms_job_id:'job',sms_status:'pending'});
    await poll(entries);
    entries[0].comments.at(-1).sms_status = 'accepted';
    entries[0].status = 'neu';
    await poll(entries);
    assert.equal(tones.length,4,'Notes, outgoing status and case status must remain silent');
    entries[0].comments.push(sms('c'));
    await poll(entries);
    assert.equal(tones.length,8,'SMS in Neu must also notify');
    entries.push({file:'new.json',status:'neu',deleted:false,comments:[]});
    entries[0].comments.push(sms('d'));
    await poll(entries);
    assert.equal(tones.length,12,'New case and incoming SMS in one response use one signal');
    const stale = JSON.parse(JSON.stringify(entries));
    stale[0].comments = [sms('a')];
    await poll(stale);
    await poll(entries);
    assert.equal(tones.length,12,'An older response must not forget known SMS');
    els.soundToggle.checked = false;
    entries[0].comments.push(sms('e'));
    await poll(entries);
    assert.equal(tones.length,12,'Mute must suppress SMS sound');
    els.soundToggle.checked = true;
    await poll(entries);
    assert.equal(tones.length,12,'Unmuting must not replay messages received while muted');
    entries[0].comments.push(sms('f'));
    await poll(entries);
    assert.equal(tones.length,16);
    assert.equal(errors.length,0);
    entries[0].comments.push(sms('0'));
    responseOk = false;
    await poll(entries);
    assert.equal(tones.length,16,'Failed request must not notify');
    responseOk = true;
    await poll(entries);
    assert.equal(tones.length,20,'Successful retry must still detect the new SMS');
    entries.push({file:'trash.json',deleted:true,comments:[sms('1')]});
    await poll(entries);
    assert.equal(tones.length,20,'Trash history must not produce an incoming-SMS sound');
})().catch(error=>{console.error(error);process.exitCode=1;});
'''
        result = subprocess.run([shutil.which("node"), "-e", program], text=True, capture_output=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_regular_app_soft_delete_restore_and_admin_only_purge(self):
        self.send()
        self.assertEqual(self.action("set_status", status="abgeschlossen")[0], 200)
        code, _ = self.action("soft_delete")
        self.assertEqual(code, 200)
        archived = self.card.parent / "papierkorb" / self.card.name
        self.assertFalse(self.card.exists())
        self.assertTrue(json.loads(archived.read_text())["app"]["deleted"])
        for action in ("restore", "purge"):
            self.assertEqual(self.action(action)[0], 403)
        self.assertEqual(self.action("admin_login", password="integration-admin")[0], 200)
        self.assertEqual(self.action("restore")[0], 200)
        restored = self.card_view()
        self.assertFalse(restored["deleted"])
        self.assertEqual(len([c for c in restored["comments"] if c.get("sms_job_id")]), 1)
        self.assertEqual(self.action("purge")[0], 400)
        self.assertEqual(self.action("soft_delete")[0], 200)
        self.assertEqual(self.action("purge")[0], 200)
        self.assertFalse(self.card.exists())
        self.assertFalse(archived.exists())

    def test_comments_status_and_urgent_flag_survive_queue_integration(self):
        self.send()
        self.assertEqual(self.action("add_comment", comment_text="Neue Rückfrage")[0], 200)
        self.assertEqual(self.action("toggle_urgent")[0], 200)
        self.assertEqual(self.action("set_status", status="abgeschlossen")[0], 200)
        view = self.card_view()
        self.assertEqual(view["status"], "abgeschlossen")
        self.assertTrue(view["urgent"])
        self.assertEqual([c["text"] for c in view["comments"] if not c.get("sms_job_id")],
                         ["Vorhandener Kommentar", "Neue Rückfrage"])
        self.assertEqual(self.action("set_status", status="in_bearbeitung")[0], 200)
        self.assertEqual(self.card_view()["last_workplace"], "testplatz")

    def test_queue_provider_is_fast_durable_and_does_not_need_router_credentials(self):
        self.assertEqual(len(SMS_TEXT), 57)
        code, response = self.send()
        self.assertEqual(code, 202)
        self.assertTrue(response["queued"])
        self.assertEqual(response["provider"], "queue")
        self.assertNotIn("gesendet", response["message"])
        # App-file is not rewritten; existing comments and processing state survive.
        self.assertEqual(json.loads(self.card.read_text()), self.original)
        comments = self.card_view()["comments"]
        sms = next(comment for comment in comments if comment.get("sms_job_id"))
        self.assertEqual(sms["sms_job_id"], response["job_id"])
        self.assertEqual(sms["sms_status"], "pending")
        self.assertIn(SMS_TEXT, sms["text"])
        self.assertEqual(sms["workplace"], "testplatz")

    def test_retry_of_same_request_has_one_job_and_conflicting_body_is_rejected(self):
        _, first = self.send()
        code, second = self.send()
        self.assertEqual(code, 202)
        self.assertEqual(first["job_id"], second["job_id"])
        code, conflict = self.send(text="Anderer Inhalt")
        self.assertGreaterEqual(code, 400)
        self.assertFalse(conflict["ok"])
        self.assertEqual(len([c for c in self.card_view()["comments"] if c.get("sms_job_id")]), 1)

    def test_new_intent_allows_same_text_as_a_new_message(self):
        _, first = self.send()
        _, second = self.send(request_id="b" * 32)
        self.assertNotEqual(first["job_id"], second["job_id"])

    def test_status_from_worker_is_shown_after_a_new_request(self):
        self.send()
        # Inject a confirmed sender; this cannot contact any real router.
        settings = {**self.settings, "fritzbox": {
            "host": "http://example.invalid", "username": "dummy", "password": "dummy",
            "totp_secret": "JBSWY3DPEHPK3PXP",
        }}
        script = """
define('TELEPRAXIS_APP', true);
require $argv[1];
require $argv[2];
$settings = json_decode(stream_get_contents(STDIN), true);
echo json_encode(tp_sms_queue_process_one($settings, static function () {
    return ['ok' => true, 'provider' => 'fritz', 'details' => ['message_uid' => 'confirmed-test']];
}));
"""
        result = subprocess.run(
            [PHP, "-r", script, str(self.site / "telepraxis-sms.php"), str(self.site / "telepraxis-sms-queue.php")],
            input=json.dumps(settings), text=True, capture_output=True, timeout=5,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)["status"], "accepted")
        comment = next(c for c in self.card_view()["comments"] if c.get("sms_job_id"))
        self.assertEqual(comment["sms_status"], "accepted")
        self.assertIn("an FRITZ!Box übergeben", comment["text"])

    def test_csrf_and_workplace_restrictions_still_apply(self):
        for overrides in ({"csrf": "invalid"}, {"workplace": "andererplatz"}):
            with self.subTest(overrides=overrides):
                code, response = self.send(**overrides)
                self.assertGreaterEqual(code, 400)
                self.assertFalse(response["ok"])
        self.assertFalse(Path(self.settings["queue"]["database_path"]).exists())

    def test_length_validation_rejects_oversize_before_enqueue(self):
        code, response = self.send(text="x" * 161)
        self.assertGreaterEqual(code, 400)
        self.assertFalse(response["ok"])
        self.assertFalse(Path(self.settings["queue"]["database_path"]).exists())

    def test_fritz_rejects_long_reply_before_direct_send_or_enqueue(self):
        self.assertEqual(len(LONG_SMS_TEXT), 158)
        for provider in ("fritz", "queue"):
            with self.subTest(provider=provider):
                self.settings["default_provider"] = provider
                self.save_settings()
                code, response = self.send(text=LONG_SMS_TEXT)
                self.assertGreaterEqual(code, 400)
                self.assertIn("70 Zeichen", response["error"])
                self.assertFalse(Path(self.settings["queue"]["database_path"]).exists())
                self.assertEqual(json.loads(self.card.read_text()), self.original)

    def test_fritz_unicode_boundary_and_seven_long_messages(self):
        code, _ = self.send(text="ö" * 70)
        self.assertEqual(code, 202)
        code, response = self.send(request_id="b" * 32, text="ö" * 71)
        self.assertGreaterEqual(code, 400)
        self.assertIn("70 Zeichen", response["error"])
        self.settings["queue"]["delivery_provider"] = "seven"
        self.save_settings()
        code, _ = self.send(request_id="c" * 32, text=LONG_SMS_TEXT)
        self.assertEqual(code, 202)

    def test_missing_queue_directory_fails_without_success_or_app_mutation(self):
        self.settings["queue"]["database_path"] = str(self.root / "missing" / "queue.sqlite")
        self.save_settings()
        code, response = self.send()
        self.assertGreaterEqual(code, 400)
        self.assertFalse(response["ok"])
        self.assertEqual(json.loads(self.card.read_text()), self.original)

    def test_none_remains_disabled_and_does_not_enqueue(self):
        self.settings["default_provider"] = "none"
        self.save_settings()
        code, response = self.send()
        self.assertEqual(code, 400)
        self.assertIn("deaktiviert", response["error"])
        self.assertFalse(Path(self.settings["queue"]["database_path"]).exists())

    def test_seven_status_does_not_claim_fritzbox_delivery(self):
        self.settings["queue"]["delivery_provider"] = "seven"
        self.save_settings()
        code, result = self.send()
        self.assertEqual(code, 202)
        with sqlite3.connect(self.settings["queue"]["database_path"]) as db:
            db.execute("UPDATE sms_queue SET status='accepted' WHERE id=?", (result["job_id"],))
        comment = next(c for c in self.card_view()["comments"] if c.get("sms_job_id"))
        self.assertIn("an seven.io übergeben", comment["text"])
        self.assertNotIn("FRITZ!Box", comment["text"])


if __name__ == "__main__":
    unittest.main()
