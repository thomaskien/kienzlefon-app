"""Synthetische Vertragsprüfung mit RFC-7520-Text auf stdin. Kein Produktivcode."""
import argparse
import base64
import fnmatch
import hashlib
import io
import json
import math
import os
from pathlib import Path
import re
import shutil
import stat
import struct
import subprocess
import sys
import tempfile
import wave
from zipfile import ZIP_STORED, ZipFile, ZipInfo
from zoneinfo import ZoneInfo

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

HERE = Path(__file__).resolve().parent
APP = HERE.parent.parent
KIENZLEFON = APP.parent / 'kienzlefon'
EXAMPLES = KIENZLEFON / 'docs/vorgang-beispiele'
ID = re.compile(r'[A-Za-z0-9_-]{1,64}')
WAV_PATH = re.compile(r'audio/[A-Za-z0-9_-]+\.wav')
HEADER = {'typ': 'kienzlefon-paket-v1+jwe', 'cty': 'application/zip',
          'alg': 'RSA-OAEP-256', 'enc': 'A256GCM'}
KEY_FIELDS = ('kty', 'n', 'e', 'd', 'p', 'q', 'dp', 'dq', 'qi')


def hex_sha256(data):
    return hashlib.sha256(data).hexdigest()


def b64url(data):
    return base64.urlsafe_b64encode(data).rstrip(b'=')


def decode(value):
    return base64.urlsafe_b64decode(value + '=' * (-len(value) % 4))


def test_keys(source):
    candidates = re.findall(r'\{[^{}]+\}\s*Figure 4: RSA 2048-Bit Private Key', source, re.S)
    if len(candidates) != 1:
        raise ValueError('Bitte RFC 7520 als Text auf stdin übergeben, siehe README.')
    fields = dict(re.findall(r'"(\w+)"\s*:\s*"([^"]+)"', candidates[0]))
    if fields.get('kid') != 'bilbo.baggins@hobbiton.example':
        raise ValueError('Erwartet wird der öffentliche RFC-Testvektor, Figure 4.')
    jwk = {name: ''.join(fields[name].split()) for name in KEY_FIELDS}
    values = {name: int.from_bytes(decode(jwk[name]), 'big') for name in KEY_FIELDS[1:]}
    public = rsa.RSAPublicNumbers(values['e'], values['n'])
    private = rsa.RSAPrivateNumbers(values['p'], values['q'], values['d'],
                                    values['dp'], values['dq'], values['qi'], public).private_key()
    assert private.key_size == 2048
    return jwk, private


def tone_wav():
    out = io.BytesIO()
    rate = 8000
    samples = [round(8000 * math.sin(2 * math.pi * 440 * i / rate)) for i in range(1600)]
    with wave.open(out, 'wb') as wav:
        wav.setparams((1, 2, rate, 0, 'NONE', 'PCM'))
        wav.writeframes(struct.pack('<' + 'h' * len(samples), *samples))
    return out.getvalue()


def zip_bytes(files):
    out = io.BytesIO()
    with ZipFile(out, 'w', ZIP_STORED) as archive:
        for path, data in files.items():
            info = ZipInfo(path, (2026, 11, 18, 10, 0, 0))
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o600) << 16
            archive.writestr(info, data)
    return out.getvalue()


def seal(zip_payload, public):
    protected = b64url(json.dumps(HEADER, separators=(',', ':')).encode())
    key, nonce = os.urandom(32), os.urandom(12)
    wrapped = public.encrypt(key, padding.OAEP(
        mgf=padding.MGF1(hashes.SHA256()), algorithm=hashes.SHA256(), label=None))
    encrypted = AESGCM(key).encrypt(nonce, zip_payload, protected)
    return b'.'.join((protected, *(b64url(part) for part in (wrapped, nonce, encrypted[:-16], encrypted[-16:]))))


