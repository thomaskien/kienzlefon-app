# AGENTS.md

## Projektkontext

Dieses Projekt umfasst die kienzlefon app (bisher telepraxis-app) und einen verschluesselten JSON-Transport zwischen Quellserver und Zielsystem. Eingehende JSON-Daten werden auf dem Quellsystem direkt verschluesselt gespeichert, vom Zielsystem per SSH/SCP abgeholt, lokal entschluesselt, validiert und anschliessend lokal sowie remote bereinigt.

Der aktuelle Architekturstand ist Mehrbenutzerbetrieb: Jeder Abrufkanal bekommt einen eigenen SSH-Benutzer auf dem Quellsystem und eine eigene Empfangsdatei `telepraxis-receive-<ssh-benutzer>.php`.

## Arbeitsweise

- Konservativ weiterarbeiten und bestehende Strukturen nicht unnoetig umbauen.
- Keine stillen Architekturwechsel vornehmen; groessere Aenderungen vorher kurz besprechen.
- Pfade, Rechte, SSH-StrictModes, php-fpm, nginx, SQLite, systemd und Mehrbenutzerlogik besonders sorgfaeltig behandeln.
- Keine Zugangsdaten, Tokens, Passwoerter, privaten Serverdaten oder privaten Keys in Dateien schreiben.
- Bei neuen Skriptversionen saubere Versionsnamen und einen kurzen Changelog im Header verwenden.
- Wenn Shell-Skripte als Ersatz geliefert werden, vollstaendige Skripte ausgeben, nicht nur Fragmente.
- Bei Unsicherheit Rueckfrage halten statt zu improvisieren oder aus aelteren Staenden zu rekonstruieren.

## Architekturregeln

- SSH-Key und RSA-Keypaar fuer Inhaltsverschluesselung strikt trennen.
- Der SSH-Key dient nur zum Abrufen und Loeschen per SSH/SCP.
- Der RSA-Public-Key liegt im PHP-Script auf dem Quellsystem.
- Der RSA-Private-Key liegt nur auf dem Zielsystem.
- JSON darf auf dem Quellsystem nicht im Klartext gespeichert werden.
- Verschluesselte Eingangsdaten werden als `*.json.enc` gespeichert.
- Das Zielsystem entschluesselt lokal, prueft SHA256 und JSON-Gueltigkeit und schreibt Klartext-JSON atomisch.

## API- und Empfangsregeln

- Fuer IONOS und Webformular wird pro Abrufkanal ein kanalbezogener Endpoint `telepraxis-receive-<ssh-benutzer>.php` verwendet; der Bindestrich vor dem Benutzernamen ist Teil des Namensschemas.
- Echte PSKs und produktive Secrets nicht in Dokumentation oder neue Quelltexte schreiben; Platzhalter wie `###CHANGE_ME_LONG_RANDOM_SECRET###` verwenden.
- IONOS-Requests werden per Header `X-TP-Token` authentifiziert und erhalten kein PHP-Rate-Limit.
- Webformular-Requests verwenden `id == "web-formular"`, OTP und Rate-Limit.
- Webformular-OTP: 24 Stunden gueltig, nur einmal nutzbar.
- Webformular-Rate-Limit: maximal 20 Requests je IP in 10 Minuten; bei Ueberschreitung HTTP 429 mit verstaendlicher JSON-Meldung.
- Keine Whitelist fuer `typ` erzwingen; alle eingehenden Typen speichern.
- Im Empfangsmodul keine strenge Pflichtfeldvalidierung erzwingen; Tool-Definitionen steuern Pflichtfelder. Es reicht, wenn wenigstens ein Nutzfeld befuellt ist.
- Dateien sicher ueber Temp-Datei plus atomarem `rename` schreiben.
- Nach aussen keine PHP-, SQLite- oder Stacktrace-Details leaken.

## Rechte- und Pfadmodell

