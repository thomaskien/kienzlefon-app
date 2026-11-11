# kienzlefon app

Kompakte Ein(zwei)-Dateien-Webapp zur Bearbeitung eingehender JSON-Vorgänge aus dem Verzeichnis `./inbox`.

Die **kienzlefon app** hieß bisher **telepraxis-app**. Die aktuelle Version **3.6**
vom 12.10.2026 ergänzt die optionale T2med-Anbindung und trennt Benutzer und
Platz. SMS-Queue, Empfang, farbige SMS-Kommentare, einmalige Bestätigung und
Benachrichtigungston bleiben erhalten. Die bisherigen Screenshots zeigen noch
ältere Versionen und den alten Namen.

Die Haupt-App bleibt `telepraxis-app.php`. Für SMS-Versand wird zusätzlich `telepraxis-sms.php` als separate Funktionsdatei eingebunden; die Konfiguration erfolgt über `sms-config.php`.

<img src="Screenshot 2026-03-31 at 13-40-44 telepraxis-app v2.1.png" alt="drawing" width="1000"/>


## Benutzungskonzept

Die App ist für mehrere Arbeitsplätze gedacht:

- **Neu**: neue eingegangene Vorgänge
- **In Bearbeitung**: nur die Vorgänge des aktuell eingestellten Benutzers
- **Abgeschlossen**: erledigte Vorgänge
- **Papierkorb**: gelöschte Vorgänge, nur mit Admin-Funktion sichtbar

**Benutzer** und **Platz** werden in der Kopfzeile oder im Hamburger-Menü
eingetragen und mit **Speichern** (oder Enter) übernommen. Benutzer bezeichnet
den Bearbeiter, etwa `Julia` oder `Dr. Müller`. Der Platz bezeichnet den
Telefonplatz, etwa `empfang-1`, und wird als `client_id` an T2med übergeben.
Die Angaben werden getrennt im Browser gespeichert. Änderungen in den
Eingabefeldern gelten erst nach Speichern für Vorgänge und Telefonaktionen.

Links erscheinen die Vorgänge des eigenen Benutzers, auch nach einem Wechsel
des Platzes. Vorgänge anderer Benutzer bleiben in der mittleren Spalte sichtbar.
Kommentare, Statusänderungen, Papierkorb-Aktionen und SMS werden dem Benutzer
zugeordnet. Für Bearbeitungsaktionen ist ein Benutzer erforderlich; für die
optionalen T2med-Aktionen zusätzlich ein Platz.

Der Button **Bookmark-Link** berücksichtigt die aktuell ausgefüllten Felder,
auch wenn nur eines gesetzt ist:

```text
telepraxis-app.php?benutzer=Julia&arbeitsplatz=empfang-1
telepraxis-app.php?arbeitsplatz=empfang-1
telepraxis-app.php?benutzer=Julia
```

Enthält die URL eines dieser Felder, bleiben nicht mitgegebene Felder bewusst
leer: Ein Platz-Bookmark fragt deshalb nach dem Benutzer und übernimmt keinen
zuletzt gespeicherten Namen. Ohne diese URL-Parameter gelten die gespeicherten
Angaben. Ein früher gespeicherter `Platz` wird beim ersten Start ohne Parameter
als bisheriger Bearbeiter übernommen; der neue Telefonplatz wird separat gesetzt.
Alte Vorgänge und Kommentare behalten ihre bisherigen Bearbeiterangaben als
Rückfallwerte. Die sichtbare Bezeichnung `Platz` und der URL-Parameter
`arbeitsplatz` bleiben erhalten.

Die App lädt die Daten regelmäßig neu und eignet sich damit für den laufenden Einsatz im Praxisalltag.

## Optionale kienzlefon-t2med-Anbindung

Die Einstellungen stehen wie bisher im Kopf von `telepraxis-app.php`.
Standardmäßig ist die Anbindung ausgeschaltet. Zum Aktivieren beide Konstanten
anpassen; die Beispieladresse durch die eigene Middleware-Adresse ersetzen:

```php
const TELEPRAXIS_T2MED_ENABLED = true;
const TELEPRAXIS_T2MED_BASE_URL = 'http://middleware.example:8765';
```

Die Basisadresse darf HTTP oder HTTPS verwenden und enthält weder Endpunkt
noch Query-Parameter oder Zugangsdaten. Sie muss vom Browser des Arbeitsplatzes
erreichbar sein. Eine zusätzliche Middleware-Konfiguration in der Oberfläche
ist nicht nötig.

- **Telefonnummer anklicken:** `/waehlen?client_id=<Platz>&telefon=<Nummer>&sofort=1`.
- Kleiner Knopf **Akte** daneben: `/patient-oeffnen?client_id=<Platz>&telefon=<Nummer>&auto_close=1`.
- Beide Aktionen sind auch in den Tabellen verfügbar; in Bearbeitung zusätzlich
  bei der übermittelten Telefonnummer, jeweils mit der danebenstehenden Nummer.
- Die Links öffnen einen neuen Tab, damit die App geöffnet bleibt. Beim Aktenaufruf
  bittet `auto_close=1` eine entsprechend aktualisierte Middleware, den Tab
  **nur nach erfolgreicher automatischer GDT-Übergabe** wieder zu schließen.
  Bei mehrdeutigen Treffern oder Fehlern bleibt er für die Auswahl bzw. Diagnose offen.
  Ob `window.close()` funktioniert, hängt von den Browserregeln für neu geöffnete
  Tabs ab; andernfalls kann der Tab manuell geschlossen werden. Sie werden
  ausschließlich durch einen Klick aufgerufen. Internationales `+` wird als
  `%2B` kodiert; Benutzer wird nicht an die Middleware gesendet.
- Bei fehlendem Platz oder ungültiger Konfiguration erscheint ein Hinweis.
  Bei ausgeschalteter Anbindung bleiben die bisherigen `tel:`-Links aktiv und
  der Akte-Knopf entfällt.

Die Integration ordnet einen bereits in der Middleware konfigurierten Client zu;
sie legt dort keine neuen Clients an. Der bisherige Updater v1.0 übernimmt die
neuen `TELEPRAXIS_T2MED_*`- und `TELEPRAXIS_USER_MAXLEN`-Einstellungen noch nicht
bei späteren Dateiwechseln; diese Werte danach im Dateikopf kontrollieren.

### Datenkompatibilität und Prüfung

Neue Vorgangsänderungen speichern den Bearbeiter in `status_updated_benutzer`
und den Platz in `status_updated_arbeitsplatz`; Kommentare enthalten `benutzer`
und `workplace`. Alte Kommentare werden nicht umgeschrieben. Das vorhandene
SMS-Queue-Schema bleibt erhalten: Dessen technische Spalte `workplace` enthält
weiterhin den Autor, ab 3.6 also den Benutzer.

```sh
php -l telepraxis-app.php
python3 -m unittest discover -s tests -p 'test_telepraxis_app_v36.py' -v
python3 -m unittest discover -s tests -p 'test_telepraxis_sms_web_queue.py' -v
node tests/test_telepraxis_app_browser.cjs
```

Der Browsertest benötigt Node, Playwright und Chromium (alternativ installiertes
Chrome mit `PLAYWRIGHT_CHANNEL=chrome`). Er arbeitet mit einer temporären Site
und synthetischen Daten; Middleware-Aufrufe werden blockiert. Er prüft
Teil-Bookmarks, Benutzerzuordnung, Linkparameter, lokale Speicherung, Polling,
Tabellen und die Kopfzeile bei verschiedenen Bildschirmbreiten.

## Wichtige Funktionen