def read_jwe(jwe, jwk, node, *, expected_ok=True):
    result = subprocess.run([node, str(HERE / 'jwe-leser.cjs')],
                            input=json.dumps({'jwe': jwe.decode(), 'key': jwk}).encode(),
                            capture_output=True, timeout=10)
    if expected_ok:
        result.check_returncode()
        return result.stdout
    assert result.returncode != 0 and not result.stdout, 'JWE mit falschem GCM-Tag akzeptiert'


def validate_vorgang(document):
    payload = document['payload']
    if document['typ'] != 'mehrere_anliegen':
        return set()
    if payload['typ'] != document['typ'] or type(payload.get('anliegen_schema_version')) is not int \
            or payload['anliegen_schema_version'] != 1:
        raise ValueError('Unbekanntes Anliegenformat')
    if not ID.fullmatch(payload['_kienzlefon']['vorgang_id']):
        raise ValueError('Ungültige Vorgangs-ID')
    items = payload['anliegen_liste']
    ids = {item['anliegen_id'] for item in items}
    if len(items) < 2 or len(ids) != len(items) or any(not ID.fullmatch(i) for i in ids):
        raise ValueError('Ungültige oder doppelte Teilanliegen-ID')
    if any(not isinstance(item.get('typ'), str) or not item['typ'].strip() for item in items):
        raise ValueError('Ungültiger Typ')
    return ids


def inspect_zip(plaintext, filename):
    with ZipFile(io.BytesIO(plaintext)) as archive:
        entries = archive.infolist()
        paths = {info.filename for info in entries}
        if 'vorgang.json' not in paths or len(paths) != len(entries):
            raise ValueError('JSON fehlt oder doppelter ZIP-Eintrag')
        for info in entries:
            mode = (info.external_attr >> 16) & 0o170000
            if info.compress_type != ZIP_STORED or mode not in (0, stat.S_IFREG) or (
                info.filename != 'vorgang.json' and not WAV_PATH.fullmatch(info.filename)
            ):
                raise ValueError('Unerlaubter ZIP-Eintrag')
        record_bytes = archive.read('vorgang.json')
        record = json.loads(record_bytes.decode('utf8'))
        ids = validate_vorgang(record)
        meta = record['payload']['_kienzlefon']
        if not ID.fullmatch(meta['vorgang_id']) or filename != meta['vorgang_id'] + '.kienzlepaket':
            raise ValueError('Vorgangs-ID stimmt nicht mit Dateiname überein')
        wavs = {}
        for entry in meta['audio']:
            path = entry['datei']
            if path not in paths or not WAV_PATH.fullmatch(path) or path in wavs:
                raise ValueError('Fehlende oder doppelte WAV')
            if 'anliegen_id' in entry and entry['anliegen_id'] not in ids:
                raise ValueError('Unbekanntes Teilanliegen der WAV')
            audio = archive.read(path)
            with wave.open(io.BytesIO(audio), 'rb') as wav:
                if wav.getnframes() == 0 or not wav.readframes(wav.getnframes()):
                    raise ValueError('Leere WAV')
            wavs[path] = audio
        if paths != {'vorgang.json', *wavs}:
            raise ValueError('Unangekündigte Datei')
        return record_bytes, wavs


def rejection(fn, reason):
    try:
        fn()
    except ValueError as error:
        assert reason in str(error), error
    else:
        raise AssertionError(f'Negativfall akzeptiert: {reason}')


def receipt(filename, encrypted, status):
    return {'v': 1, 'vorgang_id': filename.split('.')[0], 'datei': filename,
            'sha256': hex_sha256(encrypted), 'status': status}


def can_clean(ack, filename, encrypted):
    return ack == receipt(filename, encrypted, 'abgelegt')


def legacy_encrypt(private, inputs, example):
    # Kienzlefon erstellt die v1-Hülle mit seinem unveränderten Produktiv-Encoder.
    sys.path.insert(0, str(KIENZLEFON))
    from kienzlefon.crypto import TelepraxisEncryptor
    public_path = inputs / 'test-public.pem'
    public_path.write_bytes(private.public_key().public_bytes(
        serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo))
    encryptor = TelepraxisEncryptor(public_path, inputs, ZoneInfo('Europe/Berlin'))
    return (json.dumps(encryptor.encrypt(example), indent=2) + '\n').encode()


