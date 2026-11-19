# Rufgruppen, Rechte und Aufträge

## Fachliche Regeln und administrative Quelle

Stabile Gruppen-IDs: **`normal`** und **`specialist`**. Anzeigenamen kommen aus
`routing_control.groups` und lauten gemäß aktueller Kienzlefon-Planung **Normal**
und **ROT**; `ivr_digit` ist die aktuell aktive Taste oder `null`.
Umbelegen/Deaktivieren einer IVR-Taste ändert keine Gruppen-ID oder Direktnummer.

Die Freigabe gilt je Zielnebenstelle, Gruppe und Schaltrichtung. Es gibt keine
zusätzliche Beschränkung auf die eigene Arbeitsplatz-Nebenstelle. Nur bekannte
Telefonnebenstellen sind zugelassen, keine AI-/Trunk-Kanäle. Fehlende Rechte
bedeuten gesperrt. App, eigenes Telefonmenü und Anlage prüfen dieselben Rechte;
die Anlage prüft unmittelbar bei der Ausführung erneut.

Der letzte eingetragene Teilnehmer einer aktiven Gruppe darf nicht entfernt
werden, auch nicht durch zwei gleichzeitige Änderungen. Besetzt/offline ist kein
Austritt. Der Fachstellenweg ruft bei aktiviertem rotem Telefon zuerst weiterhin
dieses separat konfigurierte Telefon. Ohne aktiviertes rotes Telefon beginnt
das gemeinsame Klingeln sofort. Es klingeln nur aktive `specialist`-Mitglieder;
es gibt keine automatische Vererbung aus `normal`. Das Häkchen am roten Telefon
steuert nur seine Teilnahme am gemeinsamen Klingeln, nicht den ersten Versuch.

Administrative Richtlinie (`RoutingPolicy`, Speicherung in Kienzlefon-TOML):
`remote_enabled` plus je Nebenstelle und Gruppe `eligible`, `default_member`,
`can_enable`, `can_disable`, `penalty`. `penalty` 0–1000 entspricht der vorhandenen
Normal-Queue-Stufe; vorhandene Stufen bei Migration übernehmen. Neue Kandidaten
benötigen eine ausdrücklich gesetzte Stufe, bevor `eligible=true` möglich ist.
Für `specialist` gilt `penalty=0` und gemeinsames Klingeln; Queue-Gewichte und
Vorrang bleiben erhalten. Ein gesperrter Kandidat hat `default_member=false`
und keine externen Änderungsrechte.

Aktive Gruppen benötigen mindestens ein dauerhaftes Standardmitglied sowie
mindestens ein wirksames Mitglied. Administration und Fernbedienung verwenden
dieselbe Validierung und Sperre. Eine administrative Rechteänderung allein
erhält die aktuelle Teilnahme. Ändert die Administration `default_member` eines
Paars, wird für genau dieses Paar auch der laufende Zustand gesetzt und dessen
vorübergehende Abweichung gelöscht. Entzug der Eignung entfernt das Paar nur,
wenn die Gruppe gültig bleibt. Andere vorübergehende Zuordnungen bleiben erhalten.
Der externe Dienst kann die Richtlinie **nicht** ändern; Adminaufträge gehen
über den vorhandenen geschützten Admin-Worker an dieselbe Verwaltungslogik.

Der Dienst liefert alle verwalteten Telefonnebenstellen. Die Middleware zeigt
ihre konfigurierten Nebenstellen; fehlt deren passende Anlagenzuordnung, bleiben
Teilnahmen unbekannt und Änderungen gesperrt. Das letzte Mitglied wird gegen
die vollständige Anlagengruppe geprüft, nicht gegen eine gefilterte App-Ansicht.

## Bestätigter Zustand

`routing_control` enthält Unterstützung, zentrale Freigabe (`enabled`),
Verfügbarkeit, `pbx_epoch`, `revision`, Erfassungsalter und Gruppenbeschreibungen.
`available=true` bedeutet vollständig abgeglichen und jünger als 10 s.
Bei globaler Sperre kann der Zustand trotzdem verfügbar/lesbar sein.
`extensions[].routing` enthält beide Teilnahmen und `permissions` pro Gruppe.
Rechte gelten für den gewünschten Zielwert; ein aktives Häkchen erfordert zum
Ausschalten `can_disable`, ein inaktives zum Einschalten `can_enable`.

