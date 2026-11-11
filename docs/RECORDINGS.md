# Originalaufnahmen als Miniplayer in kienzlefon-app

**Status:** Optional feature, disabled by default.

## Verhalten

- In Vorgangskarten und Tabellen gibt es bei vorhandenen Kienzlefon-Audiometadaten einen einklappbaren Bereich **Originalaufnahmen**.
- Ein HTML5-WAV-Player pro tatsächlich aufgenommenem Segment, z. B. `Vorname`, `Nachname`, `Geburtsdatum`, `Rückrufnummer`, `Medikament 1`, `Medikament 2`, `Grund`.
- Die Reihenfolge entspricht `payload._kienzlefon.audio`. Beliebig viele Medikamentensegmente werden unterstützt, ohne sie zusammenzuführen.
- Startet ein Player, pausieren die anderen. Der fünfsekündige Polling-Refresh erhält bereits eingebundene Audioelemente (inklusive ihrer Abspielposition).
- Bestehende Vorgänge ohne passende Metadaten erhalten keinen Player. Die App transkribiert nicht neu und legt keine zusätzlichen Audiodateien an.

Die Dateinamen sind keine aus Telefonnummern geratenen Pfade. Für jeden JSON-Vorgang wird die darin enthaltene `_kienzlefon.vorgang_id` mit dem JSON-Dateinamen verglichen. Die Aufnahme muss unter `audio/<name>.wav` liegen. Leere oder ausdrücklich fehlende Aufnahmen werden übersprungen.

## Installation auf derselben VM wie Kienzlefon

Voraussetzung: `telepraxis-app.php` läuft auf einem Webserver mit direktem Dateizugriff auf den Kienzlefon-Spool. Bei einer getrennten Webserver-VM funktioniert der Symlink **nicht**; dann müsste das Verzeichnis gemountet oder synchronisiert werden.

### 1. Webverzeichnis ermitteln und Symlink erstellen

Der genaue DocumentRoot-Pfad der lokalen App hängt von der Installation ab. Im **Verzeichnis der produktiv ausgelieferten `telepraxis-app.php`**:

```bash
cd /ABSOLUTER/PFAD/ZUM/WEBVERZEICHNIS
sudo ln -s /var/spool/asterisk/kienzlefon/ready recordings
ls -ld recordings
```

Die Aufnahmen liegen unter `/var/spool/asterisk/kienzlefon/ready/<vorgang_id>/audio/*.wav`. Der relative Browserpfad wird `recordings/<vorgang_id>/audio/<datei>.wav`.

Falls `recordings` bereits existiert, nichts überschreiben, sondern vorher prüfen.

### 2. Leserechte des Webserver-Benutzers

Nginx/PHP auf Debian läuft oft als `www-data`; den **tatsächlichen Webserver-Benutzer** prüfen. Der Browser fordert die WAV-Dateien direkt beim Webserver an; dafür sind Leserechte auf den Dateien und Durchquerungsrechte auf allen übergeordneten Verzeichnissen nötig.

```bash
namei -l /var/spool/asterisk/kienzlefon/ready
# Beispiel nur für einen Webserver mit User www-data:
sudo -u www-data test -r /var/spool/asterisk/kienzlefon/ready/BEISPIEL-ID/audio/grund.wav && echo lesbar
```

Bevor Verzeichnisrechte geändert werden, die tatsächlichen Besitzer und Asterisk-/Worker-Zugriffe kontrollieren. ACLs sind möglich, ohne den Spool weltweit freizugeben; auch **zukünftig erzeugte** Ordner und WAVs müssen lesbar bleiben. Bitte keine pauschalen `chmod -R 777` oder `chmod -R 755` auf dem Spool ausführen.

### 3. Statische WAV-Freigabe im Webserver

Für Nginx lässt sich der Symlink direkt ausliefern. **Wichtig:** Im `ready`-Spool liegt neben den WAVs auch `call.json`, das weitere sensible Angaben enthält. Der URL-Pfad sollte deshalb **nur WAV-Dateien** bereitstellen. Keine Einzelberechtigungen pro Vorgang nötig.

Beispiel *innerhalb des passenden Nginx-`server`-Blocks* (vorhandene globale Authentisierung/Netzwerkbegrenzung bleibt bestehen):

```nginx
# Nur WAV-Dateien aus dem Symlink recordings/ anbieten:
location ~ "^/recordings/[0-9]{8}_[0-9]{6}_[0-9]{6}/audio/[A-Za-z0-9_-]+[.]wav$" {
    try_files $uri =404;
}
location /recordings/ {
    return 403;
}
```

Falls der bestehende `server`-Block eine spezifische `root`-, `alias`-, `location`- oder `disable_symlinks`-Regel nutzt, diese Konfiguration anpassen. Den Server-Konfigurationscheck vor dem Reload ausführen (`sudo nginx -t`).

Auch die direkten WAV-URLs müssen ausschließlich innerhalb der bestehenden Netzwerk-/Web-Zugriffsgrenzen erreichbar sein. **Eine PHP-Session für `telepraxis-app.php` schützt statische Dateien nicht automatisch.** Der Player führt absichtlich keine eigene Zugriffsprüfung pro Aufnahme durch.

### 4. Player aktivieren

In der produktiven `telepraxis-app.php` die neue Konstante setzen:

```php
const TELEPRAXIS_RECORDINGS_BASE_URL = 'recordings';
```

Die leere Voreinstellung deaktiviert die Anzeige vollständig. Keine neue Backend-API und kein Dienstneustart für PHP-Code nötig; bei aktivem OPcache ggf. den PHP-FPM-Worker aktualisieren.

### 5. Test ohne Patientendaten

Mit einem synthetischen Testvorgang, dessen Dateiname zur `_kienzlefon.vorgang_id` passt, prüfen:

1. In der App den Bereich **Originalaufnahmen** aufklappen.
2. Bei einer Rezeptbestellung erscheinen `Medikament 1`, `Medikament 2` usw. als getrennte Player.
3. Eine WAV abspielen und den automatischen Aktualisierungszyklus (>5 s) abwarten: Player und Abspielposition sollen erhalten bleiben.
4. Eine andere Aufnahme starten: Die erste pausiert.
5. Einen anderen Vorgang ohne `_kienzlefon.audio` prüfen: Es erscheint kein Player.
6. Mit `curl -I` prüfen, ob WAVs erreichbar sind, aber `call.json` über denselben Webpfad **403** erhält.

```bash
curl -I http://127.0.0.1/recordings/BEISPIEL-ID/audio/grund.wav
curl -I http://127.0.0.1/recordings/BEISPIEL-ID/call.json
```

Host, Port und Webpfad dem tatsächlichen Nginx-VHost anpassen.

## Nicht Teil dieses Features

Keine Audiozusammenführung, keine weiteren Exporte oder Aufnahmekonvertierung, keine neue Datenbank, keine Änderung an Kienzlefon/Asterisk und **noch keine neue Lösch- oder Aufbewahrungslogik**. Letztere sollte unabhängig vom Player geregelt werden.

Der bestehende Kienzlefon-Spool kann zusätzliche Audiosegmente enthalten. Die App zeigt ausschließlich Segmente, die mit dem Vorgangs-JSON als `payload._kienzlefon.audio` übertragen wurden und die Pfadprüfung bestehen.
