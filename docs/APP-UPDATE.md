# kienzlefon app aktualisieren

`kienzlefon-app-update-v1.0.sh` aktualisiert eine bestehende Installation auf dem
Zielsystem. Der bisherige `zielserver-vorbereiten-v1.8.sh` bleibt fuer die
Einrichtung zustaendig. Ab App 3.8 ergänzt der Updater die systemd-Units für
die Rufnummernauflösung und aktiviert den Timer. Benutzer, Gruppen, Schlüssel,
nginx-Konfiguration und Systempakete verwaltet weiterhin der Zielserver-Installer.

## Voraussetzungen und Umfang

Vorausgesetzt werden Bash, Python 3 und PHP CLI ab 8.1 mit curl, DOM, SimpleXML,
PDO, PDO-SQLite, Tokenizer, JSON und OpenSSL. Im normalen Betrieb werden Dienste
ueber systemd gesteuert; fuer die Pruefung laufender SMS-Worker wird `pgrep`
benoetigt.
Der ausfuehrende Benutzer muss die Programmdateien ersetzen und die betreffenden
Dienste stoppen/starten duerfen; auf dem Zielsystem normalerweise `sudo` nutzen.
PHP CLI und Web-PHP muessen kompatibel konfiguriert sein.

Für erkannte Abrufkanäle prüft der Updater vor dem Austausch Bash, OpenSSH-Client,
OpenSSL, `jq`, `od` und den Audio-Helfer mit dem lokalen Python 3 und
`cryptography`. Unter Debian/Ubuntu liefern `python3-cryptography`, `jq`,
`openssh-client`, `openssl` und `coreutils` die Laufzeit. Fehlende Pakete vor dem
Update bereitstellen. Als Abholer gelten die vom Zielsetup installierten
`telepraxis_fetch_and_decrypt_<kanal>.sh` unter `/usr/local/bin` oder `--fetch-dir`.
Ihre SSH-Ziele, Ports, Schlüsselpfade, Inboxen, Abrufintervalle, Einstellungen
für die bisherige JSON-Bereinigung und konfigurierten Nachweisverzeichnisse
werden übernommen. Abholskripte und der gemeinsame Audio-Helfer werden unter
`fetch/` im Update-Manifest geführt. Jeder zuvor aktive Fetch-Dienst wird für
den Austausch angehalten und danach wieder gestartet. Bei `--no-service`
übernimmt der Betreiber die Wartung auch für diese Dienste.

Im Webroot muessen `telepraxis-app.php`, `telepraxis-sms.php` und `sms-config.php`
bereits vorhanden sein. Der Updater installiert auch `telepraxis-sms-queue.php`,
`telepraxis-telephony.php`, `telepraxis-telephony.js` und
`telepraxis-telephony-schema.json`. Diese vier Dateien dürfen bei einem älteren
Zielstand erstmals hinzukommen. Die bisherigen Einstellungen für Queue und
Telefonie bestimmen weiterhin, ob die Funktionen aktiv sind.

Erhalten bleiben:

- Inbox, Vorgangsdateien, Kommentare, Papierkorb und sonstige Nutzdaten.
- SMS-Konfiguration in der bestehenden JSON-Datei, einschliesslich Provider,
  Zugangsdaten, Antworttexte und Queue-Pfad.
- SQLite-Datenbanken und ihre Journaldateien, Worker-Konfiguration und Schluessel.
- In der App: `TELEPRAXIS_INBOX_DIR`, `TELEPRAXIS_ADMIN_PASSWORD`,
  `TELEPRAXIS_POLL_INTERVAL_MS`, `TELEPRAXIS_DEFAULT_TIMEZONE` und
  `TELEPRAXIS_WORKPLACE_MAXLEN`. Falls im bisherigen Stand vorhanden, werden
  auch `TELEPRAXIS_USER_MAXLEN`, die beiden `TELEPRAXIS_T2MED_*`-Konstanten,
  `TELEPRAXIS_RECORDINGS_BASE_URL` und `TELEPRAXIS_AUDIO_RETENTION_DAYS` übernommen.