- Einlesen von JSON-Dateien aus `./inbox`
- Statuswechsel: **Neu**, **In Bearbeitung**, **Abgeschlossen**
- Markierung **Dringend**
- Soft-Delete in den Papierkorb
- Admin-Funktionen für **Wiederherstellen** und **endgültiges Löschen**
- Polling-Aktualisierung alle 5 Sekunden
- Benachrichtigungston bei neuen Vorgängen und weiteren eingegangenen SMS
- Klick auf den Namen kopiert `Nachname, Vorname JJJJ`
- Klick auf das Geburtsdatum kopiert das Geburtsdatum
- Gesprächszusammenfassung in Bearbeitung ein- und ausklappbar
- Geöffnete Zusammenfassungen bleiben trotz Refresh erhalten
- Telefonnummern sind direkt anklickbar
- Übermittelte Telefonnummer wird zusätzlich angezeigt
- SMS-Button in **In Bearbeitung**, nur bei vorhandener Rückrufnummer
- SMS-Versand direkt oder über eine persistente Queue; Versandstatus erscheint im Kommentarverlauf
- SMS-Empfang über einen eigenständigen Worker mit dauerhafter Sicherung und Routerbereinigung
- Weitere SMS derselben Nummer im zuletzt angelegten offenen Vorgang, blau markiert und chronologisch unter Kommentare
- Automatische Bestätigung nur für die erste SMS je Vorgang; keine automatischen Antworten an Kurznummern oder ins Ausland
- Lokale Speicherung von Arbeitsplatz, Ton, Sichtbarkeit von Abgeschlossen und Papierkorb
- **Kontaktformular mit kanalbezogenem Endpunkt**

<img src="Screenshot 2026-04-01 at 09-03-13 Telepraxis Kontakt.png" alt="drawing" width="600"/>



## Unterstützte Inhalte

Die App unterstützt die aktuell besprochenen Request-Typen des Telefonassistenten, darunter insbesondere:

- Rückruf
- Sonstiges
- Rezeptbestellung
- Überweisung
- Fallback-Typen mit reduzierten Angaben
- zusätzlicher dringender Fallback `sonstiges_dringend_fehlleitung`

## Technische Hinweise

- Hauptdatei: `telepraxis-app.php`
- SMS-Funktionsdatei: `telepraxis-sms.php`
- SMS-Konfiguration: `sms-config.php`
- Zeitzone: `Europe/Berlin`
- Standard-Polling: `5000 ms`
- Admin-Passwörter werden vom Zielserver-Installer erzeugt oder manuell gesetzt
- SMS-Credentials liegen auf dem Zielsystem außerhalb des Webroots unter `/srv/telepraxis/<ziel-benutzer>/config/sms-credentials.json`

## Kurzablauf

1. `telepraxis-app.php` im Webroot ablegen, bei SMS zusätzlich `telepraxis-sms.php` und `sms-config.php`
2. Unterhalb davon ein Verzeichnis `inbox` mit den JSON-Dateien bereitstellen
3. App im Browser öffnen
4. Arbeitsplatz eintragen
5. Vorgänge bearbeiten, abschließen, löschen oder bei vorhandener Rückrufnummer SMS senden

## Bestehende App aktualisieren

Für bestehende Zielsysteme gibt es `kienzlefon-app-update-v1.0.sh`. Der Updater
aktualisiert die App und ihre SMS-Bibliotheken, übernimmt die vorhandenen
Konfigurationskonstanten und sichert die bisherigen Programmdateien außerhalb
des Webroots. Vorgänge, SMS-Konfigurationsdateien, Schlüssel und Fetch-Dienst
bleiben erhalten. Ein vorhandener separater SMS-Worker kann gemeinsam mit
seinen Bibliotheken aktualisiert werden.

