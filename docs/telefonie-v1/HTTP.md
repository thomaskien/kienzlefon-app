# HTTP und Zugriff

## Verbindlicher Verbindungsweg

Der Browser fragt ausschließlich den **eigenen App-Origin** ab. Der App-PHP-Proxy
erreicht die Middleware serverseitig über ein Praxisnetz, VPN oder einen fest
eingerichteten SSH-Portforward. Ein ausschließlich öffentlich erreichbarer
App-Host benötigt diesen privaten Verbindungsweg vor der Aktivierung.
Diese Voraussetzung im Installer prüfen; fehlende Verbindung als nicht verfügbar
anzeigen. Es gibt keinen direkten Cross-Origin-Browserzugriff und keine neue
CORS-Freigabe. Die konkrete App-URL ist eine Installationseinstellung.

Die Middleware erreicht den neuen Kienzlefon-Dienst über die VPN-Adresse der
Anlage. Standardport des **neuen** Dienstes: **8767**; administrativ konfigurierbar.
Das vorhandene Admininterface auf 8088 und AMI auf 5038 sind andere Dienste.
Der Middleware-Port bleibt **8766**. Beispiele verwenden ausschließlich
`http://pbx-vpn.example:8767` und `http://middleware.example:8766`.
HTTP ist nur innerhalb des genannten geschützten Netzes/Tunnels vorgesehen;
für Verbindungen außerhalb davon HTTPS mit Zertifikatsprüfung. Keine
Weiterleitung von Dienstschlüsseln bei HTTP-Redirects; Redirects ablehnen.

Zwei verschiedene zufällige Schlüssel mit mindestens 256 Bit:

| Verbindung | Authentifizierung und erlaubter Umfang |
| --- | --- |
| App-Server → Middleware | `Authorization: Bearer <app_service_token>`; nur Overview, Rufgruppen-POST und zugehörige Auftragsabfragen |
| Middleware → Kienzlefon | `Authorization: Bearer <control_service_token>`; nur die unten genannten `/control/v1/`-Endpunkte |
| Browser → App-Proxy | Bestehende geschützte App-Bereitstellung, eigener PHP-Sitzungscookie und CSRF-Token; keine Dienstschlüssel |
| Eigenes Middleware-Menü | Bestehende Host-/Netz-/Origin-Prüfung und `X-Kienzlefon-Token` aus Bootstrap |

Schlüssel ausschließlich in dienstlesbaren Dateien außerhalb des Webroots
(0600), niemals URLs, Browser, Logs oder Git. Serverseitige Schlüsselidentität
bestimmt den Auftraggeber und die zulässigen `client_id`-Werte; kein frei
geliefertes `principal` akzeptieren. Middleware-Schlüssel und Clients werden
auf der Anlage registriert. App- und lokale Aufträge verwenden denselben
Middleware-Auftraggeber auf der Anlage; UUIDs sind innerhalb dieser Installation
eindeutig. Weitere Middleware-Installationen erhalten eigene Schlüssel/Identitäten.

`token_file` enthält genau 64 zufällige Hex-Zeichen (optional abschließender
Zeilenumbruch). Die Anlagen-Credentials-Datei ist JSON nach `ControlCredentials`:
pro Identität `id`, SHA-256 des Tokens und `allowed_clients`. Gleiche Tokenhashes
für mehrere Identitäten ablehnen; Vergleiche zeitkonstant durchführen. Rotation
ändert das Secret, nicht die Identität oder historische Auftragszuordnung.

Der Dienstschlüssel erteilt weder Administrationsrechte noch Zugang zu FHIR,
Patientensuche, GDT, Wahl oder Vermittlung. Diese vorhandenen Zugriffswege bleiben
unverändert. Neue Bearer-Ausnahmen gelten ausschließlich für die drei ausdrücklich
freigegebenen Middleware-Routen, zusätzlich zu Host- und Quellnetzprüfung.
Dienstlistener an konkrete VPN-/Loopback-Adresse binden; Firewall auf die
vorgesehene Gegenstelle beschränken. Keine öffentliche Portfreigabe als Standard.

