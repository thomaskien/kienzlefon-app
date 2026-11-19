# Gemeinsame Beispiele und Abnahme

`fixtures.json` enthält vollständig synthetische Nachrichten. `schema` bezeichnet
jeweils die Definition in `schema.json`, `valid` die erwartete Schema-Gültigkeit.
`semantic_error` benennt bei einem strukturell gültigen negativen Beispiel den
erwarteten Konsistenzfehler; ohne dieses Feld müssen auch die Inhaltsregeln gelten.
`scenarios.json` ergänzt verbindliche semantische Abläufe; Schema-Gültigkeit
allein beweist weder Rechte noch reale Anwendung.

Prüfung: In einer Entwicklungsumgebung zunächst
`python3 -m pip install -r docs/telefonie-v1/requirements.txt`, dann
`python3 docs/telefonie-v1/validate.py` ausführen. `jsonschema[format]` ist
verpflichtend; der Prüfer bricht bei fehlender Datumsprüfung ab. Die negative
Ablaufzeit `not-a-date+01:00` darf nicht als RFC-3339-Zeit akzeptiert werden.
Der Prüfer validiert Schemata, positive
und negative Nachrichten sowie Konsistenz der Beispieldaten; er sendet keine
Anfragen und ersetzt die unten verlangten Implementierungstests nicht.

Die Beispiele prüfen auch 9999/10000 ms als Grenze für einen aktuellen
Nebenstellenstatus sowie übereinstimmende aktuelle Epochs in `ControlState`
und `Overview`. Die HTTP-Fehlerfälle referenzieren konkrete Antwortbeispiele
und prüfen deren Status-/Antwortform; sie rufen keine realen Endpunkte auf.

## Kienzlefon liefert

1. Geschützte `/control/v1/`-Routen, sichere Annahme und Auftragsjournal,
   gemeinsame Admin-/Routingprüfung, Epoch-Erkennung und Laufzeitabgleich.
2. Administrative Gruppe/Eignung/Standardteilnahme/beide Rechte/Rufstufe;
   Migration erhält bisherige Zuordnungen, neue Fernrechte standardmäßig aus.
3. Normal-Angebote in den bestehenden Rufstufen; Fachstellen-Fallback nur eigene
   aktive Mitglieder, gemeinsames Klingeln nach dem separaten Rottelefon-Versuch.
4. Vollständige Live-Erfassung sowie bestehende Tages-/Wegeereignisse.
5. Eigene Tests für bestätigte Anwendung, unbekannte Wirkung, Rechteentzug,
   konkurrierende letzte Austritte, Anlagen- und reine Dienstneustarts.

## Middleware liefert

1. Begrenzten Control-Client ohne allgemeine Admin-/AMI-Schreibfreigabe,
   Identitätsprüfung, Aktualitätsregeln und restartfestes Auftragsjournal.
2. Additive vollständige Telefonieantwort und datensparsame Overview-Projektion;
   App-Schlüssel kann keine Patienten, Gespräche oder anderen Aktionen abrufen.
3. Beide Häkchen im eigenen Telefonmenü; Klick darauf löst keinen Wählauftrag aus.
4. Unabhängige Fehlerzustände von Tagesstatistik, Live-Zahlen, Nebenstellen und
   Routing. Der bestehende FHIR-/GDT-/Vermittlungsbetrieb bleibt funktionsfähig.

## App liefert

1. Festen Same-Origin-PHP-Proxy mit serverseitigem Schlüssel und privatem
   Verbindungsweg zur Middleware; CSRF- und bestehender App-Zugriffsschutz.
2. Bubbles für Warteschleife, IVR, AI und Anrufe heute im bestehenden Design;
   Wege-Tooltip und `—`/`≈` wie vereinbart. Kein zusätzlicher großer Statusblock.
3. Nebenstellen im ausklappbaren Bereich; Name, Status, Nummer und zwei Häkchen.
   Bezeichnungen Normal/ROT und gegebenenfalls aktuelle IVR-Taste aus dem Vertrag verwenden.
4. Während eines Auftrags den letzten bestätigten Haken erhalten, „wird geändert“
   anzeigen und Zielpaar sperren. Ergebnis abfragen; erst bei `applied` und neuem
   Overview aktuellen Zustand anzeigen. Historischer Erfolg ersetzt keinen
   aktuellen Zustand. Arbeitsplatzwechsel ändert bereits gesendete Aufträge nicht.
5. Nach Reload offene IDs aus `sessionStorage` nur nachfragen, niemals erneut
   ausführen. Keine dauerhafte lokale Gruppenwahrheit oder Dienstschlüssel.
   Fehler verständlich zeigen; bei Konflikt Zustand neu laden und Benutzerwahl
   erneut abwarten, keine automatische Umkehr oder neue Schreib-ID.

