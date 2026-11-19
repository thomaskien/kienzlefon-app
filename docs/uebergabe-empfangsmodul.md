# Uebergabe Empfangsmodul

Stand: 2026-06-25

## Ziel

Das System transportiert eingehende JSON-Daten vom Quellsystem zum Zielsystem, ohne dass die Daten auf dem Quellsystem im Klartext liegen.

- Quellsystem: nimmt POST-JSON entgegen, authentifiziert den Request und speichert nur verschluesselte Dateien.
- Zielsystem: holt `*.enc` per SSH/SCP, entschluesselt lokal, validiert Inhalt und loescht danach lokale und remote verschluesselte Zwischenfiles.
- Mehrbenutzerbetrieb: Jeder Abrufkanal bekommt einen eigenen SSH-Benutzer auf dem Quellsystem und eine eigene Empfangsdatei `telepraxis-receive-<ssh-benutzer>.php`.

## Schluesselarten

Es gibt zwei getrennte Schluesselarten:

- SSH-Key: nur fuer Abruf und Loeschen per SSH/SCP.
- RSA-Keypaar fuer Inhaltsverschluesselung: Public Key im PHP-Script auf dem Quellsystem, Private Key nur auf dem Zielsystem.

## Quellsystem

Das PHP-Empfangsmodul nimmt POST-JSON entgegen.

Authentifizierung:

- IONOS: statischer PSK im Header `X-TP-Token`.
- Webformular: `id == "web-formular"` mit OTP und Rate-Limit.

Speicherung:

- JSON wird nicht im Klartext gespeichert.
- PHP verwendet `openssl_seal()`.
- Geschrieben wird der unten beschriebene JSON-Wrapper v1.
- Dateiendung: `*.json.enc`.

### Kompatibles Format für verschlüsselte Vorgangs-JSONs

Der PHP-Empfänger und der Kienzlefon-Export schreiben dasselbe Format. Die
Datei `*.json.enc` ist ein JSON-Objekt mit diesen Feldern:

| Feld | Inhalt |
| --- | --- |
| `v` | Ganzzahl `1` für die Verschlüsselungshülle. |
| `created_at` | Zeitstempel mit UTC-Offset. |
| `cipher` | Zeichenfolge `AES-256-CBC`. |
| `sha256` | SHA-256 der UTF-8-Klartextbytes als 64-stellige Hex-Zeichenfolge. |
| `ek` | Base64 der RSA-Verschlüsselung des 32-Byte-AES-Schlüssels mit dem Public Key des Empfängers und PKCS#1 v1.5. |
| `iv` | Base64-kodierter IV von 16 Byte. |
| `ct` | Base64-kodierter AES-CBC-Chiffretext der JSON mit PKCS#7-Padding. |

Der Klartext ist das bisherige Vorgangsobjekt mit `received_at`, `remote_ip`,
`user_agent`, `typ` und `payload`. Die Prüfsumme bezieht sich auf die exakten
Bytes vor der Verschlüsselung, einschließlich vorhandener Leerzeichen und
Zeilenumbrüche. Der Abholer prüft sie vor dem Veröffentlichen der
entschlüsselten Datei. Neue Daten wie eine Anliegenliste oder der geplante
Audio-Fehlerhinweis gehören in `payload`. Sie ändern die Hülle v1 nicht.

Der PHP-POST enthält nur den Nutzinhalt. Der Empfänger baut daraus das
Vorgangsobjekt. Kienzlefon baut dieses beim direkten Export selbst und
schreibt anschließend den verschlüsselten Wrapper.

## Zielsystem

Das Fetch-Script:

- holt `*.enc` vom Quellsystem,
- entschluesselt `ek` mit dem RSA-Private-Key,
- entschluesselt `ct` mit dem daraus gewonnenen AES-Key,
- prueft SHA256,
- validiert JSON,
- schreibt das entschluesselte `*.json` atomisch,
- loescht danach lokale `.enc` und remote `.enc`.

Polling ist aktuell bewusst simpel mit 5 Sekunden vorgesehen.

