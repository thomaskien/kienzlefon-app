# Komponente kienzlefon-app

## Paket bauen

Im Projektordner `ionos` unter macOS oder Linux mit Bash und Python 3 ausführen. Das Ausgabeverzeichnis muss existieren.

```bash
bash scripts/pack.sh --output /absoluter/pfad/kienzlefon-app.tar.gz
```

Die Komponenten-ID und das oberste Archivverzeichnis heißen `kienzlefon-app`.
Die Dateiliste in `scripts/pack.sh` wählt Programmdateien, Konfigurationsbeispiele, die Worker-Unit und die Installer-Anleitung direkt aus dem Arbeitsverzeichnis aus. Dazu zählen auch ungetrackte Programmdateien. Das neue Archiv ersetzt die Ausgabe erst nach erfolgreichem Packen.

## Neuinstallation

Die Setup-Skripte laufen als root auf Linux. Das App-Zielsystem benötigt OpenSSH-Client, OpenSSL und util-linux (`runuser`, `flock`) für die Schlüssel. Für die vollständige Einrichtung werden außerdem Python 3, PHP CLI, ein Webserver für `/var/www/html`, systemd und für den Fetch-Dienst `jq` vorausgesetzt. Auf Debian/Ubuntu richtet das Zielsetup die PHP-Erweiterungen über apt ein.

Auf jedem beteiligten Linux-Host dieselbe Paketversion außerhalb des Webroots entpacken. Die folgenden Befehle verwenden beispielhaft `/opt/installer` als vorhandenes Entpackverzeichnis. `kanal1` durch den lokalen Zielbenutzer ersetzen und für beide Aufrufe auf dem App-Zielsystem beibehalten.

```bash
sudo tar -xzf /absoluter/pfad/kienzlefon-app.tar.gz -C /opt/installer
PAKET=/opt/installer/kienzlefon-app
ZIELBENUTZER=kanal1
```

### 1. Schlüssel auf dem App-Zielsystem vor dem Fon-Setup vorbereiten

```bash
sudo bash "$PAKET/zielserver-vorbereiten-v1.8.sh" --prepare-keys --user "$ZIELBENUTZER"
```

Dieser Schritt richtet Kanalbenutzer, Gruppe, Home und Schlüsselverzeichnis ein. Er kann vor der Installation von PHP, Webapp und Fetch-Dienst ausgeführt werden. Bestehende Paare werden geprüft und weiterverwendet. Fehlt nur der öffentliche Teil, wird er vom vorhandenen privaten Schlüssel abgeleitet. Fehlt der private Teil eines vorhandenen Paars oder stimmen die Schlüssel nicht überein, bricht die Vorbereitung ab.

| Öffentliche Datei auf dem App-Zielsystem | Übergabe |
| --- | --- |
| `/srv/telepraxis/<Zielbenutzer>/telepraxis_decrypt_public.pem` | Dem Fon-Setup als „Pfad zur Telepraxis-Public-Key-PEM-Datei“ übergeben. Bei getrenntem Fon-Host diese öffentliche Datei dorthin kopieren. Derselbe PEM-Block geht an das Empfangs-PHP des gewählten Quellkanals. |
| `/srv/telepraxis/<Zielbenutzer>/.ssh/telepraxis_fetch_key.pub` | Beim Einrichten des Quellkanals die eine SSH-Zeile übergeben. Sie kommt in `authorized_keys` des Abrufbenutzers. |

Die privaten Schlüssel `telepraxis_decrypt_private.pem` und `.ssh/telepraxis_fetch_key` bleiben auf dem App-Zielsystem.

### 2. Abrufkanal auf dem gewählten Quellserver einrichten

Auf einem neuen Quellserver zunächst die Webbasis einrichten, falls sie dort benötigt wird.

```bash
sudo bash "$PAKET/quellserver-vorbereiten-nginx-v1.2.sh"
```

Auf dem vorbereiteten Quellserver pro Abrufkanal ausführen.

```bash
sudo bash "$PAKET/quellserver-benutzer-erzeugen-v1.8.sh" --source-dir "$PAKET"
```

