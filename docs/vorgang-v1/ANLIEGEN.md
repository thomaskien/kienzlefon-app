# Mehrfach-Anliegen v1

## Bestätigtes Verhalten in der App

- Eine JSON ergibt einen Patientenkasten mit mehreren Typmarkern, etwa **Rezept** und **Überweisung**. Die Inhalte bleiben lesbar gegliedert; die bisherige Formatierung bleibt erhalten.
- Teilanliegen lassen sich einzeln abhaken. Für erledigte Teilanliegen werden Bearbeiter und Zeitpunkt gespeichert.
- Der gesamte Kasten befindet sich immer bei genau einem Benutzer in Bearbeitung. Die Teilanliegen werden nicht gleichzeitig verschiedenen Bearbeitern zugeteilt.
- Bei einer Übernahme verschwindet der Kasten aus der Bearbeitungsansicht des bisherigen Benutzers und erscheint links beim neuen Benutzer. Bereits erledigte Teilanliegen und deren Bearbeiter bleiben erhalten.
- Nach dem letzten Häkchen bleibt der Kasten sichtbar in Bearbeitung. Erst ein zusätzlicher Klick auf **Fertig** schließt den gesamten Vorgang ab.
- Bestehende JSON-Dateien mit nur einem Anliegen funktionieren weiterhin. Das übrige App-Design bleibt unverändert.

## Zuständigkeiten und bestehender Transport

**Kienzlefon beziehungsweise das erzeugende System** liefert Patientendaten, Anliegeninhalte und stabile Kennungen. **Die App** verwaltet den aktuellen Bearbeiter, Häkchen, Abschluss und Bearbeitungshistorie. Erzeuger setzen keine App-Erledigungsstände und überschreiben sie bei einer Wiederholung nicht.

Das bestehende Transportobjekt mit `received_at`, `typ` und `payload` bleibt erhalten. Die Erweiterung liegt innerhalb von `payload`. In kienzlefon erzeugt `TelepraxisEncryptor.build_plaintext_record()` diese Hülle aus dem internen Vorgang. Beim PHP-Empfang wird der übermittelte JSON-Inhalt ebenfalls unter `payload` abgelegt. Deshalb beim bestehenden POST-Weg den Nutzinhalt senden und keine zusätzliche Hülle einschachteln; es darf kein versehentliches `payload.payload` entstehen.

Die produktive verschlüsselte Ausgabe als `.json.enc`, die bestehende Entschlüsselung mit SHA256-Prüfung und das atomare Schreiben bleiben unverändert. Die neue Anliegenliste erfordert kein neues Verschlüsselungsformat.

## Vertrag v1 für die Nutzdaten

Das bestehende Feld `anliegen` bleibt ein Textfeld. Die neue Liste heißt **`anliegen_liste`**. Eine eigene `anliegen_schema_version` kennzeichnet die neue Struktur; sie ersetzt weder die Verschlüsselungsversion noch `_kienzlefon.version`.

| Ort | Regel für Mehrfach-Anliegen v1 |
| --- | --- |
| `typ` und `payload.typ` | Beide enthalten `mehrere_anliegen`. |
| `payload.anliegen_schema_version` | JSON-Ganzzahl `1`. |
| `payload._kienzlefon.vorgang_id` | Pflichtfeld mit der eindeutigen Vorgangs-ID des Erzeugers als Zeichenkette. |
| `payload.anliegen_liste` | Geordnete Liste von mindestens zwei Objekten. Für ein einzelnes Anliegen gilt weiterhin das bisherige Format. |
| `payload.anliegen_liste[].anliegen_id` | Pflichtfeld als Zeichenkette, innerhalb des Vorgangs eindeutig. Erlaubt sind 1 bis 64 ASCII-Zeichen aus Buchstaben, Ziffern, Unterstrich und Bindestrich. |
| `payload.anliegen_liste[].typ` | Pflichtfeld als nicht leere Zeichenkette. Inhaltsfelder stehen im jeweiligen Anliegen. |

Patientendaten und Rückrufnummer stehen einmal auf Vorgangsebene. Jedes Listenelement erhält eine innerhalb des Vorgangs eindeutige, stabile `anliegen_id`, einen eigenen `typ` und die für diesen Typ vorhandenen Inhaltsfelder. Die Liste kann auch mehrere unterschiedliche Aufträge desselben Typs enthalten; der Typ ist daher keine eindeutige Anliegenkennung.

