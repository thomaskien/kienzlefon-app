# Synthetisches Vorgangspaket v1

Am 18.11.2026 gegen die Schnittstellen von kienzlefon und kienzlefon app geprüft. Die [Formatfreigabe](../uebergabe-empfangsmodul.md#technische-freigabe-des-testpakets) gilt für die dokumentierten Datenformate und den nachfolgend beschriebenen Rundlauf.

| Datei | Zweck |
| --- | --- |
| [20261118_100000_000002.kienzlepaket](20261118_100000_000002.kienzlepaket) | JWE mit unkomprimiertem ZIP. Enthält drei Teilanliegen und eine 0,2 Sekunden lange synthetische Ton-WAV für `a-002`. |
| [20261118_100000_000002.json.enc](20261118_100000_000002.json.enc) | Alternative Ersatzlieferung mit Audio-Fehlerhinweis und derselben Vorgangs-ID im bestehenden Format. |
| [Beispielquittung für das Paket](20261118_100000_000002.4982caf0b5cdd49d18845a72787bdcf4eb4f266173b8a03d64c9a77f37755010.abgelegt.ack.json) | `abgelegt` mit dem SHA-256 der exakten verschlüsselten Paketdatei. Gilt allein für den Test. |
| [SHA256SUMS](SHA256SUMS) | Prüfsummen für Lieferdateien und Beispielquittung. |

Beide Lieferdateien sind Alternativen für denselben Vorgang. Ihr Inhalt stammt aus den [gemeinsamen Beispieldateien](../../../kienzlefon/docs/vorgang-beispiele). Das Testpaket verwendet den öffentlich dokumentierten Prüfschlüssel aus [RFC 7520, Abbildung 4](https://www.rfc-editor.org/rfc/rfc7520.html#section-3.4). Er eignet sich ausschließlich für fiktive Daten. Die Prüfung liest ihn direkt aus dem RFC ein und speichert keinen privaten Schlüssel.

## Prüfung wiederholen

Aus dem App-Repository mit Python und `cryptography`, Node.js, PHP mit OpenSSL, Bash, jq, OpenSSL und dem benachbarten `kienzlefon`-Checkout ausführen.

```bash
set -o pipefail
curl --fail --silent --show-error 'https://www.rfc-editor.org/rfc/rfc7520.txt' |
  PYTHONDONTWRITEBYTECODE=1 python3 docs/vorgang-paket-v1-test/pruefen.py
```

Mit `--node /pfad/zu/node` lässt sich der Node-Pfad angeben. `--erstellen` ergänzt fehlende Prüfmuster. Bei einer Neugenerierung ändern sich die verschlüsselten Bytes und die dazugehörige Quittung.

Das Paket wurde mit Python erzeugt. Der Prüfer liest das JWE mit Node Web Crypto und prüft ZIP, Vorgangs-ID, JSON, WAV und die Zuordnung zum zweiten Rezept. Die Negativfälle umfassen falschen GCM-Tag, fehlende und zusätzliche WAV, falsche Vorgangs-ID und doppelte Teilanliegen-ID. Die bestehende PHP-App listet die in einer temporären Inbox veröffentlichte JSON genau einmal. Der unveränderte Kienzlefon-Encoder und die vorhandene Bash-Entschlüsselungsfunktion werden für Alt- und Ersatz-JSON verwendet. Die lokale Quittungsprüfung bindet den Erfolg an Status, Dateiname und den Hash der verschlüsselten Lieferung.

Die Ende-zu-Ende-Abnahme nach der Implementierung umfasst SSH-Abruf, Quittungszustellung, Spool-Bereinigung, Wiederanlauf, doppelte Lieferung und die Bedienung der Teilanliegen und Audio-Beilagen in der App.
