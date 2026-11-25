import importlib.util
import json
import os
import secrets
import subprocess
import tempfile
import threading
import unittest
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]


def module(name):
    spec = importlib.util.spec_from_file_location(name, ROOT / "scripts" / (name + ".py"))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


class LocalTelephony(unittest.TestCase):
    def test_actual_proxy_contract_and_stale_snapshot(self):
        collector = module("asterisk-nebenstellen")
        setup = module("telefonie-einrichten")
        endpoints = """
  Endpoint:  201/201                     Not in use    0 of inf
  Endpoint:  202                         Unavailable   0 of 1
  Endpoint:  203/203                     In use        1 of 1
  Endpoint:  204                         Ring+Inuse    1 of inf
  Endpoint:  provider/491234             Not in use    0 of inf
    Channel: PJSIP/201-1234 Up
"""
        with tempfile.TemporaryDirectory() as tmp:
            snap, config = Path(tmp) / "status.json", Path(tmp) / "telephony.php"
            config.write_bytes(setup.config_bytes("asterisk", dict(asterisk_snapshot=str(snap))))

            def query(action):
                code = '$_GET["action"]=$argv[1]; $_SERVER["DOCUMENT_ROOT"]=$argv[2]; require $argv[2]."/telepraxis-telephony.php";'
                result = subprocess.check_output(["php", "-r", code, action, str(ROOT)],
                                                 env=dict(os.environ, KIENZLEFON_TELEPHONY_CONFIG=str(config)))
                return json.loads(result)

            now = datetime.now(timezone.utc)
            snap.write_text(json.dumps(collector.overview(endpoints, now)))
            self.assertEqual(query("bootstrap")["clients"], [])
            data = query("status")
            self.assertEqual([(e["id"], e["state"]) for e in data["extensions"]],
                             [("201", "idle"), ("202", "offline"), ("203", "busy"), ("204", "ringing")])
            self.assertFalse(data["routing_control"]["available"])
            self.assertNotIn("491234", json.dumps(data))
            snap.write_text(json.dumps(collector.overview(endpoints, now - timedelta(seconds=11))))
            self.assertEqual(query("status")["error"]["code"], "state_unavailable")
            snap.unlink()
            self.assertEqual(query("status")["error"]["code"], "state_unavailable")

    def test_empty_or_invalid_asterisk_output(self):
        with self.assertRaises(ValueError):
            module("asterisk-nebenstellen").overview("No such command", datetime.now(timezone.utc))

    def test_external_setup_and_failed_source_switch(self):
        setup = module("telefonie-einrichten")
        token = secrets.token_hex(32)
        rows = module("asterisk-nebenstellen").overview("Endpoint: 201 Not in use 0 of inf", datetime.now(timezone.utc))
        scope = dict(schema_version=1, pbx_id="praxis-a", mode="all", remote_enabled=True, client_ids=[])
        class Middleware(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_GET(self):
                if self.path == "/api/v1/extension-routing/workplaces" and self.headers.get("Authorization") == "Bearer " + token:
                    self.send_response(200)
                    self.end_headers()
                    self.wfile.write(json.dumps(scope).encode())
                    return
                ok = self.path == "/api/v1/telephony/overview" and self.headers.get("Authorization") == "Bearer " + token
                self.send_response(200 if ok else 401)
                self.end_headers()
                self.wfile.write(json.dumps(rows if ok else {}).encode())

        with tempfile.TemporaryDirectory() as tmp, HTTPServer(("127.0.0.1", 0), Middleware) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            self.addCleanup(thread.join, 2)
            directory = Path(tmp)
            # Der Test hat keine Systemkonten. Die PHP-Prüfung und der HTTP-Aufruf laufen unverändert.
            wrapper = directory / "runuser"
            wrapper.write_text('#!/bin/sh\nshift 3\nexec "$@"\n')
            wrapper.chmod(0o755)
            setup.PROXY = ROOT / "telepraxis-telephony.php"
            setup.DIRECTORY = directory
            setup.CONFIG = directory / "installed.php"
            key = directory / "input"
            key.write_text(token)
            key.chmod(0o600)
            url = f"http://127.0.0.1:{server.server_port}"
            try:
                with patch.dict(os.environ, PATH=tmp + ":" + os.environ["PATH"]), patch.object(os, "fchown"), \
                        patch.object(setup.pwd, "getpwnam"):
                    self.assertEqual(setup.external(url, key), 1)
                    installed = setup.CONFIG.read_bytes()
                    self.assertIn(b"praxis-a", installed)
                    self.assertNotIn(token.encode(), installed)
                    self.assertEqual(setup.external(url, key), 1)
                    scope["pbx_id"] = "praxis-b"
                    with self.assertRaisesRegex(ValueError, "anderen"):
                        setup.external(url, key)
                    self.assertEqual(setup.CONFIG.read_bytes(), installed)
                    key.write_text(secrets.token_hex(32))
                    with self.assertRaisesRegex(ValueError, "upstream_authentication_failed"):
                        setup.external(url, key)
                    self.assertEqual(setup.CONFIG.read_bytes(), installed)
                    self.assertEqual(len(list(directory.glob("external-*.token"))), 1)
            finally:
                server.shutdown()