Beispiel eines Nutzdaten-Auszugs mit ausschließlich synthetischen Inhalten. Vorhandene `_kienzlefon`-Metadaten sind hier zur Übersicht ausgelassen und müssen im tatsächlichen Export erhalten bleiben:

```json
{
  "typ": "mehrere_anliegen",
  "anliegen_schema_version": 1,
  "id": "unbekannt",
  "telefon": "unbekannt",
  "vorname": "Test",
  "nachname": "Beispiel",
  "geburtsdatum": "",
  "zusammenfassung": "Rezept: Testmedikament A.\nÜberweisung: Testfachrichtung, Testanlass.",
  "anliegen_liste": [
    {
      "anliegen_id": "a-001",
      "typ": "rezeptbestellung",
      "medikamente": "Testmedikament A",
      "zusammenfassung": "Rezeptbestellung für Testmedikament A."
    },
    {
      "anliegen_id": "a-002",
      "typ": "ueb_req",
      "fachrichtung": "Testfachrichtung",
      "grund": "Testanlass",
      "zusammenfassung": "Überweisung zur Testfachrichtung wegen Testanlass."
    }
  ]
}
```

Die bekannten Typwerte und Inhaltsfelder je Anliegen weiterverwenden:

| Typ | Bestehende Inhaltsfelder |
| --- | --- |
| `rezeptbestellung` | `medikamente` |
| `ueb_req` | `fachrichtung`, `grund` |
| `termin` | `grund`, gegebenenfalls `anliegen` |
| `rueckruf_details`, `rueckruf_tel_grund` | `grund` |
| `sonstiges` | `anliegen` |

Dies ist keine abschließende Typ-Whitelist. Weitere Typen und zusätzliche Nutzfelder dürfen beim Empfang nicht verworfen werden. Eine Zusammenfassung ergänzt die ursprünglichen Inhalte und ersetzt sie nicht. Zeilenumbrüche, Umlaute und vorhandene Textfelder erhalten; die App stellt Texte sicher dar und führt übermitteltes HTML nicht aus.

## Kennungen und Bearbeitungsstände

- Die bisherige Nutzdaten-`id` bezeichnet im kienzlefon-Spool die Anruferkennung. Sie ist keine eindeutige Vorgangs- oder Teilanliegen-ID und darf dafür nicht umgedeutet werden.
- `_kienzlefon.vorgang_id` bezeichnet den bestehenden kienzlefon-Vorgang. Die App ordnet ein Teilanliegen über das Paar `(vorgang_id, anliegen_id)` zu. Der Erzeuger vergibt und speichert die ID bei der Anlage, etwa `a-001`. Sie bleibt bei Wiederholung, Transkriptkorrektur und Umordnung gleich. Eine einmal verwendete ID wird innerhalb desselben Vorgangs nicht erneut vergeben.
- Mehrere Anrufe oder verschiedene Patienten werden nicht allein anhand gleicher Telefonnummern zu einem Kasten zusammengeführt. Diese Erweiterung betrifft mehrere Anliegen innerhalb eines gemeinsamen Vorgangs.
- Erledigungsstände werden im App-Bereich neben `payload` gespeichert und über die stabilen Anliegen-IDs zugeordnet. Die genaue Struktur des App-Bereichs ist eine interne Entscheidung der App. Der Erzeuger liefert diesen Bereich nicht mit.
- Die App muss Bearbeiterwechsel und Schreibzugriff serverseitig zusammenhängend prüfen. Ein alter Browserstand darf nach einer Übernahme keine Teilanliegen des neuen Bearbeiters verändern.

## Aufnahmen und Verarbeitung in kienzlefon

Der bestehende WAV-Abspieler verwendet `_kienzlefon.audio`, die Vorgangs-ID und relative Dateinamen der Form `audio/<dateiname>.wav`. Diese Zuordnung und die vorhandenen Audiofelder erhalten.

Für Aufnahmen eines bestimmten Teilanliegens enthält der jeweilige Audioeintrag dessen `anliegen_id`. Gemeinsame Aufnahmen, etwa zum Patientennamen, bleiben vorgangsbezogen. Eindeutige Dateinamen wie `audio/a-001-medikament-01.wav` vermeiden Überschreiben bei mehreren gleichartigen Anliegen. Eine neue Verzeichnistiefe wie `audio/a-001/medikament-01.wav` ist mit der aktuellen App-Pfadprüfung nicht kompatibel.

