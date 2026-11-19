# Gemeinsamer Telefonievertrag v1

Stand **18.11.2026**, Vertragsfassung **1.0.2**, `schema_version: 1`.
Verbindliche Grundlage für die gemeinsame Umsetzung; **kein Nachweis einer
bereits installierten Funktion**. Ersetzt die früheren Schnittstellenentwürfe
zur Nebenstellensteuerung. Produktversionen bleiben davon unabhängig.

| Bestandteil | Festlegung |
| --- | --- |
| Transport, Endpunkte, Berechtigungen | [HTTP und Zugriff](HTTP.md) |
| Mitgliedschaften, Aufträge, Neustarts | [Rufgruppen](ROUTING.md) |
| Live-Belegung und Tagesstatistik | [Zähler](COUNTERS.md) |
| Exakte JSON-Typen | [schema.json](schema.json), JSON Schema 2020-12 |
| Gemeinsame Beispiele und Abnahme | [fixtures.json](fixtures.json), [Abnahme](ACCEPTANCE.md) |

Die Dateien dieses Verzeichnisses bilden ein zusammengehöriges Vertragspaket.
Die maßgebliche Quelle liegt in `kienzlefon-t2med/docs/telefonie-v1`; identische
Kopien liegen in den beiden anderen Projekten. `SHA256SUMS` dient zum Abgleich.
Änderungen zuerst an der Quelle vornehmen und danach das gesamte Paket verteilen.
Keine voneinander abweichenden Schemakopien pflegen.

In 1.0.2 prüft der Validator für jede Nebenstelle und Gruppe, dass bei
unbekannter Mitgliedschaft oder nicht verfügbarer Gruppe beide Schaltrechte
gesperrt sind. Zwei Negativbeispiele decken diese Fälle ab.

Korrekturen in 1.0.1: HTTP-Teilfehler nach Antwortweg eindeutig geregelt;
Datumsprüfung verpflichtend gemacht; Altersgrenze für Nebenstellen und
Konsistenz aktueller Anlagenlaufzeiten mit negativen Beispielen abgesichert.
Die fachlichen Funktionen und `schema_version: 1` bleiben erhalten.

## Zuständigkeiten

```text
Browser → App-PHP-Proxy → Middleware → Kienzlefon-Steuerungsdienst → Telefonanlage
                           ↑                    ↑
                    eigenes Telefonmenü    Administration
```

- **Kienzlefon**: gemeinsame Verwaltung für Administration und Maschinenzugriff;
  administrative Grundeinstellungen, Rechte, laufzeitgebundene Teilnahmen,
  bestätigte Anwendung, vollständiger Live-Schnappschuss, Statistikereignisse.
- **Middleware**: Zustände zusammenführen, Tagesstatistik speichern, Aufträge
  vermitteln, eigenes Telefonmenü anbinden. Kein eigener bearbeitbarer Bestand
  von Rufzuordnungen und keine direkten Queue-Schreibbefehle als Ersatz.
- **App**: eigener PHP-Proxy und Darstellung; keine eigene Zählung oder
  verbindliche Speicherung von Rufzuordnungen. Bestehendes Design und getrennte
  T2med-Anbindung erhalten.

## Kompatibilität und gemeinsame Grundregeln

UTF-8-JSON, Zeitangaben RFC 3339 mit Offset, IDs immer Zeichenketten.
`null` bedeutet unbekannt, niemals automatisch `false` oder `0`.
Neue Antworten dürfen zusätzliche Felder erhalten; Leser ignorieren unbekannte
Antwortfelder. Schreibobjekte erlauben ausschließlich die im Schema genannten
Felder. Unbekannte Gruppen und Auftragszustände sperren die betreffende Aktion.
Antworten mit unbekanntem `schema_version` werden nicht interpretiert. Die App
sperrt bei einer unbekannten Overview-Version die gesamte neue Integration.
Die Middleware behandelt eine unbekannte Version ihrer Control-Quelle gemäß
[HTTP und Zugriff](HTTP.md#übersicht-und-teilfehler) als Ausfall der abhängigen
Teilzustände; unabhängig gültige Daten bleiben in ihrem eigenen Overview erhalten.

Das Schema prüft die genaue Produzentenausgabe dieser Vertragsfassung. Neue
Implementierungen dürfen es nicht als Grund verwenden, sonst kompatible
Antworten nur wegen zusätzlicher Felder abzulehnen. Der App-Proxy bildet trotzdem
immer eine ausdrückliche Feldprojektion; unbekannte Rohfelder nicht durchreichen.

Die Middleware ergänzt die bestehende `GET /api/v1/telephony`-Antwort additiv
um `schema_version`, `capabilities`, `live_counters`, `routing_control` und die
beschriebenen Routingfelder an ihren Nebenstellen. Bestehende Felder, darunter
`calls`, `queues`, `extensions[].paused` und `call_statistics`, bleiben erhalten.
`paused` ist **keine** Rufgruppenmitgliedschaft.

Die App erhält die datensparsame Projektion `Overview` über den neuen Endpunkt
`GET /api/v1/telephony/overview`. Sie enthält nur die im Schema benannten Felder,
insbesondere **keine** Patienten, Rufnummern, Kanalnamen oder Gesprächslisten.
Der App-Dienstschlüssel darf den bisherigen vollständigen Telefonie-Endpunkt
nicht aufrufen. Beide Ansichten verwenden dieselben bestätigten Zustandsquellen.

`capabilities` zeigt implementierte Unterstützung, nicht Erreichbarkeit oder
Freigabe: `live_counters`, `routing_control`, `call_statistics` sind unabhängige
Booleans. Ausfall setzt Zustandsstatus, nicht eine bekannte Fähigkeit zurück.
Alte Gegenstelle/HTTP 404: neue Funktionen als nicht unterstützt anzeigen;
vorhandene Wahl- und Aktenlinks bleiben benutzbar. Kein Ausweichen auf andere
Schreibmethoden oder pauschale AMI-Berechtigungen.

## Umsetzung je Projekt

| Projekt | Einbaupunkte und Lieferumfang |
| --- | --- |
| Kienzlefon | `config.py`, `middleware.py`, `webadmin.py`, `webinterface/admin.php`: Richtlinie und geschützter Dienst. `asterisk.py`, `ivr.py`, `queue_dispatch.py`, `queue_dialplan.py`: tatsächliche Rufangebote, Live-Phasen und Ereignisse. |
| Middleware | `Config.php`, `Runtime.php`, `HttpApi.php`: Dienstclient, enger App-Zugriff, Projektion, Auftragsjournal. `public/app.js`/`style.css`: Häkchen und bestätigte Ergebnisse; bestehendes Anklicken der Nebenstelle wählt weiterhin. |
| App | Separates `telepraxis-telephony.php` als Proxy, serverseitige Konfiguration außerhalb des Webroots, vereinbarte Ergänzungen der bestätigten `telepraxis-app.php`-Ausgangsdatei. Keine Änderungen an anderen App-Funktionen. |

Lieferreihenfolge: Kienzlefon-Dienst und Ereignisse → Middleware → App/eigenes
Telefonmenü → gemeinsame Abnahme. Bis zur Einrichtung bleiben Fernänderungen
deaktiviert. Installer prüfen Netzwerkverbindung, Fähigkeiten, Identität und
Schlüssel; sie erhalten bestehende Konfigurationen und Tagesstatistik.
Die neue Definition ändert noch keine Produktivkonfiguration.