- Das SSH-Home `/srv/telepraxis/<ssh-benutzer>` muss SSH-StrictModes-konform bleiben.
- Schreibbare App-Datenbereiche duerfen das SSH-Home nicht pauschal aufweichen.
- Inbox und OTP/State sauber vom strengen SSH-Kern trennen.
- Fuer gemeinsamen Zugriff von SSH-Benutzer und `www-data` die Gruppenloesung `tp-<ssh-benutzer>` respektieren.
- Nach Gruppen- oder Rechteaenderungen php-fpm-Neustart mitdenken.

## Wichtige Dateien

- `README.md`: Projektbeschreibung und Ablauf
- `telepraxis-app.php`: Web-App fuer eingegangene Vorgaenge
- `telepraxis-receive.php`: Vorlage fuer das Empfangsmodul fuer JSON-POSTs
- `telepraxis-receive-<ssh-benutzer>.php`: installierte Empfangsdatei pro Abrufkanal auf dem Quellsystem
- `kontakt.php`: Vorlage fuer das Webformular
- `kontakt-<ssh-benutzer>.php`: installiertes Webformular pro Abrufkanal auf dem Quellsystem
- `telepraxis_fetch_and_decrypt.sh`: Abruf und Entschluesselung auf dem Zielsystem
- `quellserver-vorbereiten-nginx-v1.2.sh`: Quellsystem vorbereiten
- `quellserver-benutzer-erzeugen-v1.8.sh`: Abrufkanal/SSH-Benutzer auf dem Quellsystem erzeugen
- `zielserver-vorbereiten-v1.6.sh`: Zielsystem pro Benutzer vorbereiten

## Regeln fuer `telepraxis-app.php`

- Immer nur auf der vom Nutzer ausdruecklich bestaetigten Ausgangsdatei aufbauen.
- Wenn Dateiinhalt, Version oder Changelog nicht zum besprochenen Stand passen, sofort nachfragen.
- Keine eigenstaendigen Zusatz- oder Rekonstruktionsaenderungen vornehmen.
- Nur exakt freigegebene Punkte aendern.
- Changelog fortfuehren und niemals entfernen.
- Neue Versionen nur nach ausdruecklicher Freigabe des Nutzers ausgeben.
- Ausgabe standardmaessig als `telepraxis-app.php`, sofern nicht anders gewuenscht.
- Funktionsverlust gegenueber der bestaetigten Basisdatei unbedingt vermeiden.
- Vor Aenderungen an der App kurz klaeren: bestaetigte Ausgangsdatei und exakt freigegebene Aenderungen.

Wichtige App-Funktionen, die erhalten bleiben muessen:

- JSON-Einlesen aus `./inbox`
- Statuswechsel Neu, In Bearbeitung, Abgeschlossen
- Soft-Delete in den Papierkorb sowie Restore/Purge
- Dringend-Markierung, Polling, Browsertitel-Zaehler und Benachrichtigungston
- Sichtbare UI-Bezeichnung `Telefon`; URL-Parameter `?arbeitsplatz=<wert>` bleibt kompatibel
- Kommentare in In Bearbeitung mit Text, `created_at` und `workplace`
- Fokus im Kommentarfeld muss Polling ueberstehen
- Druckfunktion und reine Text-Zwischenablage fuer Karten
- Karten- und Tabellenansichten fuer Neu, Abgeschlossen und Papierkorb
- Papierkorb-Overlay-/Titelleistenlogik und Admin-Sichtbarkeit
- Responsive Kopfzeile: Titelblock links und Hamburger-Menue rechts bleiben sichtbar

## Pruefung

- Shell-Skripte nach Aenderungen mit `bash -n <datei>` pruefen.
- PHP-Dateien nach Aenderungen mit `php -l <datei>` pruefen, sofern PHP verfuegbar ist.
- Bei Setup-Skripten Rechte, Gruppenmitgliedschaften, OTP-DB-Pfad, php-fpm-Neustarts, nginx-Konfiguration und systemd-User genau gegenpruefen.
- Bei `telepraxis-app.php` zusaetzlich eingebettetes JavaScript und regressionsverdaechtige Bereiche wie Header, Polling, Kommentare, Tabellen und Papierkorb gegenpruefen.
