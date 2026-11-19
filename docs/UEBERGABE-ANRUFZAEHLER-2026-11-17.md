# Übergabe Tageszähler Anrufe heute

Stand: 17.11.2026

Zielprojekt: `thomaskien/kienzlefon-t2med`

Auftrag: Einen dauerhaften Zähler eingehender Anrufe in der Middleware implementieren und über die bestehende Telefonie-API bereitstellen.

## Ziel und Umfang

Die kienzlefon app erhält in ihrer bisherigen Zählerleiste eine zusätzliche Bubble **Anrufe heute**. Die Zahl gilt für die Praxis insgesamt und ist unabhängig vom ausgewählten Benutzer oder Platz. Die Middleware liefert die Zahl; der Browser zählt nicht selbst.

Der Auftrag umfasst Erfassung, dauerhafte Speicherung, API, Konfiguration, Tests und Dokumentation im Middleware-Projekt. Der Einbau der Bubble erfolgt anschließend in der kienzlefon app. Die daneben geplanten Live-Zähler für Warteschleife, IVR und AI sind eigenständige Werte und werden nicht zum Tageszähler addiert.

## Ausgangspunkt im Middleware-Projekt

Geprüfter lokaler Ausgangsstand: Commit `c8b2781`. Vor der Umsetzung den tatsächlichen aktuellen Stand und dessen Projektanweisungen prüfen. Im vorhandenen Arbeitsbaum liegen bereits fremde Änderungen; diese erhalten und einen isolierten Arbeitsstand für die Implementierung verwenden.

| Vorhandene Stelle | Bedeutung für den Auftrag |
| --- | --- |
| `src/HttpApi.php`, `GET /api/v1/telephony` | Liefert bereits die Ausgabe von `Runtime::view()`. Diesen Vertrag additiv erweitern. |
| `src/Runtime.php`, `start()` und `synchronize()` | AMI-Ereignisse, Verbindungswechsel und Abgleich über `CoreShowChannels` und `QueueStatus`. |
| `src/Telephony.php`, `event()`, `finishSync()` und `view()` | Hält Kanäle, Warteschlangen und Nebenstellen im Arbeitsspeicher. `disconnect()` verwirft den aktuellen Telefoniezustand. |
| `src/Telephony.php`, Gruppierung in `view()` | Erzeugt Anzeige-IDs aus `Linkedid`, teilweise ergänzt um eine Kanal-ID. Diese Anzeige-IDs sind kein geeigneter dauerhafter Zählschlüssel. |
| `src/Config.php`, `config.example.toml`, `config.example.json`, `installer/` | Speicherort, Aktivierung und Voraussetzungen sauber integrieren. |
| `tests/php/run.php`, `docs/ARCHITECTURE.md`, `docs/TESTING.md` | Vorhandenes Testgerüst und API-Dokumentation fortführen. |

Im geprüften API-Stand gibt es keinen Tageszähler. Eine Momentaufnahme laufender Gespräche enthält keine bereits beendeten Anrufe und kann deshalb keinen Tagesgesamtwert ersetzen.

## Zählregeln

1. Gezählt wird jeder **neu eingehende externe Anruf an einem konfigurierten Praxisanschluss** genau einmal, sobald er die Telefonanlage erreicht und als eingehender Anruf erkannt ist.
2. Es zählt auch ein Anruf, der vor Annahme abgebrochen wird, nur die Warteschleife erreicht oder ausschließlich mit IVR beziehungsweise AI spricht. Die Annahme durch einen Menschen ist keine Voraussetzung.
3. Interne Gespräche, ausgehende Gespräche, Rückrufe und Click-to-Dial-Aufträge erhöhen diesen Zähler nicht.
4. Ein Wechsel zwischen IVR, AI, Warteschleife und Nebenstelle sowie direkte Vermittlung oder Rückfrage erzeugen keine zusätzliche Zählung des ursprünglichen eingehenden Anrufs. Technische Zusatzkanäle und Local-Kanalpaare ebenfalls nicht.
5. Ruft dieselbe Person erneut an, ist das ein neuer Anruf. Die Rufnummer ist kein Deduplizierungsschlüssel. Anonyme Anrufe zählen genauso.
6. Maßgeblich ist der Tag des ursprünglichen Anrufeingangs in **Europe/Berlin**. Ein um 23:59 eingegangener Anruf bleibt dem Vortag zugeordnet, auch wenn er nach Mitternacht weiterläuft oder vermittelt wird.
7. Der Tageswechsel erfolgt anhand des lokalen Kalenderdatums. Sommer- und Winterzeit korrekt behandeln; ein Tag ist nicht immer 86.400 Sekunden lang. Ein Cron-Reset um Mitternacht ist nicht erforderlich.
8. Neuladen, mehrere Browser, API-Abfragen, JSON-Importe, mehrere Anliegen in einer JSON und das Abschließen oder Löschen von App-Vorgängen verändern die Zahl nicht.

