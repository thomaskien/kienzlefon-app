# Abstimmung der Nebenstellensteuerung

> **Abgelöst am 18.11.2026:** Der [gemeinsame Telefonievertrag v1](telefonie-v1/README.md)
> legt Endpunkte, Zugriff, JSON-Typen, Live-Zähler, Rufgruppenaufträge und
> Neustartverhalten jetzt vollständig fest. Das lokale Paket enthält dieselben
> Schemata und Testbeispiele wie die anderen Projekte. Neue Funktionen sind
> damit definiert, noch nicht implementiert oder produktiv abgenommen.
>
> Der folgende Text bleibt als **historischer Abstimmungsstand** erhalten.
> Bei Abweichungen gilt ausschließlich der neue Vertrag, insbesondere
> Normal/ROT, der geschützte App-PHP-Proxy und die befristeten Änderungsaufträge.

Stand: 18.11.2026

Status: Schnittstellenentwurf für die gemeinsame Umsetzung in `kienzlefon`, `kienzlefon-t2med` und `kienzlefon-app`.

Abgleich mit der Kienzlefon-Planung vom 17.11.2026: Die bereits vereinbarten Freigaben pro Nebenstelle, Rufgruppe und Schaltrichtung sowie die frei belegbaren IVR-Tasten sind unten ergänzt. Die [Kienzlefon-Abstimmungsvorlage](../../kienzlefon/docs/RUFGRUPPEN-ABSTIMMUNG.md) verweist auf diesen Entwurf. Endpunktnamen und das vollständige Schema bleiben bis zum gemeinsamen Vertragsabschluss Vorschläge.

Benutzerpräzisierung und Abgleich mit der [Middleware-Abstimmung](../../T2med-asterisk/docs/NEBENSTELLENSTEUERUNG-ABSTIMMUNG.md): **Die aktuellen Einstellungen kommen aus der Telefonanlage.** Die App übernimmt den dort bestätigten Zustand. Die frühere Forderung dieses Entwurfs, jede App-Änderung dauerhaft in die Grundkonfiguration zu schreiben, entfällt. Die bestätigten App-Anforderungen einschließlich Ausgangsstand und Vorgangsbearbeitung stehen in der [Versionsplanung](PLANUNG-NAECHSTE-APP-VERSION-2026-11-17.md).

## Gewünschtes Verhalten

In der kienzlefon app erhält jede Nebenstelle zwei Häkchen: **Normal** und **ROT**. Der Benutzer hat die neue Bezeichnung **ROT** am 18.11.2026 festgelegt; sie ersetzt die bisherige Anzeige **IVR 9**. Damit lässt sich ihre Teilnahme an den jeweiligen Rufwegen einstellen. Die Bedienung soll zentral in der kienzlefon-Administration aktivierbar und deaktivierbar sein. Änderungen müssen in Administration, Middleware und App denselben Zustand betreffen.

Das bestehende App-Design bleibt erhalten. Die Checkbox-Zeile ergänzt ausschließlich die besprochene ausklappbare Nebenstellenübersicht. Name, Status und rechts daneben die Nebenstellennummer bleiben wie vereinbart. Warteschleife, IVR, AI und Anrufe heute erscheinen als zusätzliche Bubbles in der bisherigen Zählerleiste; ein zusätzlicher großer Statusblock entfällt.

## Gemeinsame Zuständigkeiten

| Projekt | Verantwortung |
| --- | --- |
| **kienzlefon und Administration** | Verwalten administrative Grundeinstellungen, Freigaben und wirksame Rufzuordnungen. Der geschützte Dienst validiert und übernimmt Änderungen in die Telefonanlage. |
| **kienzlefon-t2med Middleware** | Liefert der App Freigabe, bestätigte Zuordnungen und Änderungsstatus. Leitet zugelassene Änderungsaufträge an den zuständigen kienzlefon-Dienst weiter. |
| **kienzlefon app** | Zeigt den gemeinsamen Zustand an und sendet gezielte Änderungswünsche. Speichert keine eigene verbindliche Rufzuordnung. |