- In SMS-Bibliothek und SMS-Konfiguration: `TP_SMS_CREDENTIALS_FILE`; in
  `sms-config.php` ausserdem `TP_SMS_CONFIG_ADMIN_PASSWORD`.
- Die bisherigen Eigentuemer, Gruppen und Dateimodi der ersetzten Dateien.

Die Konstantausdruecke werden aus PHP-Tokens uebernommen, ohne die bestehende
App auszufuehren. Relative Ausdruecke wie `__DIR__` bleiben erhalten. Fehlende
oder mehrdeutige benoetigte Deklarationen fuehren zum Abbruch. Weitere manuelle
Codeaenderungen in den zu ersetzenden PHP-Dateien werden nicht zusammengefuehrt;
sie sind gegebenenfalls vorher in den gewaehlten Quellstand zu uebernehmen.
Versionsnummer und Produktname stammen aus diesem Quellstand.

## Lokaler Quellstand

Im entpackten App-Paket verwendet der Updater automatisch die mitgelieferten Dateien. Mit `--source-dir` kann ein anderes vollstaendiges Quellverzeichnis gewaehlt werden. Quelle
und installierter Webroot muessen getrennte Verzeichnisse sein:

```sh
sudo bash kienzlefon-app-update-v1.0.sh --source-dir "$PWD" --check
sudo bash kienzlefon-app-update-v1.0.sh --source-dir "$PWD"
```

`--check` bereitet die Kandidaten in einem privaten temporaeren Verzeichnis vor
und prueft sie. Es ersetzt keine installierten Dateien, legt keine dauerhafte
Sicherung an und stoppt oder startet keine Dienste. Beim eigentlichen Update
wird vor dem Austausch eine Bestaetigung verlangt; `--yes` bestaetigt den
angezeigten Ablauf fuer unbeaufsichtigte Aufrufe.

Abweichenden Webroot oder PHP-Dienst explizit angeben:

```sh
sudo bash kienzlefon-app-update-v1.0.sh \
  --source-dir "$PWD" \
  --webroot /var/www/html \
  --web-service php8.2-fpm.service
```

Den Dienstnamen an die Installation anpassen. Ohne Angabe sucht der Updater
einen eindeutig erkennbaren laufenden PHP-FPM- oder Apache-Dienst. Bei mehreren
moeglichen Diensten ist die explizite Angabe erforderlich. Waehrend des
Dateiaustauschs ist die App kurz nicht erreichbar. Ein Neustart des PHP-Dienstes
verhindert, dass ein alter OPcache-Stand weiterverwendet wird.

## Veroeffentlichten Stand laden

Nach Veroeffentlichung der zusammengehoerigen Dateien kann direkt aus dem
vorhandenen GitHub-Repository aktualisiert werden:

```sh
sudo bash kienzlefon-app-update-v1.0.sh --ref main --check
sudo bash kienzlefon-app-update-v1.0.sh --ref main
```

Der Updater laedt ein einzelnes HTTPS-Archiv aus
`thomaskien/IONOS_AI_Receptionist_Interface_Arztpraxis`. Statt `main` kann
`--ref` einen freigegebenen Tag oder Commit enthalten. Ein Commit legt den
Quellstand fuer Prueflauf und Update eindeutig fest. Ein lokaler, noch nicht
gepushter Stand ist online nicht verfuegbar. Ein unvollstaendiges oder
fehlerhaftes Archiv wird vor dem Austausch abgelehnt.

## Separater SMS-Worker

Ein vorhandener Worker in `/opt/telepraxis-sms` wird beruecksichtigt. Bei
abweichender Installation Verzeichnis und Dienst angeben:

```sh
sudo bash kienzlefon-app-update-v1.0.sh \
  --source-dir "$PWD" \
  --worker-dir /opt/telepraxis-sms \
  --worker-service telepraxis-sms-worker.service
```