## Erfassung und dauerhafte Speicherung

Eine kleine eigene Komponente für den Zähler vorsehen, beispielsweise `src/CallStatistics.php`. Die Verarbeitung von Anrufereignissen schreibt den Zustand; `view()` und HTTP-GETs lesen ihn ausschließlich. Die bestehende Telefoniegruppierung und Vermittlungslogik erhalten.

**Die Erfassungsquelle vor dem Einbau festlegen:** Eingehende Anrufe müssen anhand des tatsächlichen externen Eintritts zuverlässig erkennbar sein, etwa über die vorhandene Trunk-/Kontextkonfiguration oder einen eindeutigen Ereignismarker aus dem Dialplan. Nicht aus einer fehlenden internen Nebenstelle, einer Telefonnummer oder `state = connected` auf einen neuen Eingang schließen. Die vorhandenen AMI-Ereignisse und die Praxis-Konfiguration dafür prüfen. Falls ein zusätzlicher Dialplan-Marker notwendig ist, dessen Vertrag und die benötigte Änderung im kienzlefon-Projekt konkret benennen; keine vorhandene Unterstützung behaupten.

Pro ursprünglichem Eingang einen stabilen technischen Schlüssel verwenden, der über weitere Kanäle, Vermittlungen, Wiederholungen und Neustarts erhalten bleibt. `Linkedid` kann zur Zuordnung helfen, muss aber gegen die tatsächlichen Ereignisfolgen geprüft werden. Den Schlüssel mit einer stabilen Kennung der Telefonanlage kombinieren; Wiederverwendung von IDs nach einem Anlagenneustart berücksichtigen. Die veränderliche Anzeige-ID aus `Telephony::view()` nicht übernehmen.

Für die Speicherung ist eine separate SQLite-Datei unter dem bestehenden Datenverzeichnis vorgesehen, beispielsweise:

```text
/var/lib/kienzlefon-t2med/call-statistics.sqlite
```

Die Datenbank enthält nur technische Anrufkennungen, Eingangszeitpunkte, Tageszuordnung und Informationen zur Vollständigkeit. Telefonnummern, Patientennamen, Gesprächsinhalte und rohe AMI-Ereignisse sind dafür nicht nötig. Die Statistikdatei darf nicht Bestandteil der bei FHIR-Importen ersetzten Patientendatenbank sein.

Folgende Eigenschaften sind erforderlich:

- Eindeutiger Datenbankschlüssel pro ursprünglichem Eingang; Einfügen und Zählen transaktional. Wiederholung desselben Ereignisses bleibt wirkungslos.
- Eingangszeit intern eindeutig speichern, vorzugsweise in UTC; Tagesgrenzen mit `Europe/Berlin` bestimmen. Nachgelieferte Ereignisse ihrem tatsächlichen Eingangstag zuordnen.
- Ein erfolgreicher Schreibvorgang übersteht Prozess- und Serverneustarts. Ein AMI-Reconnect setzt die Statistik nicht zurück.
- Reconnect-Snapshots, gepufferte Ereignisse während der Synchronisierung und spätere Nachlieferungen dürfen keine Doppelzählung erzeugen.
- Datenbankfehler unterbrechen die normale Telefonie-API nicht. Die Statistik meldet ihre Nichtverfügbarkeit separat; interne Pfade und Datenbankfehler bleiben in serverseitigen Diagnosemeldungen.
- Kurze Datenbanktransaktionen mit begrenzten Wartezeiten verwenden, damit der ReactPHP-Eventloop bei einer Sperre nicht länger blockiert.
- Aufbewahrung dokumentieren. Zählschlüssel nicht entfernen, solange ein Anruf noch aktiv ist oder Ereignisse innerhalb des unterstützten Nachlieferungszeitraums erneut eintreffen können.

## Vollständigkeit bei Start und Ausfällen

Persistenz verhindert den Verlust bereits gezählter Anrufe. Sie rekonstruiert jedoch keine Anrufe, die während eines Middleware-/AMI-Ausfalls vollständig begonnen und geendet haben.

Wenn eine geeignete dauerhafte Quelle vorhanden ist, etwa ein vollständiges Anrufjournal, kann sie solche Lücken schließen. Zuvor prüfen, ob sie ursprüngliche Eingänge, Zeitpunkte und passende Identitäten zuverlässig liefert. Nachlieferung muss denselben Zählschlüssel verwenden und auch bereits per Live-Ereignis erfasste Anrufe erkennen.

Ohne eine solche Quelle gilt:

- Erstmaliger Start mitten am Tag: bekannte Eingänge zählen, den Tageswert aber als **unvollständig** kennzeichnen.
- Verbindungs- oder Prozessausfall: den betroffenen Zeitraum dauerhaft als Erfassungslücke festhalten. Nach einem Neustart auch eine unvollständige letzte Erfassungssitzung erkennen.
- Ein erfolgreicher Snapshot oder Reconnect macht einen unvollständigen Tageswert nicht automatisch wieder vollständig.
- Ein über Mitternacht reichender Ausfall betrifft beide Kalendertage. Ein vollständig erfasster späterer Tag kann wieder vollständig sein.
- Bereits vor einem Neustart bekannte, noch laufende Anrufe nicht erneut zählen. Für erstmals im Snapshot erkannte Anrufe den ursprünglichen Eingangstag nur aus belastbaren Daten bestimmen; Unklarheiten nicht dem heutigen Tag zuschlagen.

Die Kennzeichnung der Tagesstatistik ist unabhängig vom vorhandenen `stale`-Feld des Live-Zustands. Nach einem Reconnect kann die Live-Anzeige aktuell sein, während im Tageswert weiterhin Anrufe fehlen.

## API-Vertrag für die kienzlefon app

Den vorhandenen **`GET /api/v1/telephony`** um `call_statistics.today` ergänzen. Bestehende Felder und deren Bedeutung erhalten. Für diesen Auftrag ist kein zusätzlicher Endpunkt erforderlich.

Beispiel des neuen Teilobjekts; die übrigen Telefoniefelder stehen unverändert daneben:

```json
{
  "call_statistics": {
    "today": {
      "date": "2026-11-17",
      "timezone": "Europe/Berlin",
      "period_start": "2026-11-17T00:00:00+01:00",
      "period_end": "2026-11-18T00:00:00+01:00",
      "incoming_calls": 47,
      "status": "complete",
      "as_of": "2026-11-17T10:30:00+01:00",
      "reason_code": null
    }
  }
}
```

`period_start` ist inklusive, `period_end` exklusive. `as_of` bezeichnet den Stand der ausgewerteten Erfassung und wird während einer Datenlücke nicht bloß durch HTTP-Abfragen weitergesetzt. Unbekannter Stand wird als `null` ausgegeben. Alle Zeitstempel enthalten einen Offset; UTC-Zeitstempel sind ebenfalls zulässig.

| `status` | `incoming_calls` | Bedeutung und spätere Anzeige |
| --- | --- | --- |
| `complete` | Ganzzahl ≥ 0 | Alle relevanten Eingänge seit Tagesbeginn bis `as_of` sind erfasst. Bubble beispielsweise `Anrufe heute: 47`. |
| `partial` | Ganzzahl ≥ 0 | Belastbar erfasste Mindestzahl, aber der Tag enthält eine Lücke. Bubble beispielsweise `Anrufe heute: ≥47` mit Hinweis „unvollständig“. |
| `unavailable` | `null` | Keine belastbare Zahl verfügbar, zum Beispiel Statistik deaktiviert oder Datenbank nicht lesbar. Bubble `Anrufe heute: —`. |

Für `complete` ist `reason_code = null`. Für die anderen Zustände stabile technische Codes verwenden, mindestens `not_configured`, `initial_partial_day`, `capture_gap`, `storage_unavailable`; ihre Zuordnung dokumentieren. Keine Stacktraces oder freien Datenbankfehlermeldungen in die Antwort aufnehmen.

`0` bedeutet ausschließlich eine tatsächlich bekannte Anzahl null. Eine nicht vorhandene API-Erweiterung, eine deaktivierte Erfassung oder ein Speicherfehler darf nicht als null Anrufe erscheinen. Ältere Middleware-Versionen ohne `call_statistics` behandelt die App später als „nicht verfügbar“.

Demo-Modus: feste synthetische Werte liefern und niemals die produktive Statistikdatei öffnen oder verändern. Die bestehenden Prüfungen für Host, Netzwerk, Origin und API-Token beibehalten. CORS für diesen Auftrag nicht pauschal freigeben; die spätere Einbindung in die App berücksichtigt den bestehenden API-Zugriffsschutz.

## Konfiguration und Installation

Eine optionale Konfigurationssektion ergänzen. Vorschlag:

```toml
[call_statistics]
enabled = true
database_path = "/var/lib/kienzlefon-t2med/call-statistics.sqlite"
timezone = "Europe/Berlin"
```

Die stabile Anlagenkennung und erforderliche Einstellungen zur Erfassungsquelle zusätzlich passend zur vorhandenen Konfiguration definieren. Vorhandene Konfigurationen ohne den neuen Abschnitt bleiben gültig; bis zur eingerichteten Erfassung liefert die API `unavailable` und `not_configured`.

