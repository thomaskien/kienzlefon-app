# Live-Zahlen und Tagesstatistik

## Drei unabhängige Live-Zahlen

`live_counters` enthält `waiting`, `ivr`, `ai` als nichtnegative Ganzzahlen oder
bei fehlender belastbarer Erfassung **alle drei `null`**. Es werden ursprüngliche
aktuell aktive **externe Telefoneingänge** gezählt, einschließlich anonymer
Anrufe. Keine internen Tests, ausgehenden Rufe, Faxe, Rückfragen oder technischen
Zusatzkanäle. Ein Eingang steht höchstens in einer dieser drei Kategorien.

| Feld | Eintritt / Austritt |
| --- | --- |
| `waiting` | In normaler Praxisqueue oder Fachstellenqueue aufgenommen, einschließlich noch unbeantworteter Rufangebote; endet bei Annahme, Verlassen, Abbruch oder Hangup. Über beide Queues nach Eingangs-ID deduplizieren. |
| `ivr` | Klassische Begrüßung, Sprachmenüs, Untermenüs, Informationsansagen und klassische Anliegenaufnahme; endet beim Verlassen in AI, Queue, Direktvermittlung oder beim Auflegen. |
| `ai` | Tatsächlich angenommene und verbundene AI-Sitzung; bloße Reservierung, Wahlversuch oder Wartezeit auf AI zählt nicht. Endet mit Verbindungsende/Handoff/Transfer/Hangup. |

Direkter Rottelefon-Ruf vor der Queue und menschlich angenommene Gespräche
zählen in keine dieser drei Kategorien. Die Summe ist daher **keine Gesamtzahl
aller aktiven Gespräche**. Bei Übergangsereignissen gilt: bestätigte menschliche
Verbindung vor AI-Verbindung vor Queue-Wartezustand vor klassischer IVR-Stufe.

## Erfassungsquelle auf der Telefonanlage

Einziger Lieferant des neuen `live_counters` ist der Kienzlefon-Dienst über
`GET /control/v1/state`. Die Middleware berechnet IVR/AI nicht aus Anzeigenamen,
Patiententreffern, Tageswegen oder bloßem Kanalstatus `Up`.

Am echten externen Eintritt dieselbe UUID v4 und ursprüngliche Eingangszeit wie
für die Tagesstatistik erzeugen: `__KF_STATS_CALL_ID`, `__KF_STATS_ENTERED_AT`,
`__KF_STATS_PBX_ID`. Identität bleibt in Folgekanälen erhalten. Die lokale
Quelle markiert nur den ursprünglichen externen Wurzelkanal zusätzlich mit
**nicht vererbtem** `KF_LIVE_OWNER=1`. `KF_LIVE_STAGE` ist dort `ivr` oder
`other`, ebenfalls nicht vererbt. Einstieg beginnt mit `other`; unmittelbar
vor klassischen Ansagen/Menüs/Aufnahme auf `ivr`, vor jedem Ausgang daraus
auf `other` setzen, auch bei Fehler-/Abbruch-/AI-Handoff-Pfaden.

Identitätsmarker werden bei aktivierter Live-Erfassung auch dann benötigt,
wenn die Middleware-Tagesstatistik ausgeschaltet ist. Lesen/Neuaufbau erzeugt
keine neuen Eingänge oder Statistikereignisse. Ursprüngliche IDs niemals aus
Rufnummer, Patient, Browser, Linkedid oder aktuell angezeigter Call-ID ableiten.

Der lokale Dienst erstellt alle 2 s einen vollständigen Schnappschuss aus
aktiven Kanälen, deren Identitäts-/Owner-/Stage-Variablen, Queue-Einträgen und
bestätigten Bridges/Peer-Typen. Grundlage sind `CoreShowChannels`, `Getvar`,
`QueueStatus`, Bridge-Bestand und laufende AMI-Ereignisse. Nur konfigurierte
AI-Endpunkte bzw. der nachweislich aktive AI-Medienpfad zählen als AI-Verbindung.
Technische Local-Paare und gemeinsam vererbte Call-IDs zählen nicht zusätzlich.
Ein menschlicher Bridge-Teilnehmer beendet den AI-Livezähler dieses Eingangs.

