# Planung der nächsten kienzlefon app Version

**Telefonie-Vertragsstand 18.11.2026:** Die Schnittstellen für Live-/Tageszähler,
Rufgruppen und geschützten Zugriff sind im [gemeinsamen Vertrag v1](telefonie-v1/README.md)
festgelegt, mit Schema und identischen Beispielen in allen drei Projekten.
App 3.7 implementiert den App-Proxy und seine Oberfläche. Die Einrichtung und
die gemeinsame Abnahme mit Middleware und Telefonanlage folgen getrennt.

Stand: 18.11.2026. Bestätigte Anforderungen für die nächste App-Version. Die Schnittstellen sind vor der Integration mit Telefonanlage, Middleware und JSON-Erzeugern abzugleichen.

## Bestätigte Ausgangsdatei

Grundlage ist `telepraxis-app.php` im Commit `2f11f0d6c99abeea6403936f76bba8d0c6d60801`, einschließlich der drei übernommenen Erweiterungen: automatisches Schließen des Patiententabs nach erfolgreicher Übergabe, optionaler WAV-Abspieler und Bearbeiten-Button in der Tabelle.

Der alte Release-Stand 3.6 vor allen drei Pull Requests ist unter `v3.6` am Commit `e1f6a519d8a7400ae0be2e4ab90b59436b4a8eed` erhalten. Die Umsetzung trägt Version 3.7. Die Veröffentlichung ist separat.

## Mehrere Anliegen in einem Vorgang

- Eine JSON kann mehrere Anliegen desselben Patienten enthalten. Sie erscheinen in einem gemeinsamen Patientenkasten mit den passenden Typmarkern, beispielsweise Rezept und Überweisung.
- Jedes Teilanliegen lässt sich einzeln abhaken. Bearbeiter und Zeitpunkt bleiben nachvollziehbar. Ursprüngliche Inhalte und deren Formatierung bleiben erhalten.
- Nach dem letzten Häkchen bleibt der Kasten in Bearbeitung. Der gesamte Vorgang wird ausdrücklich mit **Fertig** abgeschlossen.
- Bestehende JSON-Dateien mit nur einem Anliegen bleiben kompatibel. Der [gemeinsame Vertrag für Vorgangsdaten v1](vorgang-v1/ANLIEGEN.md) beschreibt `payload.anliegen_liste`, stabile IDs und vollständige Beispieldateien. Das vorhandene Textfeld `payload.anliegen` bleibt Freitext.

## Ein Bearbeiter pro Patientenkasten

Der gesamte Kasten befindet sich immer bei genau einem Benutzer in Bearbeitung. Bei einer Übernahme verschwindet er aus der Bearbeitungsansicht des bisherigen Benutzers und erscheint im linken Bereich des neuen Benutzers. Bereits erledigte Teilanliegen und deren Bearbeiter bleiben erhalten.

Der Server muss den Wechsel zusammenhängend durchführen und spätere Schreibversuche des bisherigen Bearbeiters abweisen, auch wenn dessen Browser noch einen alten Stand anzeigt. Die Oberfläche aktualisiert die Zuordnung über die bestehende Aktualisierung. Die frei eingegebene Benutzerbezeichnung bleibt Teil des bestehenden Bedienmodells; sie ersetzt keine Benutzeranmeldung.

## Bestehendes Design und Funktionen

Nur die besprochenen Ergänzungen ändern die Oberfläche. Farben, Schrift, bestehende Karten, Tabellen und Spaltenaufteilung bleiben erhalten. Inbox, Statuswechsel, Papierkorb mit Wiederherstellen und endgültigem Löschen, Dringend-Markierung, Polling, Benachrichtigungen, Kommentare, SMS, Druck und Textkopie bleiben funktionsfähig. Der Fokus im Kommentarfeld muss Aktualisierungen weiterhin überstehen.

**Vorgemerkt für Druck und Textkopie (19.11.2026).** Beide Ausgaben sollen Bearbeiter und Platz aus derselben Zuordnung zeigen. Markiert z. B. Anna an Platz 2 einen Vorgang von Julia an Platz 1 als dringend, soll auf dem Ausdruck und in der Textkopie weiterhin „Julia, Platz 1“ stehen.