Installer und Konfigurationsprüfung berücksichtigen benötigte PHP-SQLite-Unterstützung, Dienstbenutzer, Datenbankverzeichnis und Schreibrechte einschließlich eventueller SQLite-Journaldateien. Upgrades erhalten die Statistik. Produktive Daten liegen außerhalb von Webroot und GDT-Freigabe. Tests verwenden temporäre Verzeichnisse.

## Abnahmekriterien und Tests

Eine steuerbare Uhr und synthetische Ereignisse verwenden. Die neuen Fälle in den vorhandenen PHP-Testlauf aufnehmen, beispielsweise über `tests/php/call_statistics.php`. Es sind keine echten Anrufe oder Patientendaten erforderlich.

| Testfall | Erwartung |
| --- | --- |
| Ein externer Eingang, beliebig viele Browser-Abfragen | Zähler genau 1; GET verändert weder Zähler noch Datenbank. |
| Wiederholung desselben Ereignisses und Snapshot-Replay | Weiterhin 1, auch nach Prozessneustart. |
| IVR → AI → Warteschleife → Nebenstelle → Vermittlung/Rückfrage | Derselbe Eingang bleibt genau 1 trotz zusätzlicher Kanäle und Brücken. |
| Zwei Eingänge derselben Rufnummer; zwei anonyme Eingänge | Jeweils 2, unabhängig von der Rufnummer. |
| Interner Ruf, ausgehender Ruf und Click-to-Dial | Keine Erhöhung. |
| Eingang mit anschließendem Abbruch vor Annahme | Erhöhung um 1. |
| Eingang 23:59, Weiterleitung 00:01, späterer Reconnect | Nur dem ursprünglichen Tag zugeordnet; keine neue Zählung heute. |
| Sommerzeitbeginn und Sommerzeitende | Korrekte lokale Tagesgrenzen für 23- beziehungsweise 25-Stunden-Tage. |
| Erster Start mittags ohne vollständige Historie | `partial`, auch wenn der bekannte Zähler zunächst 0 ist. |
| AMI-/Prozessausfall und anschließender erfolgreicher Snapshot | Gespeicherter Wert erhalten; offene Lücke bleibt `partial`. |
| Vollständige Nachlieferung einer Lücke, falls unterstützt | Fehlende Eingänge einmal ergänzen; erst nach belegter Schließung aller Lücken `complete`. Ohne unterstützte Nachlieferung bleibt die Lücke ausdrücklich bestehen. |
| Nachgelieferter Anruf vom Vortag | Heutigen Zähler nicht erhöhen. |
| Gleichzeitige oder wiederholte Verarbeitung, Transaktionsabbruch | Keine Doppelzählung und kein inkonsistenter Zwischenstand. |
| Fehlerhafte oder gesperrte Datenbank | Kontrollierter Statistikstatus; übrige Telefonie-API funktioniert weiter. |
| FHIR-Import und Middleware-Update | Statistik und Zählschlüssel erhalten. |
| Alte Konfiguration, Demo-Modus, unzulässiger API-Zugriff | Abwärtskompatibel; Demo ohne Produktivzugriff; bestehender Zugriffsschutz bleibt wirksam. |

Im Zielprojekt mindestens ausführen:

```sh
php tests/php/run.php
php tests/installer/run.php
python3 -m unittest discover -s tests/integration -v
python3 -m unittest discover -s tests/installer -v
git diff --check
```

Jede geänderte/neue PHP-Datei zusätzlich mit `php -l <datei>` prüfen; geänderte Shell-Skripte mit `bash -n <datei>`. Falls `installer/install.sh` angepasst wird, auch den vorhandenen lokalen Prüflauf `bash installer/install.sh --dry-run` ausführen. Bestehende fehlschlagende Tests vom Ausgangsstand unterscheiden und nachvollziehbar dokumentieren.

## Erwarteter Abschlussbericht

- Welche Quelle einen ursprünglichen eingehenden Anruf erkennt und welcher Schlüssel die einmalige Zählung sicherstellt.
- Welche Ausfallzeiträume nachträglich geschlossen werden können und wann `partial` bestehen bleibt.
- Endgültiger API-Vertrag mit Beispielen für vollständigen, unvollständigen und nicht verfügbaren Zustand.
- Geänderte Dateien, Konfiguration, Installation, Aufbewahrung und Testresultate mit Exit-Codes.
- Falls erforderlich: konkreter Folgeauftrag für den Dialplan statt einer nur angenommenen Ereignisquelle.

Diese Übergabe beauftragt die Implementierung und Prüfung im Middleware-Projekt. Commits, Pushes, Versionsfreigabe und Deployment erfolgen nach dem dortigen ausdrücklichen Auftrag.