def legacy_decrypt(private, inputs, filename, content):
    # Nur die vorhandene Entschlüsselungsfunktion laden. Das SSH-Hauptprogramm
    # bleibt deaktiviert, und der Prüfschlüssel wird über eine Pipe gelesen.
    source = (APP / 'telepraxis_fetch_and_decrypt.sh').read_text()
    assert source.rstrip().endswith('main "$@"')
    local_script = inputs / 'decrypt-functions.sh'
    local_script.write_text(source.rstrip().removesuffix('main "$@"'))
    enc = inputs / filename
    enc.write_bytes(content)
    out = inputs / 'decoded'
    out.mkdir(exist_ok=True)
    read_fd, write_fd = os.pipe()
    try:
        with os.fdopen(write_fd, 'wb') as pipe:
            pipe.write(private.private_bytes(serialization.Encoding.PEM,
                serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
        subprocess.run(['bash', '-c', 'source "$1"; PRIVATE_KEY_FILE="$2"; decrypt_one_file "$3" "$4"',
                        'vertragspruefung', str(local_script), f'/dev/fd/{read_fd}', str(enc), str(out)],
                       pass_fds=(read_fd,), check=True, capture_output=True, timeout=10)
    finally:
        os.close(read_fd)
    return (out / filename.removesuffix('.enc')).read_bytes()


def app_list(site):
    result = subprocess.run(['php', '-r',
        'session_save_path($argv[1]); $_REQUEST["ajax"] = "list"; require $argv[2];',
        str(site), str(site / 'telepraxis-app.php')], capture_output=True, timeout=10)
    if result.returncode:
        raise RuntimeError(result.stderr.decode(errors='replace'))
    return json.loads(result.stdout)['entries']


def check_local_inbox(record, wavs, case_id, work):
    site = work / 'site'
    site.mkdir()
    for name in ('telepraxis-app.php', 'telepraxis-sms.php', 'telepraxis-sms-queue.php'):
        shutil.copyfile(APP / name, site / name)
    inbox = site / 'inbox'
    inbox.mkdir()
    for name, content in wavs.items():
        target = inbox / case_id / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)
    assert app_list(site) == [], 'App listete Audio-Unterordner als Vorgang'
    staged = inbox / '.test-import.tmp'
    staged.write_bytes(record)
    staged.replace(inbox / (case_id + '.json'))
    entries = app_list(site)
    assert [entry['file'] for entry in entries] == [case_id + '.json']


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--erstellen', action='store_true', help='Neue verschlüsselte Prüfmuster anlegen')
    parser.add_argument('--node', default='node')
    args = parser.parse_args()
    jwk, private = test_keys(sys.stdin.read())
    public = private.public_key()
    original = (EXAMPLES / 'mehrere-anliegen.json').read_bytes()
    record = json.loads(original)
    case_id = record['payload']['_kienzlefon']['vorgang_id']
    assert validate_vorgang(record) == {'a-001', 'a-002', 'a-003'}
    assert record['payload']['_kienzlefon']['audio'][0]['anliegen_id'] == 'a-002'
    bad_ids = json.loads((EXAMPLES / 'ungueltige-anliegen-ids.json').read_bytes())
    rejection(lambda: validate_vorgang(bad_ids), 'Teilanliegen-ID')
    wav_path = record['payload']['_kienzlefon']['audio'][0]['datei']
    wav = tone_wav()
    package_name = f'{case_id}.kienzlepaket'
    fallback_name = f'{case_id}.json.enc'
    fallback = (EXAMPLES / 'audio-fehler.json').read_bytes()
    legacy = (EXAMPLES / 'alt-ein-anliegen.json').read_bytes()
    error_case = json.loads(fallback)
    assert validate_vorgang(error_case) == validate_vorgang(record)
    assert error_case['payload']['anliegen_liste'] == record['payload']['anliegen_liste']
    assert error_case['payload']['_kienzlefon']['vorgang_id'] == case_id
    assert error_case['payload']['_kienzlefon']['audio'] == []
    assert error_case['payload']['_kienzlefon']['audio_beilage'] == {
        'schema_version': 1, 'status': 'fehler', 'fehlercode': 'audio_uebergabe_fehlgeschlagen'}
    assert validate_vorgang(json.loads(legacy)) == set()
    assert not fnmatch.fnmatchcase(package_name, '*.enc') and fnmatch.fnmatchcase(fallback_name, '*.enc')

    with tempfile.TemporaryDirectory(prefix='kf-paket-v1-') as directory:
        work = Path(directory)
        if args.erstellen:
            for path, build in [
                (HERE / package_name, lambda: seal(zip_bytes({'vorgang.json': original, wav_path: wav}), public)),
                (HERE / fallback_name, lambda: legacy_encrypt(private, work, fallback)),
            ]:
                if not path.exists():
                    with path.open('xb') as handle:
                        handle.write(build())
        package = (HERE / package_name).read_bytes()
        fallback_enc = (HERE / fallback_name).read_bytes()
        decoded, wavs = inspect_zip(read_jwe(package, jwk, args.node), package_name)
        assert decoded == original and wavs == {wav_path: wav}
        print('JWE-Rundlauf Python → Node: JSON und WAV bytegleich, Zuordnung zu a-002 bestätigt.')

        broken = package.split(b'.')
        tag = bytearray(decode(broken[-1].decode()))
        tag[0] ^= 1
        broken[-1] = b64url(tag)
        read_jwe(b'.'.join(broken), jwk, args.node, expected_ok=False)
        missing = seal(zip_bytes({'vorgang.json': original}), public)
        rejection(lambda: inspect_zip(read_jwe(missing, jwk, args.node), package_name), 'Fehlende')
        extra = zip_bytes({'vorgang.json': original, wav_path: wav, 'audio/extra.wav': wav})
        rejection(lambda: inspect_zip(extra, package_name), 'Unangekündigte')
        rejection(lambda: inspect_zip(read_jwe(package, jwk, args.node), 'anderer-vorgang.kienzlepaket'), 'Vorgangs-ID')
        print('Negativfälle: falscher GCM-Tag, fehlende/zusätzliche WAV, falsche Vorgangs-ID und doppelte Teil-ID abgewiesen.')

        check_local_inbox(decoded, wavs, case_id, work)
        assert legacy_decrypt(private, work, fallback_name, fallback_enc) == fallback
        legacy_enc = legacy_encrypt(private, work, legacy)
        assert legacy_decrypt(private, work, 'alt-ein-anliegen.json.enc', legacy_enc) == legacy
        print('Bestehender PHP-Listenaufruf liest genau einen Vorgang; bestehender Bash-Abholer entschlüsselt Alt- und Ersatz-JSON bytegleich.')

        good_ack = receipt(package_name, package, 'abgelegt')
        ack_path = HERE / f'{case_id}.{good_ack["sha256"]}.abgelegt.ack.json'
        if args.erstellen and not ack_path.exists():
            with ack_path.open('x', encoding='utf8') as handle:
                json.dump(good_ack, handle, indent=2)
                handle.write('\n')
        assert can_clean(json.loads(ack_path.read_text()), package_name, package)
        assert not can_clean(receipt(package_name, package, 'ersatz_json_noetig'), package_name, package)
        assert not can_clean(good_ack, fallback_name, fallback_enc)
        assert not can_clean(dict(good_ack, sha256='0' * 64), package_name, package)
        assert can_clean(receipt(fallback_name, fallback_enc, 'abgelegt'), fallback_name, fallback_enc)
        print('Quittung passt zu Dateiname und SHA-256; Fehlerquittung und fremder Hash geben keine Bereinigung frei.')
        print(f'Paket-SHA256 {hex_sha256(package)}')
        print(f'Ersatz-JSON-SHA256 {hex_sha256(fallback_enc)}')


if __name__ == '__main__':
    main()