## Getrennte Integrationen und Telefonieübersicht

- T2med-Patientenanbindung und Telefoniefunktionen lassen sich getrennt im Interface aktivieren. Der Aktenaufruf bleibt am Patientenkasten.
- Die Telefonieübersicht ist in die App integriert. Oberhalb des grauen Arbeitsbereichs bleibt die bisherige Zählerleiste bestehen und erhält zusätzliche Bubbles für **Warteschleife**, **IVR**, **AI** und **Anrufe heute**.
- Nebenstellen erscheinen nebeneinander in einem ausklappbaren Bereich. Der Name steht oben, die Nebenstellennummer rechts neben dem Status. Eine zusätzliche mittlere Zeile und ein gesonderter großer Warteschleifen-/IVR-/AI-Block entfallen.
- Die Rufgruppen-Häkchen folgen dem besprochenen Screenshot und heißen **Normal** und **ROT**. **ROT** ist die am 18.11.2026 festgelegte Bezeichnung für die bisher als IVR 9 bezeichnete Fachstellen-/Rottelefon-Gruppe. Beide Gruppen sind unabhängig. Freigaben und aktuelle Einstellungen kommen über die Middleware aus der Telefonanlage.
- Die App zeigt ausschließlich den bestätigten Anlagenzustand. Sie setzt weder eine eigene Lebensdauer für Rufzuordnungen noch stellt sie nach einem Neustart frühere Häkchen aus ihrem Speicher wieder her.

Einzelrechte, Gruppenregeln und Schnittstellen stehen in der [Abstimmung der Nebenstellensteuerung](ABSTIMMUNG-NEBENSTELLENSTEUERUNG-2026-11-17.md).

### In App 3.8.4 umgesetzte Anzeigeänderungen (26.–27.11.2026)

- **Anrufanzeige oberhalb der Nebenstellen.** In beiden Zuständen („Klingelt“ und „Im Gespräch“) die Statusbezeichnung, den Namensknopf, die Rufnummer und den gegebenenfalls sichtbaren Knopf „Vermitteln“ auf einer gemeinsamen Grundlinie ausrichten. Zwischen allen sichtbaren Elementen den gleichen gut sichtbaren Abstand lassen, auch auf beiden Seiten des Namensknopfs und zwischen Rufnummer und „Vermitteln“. Fehlt der Namensknopf, gilt derselbe Abstand direkt zwischen Statusbezeichnung und Rufnummer. Das Anzeigefeld etwas breiter machen und links und rechts gleich viel Innenabstand vorsehen.
- **Telefonliste.** Die sichtbaren Statuswörter „Frei“, „Klingelt“ und „Belegt“ entfernen. Das gesamte Feld der jeweiligen Nebenstelle dezent mit der Statusfarbe hinterlegen. Vorgesehene Zuordnung ist Grün für frei, Orange für klingelt, Rot für belegt und Grau für einen unklaren Status oder eine unregistrierte Nebenstelle.
- **Beschriftung und Reihenfolge (27.11.2026).** Bezeichnung und Nebenstellennummer in einer Zeile anzeigen. Die Nummer bleibt rechtsbündig und vollständig sichtbar. Die Bezeichnung steht linksbündig und endet bei Platzmangel mit „…“. Die Liste numerisch aufsteigend nach Nebenstellennummer sortieren. Die Zifferntasten zur Nebenstellenwahl folgen der sichtbaren Reihenfolge.

## Audio-Beilagen und Löschung

Die aktive Liste liest nur Vorgangs-JSONs direkt in der konfigurierten Inbox.
Gelöschte Vorgänge liegen in `inbox/papierkorb/`, das nur bei geöffnetem
Papierkorb mit Admin-Sitzung eingelesen wird. Wiederherstellen verschiebt die
JSON zurück in die Inbox. Ist der Dateiname im Ziel bereits belegt, erhält die
verschobene Datei den ersten freien nummerierten Namen, etwa `fall-2.json`.
Das gilt auch für die Übernahme alter Papierkorbeinträge.
Bei einem an den Dateinamen gebundenen SMS-Verlauf erfordert eine Kollision
die Prüfung der Zuordnung.
Die Praxis kann den Papierkorb beliebig lange behalten.

