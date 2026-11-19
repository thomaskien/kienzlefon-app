# Komponente kienzlefon-app

## Paket bauen

Im Projektordner `ionos` unter macOS oder Linux mit Bash und Python 3 ausführen. Das Ausgabeverzeichnis muss existieren.

```bash
bash scripts/pack.sh --output /absoluter/pfad/kienzlefon-app.tar.gz
```

Die Komponenten-ID und das oberste Archivverzeichnis heißen `kienzlefon-app`.
Die Dateiliste in `scripts/pack.sh` wählt Programmdateien, Konfigurationsbeispiele, die Worker-Unit und die Installer-Anleitung direkt aus dem Arbeitsverzeichnis aus. Dazu zählen auch ungetrackte Programmdateien. Das neue Archiv ersetzt die Ausgabe erst nach erfolgreichem Packen.

## Neuinstallation

Die Setup-Skripte laufen als root auf Linux. Der Praxisserver benötigt Python 3, PHP CLI, einen Webserver für `/var/www/html` und systemd. Auf Debian/Ubuntu richtet das Zielsetup die PHP-Erweiterungen über apt ein.

Auf jedem beteiligten Linux-Host dieselbe Paketversion außerhalb des Webroots entpacken. Die folgenden Befehle verwenden beispielhaft `/opt/installer` als vorhandenes Entpackverzeichnis. `kanal1` durch den lokalen Zielbenutzer ersetzen und für beide Aufrufe auf dem App-Zielsystem beibehalten.

```bash
sudo tar -xzf /absoluter/pfad/kienzlefon-app.tar.gz -C /opt/installer
PAKET=/opt/installer/kienzlefon-app
ZIELBENUTZER=kanal1
```

### Rolle im Modulmenü auswählen

| Modul | Auswahl im Terminal | Option für Skripte |
| --- | --- | --- |
| Praxisserver | 1 · Mit Abholer | `--with-fetch` |
| Praxisserver | 2 · Ohne Abholer, lokaler Kienzlefon-Eingang | `--no-fetch` |
| Quellserver | 1 · SSH-Abrufkanal mit Formularempfang | `--with-forms` |
| Quellserver | 2 · Nur SSH-Abrufkanal für Kienzlefon mit Verschlüsseler | `--ssh-only` |

Beide Menüs öffnen sich beim Start des jeweiligen Skripts im Terminal. Enter wählt 1. Bei über die Standardeingabe automatisierten Installationen bleibt 1 die Vorgabe. Mit einer Rollenoption wird die Menüfrage übersprungen.

**Praxisserver mit lokaler Direktablage.** Zielsetup starten und 2 wählen.

```bash
sudo bash "$PAKET/zielserver-vorbereiten-v1.8.sh" --user "$ZIELBENUTZER"
```

Der Dialog fragt nach den beiden Adminpasswörtern und richtet App, SMS-Konfiguration und lokale Inbox ein. Das ausgegebene Verzeichnis `/srv/telepraxis/<Zielbenutzer>/inbox` im lokalen Kienzlefon als Ziel der Direktablage einstellen. Der schreibende Dienst benötigt Zugriff auf diese Inbox. SSH-Schlüssel, entfernte Zugangsdaten und Fetch-Dienst entfallen bei dieser Erstinstallation.

Für einen verschlüsselten Abruf von einem anderen Host gelten die folgenden drei Schritte.

### 1. Öffentliche Schlüssel für den entfernten Host vorbereiten

```bash
sudo bash "$PAKET/zielserver-vorbereiten-v1.8.sh" --prepare-keys --user "$ZIELBENUTZER"
```

Dieser Schritt richtet Kanalbenutzer, Gruppe, Home und Schlüsselverzeichnis ein. Er kann vor der Installation von PHP, Webapp und Fetch-Dienst ausgeführt werden. Bestehende Paare werden geprüft und weiterverwendet. Fehlt nur der öffentliche Teil, wird er vom vorhandenen privaten Schlüssel abgeleitet. Fehlt der private Teil eines vorhandenen Paars oder stimmen die Schlüssel nicht überein, bricht die Vorbereitung ab.

| Öffentliche Datei auf dem App-Zielsystem | Übergabe |
| --- | --- |
| `/srv/telepraxis/<Zielbenutzer>/telepraxis_decrypt_public.pem` | Dem Fon-Setup als „Pfad zur Telepraxis-Public-Key-PEM-Datei“ übergeben. Bei getrenntem Fon-Host diese öffentliche Datei dorthin kopieren. Bei aktiviertem Formularempfang geht derselbe PEM-Block zusätzlich an das Empfangs-PHP. |
| `/srv/telepraxis/<Zielbenutzer>/.ssh/telepraxis_fetch_key.pub` | Beim Einrichten des Quellkanals die eine SSH-Zeile übergeben. Sie kommt in `authorized_keys` des Abrufbenutzers. |

Die privaten Schlüssel `telepraxis_decrypt_private.pem` und `.ssh/telepraxis_fetch_key` bleiben auf dem App-Zielsystem.

### 2. Abrufkanal auf dem gewählten Quellserver einrichten

Nur für den Formularempfang bei Bedarf die Webbasis einrichten. Für den reinen Fon-Kanal genügt ein laufender OpenSSH-Server mit SCP/SFTP und `sha256sum`.

```bash
sudo bash "$PAKET/quellserver-vorbereiten-nginx-v1.2.sh"
```