## Endpunkte und Schemata

| Server | Methode / Pfad | Eingabe → Ausgabe |
| --- | --- | --- |
| Kienzlefon | `GET /control/v1/capabilities` | — → `ControlCapabilities` |
| Kienzlefon | `GET /control/v1/state` | — → `ControlState` |
| Kienzlefon | `POST /control/v1/routing/requests` | `RoutingCommand` → `CommandResponse` |
| Kienzlefon | `GET /control/v1/routing/requests/{request_id}` | UUID → `CommandResponse` |
| Middleware | `GET /api/v1/telephony/overview` | — → `Overview` |
| Middleware | `POST /api/v1/extension-routing` | `RoutingCommand` → `CommandResponse` |
| Middleware | `GET /api/v1/extension-routing/requests/{request_id}` | UUID → `CommandResponse` |
| App-Proxy | `GET /telepraxis-telephony.php?action=bootstrap` | — → `AppBootstrap` |
| App-Proxy | `GET /telepraxis-telephony.php?action=status` | — → `Overview` |
| App-Proxy | `POST /telepraxis-telephony.php?action=routing` | `RoutingCommand` → `CommandResponse` |
| App-Proxy | `GET /telepraxis-telephony.php?action=request&request_id={UUID}` | UUID → `CommandResponse` |

Der Proxy legt ausschließlich diese Aktionen fest; keine frei übergebenen URLs,
Hosts, Dateipfade, Queue-/Kanalnamen oder weiteren Methoden weiterreichen.
Er leitet POST-Inhalt unverändert validiert weiter und erhält HTTP-Status und
fachlichen Fehlercode. Serverseitige Transportdetails bleiben in lokalen Logs.

Middleware prüft `pbx_id` aus Fähigkeiten und jedem Zustand gegen ihre feste
Konfiguration. Bei abweichender Anlagenidentität, unbekannter Schemaversion
oder widersprüchlichen aktuellen Anlagenlaufzeiten werden die aus diesem
Anlagenzustand stammenden Live- und Routingdaten unverfügbar. Ein isoliert
fehlerhafter Teilzustand sperrt nur diesen Teil. Unabhängig gültige Tagesstatistik
und Nebenstellenstatus bleiben erhalten. Der Überblick verwendet dafür HTTP 200
mit unbekannten Teilzuständen gemäß den Regeln unten; eine Schreibaktion kann
bei fehlendem verlässlichem Zustand mit `state_unavailable` scheitern. Niemals
auf eine andere Anlage oder einen ungeschützten Endpunkt ausweichen.

App-Bootstrap erzeugt ein kryptografisch zufälliges sitzungsgebundenes CSRF-Token
und liefert nur `schema_version`, `csrf_token`, `clients` (`id`, `label`,
`extension`) aus serverseitig konfigurierten, tatsächlich erlaubten Clients.
Cookie `HttpOnly`, `SameSite=Strict`, bei HTTPS `Secure`; keine Freigabe durch
die frei eingegebenen App-Felder Benutzer/Platz. POST erfordert
`X-Kienzlefon-CSRF`, passenden Origin/Referer und vorhandene Sitzung.
Cross-Site-POST ablehnen. Bootstrap und Proxy unter denselben bestehenden
Praxis-/App-Zugriffsschutz stellen; der Sitzungscookie ist kein Benutzerlogin.

Alle Antworten `Content-Type: application/json; charset=utf-8`,
`Cache-Control: no-store`, `X-Content-Type-Options: nosniff`.
POST nur `application/json`, maximal 8192 Byte, begrenzte JSON-Tiefe 16.
Zeitüberschreitung pro Serverhop: Verbindung 2 s, gesamt 5 s; App-Proxy darf
den Middleware-Hop bis 8 s abwarten, Browser bis 12 s. POST-Annahme wartet nicht
auf die Anwendung. Keine automatischen neuen Schreibaufträge nach Timeout.