Der Datenfluss für eine Änderung ist damit:

```text
App → Middleware → geschützter kienzlefon-Dienst → wirksame Rufzuordnung
App ← Middleware ← bestätigter Zustand und Ergebnis
Administration → derselbe Dienst und dieselbe Konfiguration
```

Die Middleware führt keinen konkurrierenden, unabhängig bearbeitbaren Bestand der Rufzuordnungen. Ein Cache darf nur der Anzeige dienen und muss als veraltet erkennbar sein.

## Anlagenzustand und Neustarts

Die App liest nach einer Verbindung oder einem Neustart erneut den Anlagenzustand. Sie entscheidet weder über dessen Lebensdauer noch setzt sie aus einem eigenen Speicher alte Häkchen zurück. Eine Änderung ist erst bestätigt, wenn die Anlage sie übernommen hat.

Die Middleware-Abstimmung unterscheidet dauerhafte administrative Grundeinstellungen von vorübergehenden Bedienänderungen aus App und Middleware. Nach einem Anlagenneustart gelten dort wieder die Grundeinstellungen. Ein Browser- oder Middleware-Neustart setzt die Anlage nicht zurück. Diese Lebensdauer wird auf der Anlage umgesetzt; die App zeigt jeweils deren aktuellen Zustand.

Revisionen und Änderungsaufträge müssen die jeweilige Anlagenlaufzeit berücksichtigen. Nach einem Anlagenneustart dürfen alte ausstehende oder protokollierte Bedienaufträge keine vorübergehenden Einstellungen erneut anwenden. Vor einer neuen Änderung werden Zustand und Rechte frisch abgerufen.

## Zentrale Freigabe und tatsächliche Rufzuordnung

Vorgeschlagener Administrationsschalter: **Nebenstellenzuordnung über die kienzlefon app erlauben**.

- Die Freigabe erlaubt oder sperrt Änderungen über die App. Sie ist keine weitere Rufgruppenmitgliedschaft.
- Zusätzlich verwaltet die Administration die Rechte zum Aktivieren und Deaktivieren **pro Nebenstelle und Rufgruppe getrennt**. Beispielsweise darf ein Sprechzimmer die normale Gruppe ausschalten, während seine Teilnahme an der Rottelefon-Sondergruppe gegen Abschalten gesperrt ist. Ein globaler Schalter oder ein einziges `editable` pro Nebenstelle ersetzt diese Rechte nicht. Fehlende Einzelrechte erteilen keine Schreibfreigabe.
- Die Freigaben gelten für die betroffene Nebenstelle und Rufgruppe. Die App führt keine zusätzliche Beschränkung auf die eigene Arbeitsplatz-Nebenstelle ein.
- Beim Sperren bleiben gespeicherte Zuordnungen und laufende Gespräche erhalten. In der App werden die Häkchen schreibgeschützt angezeigt; die Administration kann die Zuordnung weiterhin verwalten.
- Die Middleware prüft zentrale Freigabe, betroffene Nebenstelle, Rufgruppe und gewünschte Schaltrichtung bei jedem Änderungsauftrag. Der zuständige kienzlefon-Dienst prüft sie nochmals unmittelbar bei der Ausführung, damit ein bereits wartender Auftrag eine zwischenzeitliche Sperre nicht umgeht. Entfernen oder Pausieren eines Mitglieds darf eine gesperrte Deaktivierung nicht umgehen. Die App und die Middleware können ihre Freigaben nicht selbst erweitern.
- Bei fehlender Konfiguration, unbekannter Freigabe oder nicht erreichbarem zuständigem Dienst sind keine Änderungen möglich. Neue Installationen starten ohne freigegebene Fernbedienung.
- Der lokale App-Schalter für die Telefonieanzeige und die getrennte T2med-Patientenanbindung bleiben davon unabhängig. Ein Anzeige-Schalter erteilt keine Berechtigung zur Änderung von Rufwegen.
- Die frei eingegebenen App-Felder Benutzer und Platz sind keine Benutzeranmeldung. Sie können zur Zuordnung und Protokollierung dienen, aber nicht allein Schreibrechte begründen.

