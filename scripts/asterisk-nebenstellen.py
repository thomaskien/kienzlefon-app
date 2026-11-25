"""Publish only numeric PJSIP endpoints from the local PBX to the web app."""
import json
import os
import re
import subprocess
import tempfile
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

SNAPSHOT = Path("/run/kienzlefon-app-asterisk/overview.json")
STATES = {"not in use": "idle", "unavailable": "offline", "in use": "busy", "busy": "busy",
          "on hold": "busy", "ringing": "ringing", "ring+inuse": "ringing"}


def overview(output, now):
    stamp = now.isoformat(timespec="milliseconds")
    day = now.astimezone(ZoneInfo("Europe/Berlin")).replace(hour=0, minute=0, second=0, microsecond=0)
    permission = dict(can_enable=False, can_disable=False, enable_reason="state_unknown", disable_reason="state_unknown")
    routing = dict(normal=None, specialist=None, permissions=dict(normal=permission, specialist=permission))
    extensions = []
    for line in output.splitlines():
        match = re.fullmatch(r"\s*Endpoint:\s+([0-9]{1,12})(?:/\S+)?\s+(.+?)\s+\d+ of (?:\d+|inf)\s*", line, re.I)
        if match:
            number, status = match.groups()
            state = STATES.get(status.strip().lower(), "unknown")
            extensions.append(dict(id=number, label=number, state=state, state_as_of=stamp,
                                   state_age_ms=0, routing=routing))
    if not extensions:
        raise ValueError("Asterisk meldet keine numerischen PJSIP-Nebenstellen.")
    groups = [dict(id=key, label=label, ivr_digit=None, available=False)
              for key, label in (("normal", "Normal"), ("specialist", "ROT"))]
    unavailable = dict(reason_code="not_supported", pbx_epoch=None, as_of=None, age_ms=None, max_age_ms=10000)
    return dict(schema_version=1, generated_at=stamp, source="live", extensions=extensions,
                capabilities=dict(live_counters=False, routing_control=False, call_statistics=False),
                live_counters=dict(unavailable, status="unavailable", waiting=None, ivr=None, ai=None),
                routing_control=dict(unavailable, supported=False, enabled=False, available=False, revision=None, groups=groups),
                call_statistics=dict(today=dict(date=day.date().isoformat(), timezone="Europe/Berlin",
                    period_start=day.isoformat(), period_end=(day + timedelta(days=1)).isoformat(),
                    incoming_calls=None, status="unavailable", as_of=None, reason_code="not_configured",
                    routes=None, unclassified_calls=None)))


def main():
    while True:
        result = subprocess.run(["/usr/sbin/asterisk", "-rx", "pjsip show endpoints"], check=True,
                                stdout=subprocess.PIPE, text=True, errors="replace", timeout=5)
        data = overview(result.stdout, datetime.now(timezone.utc))
        with tempfile.NamedTemporaryFile(dir=SNAPSHOT.parent, mode="w", delete=False) as out:
            try:
                json.dump(data, out)
                out.flush()
                os.fchmod(out.fileno(), 0o644)
                os.replace(out.name, SNAPSHOT)
            finally:
                Path(out.name).unlink(missing_ok=True)
        time.sleep(3)


if __name__ == "__main__":
    main()
