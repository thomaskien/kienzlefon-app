"""Round-trip checks for the coupled App proxy, using synthetic local services."""
import http.cookiejar
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.error import HTTPError
from urllib.request import Request, build_opener, HTTPCookieProcessor

ROOT = Path(__file__).resolve().parents[1]


@unittest.skipUnless(shutil.which("php"), "PHP required")
class PairedRoutingTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.stage = Path(tmp.name)
        self.token = secrets.token_hex(32)
        (self.stage / "token").write_text(self.token)
        (self.stage / "token").chmod(0o600)
        self.scope = dict(schema_version=1, pbx_id="praxis-a", remote_enabled=True, mode="all", client_ids=[])
        self.scope_status = 200
        self.calls = []
        self.jobs = {}
        fixtures = json.loads((ROOT / "docs/telefonie-v1/fixtures.json").read_text())["fixtures"]
        self.fixture = {item["name"]: item["body"] for item in fixtures}
        test = self

        class Middleware(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def reply(self, code, data):
                self.send_response(code)
                self.end_headers()
                self.wfile.write(json.dumps(data).encode())

            def do_GET(self):
                if self.headers.get("Authorization") != "Bearer " + test.token:
                    return self.reply(401, {})
                if self.path.endswith("/workplaces"):
                    return self.reply(test.scope_status, test.scope)
                if self.path.endswith("/overview"):
                    return self.reply(200, test.fixture["overview-current"])
                test.calls.append(("GET", dict(self.headers), self.path))
                job = test.jobs.get(self.path.rsplit("/", 1)[-1])
                self.reply(200, dict(request=job, error=None))

            def do_POST(self):
                data = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                test.calls.append(("POST", dict(self.headers), self.path))
                job = dict(test.fixture["request-accepted"]["request"], **data)
                test.jobs[data["request_id"]] = job
                self.reply(202, dict(request=job, error=None))

        middleware = ThreadingHTTPServer(("127.0.0.1", 0), Middleware)
        self.addCleanup(middleware.server_close)
        thread = threading.Thread(target=middleware.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(thread.join, 3)
        self.addCleanup(middleware.shutdown)
        self.config = dict(enabled=True, middleware_url=f"http://127.0.0.1:{middleware.server_port}",
                           token_file=str(self.stage / "token"), pbx_id="praxis-a", clients=[])
        self.write_config()
        with socket.socket() as port:
            port.bind(("127.0.0.1", 0))
            host = f"127.0.0.1:{port.getsockname()[1]}"
        self.url = "http://" + host
        self.browser = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
        server = subprocess.Popen(["php", "-d", f"session.save_path={self.stage}", "-d", "opcache.enable_cli=0",
                                   "-S", host, "-t", str(ROOT)],
                                  env={**os.environ, "KIENZLEFON_WORKPLACE_ID":"empfang-1",
                                       "KIENZLEFON_TELEPHONY_CONFIG":str(self.stage / "config.php")},
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.addCleanup(server.wait, 4)
        self.addCleanup(server.terminate)
        for _ in range(100):
            try:
                self.csrf = self.api("bootstrap")[1]["csrf_token"]
                break
            except OSError:
                time.sleep(0.03)
        else:
            self.fail("PHP-Testserver nicht bereit")

    def write_config(self):
        data = json.dumps(self.config).replace("'", "\\'")
        (self.stage / "config.php").write_text(f"<?php return json_decode('{data}',true);")

    def api(self, action, command=None):
        headers = {}
        payload = None
        if command is not None:
            payload = json.dumps(command).encode()
            headers = {"Origin": self.url, "Content-Type": "application/json", "X-Kienzlefon-CSRF": self.csrf}
        req = Request(self.url + "/telepraxis-telephony.php?action=" + action, data=payload, headers=headers)
        try:
            with self.browser.open(req, timeout=3) as res:
                return res.status, json.load(res)
        except HTTPError as error:
            with error:
                return error.code, json.load(error)

    def test_scope_and_command_follow_the_same_station(self):
        self.scope.update(mode="selected", client_ids=["empfang-1"])
        self.assertEqual(self.api("routing-access"), (200, {"client_id": "empfang-1"}))
        command = dict(self.fixture["routing-command"], client_id="empfang-1", extension="203")
        self.assertEqual(self.api("routing", command)[0], 202)
        request = "request&request_id=" + command["request_id"]
        self.assertEqual(self.api(request)[1]["request"]["client_id"], "empfang-1")
        self.assertEqual([method for method, _, _ in self.calls], ["POST", "GET"])
        for _, headers, _ in self.calls:
            self.assertEqual(headers["X-Kienzlefon-Pbx-Id"], "praxis-a")
            self.assertEqual(headers["X-Kienzlefon-Workplace"], "empfang-1")
        self.scope["client_ids"] = []
        self.assertIsNone(self.api("routing-access")[1]["client_id"])
        self.assertEqual(self.api(request)[0], 403)
        self.assertEqual(self.api("routing", command)[0], 403)
        self.assertEqual(len(self.calls), 2)

    def test_local_and_remote_restrictions_and_disconnected_scope(self):
        command = dict(self.fixture["routing-command"], client_id="empfang-1")
        self.config["workplace_mode"] = "selected"
        self.write_config()
        self.assertIsNone(self.api("routing-access")[1]["client_id"])
        self.assertEqual(self.api("routing", command)[0], 403)
        self.config["workplace_mode"] = "fon"
        self.write_config()
        self.scope["remote_enabled"] = False
        self.assertIsNone(self.api("routing-access")[1]["client_id"])
        self.scope["remote_enabled"] = True
        self.assertEqual(self.api("routing", dict(command, client_id="anderer-platz"))[0], 403)
        self.scope["pbx_id"] = "andere-praxis"
        self.assertEqual(self.api("routing-access")[0], 503)
        self.scope_status = 503
        self.assertEqual(self.api("routing", command)[0], 503)
        self.assertEqual(self.api("status")[0], 200)
        self.assertFalse(self.calls)