Der Updater umfasst bisher nur den Worker und seine beiden Versandbibliotheken.
Die drei zusätzlichen Empfangsmodule müssen separat aus demselben Quellstand
installiert werden. Bei aktiviertem Empfang App und Empfangsspeicher koordiniert
aktualisieren; siehe [SMS-Empfang](docs/SMS-EMPFANG.md#weitere-sms-zum-offenen-vorgang).

Aus einem vollständigen lokalen Quellverzeichnis auf dem Zielsystem zunächst
prüfen, anschließend das Update starten:

```sh
sudo bash kienzlefon-app-update-v1.0.sh --source-dir "$PWD" --check
sudo bash kienzlefon-app-update-v1.0.sh --source-dir "$PWD"
```

Unveröffentlichte Änderungen sind nur im lokalen Quellmodus verfügbar. Ohne
`--source-dir` lädt der Updater einen zusammenhängenden Stand aus GitHub.
Details zu Diensten, Sicherungen und Grenzen stehen in
[App aktualisieren](docs/APP-UPDATE.md).

## Kommentarfunktion

<img src="Screenshot 2026-04-02 at 14-37-32 telepraxis-app v2.6.png" alt="drawing" width="800"/>



## Sicherheit

- OTP wird durch `kontakt-<ssh-benutzer>.php` generiert, ist 24 Stunden gültig und kann nur einmal verwendet werden
- Webformular-Requests verwenden `id == "web-formular"`, OTP und Rate-Limit
- Webformular-Rate-Limit: maximal 20 Requests je IP in 10 Minuten
<pre>
apt-get update
apt-get install -y php-sqlite3
systemctl restart php8.2-fpm  # PHP-Version ggf. anpassen
</pre>
- IONOS kontaktiert per
<pre>
IONOS Tool Header (statisch):

Name: X-TP-Token
Value: der Wert aus $IONOS_PSK (nachdem du CHANGE_ME... ersetzt hast)
</pre>

# kienzlefon app – verschlüsselter JSON-Transport

## Systemaufbau

Das System besteht aus zwei Seiten:

### 1. Quellserver
Auf dem Quellserver bekommt jeder Abrufkanal einen eigenen SSH-Benutzer und eine eigene Empfangsdatei nach dem Schema `telepraxis-receive-<ssh-benutzer>.php`.
Diese Datei nimmt JSON per HTTP-POST entgegen.
Die Daten werden **nicht im Klartext gespeichert**, sondern direkt in PHP mit einem fest eingebetteten **Public Key** verschlüsselt und als Datei im kanalbezogenen Inbox-Verzeichnis abgelegt.

Beispiel:
- SSH-Benutzer: `CHANNEL` (durch den eigenen Kanalnamen ersetzen)
- PHP-Datei: `/var/www/html/telepraxis-receive-CHANNEL.php`
- HTTPS-Endpoint: `https://###servername###/telepraxis-receive-CHANNEL.php`
- Kontaktformular: `/var/www/html/kontakt-CHANNEL.php`
- Kontakt-URL: `https://###servername###/kontakt-CHANNEL.php`
- Ablage: `/srv/telepraxis/CHANNEL/inbox/*.json.enc`
- OTP/State: `/srv/telepraxis/state/CHANNEL/otp.sqlite`

### 2. Zielsystem
Das Zielsystem besitzt den zugehörigen **Private Key**.  
Ein Shell-Script holt die verschlüsselten Dateien regelmäßig per **SCP/SSH** vom Server, entschlüsselt sie lokal und legt daraus wieder normale JSON-Dateien ab.

Beispiel:
- geholt von: `<ssh-benutzer>@#servername#:/srv/telepraxis/<ssh-benutzer>/inbox/`
- lokal entschlüsselt nach: `/srv/telepraxis/<ziel-benutzer>/inbox/`

## Sicherheitskonzept

Es werden **zwei getrennte Schlüsselarten** verwendet:

### Inhaltsverschlüsselung
- **Public Key** liegt im PHP-Script
- **Private Key** liegt nur auf dem Zielsystem

Damit können die Dateien bereits auf dem Quellserver nur verschlüsselt gespeichert werden.

### Transport / Zugriff
Zusätzlich kann für SCP/SSH ein **separater SSH-Key** verwendet werden.  
Dieser dient nur zum Holen und Löschen der Dateien, nicht zur Entschlüsselung des Inhalts.

## Dateiformat

Die gespeicherte Datei ist ein JSON-Wrapper mit verschlüsseltem Inhalt, z. B. mit diesen Feldern:

- `cipher`
- `ek`
- `iv`
- `ct`
- `sha256`

Der eigentliche Nutzinhalt steckt verschlüsselt in `ct`.

## Ablauf

1. Client sendet JSON an PHP
2. PHP validiert den Request
3. PHP erzeugt einen Datensatz mit Metadaten
4. PHP verschlüsselt den Datensatz direkt mit dem Public Key
5. PHP speichert eine Datei `*.json.enc`
6. Zielsystem holt die Datei per SCP
7. Zielsystem entschlüsselt lokal mit dem Private Key
8. Zielsystem prüft Hash und JSON-Gültigkeit
9. Zielsystem schreibt die entschlüsselte JSON-Datei atomisch
10. Danach wird die verschlüsselte Datei lokal und auf dem Server gelöscht

## Funktionen des Fetch-Scripts

Das Shell-Script kann:

- Server, Benutzer, Pfade und Ports im Header konfigurieren
- optional einen eigenen SSH-Key verwenden
- verschlüsselte Dateien per SCP holen
- lokal entschlüsseln
- SHA-256 prüfen
- JSON validieren
- erst nach erfolgreicher Verarbeitung löschen
- einmalig oder im Polling-Betrieb laufen, z. B. alle 5 Sekunden

## Ziel des Aufbaus

Das Ziel ist, dass sensible JSON-Daten:

- **auf dem Quellserver nicht im Klartext liegen**
- **nur auf dem Zielsystem entschlüsselt werden**
- **nach erfolgreicher Verarbeitung automatisch entfernt werden**


# Installer-Ablauf

<pre>
# Quellserver-Webbasis:
chmod +x quellserver-vorbereiten-nginx-v1.2.sh
./quellserver-vorbereiten-nginx-v1.2.sh

# Zielsystem pro Benutzer:
chmod +x zielserver-vorbereiten-v1.8.sh
./zielserver-vorbereiten-v1.8.sh

# Quellserver-Abrufkanal pro SSH-Benutzer:
chmod +x quellserver-benutzer-erzeugen-v1.8.sh
./quellserver-benutzer-erzeugen-v1.8.sh
</pre>


# ionos-rezepte für die API

- bitte einzeln einkopieren

### rezeptbestellung

```json
{
  "name": "rezeptbestellung",
  "description": "IMMER verwenden wenn ein Anrufer ein Rezept bestellen möchte. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
  "parameters": {
    "type": "object",
    "properties": {
      "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
      "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückfragen (notwendig, ggf. bestätigen/erfragen)" },
      "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." },
      "vorname": { "type": "string", "description": "Vorname" },
      "nachname": { "type": "string", "description": "Nachname" },
      "geburtsdatum": { "type": "string", "description": "Geburtsdatum" },
      "medikamente": { "type": "string", "description": "Alle gewünschten Medikamente als Freitext (gern mit Stärke), mehrere möglich" }
    },
    "required": ["id", "telefon", "zusammenfassung", "vorname", "nachname", "geburtsdatum", "medikamente"]
  },
  "request": {
    "method": "POST",
    "url": "https://###servername###/telepraxis-receive-###ssh-benutzer###.php",
    "headers": [
      { "name": "Content-Type", "value": "application/json" },
      { "name": "X-TP-Token", "value": "###CHANGE_ME_LONG_RANDOM_SECRET###" }
    ],
    "queryString": [],
    "postData": {
      "mimeType": "application/json",
      "text": "{\"typ\":\"rezeptbestellung\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\",\"vorname\":\"{{ vorname }}\",\"nachname\":\"{{ nachname }}\",\"geburtsdatum\":\"{{ geburtsdatum }}\",\"medikamente\":\"{{ medikamente }}\"}"
    }
  }
}
```

### ueb_req

```json
{
  "name": "ueb_req",
  "description": "IMMER verwenden wenn ein Anrufer eine Überweisung anfragt. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Erfasse außerdem Vorname, Nachname, Geburtsdatum, gewünschte Fachrichtung, Grund und eine Zusammenfassung des Gesprächs.",
  "parameters": {
    "type": "object",
    "properties": {
      "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
      "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückfragen (notwendig, ggf. bestätigen/erfragen)" },
      "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." },
      "vorname": { "type": "string", "description": "Vorname" },
      "nachname": { "type": "string", "description": "Nachname" },
      "geburtsdatum": { "type": "string", "description": "Geburtsdatum" },
      "fachrichtung": { "type": "string", "description": "Gewünschte Fachrichtung" },
      "grund": { "type": "string", "description": "Kurzer Grund für die Überweisung" }
    },
    "required": ["id", "telefon", "zusammenfassung", "vorname", "nachname", "geburtsdatum", "fachrichtung", "grund"]
  },
  "request": {
    "method": "POST",
    "url": "https://###servername###/telepraxis-receive-###ssh-benutzer###.php",
    "headers": [
      { "name": "Content-Type", "value": "application/json" },
      { "name": "X-TP-Token", "value": "###CHANGE_ME_LONG_RANDOM_SECRET###" }
    ],
    "queryString": [],
    "postData": {
      "mimeType": "application/json",
      "text": "{\"typ\":\"ueb_req\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\",\"vorname\":\"{{ vorname }}\",\"nachname\":\"{{ nachname }}\",\"geburtsdatum\":\"{{ geburtsdatum }}\",\"fachrichtung\":\"{{ fachrichtung }}\",\"grund\":\"{{ grund }}\"}"
    }
  }
}
```

### rueckruf_min

```json
{
  "name": "rueckruf_min",
  "description": "IMMER verwenden wenn ein Anrufer um Rückruf bittet und nur die Basisdaten erfasst werden sollen. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
  "parameters": {
    "type": "object",
    "properties": {
      "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
      "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückruf (notwendig)" },
      "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." }
    },
    "required": ["id", "telefon", "zusammenfassung"]
  },
  "request": {
    "method": "POST",
    "url": "https://###servername###/telepraxis-receive-###ssh-benutzer###.php",
    "headers": [
      { "name": "Content-Type", "value": "application/json" },
      { "name": "X-TP-Token", "value": "###CHANGE_ME_LONG_RANDOM_SECRET###" }
    ],
    "queryString": [],
    "postData": {
      "mimeType": "application/json",
      "text": "{\"typ\":\"rueckruf_min\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\"}"
    }
  }
}
```

### rueckruf_tel_grund

```json
{
  "name": "rueckruf_tel_grund",
  "description": "IMMER verwenden wenn ein Anrufer um Rückruf bittet und zusätzlich einen Grund nennt. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
  "parameters": {
    "type": "object",
    "properties": {
      "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
      "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückruf (notwendig)" },
      "grund": { "type": "string", "description": "Kurzer Grund für den Rückruf" },
      "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." }
    },
    "required": ["id", "telefon", "grund", "zusammenfassung"]
  },
  "request": {
    "method": "POST",
    "url": "https://###servername###/telepraxis-receive-###ssh-benutzer###.php",
    "headers": [
      { "name": "Content-Type", "value": "application/json" },
      { "name": "X-TP-Token", "value": "###CHANGE_ME_LONG_RANDOM_SECRET###" }
    ],
    "queryString": [],
    "postData": {
      "mimeType": "application/json",
      "text": "{\"typ\":\"rueckruf_tel_grund\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"grund\":\"{{ grund }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\"}"
    }
  }
}
```

### rueckruf_details

```json
{
  "name": "rueckruf_details",
  "description": "IMMER verwenden wenn ein Anrufer um Rückruf bittet und vollständige Patientendaten genannt werden. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
  "parameters": {
    "type": "object",
    "properties": {
      "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
      "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückruf (notwendig)" },
      "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." },
      "vorname": { "type": "string", "description": "Vorname" },
      "nachname": { "type": "string", "description": "Nachname" },
      "geburtsdatum": { "type": "string", "description": "Geburtsdatum" },
      "grund": { "type": "string", "description": "Kurzer Grund für den Rückruf" }
    },
    "required": ["id", "telefon", "zusammenfassung", "vorname", "nachname", "geburtsdatum", "grund"]
  },
  "request": {
    "method": "POST",
    "url": "https://###servername###/telepraxis-receive-###ssh-benutzer###.php",
    "headers": [
      { "name": "Content-Type", "value": "application/json" },
      { "name": "X-TP-Token", "value": "###CHANGE_ME_LONG_RANDOM_SECRET###" }
    ],
    "queryString": [],
    "postData": {
      "mimeType": "application/json",
      "text": "{\"typ\":\"rueckruf_details\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\",\"vorname\":\"{{ vorname }}\",\"nachname\":\"{{ nachname }}\",\"geburtsdatum\":\"{{ geburtsdatum }}\",\"grund\":\"{{ grund }}\"}"
    }
  }
}
```

### sonstiges

```json
{
  "name": "sonstiges",
  "description": "IMMER verwenden wenn das Anliegen nicht Rezept, Rückruf oder Überweisung ist. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
  "parameters": {
    "type": "object",
    "properties": {
      "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
      "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückfragen (notwendig)" },
      "anliegen": { "type": "string", "description": "Freitext: worum geht es?" },
      "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." }
    },
    "required": ["id", "telefon", "anliegen", "zusammenfassung"]
  },
  "request": {
    "method": "POST",
    "url": "https://###servername###/telepraxis-receive-###ssh-benutzer###.php",
    "headers": [
      { "name": "Content-Type", "value": "application/json" },
      { "name": "X-TP-Token", "value": "###CHANGE_ME_LONG_RANDOM_SECRET###" }
    ],
    "queryString": [],
    "postData": {
      "mimeType": "application/json",
      "text": "{\"typ\":\"sonstiges\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"anliegen\":\"{{ anliegen }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\"}"
    }
  }
}
```

### fallback_name_tel_grund

```json
{
  "name": "fallback_name_tel_grund",
  "description": "IMMER verwenden wenn ein Sonderfall/Problem gemeldet werden muss (z. B. nicht erfolgreich durchgestellter dringender Anruf) und nur Name/Telefon/Grund vorliegen. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
  "parameters": {
    "type": "object",
    "properties": {
      "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
      "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückfragen (notwendig)" },
      "name": { "type": "string", "description": "Name der Person (Freitext, z. B. 'Nachname, Vorname')" },
      "grund": { "type": "string", "description": "Kurzer Grund / was ist passiert" },
      "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." }
    },
    "required": ["id", "telefon", "name", "grund", "zusammenfassung"]
  },
  "request": {
    "method": "POST",
    "url": "https://###servername###/telepraxis-receive-###ssh-benutzer###.php",
    "headers": [
      { "name": "Content-Type", "value": "application/json" },
      { "name": "X-TP-Token", "value": "###CHANGE_ME_LONG_RANDOM_SECRET###" }
    ],
    "queryString": [],
    "postData": {
      "mimeType": "application/json",
      "text": "{\"typ\":\"fallback_name_tel_grund\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"name\":\"{{ name }}\",\"grund\":\"{{ grund }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\"}"
    }
  }
}
```

### fallback_vn_nn_grund

```json
{
  "name": "fallback_vn_nn_grund",
  "description": "IMMER verwenden wenn ein Sonderfall/Problem gemeldet werden muss (z. B. nicht erfolgreich durchgestellter dringender Anruf) und Vorname/Nachname/Grund vorliegen. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
  "parameters": {
    "type": "object",
    "properties": {
      "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
      "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückfragen (notwendig)" },
      "vorname": { "type": "string", "description": "Vorname" },
      "nachname": { "type": "string", "description": "Nachname" },
      "grund": { "type": "string", "description": "Kurzer Grund / was ist passiert" },
      "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." }
    },
    "required": ["id", "telefon", "vorname", "nachname", "grund", "zusammenfassung"]
  },
  "request": {
    "method": "POST",
    "url": "https://###servername###/telepraxis-receive-###ssh-benutzer###.php",
    "headers": [
      { "name": "Content-Type", "value": "application/json" },
      { "name": "X-TP-Token", "value": "###CHANGE_ME_LONG_RANDOM_SECRET###" }
    ],
    "queryString": [],
    "postData": {
      "mimeType": "application/json",
      "text": "{\"typ\":\"fallback_vn_nn_grund\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"vorname\":\"{{ vorname }}\",\"nachname\":\"{{ nachname }}\",\"grund\":\"{{ grund }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\"}"
    }
  }
}
```

### fallback_id_zusammenfassung

```json
{
  "name": "fallback_id_zusammenfassung",
  "description": "IMMER verwenden wenn sonst nichts sicher erfasst werden konnte (z. B. Gespräch abgebrochen, dringender Anruf nicht durchgestellt, unklare Lage). Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
  "parameters": {
    "type": "object",
    "properties": {
      "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
      "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." }
    },
    "required": ["id", "zusammenfassung"]
  },
  "request": {
    "method": "POST",
    "url": "https://###servername###/telepraxis-receive-###ssh-benutzer###.php",
    "headers": [
      { "name": "Content-Type", "value": "application/json" },
      { "name": "X-TP-Token", "value": "###CHANGE_ME_LONG_RANDOM_SECRET###" }
    ],
    "queryString": [],
    "postData": {
      "mimeType": "application/json",
      "text": "{\"typ\":\"fallback_id_zusammenfassung\",\"id\":\"{{ id }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\"}"
    }
  }
}
```

## SMS-Anbindung

Das IONOS-Repository ist künftig maßgeblich für die SMS-Funktionen. Die reguläre
App unterstützt die persistente Queue als dritten aktiven Versandweg neben
FRITZ!Box und seven.io. Sie wird in `sms-config.php` ausgewählt; der unabhängige
Worker erledigt Versand und Bereinigung.

Der Empfang ist implementiert und auf einer FRITZ!Box 6850 LTE mit FRITZ!OS 8.25
geprüft. Vollständige SMS werden vor der Routerbereinigung dauerhaft lokal
gesichert und in die Ziel-Inbox übernommen. Bei mehreren offenen Vorgängen
derselben Nummer entscheidet der jüngste Anlagezeitpunkt; **Neu** und **In
Bearbeitung** sind zulässig, abgeschlossene und gelöschte Vorgänge nicht.
Weitere SMS erscheinen als blaue Kommentare mit Empfangszeit und Absender.

Die konfigurierte Bestätigungsliste wird nur bei der ersten SMS je Vorgang
eingereiht und nur an vollständige deutsche Nummern. Weitere SMS lösen den
gewohnten Benachrichtigungston aus, ohne erneute Bestätigung. Bereits bekannte
SMS und der erste Seitenabruf bleiben stumm; die Ton-Einstellung gilt weiterhin.

Antworttexte stehen in `sms-config.php` unter **„Vorbereitete Auto-Reply-SMS
(eine SMS pro Zeile)“**. Nach dem Speichern den SMS-Worker neu starten.
Bei FRITZ!Box maximal 70 UTF-16-Codeeinheiten je ausgehender SMS; ein leeres Feld
deaktiviert automatische Antworten. Bereits gespeicherte Antwortentscheidungen
werden dadurch nicht nachträglich geändert.

- [Einrichtung und Grenzen der SMS-Queue](docs/SMS-QUEUE.md)
- [SMS-Empfang, Vorgangszuordnung und Wiederanlauf](docs/SMS-EMPFANG.md)
- [Repository-Zuordnung und Übergabe](docs/SMS-REPOSITORIES.md)
- [Übergabe für die weitere SMS-Entwicklung](docs/SMS-UEBERGABE-2026-09-29.md)

Die getestete FRITZ!OS-Version liefert lange SMS bereits zusammengesetzt.
Unbekannte Segment- oder Listenformate werden nicht geraten: Sie bleiben zur
Prüfung zurückgehalten. Andere Geräte/Firmwarestände müssen gesondert geprüft werden.

## Entwicklung prüfen

Mit PHP CLI, Python 3 und Node.js im `PATH`:

```sh
python3 -B -m unittest discover -s tests
php -l telepraxis-app.php
bash -n zielserver-vorbereiten-v1.8.sh
bash -n kienzlefon-app-update-v1.0.sh
git diff --check
```

Die Tests verwenden temporäre Daten und lokale HTTP-Simulationen ohne echten
SMS-Versand. Prüfstand vom 29.09.2026: **170 Tests bestanden**, davon 146 für
SMS/App/Konfiguration und 24 für den Updater.