Der Dialog erwartet in dieser Reihenfolge den **SSH-Benutzernamen auf dem Quellserver**, den SSH-Public-Key als einzelne Zeile, den RSA-Public-Key als vollständigen PEM-Block und die Auswahl für das IONOS-Secret. Er installiert `telepraxis-receive-<ssh-benutzer>.php` und `kontakt-<ssh-benutzer>.php` mit gemeinsamer kanalbezogener OTP-Datenbank. Den ausgegebenen PSK als `X-TP-Token` für den IONOS-Endpunkt verwenden.

### 3. App und Fetch-Dienst auf dem App-Zielsystem einrichten

```bash
sudo bash "$PAKET/zielserver-vorbereiten-v1.8.sh" --source-dir "$PAKET" --user "$ZIELBENUTZER"
```

Der Dialog fragt nach Quellhost, SSH-Benutzer des Quellkanals, Port und den zwei Adminpasswörtern. Er verwendet die in Schritt 1 vorbereiteten Schlüssel und installiert App, SMS-Bibliotheken, Telefonie-PHP, JavaScript, Schema, das kanalspezifische Fetch-Skript und daneben `telepraxis-audio-delivery.py`. Der Fetch-Dienst läuft als Zielbenutzer und verwendet SSH mit Hostschlüsselprüfung. Der geprüfte Hostschlüssel des Quellservers muss diesem Benutzer bekannt sein.

Für den Audio-Abholer installiert das Zielsetup unter Debian/Ubuntu auch `python3-cryptography`, `jq`, `openssl` und `openssh-client`. Auf anderen Systemen müssen diese Programme vor dem Start bereitstehen. Der Quellkanal braucht `sha256sum` sowie Schreibzugriff auf `.kienzlefon-ack/` unter seiner Liefer-Inbox. Die Kienzlefon-Quittungsverarbeitung benötigt dort Lese- und Löschrechte.

Der Nachweis je Vorgangs-ID liegt unter `<LOCAL_PULL_DIR>/.vorgang-v1/` auf dem Zielsystem. Er gehört dauerhaft zur jeweiligen Inbox und in ihre konsistente Sicherung, auch nach Papierkorb und endgültiger Löschung. Er enthält Quittungsdaten und Versuchszähler. Die App steuert die WAV-Aufbewahrung über `TELEPRAXIS_AUDIO_RETENTION_DAYS`. Der Wert `0` deaktiviert die zeitgesteuerte Löschung. Beim Verschieben in den Papierkorb löscht die App die WAVs weiterhin.

`--source-dir` bindet sämtliche Projektdateien des jeweiligen Setups an das angegebene Verzeichnis. Unvollständige Pakete werden vor der Systemeinrichtung abgelehnt. Die Installation der Betriebssystempakete auf dem Zielhost verwendet weiterhin dessen Paketquellen. Ohne Optionen bieten beide Setup-Skripte den bisherigen interaktiven Einzelaufruf mit Online-Bezug der Projektdateien an.

## Bestehende App aktualisieren

Auf dem App-Zielsystem für beide Befehle dasselbe entpackte Paket und dieselben Zielpfade verwenden. Bei Erfolg des Prüflaufs folgt die Installation.

```bash
sudo bash "$PAKET/kienzlefon-app-update-v1.0.sh" --source-dir "$PAKET" --check &&
  sudo bash "$PAKET/kienzlefon-app-update-v1.0.sh" --source-dir "$PAKET" --yes
```

Der Standard-Webroot ist `/var/www/html`. Einen vorhandenen Worker erkennt der Updater unter `/opt/telepraxis-sms`. Bei abweichenden Pfaden beiden Befehlen dieselben Optionen `--webroot VERZEICHNIS`, `--worker-dir VERZEICHNIS` und gegebenenfalls `--web-service DIENST` und `--worker-service DIENST` mitgeben. Alle sechs Worker-Dateien einschließlich der drei Empfangsmodule gehören zu diesem Update. Der Prüflauf arbeitet ohne Dienständerung. Das Update startet nur zuvor aktive Dienste. [Details zu Erhalt, Sicherung und Rollback](APP-UPDATE.md)

Der Updater erkennt vorhandene Kanal-Abholer unter `/usr/local/bin/telepraxis_fetch_and_decrypt_<kanal>.sh`. Bei abweichendem Installationsverzeichnis beiden Aufrufen `--fetch-dir VERZEICHNIS` mitgeben. Ein Ziel mit reiner Direktablage braucht keinen Abrufkanal.