Wirksame Rechte berücksichtigen in dieser Reihenfolge: nicht unterstützt,
unbekannter Zustand, Gruppe nicht verfügbar, globale Sperre, Eignung,
administratives Einzelrecht, letzter Teilnehmer. Der erste zutreffende
Sperrgrund wird geliefert: `not_supported`, `state_unknown`, `route_unavailable`,
`global_disabled`, `not_eligible`, `admin_locked`, `last_member`.
Bei erlaubter Richtung ist der Grund `null`. `can_disable=false/last_member`
gilt beim einzigen aktiven Mitglied, nicht bei einem ohnehin inaktiven Kandidaten.
Unbekannte Teilnahmen sind `null`, beide Rechte dann `false`.

Eine Routingrevision hat exakt die Form `r1:<pbx_epoch>:<Zähler>`; Zähler ist
eine positive Dezimalzahl. Er steigt bei wirksamer Mitgliedschaftsänderung,
administrativer Richtlinien-/Gruppenänderung oder erkanntem externen Drift.
Keine Erhöhung durch Lesen, Warteschlangenbelegung, reine Annahme eines Auftrags
oder bestätigtes Setzen eines schon vorhandenen Werts. Die Revision umfasst die
gesamte Anlage, nicht nur eine Nebenstelle. Zwei Aufträge zur selben Revision
können daher in Konflikt geraten; die Oberfläche lädt neu und überschreibt nicht.

## Auftrag und Lebenszyklus

`RoutingCommand` enthält ausschließlich:
`request_id` (UUID v4), `client_id`, `extension`, `route`, `member` (Boolean),
`expected_revision`, `pbx_epoch`, `expires_at`.
`expires_at` entsteht aus `generated_at` des frisch gelesenen Overview plus
30 Sekunden; Uhren der Server synchronisieren. Der Dienst akzeptiert maximal
35 Sekunden Zukunft (5 s Toleranz), keinen bereits abgelaufenen neuen Auftrag.
Epoch und Revision müssen zusammenpassen. Kein blindes Umschalten.

1. Authentifizieren und Schema prüfen. Bereits bekannte ID **zuerst** nachsehen:
   derselbe normalisierte Inhalt liefert den bestehenden, eventuell inzwischen
   fortgeschrittenen Auftrag. Abweichender Inhalt: `request_id_conflict`.
2. Middleware speichert Auftrag und Inhalt vor dem Versand in ihrem dauerhaften
   Journal. Anlage speichert Annahme vor HTTP 202 in ihrem Journal. Identität
   und UUID bleiben über alle Stationen unverändert. Kein neues ID-Mapping.
3. Der Anlagen-Worker serialisiert Änderungen mit Adminaufträgen. Unmittelbar
   vor der Anwendung Epoch, Frist, Revision, Rechte und letzten Teilnehmer unter
   derselben Sperre prüfen. Wartende Aufträge umgehen keinen Rechteentzug.
4. Gewünschten Zustand für nachfolgende Rufangebote anwenden und zurücklesen.
   Erst dann `applied`, neue Revision und tatsächlichen Zustand veröffentlichen.
   Ein unveränderter Zielwert ist nach denselben Prüfungen `applied/changed=false`.
5. Unklarer Ausgang: `unknown`; aus tatsächlichem Laufzeitzustand und Journal
   abgleichen. Kein erneutes Anwenden auf bloße Vermutung, kein automatisches
   Rücksetzen inzwischen erfolgter Änderungen.

| Zustand | Bedeutung / weiterer Verlauf |
| --- | --- |
| `accepted` | Dauerhaft angenommen, noch nicht angewendet; → applying/rejected/expired |
| `applying` | Anwendung läuft; → applied/rejected/unknown |
| `applied` | Zielwert bestätigt; terminal, historische Bestätigung |
| `rejected` | Sicher nicht angewendet bzw. voriger Zustand sicher erhalten; terminal |
| `unknown` | Wirkung nicht sicher bekannt; → applied/rejected/expired nach Abgleich |
| `expired` | Nicht mehr ausführbar (Frist oder neue Anlagenlaufzeit); terminal |