Der Updater aktualisiert den Worker zusammen mit der Webapp. Zum Worker gehören
`telepraxis-sms-worker.php`, `telepraxis-sms.php`, `telepraxis-sms-queue.php`
sowie alle drei Empfangsmodule. Er prüft auch bei einem reinen Versandworker
alle sechs Quelldateien vor dem Austausch. Fehlende Empfangsmodule im
Worker-Zielverzeichnis installiert er mit den Rechten der dortigen SMS-Bibliothek.
Ob der Empfang aktiviert ist, bestimmt weiterhin die lokale Worker-Konfiguration.
Der eigene `TP_SMS_CREDENTIALS_FILE`-Wert des Workers bleibt erhalten.
Der Updater stoppt laufende verwaltete Dienste vor dem Austausch und startet
anschliessend nur die zuvor laufenden Dienste wieder. Er richtet keinen neuen
SMS-Dienst ein und fuehrt weder `--once` noch einen Testversand aus.

Bei aktiviertem Empfang werden `telepraxis-sms-journal.php`,
`telepraxis-sms-receive-store.php` und `telepraxis-sms-receive.php` gemeinsam
mit den übrigen Worker-Dateien geprüft und vor dem Wiederstart installiert.
So verwenden App und Empfangsspeicher die Inbox-Sperre desselben Stands.
Die Vorgangszuordnung ist im Projekt `ionos` unter `docs/SMS-EMPFANG.md` beschrieben.

Manuell gestartete Worker oder Prozesse in anderen Verzeichnissen muessen
vorher separat angehalten werden. `--no-service` ist ausschliesslich fuer einen
bereits extern sichergestellten Wartungsmodus gedacht: Alle Web-PHP- und
SMS-Worker-Prozesse muessen gestoppt sein und danach extern neu gestartet werden.
Der Updater prueft diesen externen Wartungsmodus nicht.

Der wieder gestartete Worker nimmt seinen normalen Betrieb auf und kann
vorgemerkte SMS senden. Die neue Queue-Bibliothek kann beim regulaeren Betrieb
das Datenbankschema migrieren. Der Updater selbst oeffnet oder migriert keine
Queue-Datenbank. Hinweise zu deren separater Sicherung stehen im Projekt
`ionos` unter `docs/SMS-QUEUE.md`.

## Sicherung und Fehlerfall

Standardmaessig liegen Sicherungen unter `/var/backups/kienzlefon-app`;
`--backup-dir` waehlt einen anderen geschuetzten Ort ausserhalb von Webroot und
Worker-Verzeichnis. Ein bereits vorhandenes Sicherungsverzeichnis muss dem
ausfuehrenden Benutzer gehoeren und Modus `0700` haben; der Updater aendert seine
Rechte nicht nachtraeglich. Jede Sicherung enthaelt Originaldateien und ein Manifest
mit Zielpfaden und Pruefsummen. Sie kann eingebettete Adminpasswoerter enthalten
und darf nicht oeffentlich zugaenglich sein.

Alle PHP-Kandidaten werden vor dem Austausch auf Syntax geprüft, das Telefonieschema auf gültiges JSON. Scheitert der
Austausch oder ein anschliessender Dienststart, versucht der Updater, die
Originaldateien und vorherigen Dienstzustaende wiederherzustellen. Erstmals
installierte Zusatzdateien werden dabei wieder entfernt. Ruecksetzfehler
werden gemeldet; Sicherungen bleiben fuer eine manuelle Wiederherstellung
erhalten. Der ausgegebene Sicherungspfad gehoert deshalb zum Updateprotokoll.
Laesst sich ein Dienst nach einem fehlgeschlagenen Neustart nicht mehr stoppen,
wird keine Ruecksetzung unter laufenden Prozessen erzwungen. Bei unvollstaendiger
Ruecksetzung werden Dienste nicht automatisch erneut gestartet.