## Vorhandene technische Grundlagen

Im eingesehenen kienzlefon-Arbeitsstand `799378b` arbeitet `webinterface/admin.php` mit JSON-Aufträgen an einen privilegierten Dienst in `kienzlefon/webadmin.py`. PHP bearbeitet die geschützte TOML nicht direkt. Der Dienst prüft erlaubte Aktionen, eine Konfigurationsrevision und die neue Konfiguration vor der atomaren Übernahme. An diese Grenze anknüpfen.

Der lokale kienzlefon-Checkout liegt hinter seinem vorhandenen `origin/main` und enthält fremde Änderungen. Auch im Middleware-Projekt bestehen laufende Änderungen, unter anderem für die Anrufstatistik. Vor der Umsetzung den tatsächlich vorgesehenen Ausgangsstand feststellen; keine fremden Änderungen zurücksetzen oder aus einem älteren Stand rekonstruieren.

Für die konkrete Integration sind insbesondere diese Stellen relevant:

| kienzlefon | Middleware und App |
| --- | --- |
| `kienzlefon/config.py`, `kienzlefon/webadmin.py`, `webinterface/admin.php` | `src/HttpApi.php`, `src/Runtime.php`, `src/Config.php` in kienzlefon-t2med |
| `kienzlefon/ivr.py`, `kienzlefon/asterisk.py` | Bestehende `GET /api/v1/telephony`-Antwort mit Nebenstellenstatus |
| `kienzlefon/queue_dispatch.py`, `kienzlefon/queue_dialplan.py` | `telepraxis-app.php` in kienzlefon-app, erst nach abgestimmtem Vertrag |

Die Anrufstatistik ist ein separater Auftrag; siehe [Übergabe Tageszähler](UEBERGABE-ANRUFZAEHLER-2026-11-17.md). Deren API-Felder und laufende Implementierung nicht überschreiben.

## Bedeutung von Normal und ROT

**Normal** bezeichnet die Teilnahme an den normalen eingehenden Praxisrufen. **ROT** bezeichnet die Rufgruppe des Fachstellen-/Rottelefon-Rufwegs. Beide Einstellungen müssen unabhängig voneinander möglich sein. Frei, besetzt, klingelnd und nicht erreichbar bleiben davon getrennte Live-Zustände: Ein freies Telefon ist nicht automatisch Mitglied einer Rufgruppe.

Die IVR-Tasten sollen einzeln umbelegbar und deaktivierbar werden. Die stabilen fachlichen Gruppenkennungen im gemeinsamen Entwurf sind deshalb `normal` und `specialist`; ihre Anzeigenamen lauten **Normal** und **ROT**. Die Umbenennung der Anzeige ändert die technische Kennung `specialist` nicht. Die Bezeichnung **ROT** bleibt unabhängig von der aktuell konfigurierten IVR-Taste. Das Deaktivieren eines IVR-Menüpunkts deaktiviert nicht automatisch die zugehörige Gruppe oder ihre gesonderte Direktnummer.

Der vorhandene ROT-Rufweg ist mehrstufig: `ivr.py` verweist bei verfügbarer Fachstellenfunktion auf `kienzlefon-specialist`; `asterisk.py` versucht je nach Konfiguration zunächst das rote Telefon und danach die priorisierte Sonderqueue. Die Bedeutung des Häkchens ist deshalb nicht allein durch Hinzufügen zu einer beliebigen Queue erfüllt.

Die fachlichen Vorgaben der Middleware-Abstimmung gelten auch für die App:

1. Das Häkchen **ROT** steuert die Teilnahme am gemeinsamen Klingeln. Ohne aktiviertes separates rotes Telefon klingeln die eingeschalteten ROT-Mitglieder sofort. Mit rotem Telefon erfolgt zuerst dessen separater Rufversuch; erst bei Nichtannahme, Besetzt oder Nichterreichbarkeit klingelt die Gruppe gemeinsam. Bei Annahme am roten Telefon folgt kein Gruppenklingeln. Der erste Rufversuch bleibt vom Gruppenhäkchen unabhängig. Die Gruppe übernimmt keine Mitglieder automatisch aus der normalen Praxisgruppe. Das Häkchen schaltet weder das rote Telefon noch die Direktnummer des ROT-Rufwegs global ab.
2. Der letzte eingetragene Teilnehmer einer Gruppe darf nicht entfernt werden. Diese Prüfung erfolgt auf der Anlage zusammen mit der Änderung und gilt auch bei gleichzeitigen Aufträgen. Besetzt oder offline bedeutet keinen Gruppenaustritt. Wirksame Rechte und Sperrgründe müssen diese Regel abbilden.
3. Bestehende Rufstufen, Queue-Reihenfolge, Penalties, Gewichte und Local-Kanalsteuerung erhalten. Die Stufe neuer Normal-Mitglieder wird in der Administration festgelegt. Kein pauschales Ersetzen der bisherigen Logik durch direkte `QueueAdd`-/`QueueRemove`-Befehle aus der Middleware.

Zeitregeln, Ansagen, Prioritätswege und laufende verbundene Gespräche bleiben erhalten. Änderungen betreffen nachfolgende Rufangebote. Es werden weder Gespräche getrennt noch Ansagen neu erzeugt oder Asterisk vollständig neu gestartet, nur weil ein Häkchen geändert wurde.

## Vorschlag für den Vertrag zur App

Die folgenden Felder und Routen sind **neu vorgeschlagen**, keine Behauptung über bereits vorhandene Endpunkte. Vor getrennter Implementierung einen gemeinsamen Vertrag mit identischen Testdaten festlegen.

`GET /api/v1/telephony` additiv ergänzen: Freigabe und Aktualität unter `routing_control`; die Zuordnungen an den bereits vorhandenen Nebenstellen. Beispielauszug:

```json
{
  "routing_control": {
    "supported": true,
    "enabled": true,
    "available": true,
    "revision": "routing-17"
  },
  "extensions": [
    {
      "id": "201",
      "label": "Empfang 1",
      "state": "idle",
      "paused": false,
      "routing": {
        "normal": true,
        "specialist": true,
        "permissions": {
          "normal": {
            "can_enable": true,
            "can_disable": true,
            "enable_reason": null,
            "disable_reason": null
          },
          "specialist": {
            "can_enable": true,
            "can_disable": false,
            "enable_reason": null,
            "disable_reason": "admin_locked"
          }
        }
      }
    }
  ]
}
```

`available` bezeichnet einen verlässlich nutzbaren Routingzustand und ist vom AMI-Livestatus getrennt. Unbekannte Mitgliedschaften als `null`, nicht als `false`, darstellen. `permissions` enthält pro Rufgruppe die wirksamen Rechte für beide Schaltrichtungen und gegebenenfalls einen Sperrgrund. Diese Rechte berücksichtigen zentrale Freigabe, verfügbare Zustandsdaten, erreichbaren Dienst und die Einzelrechte der Nebenstelle. Bei unbekannter Mitgliedschaft zuerst den Zustand klären. Eine aktive Mitgliedschaft darf nur mit `can_disable` abgeschaltet, eine inaktive nur mit `can_enable` eingeschaltet werden. Fehlende Rechte sind keine Freigabe; der berechtigte Client kann den bekannten Status auch bei gesperrter Änderung lesen. Ältere Middleware-Versionen ohne diese Erweiterung ermöglichen weiterhin die bisherige Anzeige, aber keine neuen Routingänderungen.

Vorgeschlagener gezielter Schreibauftrag an die Middleware: `POST /api/v1/extension-routing`. Ein Auftrag setzt einen eindeutigen Zielwert und invertiert nicht einfach den aktuellen Wert:

```json
{
  "client_id": "empfang-1",
  "extension": "201",
  "route": "normal",
  "member": false,
  "expected_revision": "routing-17",
  "request_id": "11111111-1111-4111-8111-111111111111"
}
```

- Nur bekannte Nebenstellen und die freigegebenen fachlichen Routennamen akzeptieren. Keine frei übergebenen Kanalnamen, Queue-Namen, Shell-Kommandos oder Dateipfade.
- Identische `request_id` mit identischem Inhalt liefert dasselbe Auftragsergebnis. Wiederverwendung mit anderem Inhalt wird abgewiesen. Deduplizierung bis zur zuständigen schreibenden Instanz durchführen, auch bei Transportwiederholungen.
- Die Revision umfasst Zuordnungen, zentrale Freigabe, Einzelrechte pro Nebenstelle/Rufgruppe und die Anlagenlaufzeit. Prüfung und Änderung erfolgen zusammen unter derselben Sperre beziehungsweise Transaktion, einschließlich des Schutzes des letzten Gruppenmitglieds. Bei einem Konflikt antwortet die Middleware mit HTTP 409 und fordert einen neuen Zustandsabruf an; keine fremde Änderung überschreiben.
- HTTP 202 bedeutet nur angenommen. Für asynchrone Verarbeitung einen abfragbaren Auftragsstatus mit eindeutigem Ergebnis bereitstellen, beispielsweise `GET /api/v1/extension-routing/requests/{request_id}`. Die genaue Form gemeinsam festlegen.
- Erfolg bedeutet von der Anlage bestätigt und für nachfolgende Rufangebote wirksam. Eine Bedienänderung muss dafür nicht die administrative Grundkonfiguration überschreiben. Bis zur bestätigten Anwendung bleibt der Auftrag ausstehend oder meldet einen Anwendungsfehler.
- Bei Timeout ist das Ergebnis zunächst unbekannt. Die App zeigt den letzten bestätigten Stand und den offenen Status, fragt denselben Auftrag nach und sendet keine automatische neue Änderung mit neuer ID.
- Änderungen in der Administration erscheinen über denselben Zustandsabruf in allen geöffneten Apps. Polling darf weder einen offenen Schreibauftrag verdecken noch ein unbestätigtes Häkchen als Erfolg darstellen.

## Verbindung zwischen Middleware und kienzlefon

Die Administration bietet derzeit einen geschützten Web- und Auftragsweg. Für die Middleware wird ein eng begrenzter maschineller Zugang zu **demselben schreibenden Dienst** benötigt. Den Dienstzugang für die in der Middleware-Abstimmung benannte Anlagen- und Middleware-Topologie konkretisieren; die Administrationsoberfläche ist noch kein maschineller Steuerungsendpunkt. Eine gemeinsame Dateisystemablage nicht voraussetzen.

Dieser Zugang erlaubt ausschließlich den freigegebenen Zustandsabruf und gezielte Rufzuordnungsänderungen. Die zentrale Freigabe und die Einzelrechte selbst bleiben der Administration vorbehalten. Der Dienst führt dieselben fachlichen Validierungen und Revisionsprüfungen für Administration und Middleware aus; für Middleware-Aufträge setzt er zusätzlich die delegierten Schaltrechte durch. Er verarbeitet keine allgemeinen Konfigurations- oder Shell-Aufträge vom App-Client.

Dienstzugangsdaten bleiben serverseitig, sind auf diesen Zweck begrenzt und werden nicht durch Weiterreichen des Administrationspassworts ersetzt. Bestehende Origin-, Netzwerk-, Host- und Token-Prüfungen beibehalten. Für App und Middleware einen passenden Zugriffspfad festlegen; eine pauschale CORS-Freigabe oder Browserzugriff auf AMI ist dafür nicht vorgesehen.

