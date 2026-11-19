# Gemeinsame Verträge für Vorgänge v1

Freigegebener Stand vom 18.11.2026 für **Kienzlefon und Kienzlefon-App**.
Die Freigabe gilt für die Schnittstelle und die gemeinsame Abnahme.
Die Umsetzung bleibt pausiert.

| Vertrag | Inhalt |
| --- | --- |
| [Mehrfach-Anliegen v1](ANLIEGEN.md) | JSON-Feldnamen, Datentypen, stabile IDs, Altformat und gemeinsame Beispieldateien |
| [Audio-Paket und Ablagebestätigung v1](AUDIO.md) | Verschlüsseltes Paket mit JSON und WAVs, Quittung, Ersatz-JSON im bisherigen Format und Spool-Bereinigung |

Mehrfach-Anliegen und Audio-Beilagen lassen sich unabhängig verwenden.
Die beiden Repositories enthalten denselben Stand unter `docs/vorgang-v1`.
Für Änderungen zuerst den Vertragsstand in Kienzlefon aktualisieren und
anschließend den gesamten Stand in die App übernehmen. `SHA256SUMS`
bezieht sich auf die Vertragsdokumente und JSON-Beispiele in diesem
Verzeichnis.