## Spezifikation für den Abholer mit WAV-Beilagen

Seit 18.11.2026 gilt der
[gemeinsame Audiovertrag v1](vorgang-v1/AUDIO.md) für Kienzlefon und App.
Diese Übergabe ergänzt ihn um den Betriebskontext des Abholers. App 3.7
verarbeitet lokal abgelegte JSONs und WAV-Beilagen. Der bestehende SSH-Abholer
verarbeitet `*.json.enc`. Die Erweiterung für Vorgangspakete und Quittungen
gehört zur separaten Umsetzung des verschlüsselten Audiotransports.

### Ausgabe und Übergabe

- Der Kienzlefon-Installer fragt bei Installation, Update und Neukonfiguration,
  ob Audio beigelegt oder nur JSON ausgegeben wird. Das gilt auch beim
  Wiederverwenden der Konfiguration. Bei einem Update dient die bisherige
  Entscheidung zur Aufbewahrung als Vorgabe. Bei **Nur JSON** bleibt
  die bestehende verschlüsselte Lieferung als `*.json.enc` nutzbar.
  Kienzlefon räumt die temporären WAVs nach erfolgreicher Erkennung und
  gesicherter JSON-Ausgabe auf.
- Bei **Audio beilegen** enthält ein verschlüsseltes Vorgangspaket die
  Vorgangs-JSON und die zugehörigen WAV-Dateien. Der Abholer holt das Paket
  über den bestehenden SSH-Abrufkanal. Die Inhaltsverschlüsselung nutzt den
  Empfängerschlüssel. Der private Schlüssel bleibt auf dem Zielsystem.
- Der Abholer entschlüsselt in einem geschützten temporären Arbeitsbereich.
  Vor der Ablage prüft er die Integrität, die Vorgangs-ID, die JSON und
  sämtliche angekündigten WAV-Dateien. Die Audioverweise müssen relativ zum
  Begleitverzeichnis auf reguläre WAV-Dateien dieses Vorgangs zeigen.
- Zuerst legt der Abholer das vollständige Begleitverzeichnis in der
  konfigurierten Inbox ab. Erst danach macht er die JSON atomar sichtbar.
  Die dauerhafte Aufbewahrung erfolgt ausschließlich in diesem
  Begleitverzeichnis. Bei lokaler Ablage übernimmt Kienzlefon denselben
  Ablauf und verschiebt die WAVs aus seinem Arbeitsbereich in die Inbox.
  Die App entscheidet ab der Ablage allein über Aufbewahrung und Löschung.

### Format des verschlüsselten Vorgangspakets v1

Eine Vorgangsdatei
`<vorgang_id>.kienzlepaket` enthält genau ein verschlüsseltes ZIP-Archiv.
Die Endung liegt außerhalb des bisherigen Suchmusters `*.enc`. Der neue
Abholer erkennt sie separat. Pakete darf der Erzeuger ausgeben, sobald für
den Abrufkanal ein kompatibler Abholer eingerichtet ist.

Das ZIP enthält `vorgang.json` als UTF-8-Datei mit der bestehenden
Vorgangshülle und die vollständige Menge der zugehörigen WAVs als
`audio/<dateiname>.wav`. ZIP-Einträge werden unkomprimiert gespeichert
(`STORE`). `payload._kienzlefon.vorgang_id` entspricht der Kennung im
Paketdateinamen. Die Kennung besteht aus 1 bis 64 ASCII-Zeichen aus
Buchstaben, Ziffern, Bindestrich und Unterstrich. Die relativen Pfade
in `payload._kienzlefon.audio[].datei` bezeichnen genau die gelieferten
WAVs. Bei einem Audiosegment für ein Teilanliegen ist `anliegen_id`
der Schlüssel zu genau einem Eintrag der Anliegenliste. Gemeinsame
Segmente haben dieses Feld nicht. Der Abholer weist fehlende,
zusätzliche oder doppelte Dateieinträge, absolute Pfade, Pfade mit
Verzeichniswechseln und Links zurück.