Der Austausch ist pro Datei atomar, aber keine stromausfallsichere Transaktion
ueber alle Dateien und Dienste. Bei hartem Prozessabbruch oder Stromausfall
den Dienstzustand und den vollstaendigen Dateisatz anhand des Manifests pruefen,
bevor der Betrieb fortgesetzt wird. Die Programmsicherung ersetzt keine
Datensicherung und setzt ein inzwischen migriertes Queue-Schema nicht zurueck.

## Lokale Tests

Im Projektordner `ionos` ausführen.

```sh
bash -n kienzlefon-app-update-v1.0.sh
python3 tests/test_app_update.py
python3 tests/test_app_package.py
```

Die Tests verwenden temporaere Installationen und simulierte Dienste. Sie
benoetigen weder Rootrechte noch Netzwerkzugriff und versenden keine SMS.

## Arbeitsplatztelefonie und T2med-Aktentreffer ab App 3.8

Ab App 3.8.2 gilt die Arbeitsplatzfreigabe der gekoppelten Anlage für die Haken **Normal** und **ROT**. Bei „alle Arbeitsplätze“ und leeren Standardlisten genügt die bestehende Praxis-App-Sitzung. Die App liest die Freigabe auch beim Absenden und beim Ergebnisabruf. Die Haken folgen den je Rufgruppe erlaubten Schaltrichtungen.

Für namentlich ausgewählte Plätze kann der Praxis-Webserver die FastCGI-/Servervariable `KIENZLEFON_WORKPLACE_ID` anhand seiner vertrauenswürdigen Arbeitsplatzzuordnung setzen, beispielsweise auf `201`. Ohne Zuordnung erhält die Sitzung eine vom App-Server erzeugte Kennung. Das frei editierbare Feld „Telefon“ (bis 3.8.2 „Platz“) bleibt dem eigenen Telefon und der Vorgangsbearbeitung zugeordnet. Eine bestehende App-Liste oder der explizite Modus `selected` schränkt die Anlagenfreigabe zusätzlich ein.

App 3.8.1 und Middleware 1.5.8 zeigen gefundene Akten als „Nachname, Vorname“.
Der Rufnummerndienst ergänzt die Namensfelder auch bei bereits gespeicherten
Treffern in der Inbox. Dazu gleicht er den Bestand einmal mit T2med ab und
übernimmt die Felder nur für denselben Treffer.

Die vollständige Arbeitsplatzanbindung benötigt Middleware 1.5.7 und deren eigene Zugänge für die Rufnummernsuche und die Bedienung. Der Middleware-Installer erzeugt die Schlüssel bei der App-Anbindung und installiert sie bei einer lokalen App für den Webdienst. Bei getrennter Installation die beiden privaten Begleitdateien mit den Endungen `.lookup` und `.workplace` über denselben geschützten Weg wie die App-Schlüsseldatei übertragen und zusammen per `--token-file` einrichten. Der bisherige Übersichtsschlüssel behält seinen Umfang.

Erstinstallation und reguläres App-Update richten den Timer für offene Vorgänge
ein. Vor dem Dateiaustausch hält der Updater einen laufenden Timer und dessen
Dienst an. Er berücksichtigt vorhandene Units und administrative Maskierungen.
Bei externer Dienstverwaltung mit `--no-service` verwaltet der Betreiber auch
diesen Timer. Für eine bestehende Standardinstallation genügt im App-Paket
folgender Aufruf zur nachträglichen Einrichtung.

```sh
sudo bash zielserver-vorbereiten-v1.8.sh --install-lookup-timer
```

Alle 15 Sekunden prüft der Timer die lokale Inbox. Ein Suchergebnis pro Nummer bleibt beim Vorgang unter `app.rufnummernaufloesung`. Ein Ausfall lässt offene Suchen für den nächsten Lauf stehen. Auch bestehende neue und in Bearbeitung befindliche Vorgänge werden berücksichtigt. Für Geburtsdaten aus dem FHIR-Verzeichnis den üblichen Verzeichnisabgleich in T2med nach dem Importer-Update einmal ausführen. Die AI-Bubble benötigt den aktuellen Control-Dienst der Telefonanlage, der den Aktivierungszustand mitsendet.