Abgelaufene Frist beendet einen noch nicht begonnenen Auftrag. Eine bereits
begonnene Anwendung wird abgeglichen, nicht rückwirkend als wirkungslos erklärt.
Bei sicherer Anwendung und anschließend verlorenem Antwortpaket muss die
Abfrage `applied` ergeben. Bei nicht bestätigter Rücknahme niemals `rejected`.
Keine neue Ausführung nach 30 s; unbekannte Aufträge weiter abfragen. Pro Zielpaar
bleibt bei unklarem Ergebnis die Bedienung gesperrt. Nach 30 s ein Hinweis statt
endloser Erfolgserwartung; Statusabfragen laufen weiter (alle 5 s).

GET-Auftragsabfrage ist lesend. Unbekannte ID: 404/`request_not_found`.
Bei verlorenem POST ist 404 vor Fristablauf **kein Beweis**, dass ein verzögertes
Paket nicht mehr ankommen kann. Middleware fragt weiter; nach Fristablauf und
bestätigtem Nichtvorhandensein auf der Anlage `expired`. Keine neue ID erzeugen.
Ein Client darf denselben POST mit identischer ID/Inhalt innerhalb der Frist
wiederholen; automatische Wiederholungen beschränken sich auf GET.

Die Middleware verfolgt offene Aufträge unabhängig von geöffneten Browsern im
Hintergrund (zunächst alle 2 s, nach 30 s alle 5 s). Ist der Versand nach lokaler
Journalannahme unklar, liefert sie HTTP 202 mit `unknown/upstream_timeout` bzw.
`unknown/upstream_unreachable`, nicht einen scheinbar sicher erfolglosen Auftrag.
Der lokale vorläufige Auftrag wird beim Abgleich durch den kanonischen
Anlagenauftrag ersetzt; Eingabeinhalt und ID bleiben identisch. GET des bekannten
lokalen Auftrags liefert auch bei nachgelagertem Ausfall HTTP 200/`unknown`.
Vor einer neuen Anwendung `expires_at` prüfen; reines Nachfragen läuft danach weiter.

Auftragsdaten einschließlich Resultat mindestens 7 Tage aufbewahren; noch
`accepted/applying/unknown` nicht durch Zeitablauf aus dem Journal löschen.
Nach Bereinigung einer terminalen ID liefert GET 404, ein alter POST scheitert
an Frist/Epoch. Journale enthalten technische IDs, keine Patienten/Rufnummern.
Middleware gibt nur Aufträge ihrer eigenen Installation/erlaubten Clients heraus.
Speicherausfall vor sicherer Annahme: 503, nichts anwenden.

## Anlagenneustart und Beständigkeit

`pbx_epoch` ist eine UUID v4 pro **Asterisk-Prozesslaufzeit**. Reload,
Admin-/Steuerungsdienst-Neustart und Middleware-Reconnect ändern sie nicht.
Der lokale Dienst verbindet sie mit Linux-Boot-ID, Asterisk-PID und Prozess-
Startticks und speichert die Zuordnung atomar; ein neuer Asterisk-Prozess
erhält vor der Freigabe eine neue UUID. Bei unklarer Prozessidentität bleiben
Schreibzugriff und Routingstatus unverfügbar. Keine bloße Dienststart-UUID.

Vorübergehende Teilnahmen und Journal sind auf der Anlage dauerhaft gespeichert,
aber an die Epoch gebunden. So übersteht die Teilnahme einen Neustart nur des
Steuerungsdienstes. Bei neuer Asterisk-Epoch werden administrative Standards
angewendet, alte vorübergehende Werte nie wieder eingespielt. Nicht abgeschlossene
alte Aufträge werden `expired/pbx_restarted`; terminale Ergebnisse bleiben
historisch lesbar und beweisen keinen aktuellen Zustand. Browser laden Overview
neu. Gegen die alte Epoch eingehende neue Aufträge: 409/`pbx_restarted`.

Admin- und Routing-Worker verwenden dieselbe Serialisierung und Anwendungslogik.
Während nicht eindeutig bestätigter Anwendung ist Routing `available=false`;
alte bekannte Teilnahmen dürfen nur ausdrücklich veraltet angezeigt werden.
Externe Queue-/Pause-Änderungen, welche eine Rechteprüfung umgehen könnten,
werden als Drift erkannt, abgeglichen und bis dahin nicht als verfügbare
Rufzuordnung ausgegeben. Keine Gespräche trennen, Ansagen erzeugen oder Asterisk
neu starten, um eine Teilnahme zu ändern.
