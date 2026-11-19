import importlib.util
import io
import json
import tempfile
import unittest
import wave
from pathlib import Path
from unittest import mock
from zipfile import ZipFile


HELPER = Path(__file__).resolve().parents[1] / "telepraxis-audio-delivery.py"
SPEC = importlib.util.spec_from_file_location("audio_delivery", HELPER)
delivery = importlib.util.module_from_spec(SPEC)
try:
    SPEC.loader.exec_module(delivery)
except ModuleNotFoundError as error:
    if error.name != "cryptography":
        raise
    delivery = None


@unittest.skipIf(delivery is None, "cryptography required for the delivery helper")
class DeliveryRetryTests(unittest.TestCase):
    def test_replacement_can_finish_after_interrupted_wav_staging(self):
        with tempfile.TemporaryDirectory() as folder:
            inbox, state = Path(folder) / "inbox", Path(folder) / "state"
            inbox.mkdir()
            package = delivery.delivery_ack("partial.kienzlepaket", "a" * 64, "abgelegt")
            fallback = delivery.delivery_ack("partial.json.enc", "b" * 64, "abgelegt")
            record = {"payload": {"_kienzlefon": {"vorgang_id": "partial", "audio": []}}}
            write = delivery.atomic_write
            def interrupted(path, data, mode=0o660):
                if path.name == "b.wav":
                    raise OSError("simulierter Schreibfehler")
                write(path, data, mode)
            with mock.patch.object(delivery, "atomic_write", side_effect=interrupted):
                with self.assertRaises(OSError):
                    delivery.publish(inbox, state, package, record, {"audio/a.wav": b"A", "audio/b.wav": b"B"}, kind="paket")
            self.assertFalse((inbox / "partial.json").exists())
            self.assertFalse((inbox / "partial/audio").exists())
            record["payload"]["_kienzlefon"]["audio_beilage"] = delivery.ERROR
            self.assertEqual(delivery.publish(inbox, state, fallback, record, {}, kind="ersatz"), [fallback])
            self.assertEqual(json.loads((inbox / "partial.json").read_bytes()), record)
            self.assertFalse(any((inbox / "partial").iterdir()))

    def test_resume_before_or_after_atomic_publication_and_purge(self):
        for published in (False, True):
            with self.subTest(published=published), tempfile.TemporaryDirectory() as folder:
                root = Path(folder)
                inbox, state = root / "inbox", root / "state"
                inbox.mkdir()
                ack = delivery.delivery_ack("test.kienzlepaket", "a" * 64, "abgelegt")
                record = {"payload": {"_kienzlefon": {"vorgang_id": "test"}}}
                wavs = {"audio/a.wav": b"first", "audio/b.wav": b"second"}
                target = inbox / "test.json"
                complete = delivery.complete_pending

                def interrupt(directory, journal, current):
                    self.assertFalse(target.exists())
                    staged_audio = inbox / "test/.delivery-audio"
                    self.assertEqual({p.name: p.read_bytes() for p in staged_audio.iterdir()},
                                     {"a.wav": b"first", "b.wav": b"second"})
                    if published:
                        staged_audio.rename(inbox / "test/audio")
                        (directory / ".test.delivery-pending").replace(target)
                        target.unlink()
                        for file in (inbox / "test/audio").iterdir():
                            file.unlink()
                    raise OSError("simulierter Abbruch")

                with mock.patch.object(delivery, "complete_pending", side_effect=interrupt):
                    with self.assertRaises(OSError):
                        delivery.publish(inbox, state, ack, record, wavs, kind="paket")
                self.assertFalse(json.loads((state / "test.json").read_text())["complete"])
                with mock.patch.object(delivery, "complete_pending", wraps=complete) as resumed:
                    self.assertEqual(delivery.publish(inbox, state, ack, record, wavs, kind="paket"), [ack])
                    resumed.assert_called_once()
                self.assertEqual(target.exists(), not published)
                self.assertEqual((inbox / "test/audio/a.wav").exists(), not published)
                self.assertFalse((inbox / ".test.delivery-pending").exists())
                self.assertTrue(json.loads((state / "test.json").read_text())["complete"])

    def test_archive_checks_id_references_wav_content_and_file_set(self):
        fixture = HELPER.parent / "docs/vorgang-v1/beispiele/mehrere-anliegen.json"
        record = json.loads(fixture.read_bytes())
        meta = record["payload"]["_kienzlefon"]
        path = meta["audio"][0]["datei"]
        data = io.BytesIO()
        with wave.open(data, "wb") as wav:
            wav.setparams((1, 2, 8000, 0, "NONE", ""))
            wav.writeframes(b"\0\1" * 8)
        def archive(files):
            output = io.BytesIO()
            with ZipFile(output, "w") as zipped:
                zipped.writestr("vorgang.json", json.dumps(record))
                for name, content in files.items():
                    zipped.writestr(name, content)
            return output.getvalue()
        valid = {path: data.getvalue()}
        self.assertEqual(delivery.inspect_archive(archive(valid), meta["vorgang_id"]), (record, valid))
        for files, identifier in ((valid, "other"), ({}, meta["vorgang_id"]),
                                  ({**valid, "audio/extra.wav": b""}, meta["vorgang_id"]),
                                  ({path: data.getvalue()[:-2]}, meta["vorgang_id"])):
            with self.subTest(files=list(files), identifier=identifier), self.assertRaises(ValueError):
                delivery.inspect_archive(archive(files), identifier)
        meta["audio"][0]["anliegen_id"] = "unbekannt"
        with self.assertRaises(ValueError):
            delivery.inspect_archive(archive(valid), meta["vorgang_id"])

    def test_plain_retry_preserves_item_status_and_does_not_restore_deleted_case(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            inbox, state = root / "inbox", root / "state"
            inbox.mkdir()
            identifier = "ai-20261118"
            ack = delivery.delivery_ack(f"{identifier}.json.enc", "a" * 64, "abgelegt")
            document = {"payload": {"_kienzlefon": {"vorgang_id": identifier, "audio": []}}}
            target = inbox / f"{identifier}.json"

            delivery.publish(inbox, state, ack, document, {}, kind="json")
            saved = json.loads(target.read_text())
            saved["app"] = {"anliegen_status": {"a-001": {"benutzer": "Test"}}}
            target.write_text(json.dumps(saved))

            self.assertEqual(delivery.publish(inbox, state, ack, document, {}, kind="json"), [ack])
            self.assertEqual(json.loads(target.read_text()), saved)

            newer_hash = delivery.delivery_ack(f"{identifier}.json.enc", "b" * 64, "abgelegt")
            fallback = {"payload": {"_kienzlefon": {"vorgang_id": identifier, "audio": [],
                                                     "audio_beilage": delivery.ERROR}}}
            self.assertEqual(delivery.publish(inbox, state, newer_hash, fallback, {}, kind="ersatz"), [newer_hash])
            with_error = json.loads(target.read_text())
            self.assertEqual(with_error["app"], saved["app"])
            self.assertEqual(with_error["payload"]["_kienzlefon"]["audio_beilage"], delivery.ERROR)

            target.unlink()
            delivery.publish(inbox, state, ack, document, {}, kind="json")
            self.assertFalse(target.exists())