Auf dem vorbereiteten Quellserver pro Abrufkanal ausführen.

```bash
sudo bash "$PAKET/quellserver-benutzer-erzeugen-v1.8.sh"
```

Beide Varianten fragen den **Abrufbenutzer auf dem Quellserver** und dessen öffentlichen SSH-Schlüssel ab. Bei Auswahl 2 ist der Kanal damit eingerichtet. Im Kienzlefon-Verschlüsseler `/srv/telepraxis/<Abrufbenutzer>/inbox` als Lieferverzeichnis und den öffentlichen RSA-Schlüssel aus Schritt 1 einstellen. Kienzlefon benötigt Schreibzugriff auf die Liefer-Inbox sowie Zugriff auf die Quittungen unter `.kienzlefon-ack/`. Dafür steht die Gruppe `tp-<Abrufbenutzer>` bereit.

Bei Auswahl 1 fragt der Installer zusätzlich nach dem RSA-Public-Key als vollständigem PEM-Block und dem IONOS-Secret. Er installiert `telepraxis-receive-<ssh-benutzer>.php` und `kontakt-<ssh-benutzer>.php` mit gemeinsamer kanalbezogener OTP-Datenbank. Den ausgegebenen PSK als `X-TP-Token` für den IONOS-Endpunkt verwenden.

### 3. App und Fetch-Dienst auf dem App-Zielsystem einrichten

```bash
sudo bash "$PAKET/zielserver-vorbereiten-v1.8.sh" --with-fetch --user "$ZIELBENUTZER"
```

Der Dialog fragt nach Quellhost, SSH-Benutzer des Quellkanals, Port und den zwei Adminpasswörtern. Er verwendet die in Schritt 1 vorbereiteten Schlüssel und installiert App, SMS-Bibliotheken, Telefonie-PHP, JavaScript, Schema, das kanalspezifische Fetch-Skript und daneben `telepraxis-audio-delivery.py`. Der Fetch-Dienst läuft als Zielbenutzer und verwendet SSH mit Hostschlüsselprüfung. Der geprüfte Hostschlüssel des Quellservers muss diesem Benutzer bekannt sein.

Für die Schlüsselvorbereitung werden OpenSSL, OpenSSH-Client und util-linux (`runuser`, `flock`) benötigt. Für den Audio-Abholer installiert das Zielsetup unter Debian/Ubuntu auch `python3-cryptography`, `jq`, `openssl` und `openssh-client`. Auf anderen Systemen müssen diese Programme vor dem Start bereitstehen. Der Quellkanal braucht `sha256sum` sowie Schreibzugriff auf `.kienzlefon-ack/` unter seiner Liefer-Inbox. Die Kienzlefon-Quittungsverarbeitung benötigt dort Lese- und Löschrechte.

Der Nachweis je Vorgangs-ID liegt unter `<LOCAL_PULL_DIR>/.vorgang-v1/` auf dem Zielsystem. Er gehört dauerhaft zur jeweiligen Inbox und in ihre konsistente Sicherung, auch nach Papierkorb und endgültiger Löschung. Er enthält Quittungsdaten und Versuchszähler. Die App steuert die WAV-Aufbewahrung über `TELEPRAXIS_AUDIO_RETENTION_DAYS`. Der Wert `0` deaktiviert die zeitgesteuerte Löschung. Beim Verschieben in den Papierkorb löscht die App die WAVs weiterhin.

Beim Start aus dem entpackten Paket verwenden Setup und Updater automatisch die Programmdateien neben dem jeweiligen Skript, unabhängig vom aktuellen Arbeitsverzeichnis. `--source-dir` wählt eine andere lokale Quelle. Fehlt eine für die gewählte Rolle benötigte Datei, bricht das Setup vor der Einrichtung ab. Ein separat kopiertes Setup-Skript bezieht benötigte Programmdateien weiterhin online. Für Betriebssystempakete verwendet der Zielhost seine üblichen Paketquellen.

## Bestehende App aktualisieren

Auf dem App-Zielsystem für beide Befehle dasselbe entpackte Paket und dieselben Zielpfade verwenden. Bei Erfolg des Prüflaufs folgt die Installation.

```bash
sudo bash "$PAKET/kienzlefon-app-update-v1.0.sh" --source-dir "$PAKET" --check &&
  sudo bash "$PAKET/kienzlefon-app-update-v1.0.sh" --source-dir "$PAKET" --yes
```

Der Standard-Webroot ist `/var/www/html`. Einen vorhandenen Worker erkennt der Updater unter `/opt/telepraxis-sms`. Bei abweichenden Pfaden beiden Befehlen dieselben Optionen `--webroot VERZEICHNIS`, `--worker-dir VERZEICHNIS` und gegebenenfalls `--web-service DIENST` und `--worker-service DIENST` mitgeben. Alle sechs Worker-Dateien einschließlich der drei Empfangsmodule gehören zu diesem Update. Der Prüflauf arbeitet ohne Dienständerung. Das Update startet nur zuvor aktive Dienste. [Details zu Erhalt, Sicherung und Rollback](APP-UPDATE.md)

Der Updater erkennt vorhandene Kanal-Abholer unter `/usr/local/bin/telepraxis_fetch_and_decrypt_<kanal>.sh`. Bei abweichendem Installationsverzeichnis beiden Aufrufen `--fetch-dir VERZEICHNIS` mitgeben. Ein Ziel mit reiner Direktablage braucht keinen Abrufkanal.