Administrative Grundeinstellungen und Freigaben werden dauerhaft auf der Anlage verwaltet. Ein aufbewahrtes Auftragsprotokoll ist keine Anweisung, vorübergehende Bedienänderungen nach einem Anlagenneustart wiederherzustellen. Der vorhandene Status unter `/run` ist nur eine wiederherstellbare Anzeige. Eine reine Routingänderung erhält einen eigenen begrenzten Verarbeitungspfad, damit sie nicht versehentlich die bestehende Ansagenerzeugung auslöst.

## Abgestimmte Umsetzung und gemeinsame Abnahme

1. Im kienzlefon-Projekt die festgelegten Gruppenregeln, administrativen Grundeinstellungen und vorübergehenden Bedienänderungen auf den maschinellen Dienstvertrag abbilden. Endpunkte, Auftragszustände, Sperrgründe, Revisionen und Neustarterkennung mit gemeinsamen Beispielantworten abschließen.
2. Administration und Dienst gegen diesen Vertrag implementieren; bestehende Rufwege und laufende Gespräche mit synthetischen Abläufen prüfen.
3. Die Middleware implementiert Zustandsübersetzung und Auftragsvermittlung gegen dieselben Beispiele. Sie erhält keine unabhängige Routingkonfiguration.
4. Erst danach die beiden Häkchen in der App anschließen. Das übrige Design bleibt unverändert.

Gemeinsame Abnahmekriterien:

- Änderung über App und über Administration ergibt jeweils denselben bestätigten Zustand in allen Ansichten.
- Normal und ROT lassen sich unabhängig einstellen; Telefonstatus und Mitgliedschaft werden nicht verwechselt. Beide Bedienoberflächen verwenden dieselben Anzeigenamen.
- Zentrale Sperre verhindert neue Schreibaufträge und noch nicht ausgeführte alte Aufträge, ohne vorhandene Zuordnungen oder Gespräche zu verändern.
- Pro Nebenstelle unterschiedliche Rechte für beide Rufgruppen und beide Schaltrichtungen prüfen. Eine gesperrte Deaktivierung wird auch bei direktem API-Aufruf oder zwischenzeitlich geänderter Freigabe abgewiesen; Statuslesen bleibt möglich.
- Umbelegung und Deaktivierung der IVR-Taste ändern weder die Identität der fachlichen Rufgruppe noch unbemerkt ihre Direktnummern. Beschriftung und Zuordnung stimmen in Administration, Middleware und App überein.
- Gleichzeitige Änderungen, doppelte Übertragung und veraltete Revisionen führen weder zu verlorenen Änderungen noch zu doppelten Aktionen.
- Ausfall zwischen Speicherung und Anwendung, Dienstneustart und unbekanntes Auftragsergebnis bleiben korrekt erkennbar und können abgeglichen werden.
- Nach einem Anlagenneustart zeigen App und Middleware den neu abgerufenen Anlagenzustand. Alte Aufträge stellen keine vorübergehenden Einstellungen wieder her; ein reiner Browser- oder Middleware-Neustart verändert die Anlage nicht.
- ROT ohne separates rotes Telefon klingelt sofort gemeinsam; mit rotem Telefon folgt das Gruppenklingeln erst auf dessen erfolglosen separaten Rufversuch. Bei Annahme klingelt die Gruppe nicht zusätzlich. Diese Abläufe sowie der Schutz des letzten Gruppenmitglieds sind durch eigene Tests abgesichert.
- Queue-Reihenfolge, Penalties, Zeitregeln, bestehende Vermittlung, Fallbacks und verbundene Gespräche bleiben erhalten.
- Die neue Funktion verändert weder den Tageszähler noch die T2med-Patientenübergabe und löst keine Ansagenerzeugung aus.

Das ist ein Abstimmungsauftrag. Die fachlichen App-Vorgaben sind geklärt. Die technische Umsetzung benötigt den gemeinsamen Schnittstellenvertrag und den bestätigten jeweiligen Ausgangsstand.