## Fehler und Statuscodes

`ErrorResponse = {"error": Error}`; Schreib-/Auftragsantworten verwenden
`CommandResponse = {"request": RoutingRequest|null, "error": Error|null}`.
Fehler vor der fachlichen Auftragsverarbeitung (Authentifizierung, Methode,
Body/JSON) dürfen auch bei POST `ErrorResponse` verwenden. Fachliche Ablehnungen
verwenden `CommandResponse`; `request=null`, wenn kein Auftrag angenommen wurde.
Bei einem vorhandenen Auftrag stimmt der äußere Fehler mit `request.error`
überein. Leere Kombination `request=null,error=null` ist unzulässig.
`Error` enthält `code`, verständliche `message`, `retryable`, `current_revision`
und `current_pbx_epoch`. Nicht bekannte aktuelle Werte sind `null`.
GET-Auftragsabfragen liefern für einen bekannten Auftrag HTTP 200, auch wenn
sein Zustand `rejected`, `expired` oder `unknown` ist. POST liefert 202 bei
`accepted`/`applying`/`unknown`, 200 bei `applied`, sonst den passenden Fehlerstatus.
`retryable` erlaubt erneutes Lesen; es erlaubt **keine neue Schreib-UUID**.

| HTTP | Fehlercode |
| --- | --- |
| 400 | `invalid_request`, `unknown_extension`, `unknown_route`, `unknown_client` |
| 401 | `unauthenticated` |
| 403 | `forbidden_origin`, `forbidden_network`, `csrf_failed`, `client_forbidden`, `global_disabled`, `admin_locked`, `not_eligible` |
| 404 | `request_not_found`, `not_found` |
| 405 / 413 / 415 | `method_not_allowed` / `payload_too_large` / `json_required` |
| 409 | `revision_conflict`, `pbx_restarted`, `request_id_conflict`, `last_member`, `state_unknown`, `request_expired`, `route_unavailable` |
| 501 | `not_supported` |
| 503 | `upstream_unreachable`, `upstream_authentication_failed`, `state_unavailable`, `apply_failed` |
| 504 | `upstream_timeout` |

### Übersicht und Teilfehler

Für lesende Übersichten gelten diese Regeln, nachdem der Aufrufer erfolgreich
authentifiziert und zum Zugriff berechtigt wurde:

| Antwortweg / Fehler | HTTP und Inhalt |
| --- | --- |
| Middleware-Overview oder vollständiger Telefonie-GET: einzelne Quelle ausgefallen, Timeout oder deren Anmeldung fehlgeschlagen | **200** mit gültiger Gesamtantwort; nur abhängige Teilzustände unbekannt. Unabhängig gültige Daten bleiben erhalten. |
| Middleware-Übersicht: fehlerhafte Control-Daten, falsche Anlagenidentität, unbekannte Schemaversion oder widersprüchliche aktuelle Epochs | **200**; betroffene Control-Teile unverfügbar, weiterhin gültige Tagesstatistik und unabhängiger Nebenstellenstatus erhalten. |
| Anlagen-GET `/control/v1/state`: lokale Live- oder Routingquelle nicht verfügbar | **200** mit gültigem `ControlState` und unbekannten betroffenen Teilen. |
| App-Proxy: gültiges Middleware-Overview mit unbekannten Teilen | **200**, gültige Teile durchreichen. |
| App-Proxy: Middleware nicht erreichbar / Anmeldung dort abgewiesen | **503** mit `upstream_unreachable` / `upstream_authentication_failed`. |
| App-Proxy: Middleware antwortet nicht innerhalb der Frist | **504** mit `upstream_timeout`. |
| App-Proxy: Middleware liefert kein gültiges Overview, etwa ungültiges JSON, Schema oder widersprüchliche aktuelle Epochs | **503** mit `state_unavailable`; keine Rohantwort oder erfundene Ersatzstatistik. |