Die Datei verwendet JWE Compact Serialization nach
[RFC 7516](https://www.rfc-editor.org/rfc/rfc7516.html#section-7.1).
Der geschützte Header enthält `typ=kienzlefon-paket-v1+jwe`,
`cty=application/zip`, `alg=RSA-OAEP-256` und `enc=A256GCM`.
Der öffentliche RSA-Schlüssel des Empfängers schützt den Inhaltsschlüssel.
Die Prüfung des GCM-Tags muss erfolgreich sein, bevor der Abholer das ZIP
öffnet. Pfade, Patienteninformationen und Audiodaten befinden sich im
verschlüsselten Teil. Die Verfahren sind in
[RFC 7518](https://www.rfc-editor.org/rfc/rfc7518.html#section-4.3) beschrieben.

Beispiel für den Paketinhalt nach Entschlüsselung und die Zuordnung in der
Inbox.

| Im ZIP | Am Ziel |
| --- | --- |
| `vorgang.json` | `inbox/<vorgang_id>.json` |
| `audio/grund.wav` | `inbox/<vorgang_id>/audio/grund.wav` |

Reine JSON-Lieferungen und verschlüsselte Ersatzlieferungen mit
Audio-Fehlerhinweis nutzen weiterhin `*.json.enc` v1. Der Fehlerhinweis
gehört in das verschlüsselte `payload`. Die Vorgangs-ID bleibt dieselbe.

### Ablagebestätigung v1

Der Abholer führt auf dem Zielsystem einen dauerhaften Nachweis je
Abrufkanal und Vorgangs-ID. Unter derselben Vorgangssperre werden die
Dateien veröffentlicht und der erfolgreiche Import vermerkt, bevor die
Quittung versendet wird. Ein abgebrochener Import wird vor einer erneuten
Veröffentlichung anhand des gespeicherten Importzustands abgeglichen.
Der Nachweis gilt auch nach dem Löschen der WAVs oder des Vorgangs,
solange noch Lieferungen wiederholt werden können. Bei Wiederholung
stellt der Abholer die vorhandene Quittung erneut bereit.

Die Quittung geht über den bestehenden, authentifizierten SSH-Abrufkanal
an Kienzlefon. Sie wird als UTF-8-JSON im separaten Quittungsverzeichnis des
Kanals temporär geschrieben und atomar veröffentlicht. Dateiname ist
`<vorgang_id>.<sha256>.<status>.ack.json`. `sha256` wird über die exakten
Bytes der **verschlüsselten Lieferdatei** gebildet. Beispiel mit einem
fiktiven Hash.

```json
{
  "v": 1,
  "vorgang_id": "20261118_100000_000002",
  "datei": "20261118_100000_000002.kienzlepaket",
  "sha256": "1111111111111111111111111111111111111111111111111111111111111111",
  "status": "abgelegt"
}
```

| Status | Bedeutung |
| --- | --- |
| `abgelegt` | Geprüftes Paket oder geprüfte Ersatz-JSON dauerhaft abgelegt. Bei einer vorhandenen Vorgangs-JSON gilt auch der dauerhaft zugeordnete Audiohinweis. Bei Wiederholung bestätigt der Nachweis das frühere Ergebnis derselben Datei. |
| `ersatz_json_noetig` | Audioübergabe für dieses Paket nach den Abrufversuchen fehlgeschlagen. Kienzlefon soll die Ersatz-JSON liefern. Die Quittung bestätigt noch keine Ablage. |
| `ueberholt` | Die andere Lieferdatei desselben Vorgangs wurde bereits erfolgreich abgelegt. Der Abholer sendet deren `abgelegt`-Quittung erneut. |

Kienzlefon ordnet die Quittung über Kanal, Vorgangs-ID, Dateinamen und Hash
der noch offenen Lieferung zu. Jede Lieferdatei bleibt für ihre Wiederholungen
bytegleich. Nur `abgelegt` für die Paketdatei oder die zugehörige
Ersatz-JSON erlaubt die Quellbereinigung. Eine Quittung über ein anderes
Paket oder `ersatz_json_noetig` allein erlaubt keine Bereinigung.

### Fehler und Spool-Bereinigung

- Für ein erreichbares Paket sind drei erfolglose Abruf- oder
  Validierungsversuche pro Lieferdatei vorgesehen, einschließlich des
  ersten Versuchs. Danach sendet der Abholer `ersatz_json_noetig`.
  Bei unvollständiger lokaler Kopie liest er den Hash der unveränderten
  Quelldatei über den Abrufkanal. Bei einem Verbindungsabbruch ohne
  bestätigbare Datei bleibt die Lieferung offen. Wenn die Inbox vorübergehend
  nicht beschreibbar ist, bleibt die Ablage offen, bis das Ziel wieder
  schreiben kann. Der Ausfall wird als Transportfehler gemeldet.
- Auf `ersatz_json_noetig` stellt Kienzlefon aus den gesicherten
  Vorgangsdaten eine `<vorgang_id>.json.enc` v1 bereit. Die JSON behält
  Vorgangs-ID, Teilanliegen und fachliche Inhalte. Für die Ersatzlieferung
  ist `payload._kienzlefon.audio` eine leere Liste. Der Block
  `payload._kienzlefon.audio_beilage` enthält `schema_version: 1`,
  `status: "fehler"` und `fehlercode: "audio_uebergabe_fehlgeschlagen"`.
  Der Abholer bestätigt die tatsächliche Ablage dieser Datei mit ihrem
  eigenen Hash. Bei bewusst gewähltem **Nur JSON** fehlt der Fehlerblock.
  Die App zeigt bei diesem Code „Die Audioaufnahmen konnten nicht
  übertragen werden.“
- Nach bestätigter vollständiger Ablage oder bestätigter Ablage der
  Fehler-JSON werden das Quellpaket, die zugehörigen temporären WAVs im
  Kienzlefon-Spool sowie die temporären Download- und Entschlüsselungsdateien
  entfernt. Eine begonnene Übertragung allein löst die Quellbereinigung
  nicht aus. Für fehlgeschlagene Audiolieferungen entsteht kein Dauerarchiv
  im Spool.
- Trifft eine Fehler-JSON nach bestätigter Ablage des vollständigen Pakets
  ein, wird sie als überholt quittiert. Trifft sie bei einem schon
  vorhandenen Vorgang ohne bestätigte Audioübergabe ein, kann die App den
  Audiohinweis unter der Vorgangssperre ergänzen. Beide Fälle erhalten
  Bearbeitungsstände und verhindern eine zweite Vorgangsanzeige. Ein
  späteres Paket wird nach quittierter Fehler-JSON nicht mehr importiert.
  Eine Wiederholung legt bereits gelöschte WAVs nicht erneut an.

### Technische Freigabe des Testpakets

Am 18.11.2026 technisch als Grundlage für die gemeinsame Implementierung
freigegeben. Das [synthetische Testpaket](vorgang-paket-v1-test/README.md)
basiert auf den
[gemeinsamen Vorgangsbeispielen](../../kienzlefon/docs/UEBERGABE-MEHRERE-ANLIEGEN-JSON-2026-11-17.md#gemeinsame-beispieldateien).
Python hat das vereinbarte JWE/ZIP erzeugt. Node Web Crypto hat es
unabhängig entschlüsselt. JSON und WAV waren bytegleich mit den
Ausgangsdaten. Die Zuordnung zum Teilanliegen ist geprüft.
Der bestehende Kienzlefon-Encoder und die Bash-Entschlüsselung bestätigen
die Kompatibilität der bisherigen und der Ersatz-JSON. Die bestehende
PHP-Liste liest die lokal abgelegte JSON als einen Vorgang.

Das Testpaket prüft auch Integritätsfehler, fehlende und zusätzliche WAVs,
abweichende Vorgangs-IDs, doppelte Teilanliegen-IDs und die Bindung der
Quittung an die verschlüsselte Lieferdatei. Die Laufzeitabnahme nach der
Implementierung prüft Abrufabbruch, Zielausfall, verlorene Quittungen,
Wiederanlauf und Spool-Bereinigung über den echten SSH-Abrufkanal.

## Zentraler Architekturpunkt

Es gab ein reales Problem: SSH funktionierte oder OTP/SQLite funktionierte, aber nicht beides gleichzeitig.

Ursache:

- SSH mit `StrictModes` verlangt strikte Rechte im Home- und `.ssh`-Bereich.
- SQLite/PHP braucht Schreibrechte im Datenbereich.

Loesung:

- SSH-Home streng halten.
- Schreibbare App-Datenbereiche getrennt fuehren.
- Gemeinsame Gruppe `tp-<ssh-benutzer>` fuer SSH-Benutzer und `www-data` verwenden.
- php-fpm nach relevanten Gruppen-/Rechteaenderungen neu starten.

## Aktuelle Basisdateien

Quellsystem:

- `quellserver-vorbereiten-nginx-v1.2.sh`
- `quellserver-benutzer-erzeugen-v1.8.sh`

Zielsystem:

- `zielserver-vorbereiten-v1.6.sh`

Weitere relevante Dateien:

- `telepraxis-receive.php` als Vorlage
- `telepraxis-receive-<ssh-benutzer>.php` als installierte Empfangsdatei pro Abrufkanal
- `kontakt.php` als Vorlage
- `kontakt-<ssh-benutzer>.php` als installiertes Webformular pro Abrufkanal
- `telepraxis-app.php`
- `telepraxis_fetch_and_decrypt.sh`

Die genannten Skriptversionen sind der aktuelle Arbeitsstand und sollen als Basis fuer Weiterarbeit gelten.

## Skriptlogik

### `quellserver-vorbereiten-nginx-v1.2.sh`

Soll ein Quellsystem mit nginx vorbereiten:

- notwendige Pakete installieren,
- nginx, certbot und php-fpm einrichten,
- FQDN interaktiv abfragen,
- Let's Encrypt korrekt einrichten,
- auf Jitsi/nginx-Rahmenbedingungen Ruecksicht nehmen.

Wichtig:

- Kein generisches `php`-Metapaket installieren, weil es ungewollt Apache nachziehen kann.
- Stattdessen gezielt `php-fpm`, `php-cli`, `php-curl`, `php-sqlite3`.
- OpenSSL in PHP pruefen, aber kein separates `php-openssl`-Paket erwarten.

### `quellserver-benutzer-erzeugen-v1.8.sh`

Richtet pro Abrufkanal einen separaten SSH-Benutzer auf dem Quellsystem ein.

Fragt interaktiv ab:

- SSH-Benutzername,
- SSH-Public-Key,
- Public Key fuer JSON-Verschluesselung,
- IONOS-Secret manuell oder automatisch erzeugt.

Soll dann:

- Benutzer anlegen,
- Verzeichnisstruktur anlegen,
- SSH-Home als `owner=<ssh-benutzer>`, `group=tp-<ssh-benutzer>`, `mode=0710` setzen,
- `authorized_keys` setzen,
- PHP-Datei von GitHub bootstrappen,
- PHP-Zieldatei als `/var/www/html/telepraxis-receive-<ssh-benutzer>.php` schreiben,
- `$INBOX_DIR`, `$OTP_DB`, `$IONOS_PSK`, `$PUBLIC_KEY_PEM` patchen,
- Kontaktformular als `/var/www/html/kontakt-<ssh-benutzer>.php` schreiben,
- im Kontaktformular `$OTP_DB` und den Endpoint `/telepraxis-receive-<ssh-benutzer>.php` patchen,
- erzeugtes IONOS-Secret am Ende auf der Konsole ausgeben.

### `zielserver-vorbereiten-v1.6.sh`

Richtet das Zielsystem pro Benutzer ein.

Fragt interaktiv ab:

- lokaler Zielbenutzer,
- Quellserver-Host,
- SSH-Benutzer auf dem Quellsystem,
- optional Port.

Soll dann:

- auf Debian/Ubuntu gezielt `php-curl` und `php-xml` installieren,
- PHP-Module `curl`, `dom` und `SimpleXML` pruefen,
- Benutzer anlegen,
- SSH-Key erzeugen,
- RSA-Keypaar fuer JSON-Entschluesselung erzeugen,
- `telepraxis-app.php` nach `/var/www/html/telepraxis-app.php` bootstrappen,
- `telepraxis_fetch_and_decrypt.sh` bootstrappen,
- Fetch-Script patchen,
- systemd-Dienst einrichten, der unter diesem Benutzer laeuft,
- SSH-Public-Key und RSA-Public-Key am Ende ausgeben.

Aktuelle Rechteergaenzung ab `zielserver-vorbereiten-v1.4.sh`:

- lokale Benutzerbasis `/srv/telepraxis/<ziel-benutzer>`: `owner=<ziel-benutzer>`, `group=tp-<ziel-benutzer>`, `mode=0710`,
- lokale Inbox: `2770` mit Gruppe `tp-<ziel-benutzer>`,
- dadurch kann `www-data` die App-Inbox erreichen, ohne die lokale Benutzerbasis listen oder beschreiben zu duerfen.

## Pfadlogik

Quellsystem pro Kanal:

- SSH-Home: `/srv/telepraxis/<ssh-benutzer>`
- Inbox: `/srv/telepraxis/<ssh-benutzer>/inbox`
- PHP-Datei: `/var/www/html/telepraxis-receive-<ssh-benutzer>.php`
- Kontaktformular: `/var/www/html/kontakt-<ssh-benutzer>.php`
- OTP/State: getrennt vom eigentlichen SSH-Kern

Rechte:

- SSH-Home: `0710`, damit `www-data` ueber die Gruppe `tp-<ssh-benutzer>` nur zur Inbox durchqueren kann.
- `.ssh`: `0700`, `authorized_keys`: `0600`, jeweils beim SSH-Benutzer.
- Inbox und State: `2770` mit Gruppe `tp-<ssh-benutzer>`.

Zielsystem pro Zielbenutzer:

- Basis: `/srv/telepraxis/<ziel-benutzer>`
- lokale verschluesselte Zwischenablage: `/srv/telepraxis/<ziel-benutzer>/.encrypted`
- lokale entschluesselte Inbox: `/srv/telepraxis/<ziel-benutzer>/inbox`
- SSH-Key: `/srv/telepraxis/<ziel-benutzer>/.ssh/telepraxis_fetch_key`
- RSA-Private-Key: `/srv/telepraxis/<ziel-benutzer>/telepraxis_decrypt_private.pem`
- RSA-Public-Key: `/srv/telepraxis/<ziel-benutzer>/telepraxis_decrypt_public.pem`
- Web-App: `/var/www/html/telepraxis-app.php`

## Bekannte Problemstellen

- Apache wurde ungewollt durch generische PHP-Metapakete installiert.
- `php-openssl` existiert unter Debian/Ubuntu meist nicht als separates Paket; OpenSSL-Support per `php -m` pruefen.
- SQLite-Fehler `unable to open database file` deutet oft auf Pfad-/Rechteprobleme, relative Pfade oder fehlenden php-fpm-Neustart nach Gruppenaenderungen.
- SSH-Key-Login scheitert typischerweise an falschem `authorized_keys`, falschem Home oder zu offenen/falschen Rechten.
- OTP/SQLite und SSH-StrictModes duerfen nicht durch lockere Home-Rechte gegeneinander ausgespielt werden.

## Naechste Pruefpunkte

- Alle drei Install-Skripte gemeinsam gegen reale Zielpfade testen.
- Quellsystem-Rechte, OTP-DB-Pfad, Gruppenmitgliedschaften und php-fpm-Neustarts pruefen.
- Mehrbenutzerbetrieb mit mindestens zwei getrennten Benutzern/Kanaelen testen.
- Pruefen, ob `telepraxis-app.php` in der finalen Mehrbenutzerform pro Benutzer sauber funktioniert.
- Optional Logging, Health-Checks, systemd-Diagnoseausgaben und Remote-Loesch-Fail-Szenarien verbessern.