Der Kienzlefon-Installer fragt bei Installation, Update und Neukonfiguration,
ob Audio beigelegt oder nur JSON ausgegeben wird. Beigefügte WAVs liegen
dauerhaft ausschließlich im Begleitverzeichnis des Vorgangs in der Inbox.
Ab der Ablage entscheidet allein die App über Aufbewahrung und Löschung.
Eine mögliche Löschfrist ist eine Einstellung der App.

Nach **Fertig** steht der Vorgang unter **Abgeschlossen** und vorhandene
WAVs bleiben abspielbar. Beim Verschieben in den **Papierkorb** löscht die
App alle noch vorhandenen WAVs des Vorgangs. Das gilt für
neue und abgeschlossene Vorgänge. Vorgänge in Bearbeitung sind vor dem
Verschieben geschützt, auch für den aktuellen Bearbeiter. Im Papierkorb liegt ausschließlich
die JSON. Wiederherstellen bringt nur die JSON zurück. Player und Metadaten an die
vorgangsbezogene Beilage anpassen. Verschlüsseltes Paket, Fehler-JSON und
Spool-Bereinigung stehen in der
[Spezifikation für den Abholer v1](vorgang-v1/AUDIO.md).
Details zum Player stehen unter
[Originalaufnahmen](RECORDINGS.md#vorgangsbezogene-audio-beilagen-ab-app-37).

## Zähler und technische Integration

Die Live-Zahlen für Warteschleife, IVR und AI zählen aktuell aktive Anrufe. **Anrufe heute** zählt ursprüngliche externe Eingänge einmal pro Anruf und Tag in Europe/Berlin, einschließlich anonymer und vor Annahme abgebrochener Anrufe. Interne und ausgehende Anrufe zählen nicht dazu. Weiterleitungen erzeugen keinen neuen Eingang. Die App übernimmt die Zahlen aus der Middleware; fehlende Daten werden nicht als null Anrufe dargestellt.

Die [Übergabe zum Tageszähler](UEBERGABE-ANRUFZAEHLER-2026-11-17.md) und der aktuelle [Middleware-Statistikvertrag](../../T2med-asterisk/docs/ANRUFSTATISTIK.md) sind gemeinsam abzugleichen. Die Statistikereignisse und die Live-Erkennung von IVR-/AI-Anrufen müssen von den beteiligten Systemen tatsächlich bereitgestellt werden.

Vor der Integration technisch abzuschließen:

1. Teilanliegen und Audio-Beilagen anhand des [technisch freigegebenen Testpakets](vorgang-paket-v1-test/README.md) umsetzen.
2. Geschützter Anlagenzugang der Middleware und konfigurierbarer Zugriffspfad der App, einschließlich Browser-Origin-Regeln. Private Serveradressen und Zugangsdaten gehören nicht in diese Planung.
3. Gemeinsame Testantworten für Rufgruppen, Rechte, Änderungen, Konflikte, Ausfälle und Anlagenneustarts sowie Live- und Tageszähler.
4. Konfigurationserhalt beim App-Update und Korrektur der beim Commit-Review gefundenen Testprobleme. Mehrbenutzerwechsel, Abschlussregeln und die bestehenden App-Funktionen bei der Umsetzung prüfen.

## Technische Prüfung von App 3.7

Die App-Regression umfasst 182 automatisierte Tests. Zusätzlich prüft Chrome
den Vorgang mit drei Teilanliegen, die Wiedergabe-URL für lokale WAVs, den
separaten Papierkorb, die unabhängigen T2med- und Telefonie-Schalter sowie die
Zähler bei schmalen Fenstern. Der lokale Middleware-Stand verarbeitet über
den App-Proxy eine Übersicht und einen bestätigten Normal-Schaltauftrag an
der simulierten Anlage. Der verschlüsselte Audio-Abholer wurde am 19.11.2026
aus dem App-Paket mit Kienzlefon 2.6.0 offline geprüft. Ausstehend sind die
Abnahme an einer realen Telefonanlage und der Test am tatsächlichen SSH-Kanal.