Die Transkriptverarbeitung muss das richtige Teilanliegen aktualisieren. Ein globales Feld `medikamente` oder `grund` allein reicht bei mehreren Anliegen nicht mehr zur Zuordnung. Bestehende Texte, Aufnahmen, Fehlerbehandlung und Wiederholungen dürfen dabei nicht verloren gehen. Für Übergabe und Audioaufbewahrung gilt der [Audiovertrag v1](AUDIO.md).

## Kompatibilität und Einführung

Für bisherige Vorgangstypen ohne die neuen Felder gilt der Ein-Anliegen-Ablauf. Beim Typ `mehrere_anliegen` mit fehlender oder ungültiger Liste, ungültigen IDs oder unbekannter Schemaversion zeigt die App den Vorgang mit seinen vorhandenen Texten und einem Strukturhinweis an. Die Teilhäkchen werden in diesem Fall gesperrt. Die Annahme anderer JSONs durch den Empfang bleibt davon unabhängig.

Ein alter App-Stand versteht die Liste noch nicht. Eine vollständige Gesamtzusammenfassung hilft bei der Textanzeige, ersetzt aber weder mehrere Typmarker noch die einzelnen Häkchen. Deshalb die neue Ausgabe erst mit einer kompatiblen App aktivieren. Die Unterstützung alter Ein-Anliegen-Dateien durch die neue App ist verbindlich; vollständige Unterstützung neuer Listen durch alte Apps wird nicht vorausgesetzt.

Eine Wiederholung derselben Vorgangs-ID erzeugt keinen weiteren Patientenkasten. Für v1 bleibt der zuerst importierte fachliche Inhalt maßgeblich. Spätere automatische Inhaltskorrekturen benötigen eine eigene Vereinbarung. Eine Fehler-JSON kann dem bestehenden Vorgang gemäß der [Abholerspezifikation](AUDIO.md#fehler-und-spool-bereinigung) einen Audiohinweis hinzufügen.

Das Format ist für jeden kompatiblen Erzeuger nutzbar. Welche Erfassungswege tatsächlich mehrere Anliegen aufnehmen, wird bei deren Anbindung festgelegt.

## Gemeinsame Beispieldateien

Die Dateien sind vollständige synthetische Vorgangsobjekte für Kienzlefon und App. Für einen PHP-POST wird jeweils nur `payload` entnommen.

| Datei | Erwartung |
| --- | --- |
| [Ein Rezept im bisherigen Format](beispiele/alt-ein-anliegen.json) | Ein Vorgang im bisherigen Ablauf. |
| [Zwei Rezepte und eine Überweisung](beispiele/mehrere-anliegen.json) | Ein Vorgang mit drei einzeln abhakbaren Anliegen. Die WAV gehört zum zweiten Rezept. |
| [Doppelte Teilanliegen-ID](beispiele/ungueltige-anliegen-ids.json) | Inhalte bleiben sichtbar, der Strukturhinweis erscheint und die Teilhäkchen bleiben gesperrt. |
| [JSON-Ersatzlieferung mit Audiofehler](beispiele/audio-fehler.json) | Dieselbe Vorgangs-ID und dieselben Teilanliegen wie im Mehrfachbeispiel. Ein Audiohinweis erscheint. Die JSON enthält keine WAV-Verweise. |

## Abnahmekriterien

- Ein altes Rezept-JSON bleibt unverändert nutzbar.
- Rezept und Überweisung in einer JSON ergeben einen Kasten, zwei Typmarker und zwei getrennte Häkchen.
- Zwei eigenständige Anliegen desselben Typs behalten unterschiedliche IDs und Erledigungsstände.
- Nach einem Häkchen bleibt das andere Anliegen offen. Nach dem letzten Häkchen erfolgt kein automatischer Gesamtabschluss; **Fertig** schließt den Vorgang ausdrücklich ab.
- Bei einer Übernahme wechselt der Kasten zum neuen Bearbeiter nach links; vorhandene Häkchen samt Urhebern bleiben erhalten. Veraltete Schreibversuche werden abgewiesen.
- Umordnung oder Wiederholung vertauscht keine Inhalte, Häkchen oder Aufnahmen. Fehlende und doppelte IDs werden erkannt, ohne den ursprünglichen Inhalt zu verlieren.
- Verschlüsselung, Entschlüsselung und JSON-Rundlauf erhalten Liste, Kennungen, unbekannte Typen, Zusatzfelder, Zeilenumbrüche und Umlaute.
- Vorhandene Aufnahmen bleiben abspielbar. Mehrere gleichartige Audiofelder werden dem richtigen Teilanliegen zugeordnet.
