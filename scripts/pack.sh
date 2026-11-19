#!/usr/bin/env bash
# v1.1 – lokales Komponentenpaket mit dem Audio-Helfer für den SSH-Abholer.
set -euo pipefail

if [[ $# != 2 || $1 != --output || $2 != /* ]]; then
  echo "Aufruf: bash scripts/pack.sh --output /absoluter/pfad/kienzlefon-app.tar.gz" >&2
  exit 2
fi
CDPATH= cd -P -- "$(dirname -- "$0")/.."
exec python3 - "$2" <<'PY'
import os
import sys
import tarfile
import tempfile
from pathlib import Path

component = "kienzlefon-app"
root = Path.cwd()
output = Path(sys.argv[1])
files = """
scripts/pack.sh
docs/INSTALLER.md
docs/APP-UPDATE.md
zielserver-vorbereiten-v1.8.sh
quellserver-vorbereiten-nginx-v1.2.sh
quellserver-benutzer-erzeugen-v1.8.sh
kienzlefon-app-update-v1.0.sh
telepraxis_fetch_and_decrypt.sh
telepraxis-audio-delivery.py
telepraxis-receive.php
kontakt.php
telepraxis-app.php
sms-config.php
telepraxis-sms.php
telepraxis-sms-queue.php
telepraxis-sms-worker.php
telepraxis-sms-journal.php
telepraxis-sms-receive-store.php
telepraxis-sms-receive.php
telepraxis-sms-inspect.php
telepraxis-telephony.php
telepraxis-telephony.js
telepraxis-telephony-schema.json
config/telephony-config.example.php
config/sms-queue-web.example.json
config/sms-queue-worker.example.json
config/telepraxis-sms-worker.service
""".split()

for name in files:
    path = root / name
    if path.resolve() != path or not path.is_file() or not path.stat().st_size:
        sys.exit(f"Paketdatei fehlt, ist leer oder ein Symlink: {name}")
    if output.resolve() == path:
        sys.exit(f"Ausgabe würde eine Paketdatei überschreiben: {name}")

# Only publish a complete archive. No macOS resource forks or checkout owners.
with tempfile.TemporaryDirectory(dir=output.parent, prefix=".kienzlefon-app-") as temp:
    staged = Path(temp) / "archive.tar.gz"
    with tarfile.open(staged, "w:gz", format=tarfile.USTAR_FORMAT, dereference=True) as archive:
        top = tarfile.TarInfo(component)
        top.type = tarfile.DIRTYPE
        top.mode = 0o755
        archive.addfile(top)
        for name in files:
            item = archive.gettarinfo(name, arcname=f"{component}/{name}")
            item.uid = item.gid = 0
            item.uname = item.gname = "root"
            item.mode = 0o755 if name.endswith(".sh") else 0o644
            with open(name, "rb") as stream:
                archive.addfile(item, stream)
    os.replace(staged, output)
print(f"Paket erstellt: {output}")
PY