## Verbindliche Integrationstests

| Fall | Erwartung |
| --- | --- |
| IVR → verbundene AI → Queue → Mensch → Hangup | Pro Phase höchstens eine Live-Zählung; Tageszahl 1, Wege jeweils einmal |
| Anonym, erneuter Anruf derselben Nummer, frühes Auflegen | Je Eingang genau einmal; keine Abhängigkeit von Nummer oder Patientenlookup |
| Interner Ruf, Click-to-Dial, Fax, Local-Zusatzkanäle | Kein zusätzlicher externer Live-/Tageseingang |
| Queue klingelt, aber noch nicht angenommen | `waiting` bleibt 1; weder AI noch Mensch aus bloßem `Up` ableiten |
| Reconnect, fehlender Hangup, Neuaufbau | Vollständiger Bestand entfernt Phantom-Livezähler; Tageslücke bleibt gekennzeichnet |
| Nicht erfasster Kanal/defekte Quelle | Live-Zahlen null und unavailable, niemals 0 vortäuschen |
| Nur Steuerungsdienst ausgefallen | Routing/Live unverfügbar; separat funktionsfähige Statistik/Telefonie bleibt erhalten |
| Control-Anmeldung oder Control-Antwort fehlerhaft | Middleware-Overview HTTP 200 mit unbekannten abhängigen Teilen; gültige Tagesstatistik bleibt sichtbar |
| App-Proxy erhält kein gültiges Middleware-Overview | 503 mit passendem Fehler, bei Transport-Timeout 504; keine erfundene Übersicht |
| Schreibantwort nach möglichem Versand fehlerhaft | POST 202/unknown, GET des bekannten Auftrags 200/unknown; keine erneute Schreib-ID |
| Ungültige Ablaufzeit, alter Frei-Status, gemischte aktuelle Epochs | Schema-/Konsistenzprüfung weist die Daten zurück; kein aktueller Status oder Schreibrecht daraus |
| Gleiche ID/gleicher Inhalt, danach anderer Inhalt | Ein Auftrag; beim anderen Inhalt 409/request_id_conflict |
| Zwei Änderungen zur selben Revision | Erste wirksame Änderung erhöht Revision; zweite wird konfliktfrei abgewiesen, nicht überschrieben |
| Zwei letzte Austritte / Rechteentzug vor Ausführung | Mindestens ein Teilnehmer bleibt; widerrufene Änderung wird nicht ausgeführt |
| Antwort nach tatsächlicher Anwendung verloren | Derselbe Auftrag liefert nach Abgleich applied, keine zweite Ausführung |
| Ausfall vor/zwischen Speicherung und Anwendung | Sicher unberührt oder unknown; niemals fälschlich applied/rejected |
| Ablauf / neuer Asterisk-Prozess | Keine späte Anwendung; Standards aktiv, alte Aufträge nicht wiederhergestellt |
| Nur Middleware-/Control-Dienst neu gestartet | Gleiche Epoch und laufende Teilnahmen bleiben; Journale verhindern Wiederholung |
| Standard/Einzelrecht administrativ geändert | Dieselbe Revision/Teilnahme in allen Ansichten; Rechteänderung allein verändert keine Teilnahme |
| Rottelefon/Normal/Fachstellen unabhängig | Erster Rot-Versuch erhalten; Fallback ausschließlich eigene aktive Fachstellenmitglieder |
| ROT ohne aktiviertes rotes Telefon | Unmittelbar gemeinsames Klingeln der aktiven ROT-Mitglieder |
| Unbekannter, gefälschter oder nicht erlaubter Client | Keine erweiterte Berechtigung durch Benutzer-/Platzfelder |
| App-Schlüssel auf FHIR/Patienten/Wahl/vollem Telefonie-GET | Abgewiesen; keine Datenweitergabe |
| Fremder Origin, fehlendes CSRF, beliebige Proxy-URL | Abgewiesen; keine offene Weiterleitung |
| Alte Gegenstelle, falsche pbx_id, Schema-Version unbekannt | Neue Funktionen gesperrt, vorhandene Funktionen unverändert |
| Veraltete Antwort nach neuer Antwort / Browser verborgen | Kein Zurückspringen; nach 10 s keine aktuellen Live-Zahlen/Schreibrechte |
| FHIR-Import, Update, Sommer-/Winterzeit, Mitternacht | Statistik bleibt erhalten; richtige Tageszuordnung, keine Neuzählung |

Vor Freigabe dieselben Fixtures in allen drei Projekten verwenden und die
Integration mit synthetischen Anrufen gegen einen echten Asterisk-Testbestand
prüfen. Dokument-/Schemaprüfungen sind noch keine solche Laufzeitabnahme.