Unbekannte Live-Werte sind `null` bei `status=unavailable`. Unbekanntes Routing
hat `available=false`, Teilnahmen `null` und beide Schaltrechte `false` mit
`state_unknown`. Als Teilzustandsgrund dient bei Quellverbindungs-/Anmeldefehlern
und Timeout `source_disconnected`, bei fehlerhaften Quelldaten `source_incomplete`.
Bereits definierte Gründe wie `not_supported`, `not_configured` oder `stale`
bleiben für diese Fälle erhalten. Bekannte Fähigkeiten werden durch einen
Quellausfall nicht zurückgesetzt. Veraltete Zahlen nie als aktuell ausgeben.

Ein Server, der selbst keine gültige Gesamtantwort bilden kann, liefert 503
mit `state_unavailable`. Das betrifft nicht den gewöhnlichen Ausfall einer
einzelnen Quelle: Dafür sind die unbekannten Teilzustände vorgesehen. Ein
Anmeldefehler des aktuellen Aufrufers bleibt 401; Berechtigungsfehler bleiben 403.
Diese Fehler werden nicht zu einer anonym zugänglichen Übersicht umgewandelt.

### Schreibaufträge und Auftragsabfragen

Die HTTP-200-Regel für Übersichten gilt nicht pauschal für Schreibaufträge.
Bei sicherem Scheitern vor Annahme/Versand gelten die Fehlerstatus der Tabelle:
503/`upstream_authentication_failed` bei nachgelagerter Anmeldung,
503/`state_unavailable` bei unbrauchbarem Zustand, 503/`upstream_unreachable`
oder 504/`upstream_timeout` bei Verbindungsproblemen.

Ist nach Journalannahme und möglichem Versand die Wirkung unklar, bleibt der
Auftrag `unknown`: POST liefert **202**, GET des bekannten Auftrags **200**,
jeweils `CommandResponse` mit dem passenden Fehler. Das gilt auch bei einer
unbrauchbaren nachgelagerten Antwort. Ein möglicher Erfolg darf nicht als sicher
fehlgeschlagen dargestellt werden. Bereits sicher bestätigte terminale
Ergebnisse bleiben historisch lesbar. Einzelheiten siehe [Auftragslebenszyklus](ROUTING.md#auftrag-und-lebenszyklus).

Nachgelagerte Anmeldefehler fordern niemals den Benutzer zum Ändern seines
App-Passworts auf. Keine internen Pfade, Stacktraces oder Rohantworten ausgeben.

## Erforderliche Konfiguration

| Projekt | Werte; nur Namen, keine produktiven Secrets |
| --- | --- |
| Kienzlefon | Listener-Adresse/-Port 8767, erlaubte Middleware-Quellen, Schlüsseldatei je Middleware, erlaubte Client-IDs, stabile `pbx_id`, Routingrichtlinie |
| Middleware | Control-Basis-URL, Schlüsseldatei, erwartete `pbx_id`, zulässige Hosts/Netze; App-Schlüsseldatei mit Client-Liste; Statistik unverändert separat |
| App | Middleware-Basis-URL für Serverzugriff, App-Schlüsseldatei, feste Client-Zuordnung, Proxy aktiviert/deaktiviert getrennt von T2med |

Konfigurationsnamen in neuen Implementierungen: Middleware `[telephony_control]`
mit `enabled`, `base_url`, `token_file`, `pbx_id`; `[app_access]` mit `enabled`,
`token_file`, `allowed_clients`, `allowed_networks`. App-Datei
`telephony-config.php` außerhalb des Webroots: `enabled`, `middleware_url`,
`token_file`, `clients`. Kienzlefon `[control_api]`: `enabled`, `bind`, `port`,
`allowed_networks`, `credentials_file`, `pbx_id`. Sämtliche neuen Module starten
mit `enabled=false`. Eine Einzelinstallation kann einen Schlüssel je Verbindung
nutzen; mehrere Identitäten werden in der Credentials-Datei mit denselben
Scopes getrennt verwaltet. Keine Laufzeit-URLs aus Browserparametern übernehmen.