Schnappschuss vollständig abschließen, währenddessen eingehende Ereignisse
puffern und anschließend anwenden. Bei Rename/Masquerade/Transfer muss genau
eine lebende externe Wurzel erhalten bzw. eindeutig zugeordnet werden.
Hangup entfernt den Eingang; Kindkanäle halten keinen aufgelegten Eingang am Leben.
Fehlende/mehrdeutige Wurzel, Variablenfehler bei noch aktivem Kanal, nicht fertig
gelesener Bestand oder widersprüchliche Zuordnung ergeben `unavailable`, keine
geschätzte Null. Ein nachweislich inzwischen beendeter Kanal wird entfernt.

AMI-Trennung invalidiert sofort. Nach Wiederverbindung/Dienstneustart erst nach
vollständigem Neuaufbau `current`. Dadurch bleiben keine verlorenen IVR-/AI-
Einträge hängen. Der Dienst muss alle tatsächlich aktiven externen Eingänge
abdecken; bei Einrichtung während laufender unmarkierter Gespräche bleibt die
Erfassung zunächst unverfügbar. Ein leerer bestätigter Bestand liefert dreimal 0.

Technische Grundlagen: Asterisk dokumentiert den aktiven Kanalbestand über
[CoreShowChannels](https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/CoreShowChannels/)
und das Lesen einer Kanalvariable über
[Getvar](https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Getvar/).
Die Identitätsmarker nutzen die dokumentierte
[Variablenvererbung](https://docs.asterisk.org/Configuration/Dialplan/Variables/Channel-Variables/Variable-Inheritance/);
Owner und Stage dürfen gerade keine vererbten Marker sein. Die zusätzliche
Fachlogik dieses Vertrags ist im Kienzlefon-Projekt zu implementieren.

## Aktualität und Darstellung

`live_counters.status` ist `current` oder `unavailable`; `reason_code` bei
`current` null, sonst `not_supported`, `not_configured`, `source_disconnected`,
`synchronizing`, `source_incomplete` oder `stale`. `pbx_epoch` benennt die
Anlagenlaufzeit. `as_of` ist die Erfassungszeit, `age_ms` ihr Alter am antwortenden
Server, `max_age_ms` immer 10000. Bei unbekannter Erfassung: Werte, `as_of` und
`age_ms` null; bekannte Epoch darf erhalten bleiben.

Jeder Vermittler erhöht `age_ms` um eigene Warte-/Cachezeit und gemessene
Transportdauer; nie durch eine neue HTTP-Antwort auf 0 setzen. Ab 10000 ms auf
unverfügbar wechseln. Browser rechnen Empfangsalter plus monotone verstrichene
Zeit; eine abweichende Arbeitsplatzuhr macht Daten nicht frisch.
Routing verwendet dieselbe Altersregel, aber einen eigenen Zustand.
Nebenstellenstatus hat `state_as_of` und `state_age_ms`; ab 10 s `unknown`.
Ein anderer Status benötigt einen gültigen Erfassungszeitpunkt und ein Alter
zwischen 0 und 9999 ms. Ein fehlendes Alter darf nicht als 0 interpretiert werden.

Aktuell verfügbare Live- und Routingdaten einer Antwort müssen dieselbe
`pbx_epoch` besitzen. In `ControlState` muss sie außerdem der übergeordneten,
bekannten `pbx_epoch` entsprechen. Ein unbekannter übergeordneter Wert erlaubt
keine aktuellen Teilzustände. Unverfügbare Teile dürfen ihre zuletzt bekannte
Epoch als historische Metadaten behalten; daraus entstehen keine aktuellen
Zahlen oder Schreibrechte. Gemischte aktuelle Epochs sind ein Datenfehler und
werden gemäß [HTTP und Zugriff](HTTP.md#übersicht-und-teilfehler) behandelt.

Middleware pollt den Anlagenzustand unabhängig von offenen Browsern alle 2 s.
App und eigenes Menü: sichtbare Seite 2 s, verborgen 15 s, bei Rückkehr sofort.
Maximal eine gleichzeitige Leseanfrage pro Ansicht; langsamere alte Antworten
verwerfen. Verbindungsfehler zeigen `—`, keine 0 und keine aktive Schreibfreigabe.
Keine historischen Zähler im Browser nachführen.

`queues[].waiting_count` im bestehenden vollständigen Telefonie-Endpunkt bleibt
unverändert. Die neue praxisweite Live-Bubble verwendet `live_counters.waiting`;
keine eigene Summierung alter Queue-Zahlen, die auch interne Zugänge oder andere
konfigurierte Queues enthalten können.

## Tagesstatistik: bestehender Vertrag bleibt erhalten

`call_statistics.today` bleibt identisch zur bereits lokal umgesetzten
Middleware-Statistik: `date`, `timezone`, `period_start`, `period_end`,
`incoming_calls`, `status`, `as_of`, `reason_code`, `routes`, `unclassified_calls`.
Die API liefert alle 11 Wege, auch mit 0:
`ivr`, `ai`, `queue`, `prescription`, `referral`, `appointment`, `callback`,
`other`, `opening_hours`, `pharmacy`, `specialist`.

Jeder externe Eingang einmal, jeder Weg je Eingang einmal. Wiederanruf ist ein
neuer Eingang. Wege nach Mitternacht bleiben dem Eingangstag in Europe/Berlin
zugeordnet. Mehrere Wege pro Anruf sind möglich. `unclassified_calls` bedeutet
ohne erfassten Weg, nicht automatisch aufgelegt. Keine Rufnummern oder Patienten
in der Statistik; eigenes SQLite außerhalb des FHIR-Bestands.

| Status | Anzeige |
| --- | --- |
| `complete` | `Anrufe heute: 47`, `reason_code=null` |
| `partial` | `Anrufe heute: ≈47`, Grund `initial_partial_day` oder `capture_gap` |
| `unavailable` | `Anrufe heute: —`, Grund `not_configured` oder `storage_unavailable`; Zahl/Wege/Stand null |

Tooltip: alle Wege mit Zahl, „Ohne erfassten Weg: N“, „Mehrere Wege pro Anruf
möglich“; bei `partial` „Ungefährer Wert; Erfassung nicht durchgehend“.
Ein reiner Ausfall des neuen Steuerungsdienstes macht eine weiterhin über AMI
erfasste Tagesstatistik nicht automatisch unvollständig. AMI-/Erfassungslücken
dagegen bleiben als `partial` erhalten; keine Nachberechnung verlangt.

Bei erstmalig eingerichteter Statistik ohne bestätigte Erfassung kann auch ein
`partial`-Wert `as_of=null` enthalten. Das bestehende Verhalten bleibt gültig;
die App erfindet dafür keinen Erfassungszeitpunkt.

AMI-Ereignisse unverändert: `Event: UserEvent`, `SchemaVersion: 1`,
`UserEvent: KienzlefonCallEntered` oder `KienzlefonCallRoute`, `PbxId` (stabil),
`CallId` (UUID v4), `EnteredAt` (ursprüngliche Unixsekunden), bei Route zusätzlich
`Route` (eine der 11 IDs). Einstieg vor Annahme/Kapazitätsprüfung/Lookup markieren;
anonyme und früh abgebrochene Anrufe einschließen. Wiederholte Hooks behalten
dieselbe ID. AI-Weg erst bei tatsächlicher Verbindung. Snapshot/GET erzeugt
niemals ein solches Ereignis. Der neue Live-Vertrag ersetzt diese Ereignisse nicht.
