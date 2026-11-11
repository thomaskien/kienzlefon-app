<?php
/*
 * telepraxis-app.php
 * Version: 3.6
 *
 * Fortgeführter Changelog (niemals entfernen, nur ergänzen):
 * - Feature-Branch recording-miniplayer (unveröffentlicht; Version bleibt 3.6):
 *   - Ein einklappbarer HTML5-Player pro Kienzlefon-WAV-Segment, auch bei mehreren Medikamenten.
 *   - Optionale statische recordings/-Freigabe; Audioelemente bleiben bei Polling-Updates erhalten.
 * - v3.6 (2026-10-12)
 *   - Optionale T2med-Middleware über Einstellungen im Dateikopf: Telefonnummer wählt, kleiner „Akte“-Knopf öffnet die Patientenakte.
 *   - Benutzer und Platz getrennt in Kopfzeile und Menü: Benutzer ist Bearbeiter, Platz wird als client_id an die Middleware übergeben.
 *   - Benutzerbezogene Vorgänge, Kommentare und SMS; vorhandene Bearbeiterangaben bleiben als Rückfallwerte erhalten.
 *   - Bookmarks mit benutzer und/oder arbeitsplatz; bei Teil-Bookmarks bleibt das andere Feld bewusst leer. Beide Angaben werden separat im Browser gespeichert.
 * - v3.5 (2026-09-29)
 *   - SMS-Funktionen als neue App-Version freigegeben: persistente Versandqueue, eigenständiger Empfangsworker und sichere Routerbereinigung.
 *   - Weitere SMS zum zuletzt angelegten offenen Vorgang als farbige chronologische Kommentare mit Benachrichtigungston; automatische Bestätigung nur für die erste SMS je Vorgang und nur an vollständige deutsche Rufnummern.
 *   - Gemeinsame Inbox-Sperre und atomare Dateischreibvorgänge für App und Worker; bestehende Vorgänge, Kommentare und Papierkorb bleiben erhalten.
 * - 2026-09-29 (Version unverändert: 3.4.2)
 *   - Benachrichtigungston auch für neue SMS-Kommentare in bestehenden Vorgängen; bekannte SMS lösen beim Polling keinen weiteren Ton aus.
 * - 2026-09-29 (Version unverändert: 3.4.2)
 *   - Weitere eingegangene SMS farbig im chronologischen Kommentarverlauf dargestellt; gemeinsame Inbox-Sperre und atomare Änderungen schützen vor gleichzeitigen Worker-Zugriffen.
 * - 2026-09-29 (Version unverändert: 3.4.2)
 *   - SMS-Queue als dritten aktiven Versandweg integriert; Webanfragen speichern Aufträge ohne Routerwartezeit, Versandstatus erscheint bei der Karte.
 * - 2026-09-29 (Version unverändert: 3.4.2)
 *   - Produktname in Kopfzeile und Browsertitel auf "kienzlefon app" umgestellt; Dateinamen und technische Bezeichner bleiben unverändert.
 * - v3.4.2 (2026-07-11)
 *   - Eigene Kategorie "Termin" ergänzt; Termine werden wie Überweisungen sortiert und zeigen Terminwunsch/Grund als Haupttext.
 *   - Webformular-Termine werden nun als typ "termin" statt als "sonstiges" verarbeitet.
 * - v3.4.1 (2026-06-25)
 *   - Adminpasswortvergleich toleriert versehentliche Leerzeichen/Zeilenumbrueche aus Installer-Patches.
 * - v3.4 (2026-06-25)
 *   - SMS-Integration für Karten in „In Bearbeitung“ ergänzt: SMS-Button nur bei vorhandener Rückrufnummer, aufklappbares SMS-Feld analog zum Kommentarfeld und Eintrag der versendeten SMS als Kommentar.
 *   - SMS-Versand über separate Funktionsdatei telepraxis-sms.php angebunden, damit die App-Datei schlank bleibt; der Versand läuft per AJAX mit Sendestatus, damit das Interface während der FRITZ!Box-Wartezeit bedienbar bleibt.
 * - v3.3.2 (2026-06-25)
 *   - Darstellungsfix für die rechte Spalte: Vorschautexte in der Tabellenansicht „Abgeschlossen“ werden nun wie in „Neu“ begrenzt; lange ununterbrochene Inhalte brechen in Tabellen- und Kartenvorschauen sauber um.
 * - v3.3.1 (2026-04-14)
 *   - Ausblendelogik der oberen Leiste korrigiert: Der linke Titelblock und das Hamburger-Menü rechts bleiben nun immer sichtbar; bei Platzmangel werden ausschließlich der Mittelteil mit den Statuschips und die zusätzliche Schaltfläche „Abgeschlossen anzeigen“ ausgeblendet.
 * - v3.3 (2026-04-14)
 *   - Im Bereich „In Bearbeitung“ wurde der Löschknopf wie besprochen entfernt; Karten dort bieten nun kein direktes Löschen mehr an.
 *   - Für Löschaktionen in „Abgeschlossen“ wurden Rückfragen ergänzt: sowohl Einzelaktionen als auch Sammelaktionen fragen nun vor dem Verschieben in den Papierkorb nach.
 *   - „Abgeschlossen anzeigen“ wurde zusätzlich wieder in die obere Leiste direkt links neben das Hamburger-Menü aufgenommen; die Anzeige ist mit der Menü-Checkbox synchronisiert und blendet sich bei Platzmangel aus.
 * - v3.2 (2026-04-14)
 *   - Papierkorb-Pfeilknopf neu positioniert: bei geschlossenem Papierkorb als frei schwebender Overlay-Button mittig am unteren Fensterrand ohne Platzreservierung, bei geöffnetem Papierkorb direkt in dessen Titelleiste.
 *   - Pfeilrichtung des Papierkorb-Knopfes an den Zustand angepasst: geschlossen zeigt nach oben, geöffnet nach unten.
 *   - Platz-Bereich im Hamburger-Menü ohne Umbruch nach „Speichern“ verdichtet; die Aktionszeile mit Speichern, Bookmark-Link und Öffnen bleibt nun zusammen.
 * - v3.1 (2026-04-14)
 *   - Platz-Placeholder auf „z.B. "Julia", "anm-li" oder "Dr. Meier"“ angepasst.
 *   - Platz-Eingabefeld ohne gesetzten Platz so verbreitert, dass der Placeholder vollständig sichtbar bleibt.
 *   - „Papierkorb anzeigen“ wird im Hamburger-Menü nur noch bei aktivem Admin-Zugang eingeblendet.
 *   - Zusätzlicher Papierkorb-Pfeilknopf ergänzt; Kopfzeile optisch verfeinert: nur „telepraxis-app“ ist hervorgehoben, Versions-/Autorenzeile wirkt dezenter.
 * - v3.0 (2026-04-14)
 *   - Oberes User-Interface platzsparend neu aufgebaut: eine einzeilige Kopfzeile mit Titel links, responsiver Statuszusammenfassung in der Mitte und Hamburger-Menü rechts; die Statuspunkte werden bei Platzmangel von hinten ausgeblendet und umbrechen nie.
 *   - Platz-Konzept überarbeitet: Wenn kein Platz gesetzt ist, erscheint das Eingabefeld als zweite Zeile oberhalb des Inhalts; bei gesetztem Platz bleibt die Eingabe nur noch im Hamburger-Menü verfügbar.
 *   - Hamburger-Menü ergänzt und strukturiert: Platz inkl. Bookmark-Link/Öffnen, Admin-Bereich, einzelne Anzeige-/Ton-Optionen sowie die Zähler für Neu, Dringend, In Bearbeitung und Abgeschlossen.
 *   - Placeholder für Platz auf „z.B. "Julia" oder "anmeldung-links" oder "Dr. Meier"“ erweitert.
 * - v2.9 (2026-04-02)
 *   - Darstellung in „In Bearbeitung“ weiter stabilisiert: Karten wachsen nun auch bei langen Kommentaren zuverlässig bis zum tatsächlichen Inhaltsende; Flex-Shrinking wurde für die Karten in der linken Spalte unterbunden.
 *   - Kommentartexte, mehrzeilige Inhalte und das Kommentarfeld erzwingen nun bei langen Wörtern oder eingefügten Blöcken saubere Umbrüche statt das Layout zu sprengen.
 *   - In der Tabellenansicht von „Neu“ wird die Vorschau nun auf drei Zeilen begrenzt; der vollständige Text bleibt per Tooltip verfügbar.
 * - v2.8 (2026-04-02)
 *   - Druckfunktion für Karten in Bearbeitung robust neu umgesetzt; der Druckdialog wird nun über ein verstecktes Druck-iframe ausgelöst statt über ein fragiles Popup-Fenster.
 *   - Karten in „Neu“ wachsen nun auch bei schmalem Fenster mit umbrechenden Button-Zeilen sauber mit; die starre Höhenbegrenzung wurde entfernt.
 *   - Karten in „In Bearbeitung“ wachsen beim geöffneten Kommentarfeld wieder sauber mit; automatische Abstände am unteren Kartenende werden dort nicht mehr erzwungen.
 *   - In „Neu“ wurde der Kopfzeilen-Button „Alle auswählen“ wie besprochen entfernt.
* - v2.7 (2026-04-02)
*   - Mitte, Abgeschlossen und Papierkorb wurden wieder auf den besprochenen Funktionsstand gebracht und sind nun erneut zwischen Karten- und Tabellenansicht umschaltbar, inklusive Checkboxen, Sammelaktionen und lokaler Speicherung der Ansichtsart.
*   - Tabellenansichten wurden platzsparend überarbeitet: Checkbox ohne Text, Name überall linksbündig, Eingangszeit unter dem Namen, Vorschautext immer sichtbar, Telefonspalte entfernt; Tabellenzeilen sind umrandet und farblich analog zu den Karten.
*   - Bereich „In Bearbeitung“ wurde stabilisiert: Kommentarfelder behalten bei Polling Fokus, Text und Cursorposition; „Fertig“ steht links; Druck- und Zwischenablage-Aktionen sind als Symbol-Buttons umgesetzt.
*   - Druckfunktion und Zwischenablageausgabe repariert; beide nutzen den vollständigen Karteninhalt als reinen Text inklusive deutlichem DRINGEND-Hinweis, Gesprächszusammenfassung, übermittelter Telefonnummer und Kommentaren.
*   - Neuer Button „Bookmark-Link“ beim Feld „Platz“: kopiert den platzbezogenen Link in die Zwischenablage; danach erscheint zusätzlich „Öffnen“.
*   - Browsertitel zeigt wieder dynamisch die Anzahl neuer Vorgänge; der Benachrichtigungston erfolgt nun viermal hintereinander, der letzte Ton dreifach lang.
 * - v2.5 (2026-04-02)
 *   - Bereich „In Bearbeitung“ erhielt eine Kommentarfunktion mit 3-zeiligem Eingabefeld, Speicherung beliebig vieler Kommentare im JSON mit Zeitstempel und Platz sowie Anzeige aller Kommentare unterhalb der übermittelten Telefonnummer.
 *   - Sichtbare UI-Bezeichnung „Arbeitsplatz“ wurde durchgängig zu „Platz“ umgestellt; interne Feld- und Variablennamen bleiben unverändert.
 *   - Karten in Bearbeitung erhielten die Zusatzaktionen „Kommentar“, „Drucken“ und „Karte in die Zwischenablage“.
 *   - Druck- und Zwischenablageausgabe umfassen nun die komplette Karte inklusive deutlichem Dringend-Hinweis, Gesprächszusammenfassung, übermittelter Telefonnummer und vorhandener Kommentare.
 * - v2.0 (2026-03-31)
 *   - Darstellungsbug in der linken Spalte behoben: Karten in Bearbeitung wachsen nun zuverlässig vollständig mit dem Inhalt, auch bei längeren Detailblöcken und Button-Reihen.
 * - v1.9 (2026-03-31)
 *   - Zustand aufgeklappter Gesprächszusammenfassungen wird nun pro Karte im Browser gemerkt und bleibt trotz Polling-Refresh erhalten.
 *   - Namenskopie im Header robuster umgesetzt; Klick auf den Namen kopiert wie besprochen „Nachname, Vorname JJJJ“.
 *   - Klick auf das Geburtsdatum kopiert nun immer das Geburtsdatum in die Zwischenablage.
 *   - In der linken Spalte wird die Namenszeile innerhalb der Karte linksbündig dargestellt.
 * - v1.8 (2026-03-31)
 *   - Karten in Bearbeitung wachsen nun immer mit dem Inhalt; keine fixe Begrenzung der Detailhöhe.
 *   - Block „Zusammenfassung des Gesprächs“ in Bearbeitung zunächst eingeklappt; Klick auf die Überschrift öffnet den Text im gleichen umrandeten Bereich.
 * - v1.7 (2026-03-31)
 *   - Header wieder auf den besprochenen Stand aus v1.5 zurückgeführt: vollständiger umrandeter Kopf über die ganze Kartenbreite.
 *   - Kategorien auf die besprochenen Anzeige-Kategorien festgelegt: Rückruf, Sonstiges, Rezept, Überweisung.
 *   - Geburtsdatum im Kopf nicht mehr fett dargestellt.
 *   - Parser/UI um aktuelle Request-Typen und Felder erweitert, inklusive id/anrufer_id als übermittelte Telefonnummer.
 *   - In Bearbeitung: neuer Block „Zusammenfassung des Gesprächs“ und Anzeige „Übermittelte Telefonnummer“ am Kartenende.
 *   - Vorschautext in Mitte/Abgeschlossen/Papierkorb nutzt zusammenfassung als Fallback, wenn typspezifische Felder leer sind.
 * - v1.6 (2026-03-31)
 *   - Unterstützung neuer Typen/Felder aus dem Telefonassistenten ergänzt.
 * - v1.5 (2026-03-30)
 *   - Bei in Bearbeitung steht „bei Arbeitsplatz“ in der untersten Headerzeile zwischen Dringend-Symbol und Eingangsdatum.
 * - v1.4 (2026-03-30)
 *   - Kategorie wieder umrandet, nicht fett; Body linksbündig; Karten minimal vergrößert; kompakte Texte mit Ellipsis und Tooltip.
 * - v1.3 (2026-03-30)
 *   - Kopf wieder als durchgehend umrandeter Vollbreiten-Header mit drei Zeilen umgesetzt.
 * - v1.2 (2026-03-30)
 *   - Namenszeile im Header mit Kopierfunktion und globaler Dringend-Sortierung ergänzt.
 * - v1.1 (2026-03-30)
 *   - Karteninhalt verschlankt und Button-Sets je Spalte angepasst.
 * - v1.0 (2026-03-30)
 *   - Erstversion als Ein-Datei-Webapp für JSON-Dateien aus ./inbox.
 */

declare(strict_types=1);

session_start();
date_default_timezone_set('Europe/Berlin');

define('TELEPRAXIS_APP', true);
require_once __DIR__ . '/telepraxis-sms.php';

const TELEPRAXIS_APP_NAME = 'kienzlefon app';
const TELEPRAXIS_APP_VERSION = '3.6';
const TELEPRAXIS_INBOX_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'inbox';
const TELEPRAXIS_POLL_INTERVAL_MS = 5000;
const TELEPRAXIS_DEFAULT_TIMEZONE = 'Europe/Berlin';
const TELEPRAXIS_ADMIN_PASSWORD = 'bitte-aendern';
const TELEPRAXIS_WORKPLACE_MAXLEN = 64;
const TELEPRAXIS_USER_MAXLEN = 64;

// Optionale kienzlefon-t2med-Anbindung. Hier aktivieren und die eigene Basisadresse
// eintragen, z.B. http://middleware.example:8765 (ohne /waehlen oder Parameter).
// Der Platz aus der Kopfzeile ist die client_id; telefon kommt aus dem Vorgang.
const TELEPRAXIS_T2MED_ENABLED = false;
const TELEPRAXIS_T2MED_BASE_URL = '';

// Optional: URL-Pfad zum Webserver-Symlink auf den Kienzlefon-Ready-Spool.
// Beispiel: 'recordings' (relativ zur telepraxis-app.php). Leer = Player aus.
const TELEPRAXIS_RECORDINGS_BASE_URL = '';

function tp_now_iso(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(TELEPRAXIS_DEFAULT_TIMEZONE)))->format(DATE_ATOM);
}

function tp_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tp_json_response(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tp_is_admin_enabled(): bool
{
    return tp_admin_password_value() !== '';
}

function tp_admin_password_value(): string
{
    return trim((string)TELEPRAXIS_ADMIN_PASSWORD);
}

function tp_is_admin(): bool
{
    return !empty($_SESSION['telepraxis_admin']) && $_SESSION['telepraxis_admin'] === true;
}

function tp_get_csrf_token(): string
{
    if (empty($_SESSION['telepraxis_csrf']) || !is_string($_SESSION['telepraxis_csrf'])) {
        $_SESSION['telepraxis_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['telepraxis_csrf'];
}

function tp_require_csrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals(tp_get_csrf_token(), $token)) {
        tp_json_response(['ok' => false, 'error' => 'Ungültiger CSRF-Token.'], 400);
    }
}

function tp_sanitize_workplace(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/[^a-zA-Z0-9_-]+/', '', $value) ?? '';
    return substr($value, 0, TELEPRAXIS_WORKPLACE_MAXLEN);
}

function tp_sanitize_user(?string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$value) ?? '';
    $chars = preg_split('//u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return implode('', array_slice($chars, 0, TELEPRAXIS_USER_MAXLEN));
}

function tp_normalize_phone(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[^\d+]/', '', $value) ?? '';
    if ($value === '') {
        return '';
    }

    if (strpos($value, '+49') === 0) {
        return '0' . substr($value, 3);
    }
    if (strpos($value, '0049') === 0) {
        return '0' . substr($value, 4);
    }
    if (strpos($value, '49') === 0 && (strlen($value) < 3 || $value[0] !== '0')) {
        return '0' . substr($value, 2);
    }
    if ($value[0] === '+') {
        return ltrim($value, '+');
    }
    return $value;
}

function tp_valid_tel_href(?string $value): string
{
    $normalized = tp_normalize_phone($value);
    if ($normalized === '') {
        return '';
    }
    return preg_match('/^[0-9]+$/', $normalized) ? $normalized : '';
}

function tp_format_datetime(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('d.m.Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

function tp_read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return null;
    }

    try {
        if (!flock($fh, LOCK_SH)) {
            fclose($fh);
            return null;
        }
        $raw = stream_get_contents($fh) ?: '';
        flock($fh, LOCK_UN);
        fclose($fh);
    } catch (Throwable $e) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
        return null;
    }

    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function tp_entry_default_app(array $entry): array
{
    $received = (string)($entry['received_at'] ?? tp_now_iso());
    return [
        'status' => 'neu',
        'dringend' => false,
        'deleted' => false,
        'status_updated_at' => $received,
        'status_updated_arbeitsplatz' => '',
        'last_action' => 'created',
        'completed_at' => null,
        'deleted_at' => null,
        'deleted_arbeitsplatz' => null,
        'in_bearbeitung_at' => null,
        'comments' => [],
    ];
}

function tp_ensure_entry_app(array $entry): array
{
    $defaults = tp_entry_default_app($entry);
    if (!isset($entry['app']) || !is_array($entry['app'])) {
        $entry['app'] = [];
    }
    if (!array_key_exists('status_updated_benutzer', $entry['app'])) {
        $entry['app']['status_updated_benutzer'] = tp_sanitize_user((string)($entry['app']['status_updated_arbeitsplatz'] ?? ''));
    }
    if (!array_key_exists('deleted_benutzer', $entry['app'])) {
        $entry['app']['deleted_benutzer'] = $entry['app']['deleted_arbeitsplatz'] ?? null;
    }
    $entry['app'] = array_merge($defaults, $entry['app']);

    if (!in_array($entry['app']['status'], ['neu', 'in_bearbeitung', 'abgeschlossen'], true)) {
        $entry['app']['status'] = 'neu';
    }
    $entry['app']['dringend'] = (bool)$entry['app']['dringend'];
    $entry['app']['deleted'] = (bool)$entry['app']['deleted'];
    $entry['app']['comments'] = tp_normalize_comments($entry['app']['comments'] ?? []);
    return $entry;
}

function tp_normalize_comments($comments): array
{
    if (!is_array($comments)) {
        return [];
    }

    $normalized = [];
    foreach ($comments as $comment) {
        if (!is_array($comment)) {
            continue;
        }
        $isSms = ($comment['kind'] ?? '') === 'sms_received'
            && preg_match('/^[a-f0-9]{64}$/D', (string)($comment['sms_received_key'] ?? ''));
        $text = $isSms ? (string)($comment['text'] ?? '') : trim((string)($comment['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $item = [
            'text' => $text,
            'created_at' => (string)($comment['created_at'] ?? tp_now_iso()),
            'workplace' => tp_sanitize_workplace((string)($comment['workplace'] ?? '')),
        ];
        if (array_key_exists('benutzer', $comment)) {
            $item['benutzer'] = tp_sanitize_user((string)$comment['benutzer']);
        }
        if ($isSms) {
            $item['kind'] = 'sms_received';
            $item['sms_received_key'] = (string)$comment['sms_received_key'];
            $item['sms_sender'] = (string)($comment['sms_sender'] ?? '');
        }
        $normalized[] = $item;
    }

    return $normalized;
}

function tp_payload(array $entry): array
{
    return isset($entry['payload']) && is_array($entry['payload']) ? $entry['payload'] : [];
}

function tp_entry_typ(array $entry): string
{
    $typ = (string)($entry['typ'] ?? '');
    if ($typ === '' && isset($entry['payload']['typ'])) {
        $typ = (string)$entry['payload']['typ'];
    }
    return strtolower(trim($typ));
}

function tp_payload_value(array $payload, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($payload[$key]) && trim((string)$payload[$key]) !== '') {
            return trim((string)$payload[$key]);
        }
    }
    return '';
}

function tp_entry_callback_phone_raw(array $entry): string
{
    return tp_payload_value(tp_payload($entry), ['telefon']);
}

function tp_entry_category_key(array $entry): string
{
    $typ = tp_entry_typ($entry);
    if ($typ === 'rezeptbestellung' || strpos($typ, 'rezept') !== false) {
        return 'rezept';
    }
    if ($typ === 'ueb_req' || strpos($typ, 'ueberweisung') !== false || strpos($typ, 'überweisung') !== false) {
        return 'ueberweisung';
    }
    if ($typ === 'termin') {
        return 'termin';
    }
    if ($typ === 'sonstiges') {
        return 'sonstiges';
    }
    return 'rueckruf';
}

function tp_category_label(string $key): string
{
    switch ($key) {
        case 'rezept':
            return 'Rezept';
        case 'ueberweisung':
            return 'Überweisung';
        case 'termin':
            return 'Termin';
        case 'sonstiges':
            return 'Sonstiges';
        default:
            return 'Rückruf';
    }
}

function tp_category_order(string $key): int
{
    switch ($key) {
        case 'rueckruf':
        case 'sonstiges':
            return 0;
        case 'ueberweisung':
        case 'termin':
            return 1;
        case 'rezept':
            return 2;
        default:
            return 9;
    }
}

function tp_birth_year(?string $birthDate): string
{
    $birthDate = trim((string)$birthDate);
    if ($birthDate === '') {
        return '';
    }
    if (preg_match('/^\d{2}\.\d{2}\.(\d{4})$/', $birthDate, $m)) {
        return $m[1];
    }
    if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $birthDate, $m)) {
        return $m[1];
    }
    return '';
}

function tp_person_strings(array $payload): array
{
    $firstName = tp_payload_value($payload, ['vorname']);
    $lastName = tp_payload_value($payload, ['nachname']);
    $singleName = tp_payload_value($payload, ['name']);
    $birthDate = tp_payload_value($payload, ['geburtsdatum']);

    $nameMain = '';
    if ($lastName !== '' || $firstName !== '') {
        $nameMain = trim($lastName . ', ' . $firstName, ' ,');
    } elseif ($singleName !== '') {
        $nameMain = $singleName;
    }

    $display = $nameMain;
    if ($nameMain !== '' && $birthDate !== '') {
        $display .= ' ' . $birthDate;
    }

    $copy = $nameMain;
    $year = tp_birth_year($birthDate);
    if ($copy !== '' && $year !== '') {
        $copy .= ' ' . $year;
    }

    return [
        'name_main' => $nameMain,
        'birth_date' => $birthDate,
        'display' => $display,
        'copy' => $copy,
    ];
}

function tp_build_main_text(array $entry): string
{
    $payload = tp_payload($entry);
    $typ = tp_entry_typ($entry);

    $summary = tp_payload_value($payload, ['zusammenfassung']);
    $medikamente = tp_payload_value($payload, ['medikamente']);
    $fachrichtung = tp_payload_value($payload, ['fachrichtung']);
    $grund = tp_payload_value($payload, ['grund']);
    $anliegen = tp_payload_value($payload, ['anliegen']);

    if ($typ === 'rezeptbestellung' || strpos($typ, 'rezept') !== false) {
        return $medikamente !== '' ? $medikamente : ($summary !== '' ? $summary : '—');
    }

    if ($typ === 'ueb_req') {
        $parts = [];
        if ($fachrichtung !== '') {
            $parts[] = $fachrichtung;
        }
        if ($grund !== '') {
            $parts[] = $grund;
        }
        if ($parts !== []) {
            return implode(' – ', $parts);
        }
        return $summary !== '' ? $summary : '—';
    }

    if ($typ === 'termin') {
        return $grund !== '' ? $grund : ($anliegen !== '' ? $anliegen : ($summary !== '' ? $summary : '—'));
    }

    if (in_array($typ, ['rueckruf_tel_grund', 'rueckruf_details', 'fallback_name_tel_grund', 'fallback_vn_nn_grund'], true)) {
        return $grund !== '' ? $grund : ($summary !== '' ? $summary : '—');
    }

    if ($typ === 'sonstiges') {
        return $anliegen !== '' ? $anliegen : ($summary !== '' ? $summary : '—');
    }

    if ($typ === 'rueckruf_min') {
        return $summary !== '' ? $summary : 'Bitte zurückrufen.';
    }

    if ($typ === 'fallback_id_zusammenfassung') {
        return $summary !== '' ? $summary : '—';
    }

    return $summary !== '' ? $summary : ($grund !== '' ? $grund : ($anliegen !== '' ? $anliegen : '—'));
}

function tp_sms_queue_comments(string $fileName): array
{
    static $settings = null;
    static $unavailable = false;
    try {
        if ($unavailable) {
            throw new RuntimeException('Queue nicht erreichbar.');
        }
        if ($settings === null) {
            $settings = tp_sms_load_settings();
        }
        if (trim((string)($settings['queue']['database_path'] ?? '')) === '') {
            return [];
        }
        require_once __DIR__ . '/telepraxis-sms-queue.php';
        $jobs = tp_sms_queue_for_source($settings, $fileName);
        $comments = [];
        foreach ($jobs as $job) {
            $labels = [
                'pending' => 'zum Versand vorgemerkt',
                'sending' => 'wird gesendet',
                'accepted' => ($job['delivery_provider'] ?? 'fritz') === 'seven'
                    ? 'an seven.io übergeben' : 'an FRITZ!Box übergeben',
                'failed' => 'fehlgeschlagen – bitte prüfen',
                'uncertain' => 'Versand unklar – vor erneutem Versand prüfen',
            ];
            $label = $labels[$job['status']] ?? 'Status unbekannt – bitte prüfen';
            $comments[] = [
                'text' => 'SMS an ' . (string)$job['recipient'] . ' · ' . $label . ":\n" . (string)$job['message'],
                'created_at' => (string)$job['created_at'],
                'created_at_display' => tp_format_datetime((string)$job['created_at']),
                'workplace' => (string)$job['workplace'],
                // Die bestehende Queue-Spalte workplace transportiert weiterhin den Autor.
                'benutzer' => (string)$job['workplace'],
                'sms_job_id' => (string)$job['id'],
                'sms_status' => (string)$job['status'],
            ];
        }
        return $comments;
    } catch (Throwable $error) {
        $unavailable = true;
        return [[
            'text' => 'SMS-Queue-Status derzeit nicht verfügbar. Bereits vorgemerkte SMS nicht erneut absenden.',
            'created_at' => '', 'created_at_display' => '', 'workplace' => '',
        ]];
    }
}

/**
 * Originalaufnahmen der Whisper-Felder aus der bestehenden Kienzlefon-Metadatenliste.
 * Keine Dateien suchen oder kopieren: der Webserver liefert WAVs über einen
 * separat eingerichteten statischen recordings/-Symlink aus.
 */
function tp_recording_segments(array $entry, string $fileName): array
{
    $base = trim(TELEPRAXIS_RECORDINGS_BASE_URL);
    if ($base === '' || !preg_match('~^/?[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $base)) {
        return [];
    }

    $payload = tp_payload($entry);
    $meta = $payload['_kienzlefon'] ?? null;
    if (!is_array($meta) || !isset($meta['audio']) || !is_array($meta['audio'])) {
        return [];
    }

    $callId = pathinfo($fileName, PATHINFO_FILENAME);
    if (!preg_match('/^[0-9]{8}_[0-9]{6}_[0-9]{6}$/D', $callId)
        || ($meta['vorgang_id'] ?? null) !== $callId) {
        return [];
    }

    $labels = [
        'vorname' => 'Vorname',
        'nachname' => 'Nachname',
        'geburtsdatum' => 'Geburtsdatum',
        'telefon' => 'Rückrufnummer',
        'fachrichtung' => 'Fachrichtung',
        'grund' => 'Grund',
        'anliegen' => 'Anliegen',
    ];
    $segments = [];
    $seen = [];
    foreach ($meta['audio'] as $audio) {
        if (!is_array($audio) || ($audio['datei_vorhanden'] ?? true) === false
            || ($audio['status'] ?? '') === 'empty') {
            continue;
        }
        $relative = $audio['datei'] ?? null;
        if (!is_string($relative) || !preg_match('~^audio/([a-zA-Z0-9_-]+\.wav)$~D', $relative, $match)
            || isset($seen[$relative])) {
            continue;
        }
        $seen[$relative] = true;
        $field = (string)($audio['feld'] ?? '');
        if ($field === 'medikamente') {
            $index = filter_var($audio['index'] ?? null, FILTER_VALIDATE_INT);
            $label = $index !== false && $index > 0 ? "Medikament $index" : 'Medikament';
        } else {
            $label = $labels[$field] ?? 'Aufnahme ' . (count($segments) + 1);
        }
        $segments[] = [
            'file' => $relative,
            'label' => $label,
            'url' => rtrim($base, '/') . '/' . rawurlencode($callId)
                . '/audio/' . rawurlencode($match[1]),
        ];
    }

    return $segments;
}

function tp_build_entry_view(array $entry, string $fileName): array
{
    $entry = tp_ensure_entry_app($entry);
    $payload = tp_payload($entry);
    $categoryKey = tp_entry_category_key($entry);
    $person = tp_person_strings($payload);

    $summary = tp_payload_value($payload, ['zusammenfassung']);
    $callbackPhoneRaw = tp_entry_callback_phone_raw($entry);
    $displayPhoneRaw = tp_payload_value($payload, ['telefon']);
    $transmittedPhoneRaw = tp_payload_value($payload, ['id', 'anrufer_id']);
    if ($displayPhoneRaw === '') {
        $displayPhoneRaw = $transmittedPhoneRaw;
    }

    $body = tp_build_main_text($entry);
    $comments = [];
    foreach (($entry['app']['comments'] ?? []) as $comment) {
        if (!is_array($comment)) {
            continue;
        }
        $comments[] = [
            'text' => (string)($comment['text'] ?? ''),
            'created_at' => (string)($comment['created_at'] ?? ''),
            'created_at_display' => tp_format_datetime((string)($comment['created_at'] ?? '')),
            'workplace' => tp_sanitize_workplace((string)($comment['workplace'] ?? '')),
            'benutzer' => tp_sanitize_user((string)($comment['benutzer'] ?? $comment['workplace'] ?? '')),
            'kind' => (string)($comment['kind'] ?? ''),
            'sms_received_key' => (string)($comment['sms_received_key'] ?? ''),
            'sms_sender' => (string)($comment['sms_sender'] ?? ''),
        ];
    }

    $comments = array_merge($comments, tp_sms_queue_comments($fileName));
    usort($comments, static function (array $left, array $right): int {
        return (strtotime($left['created_at']) ?: 0) <=> (strtotime($right['created_at']) ?: 0);
    });

    return [
        'id' => (string)($entry['id'] ?? pathinfo($fileName, PATHINFO_FILENAME)),
        'file' => $fileName,
        'received_at' => (string)($entry['received_at'] ?? ''),
        'received_at_display' => tp_format_datetime((string)($entry['received_at'] ?? '')),
        'type' => tp_entry_typ($entry),
        'category_key' => $categoryKey,
        'category_label' => tp_category_label($categoryKey),
        'category_order' => tp_category_order($categoryKey),
        'status' => (string)$entry['app']['status'],
        'urgent' => (bool)$entry['app']['dringend'],
        'deleted' => (bool)$entry['app']['deleted'],
        'person_name' => $person['name_main'],
        'person_birth_date' => $person['birth_date'],
        'person_display' => $person['display'],
        'person_copy' => $person['copy'],
        'body' => $body,
        'summary' => $summary,
        'telephone_display' => tp_normalize_phone($displayPhoneRaw),
        'telephone_href' => tp_valid_tel_href($displayPhoneRaw),
        'telephone_raw' => $displayPhoneRaw,
        'sms_phone_display' => tp_normalize_phone($callbackPhoneRaw),
        'sms_phone_href' => tp_valid_tel_href($callbackPhoneRaw),
        'sms_phone_raw' => $callbackPhoneRaw,
        'transmitted_phone_display' => tp_normalize_phone($transmittedPhoneRaw) !== '' ? tp_normalize_phone($transmittedPhoneRaw) : $transmittedPhoneRaw,
        'transmitted_phone_href' => tp_valid_tel_href($transmittedPhoneRaw),
        'transmitted_phone_raw' => $transmittedPhoneRaw,
        'last_updated_at' => (string)($entry['app']['status_updated_at'] ?? ''),
        'last_updated_display' => tp_format_datetime((string)($entry['app']['status_updated_at'] ?? '')),
        'last_workplace' => (string)($entry['app']['status_updated_arbeitsplatz'] ?? ''),
        'last_user' => tp_sanitize_user((string)($entry['app']['status_updated_benutzer'] ?? '')),
        'deleted_at_display' => tp_format_datetime((string)($entry['app']['deleted_at'] ?? '')),
        'deleted_workplace' => (string)($entry['app']['deleted_arbeitsplatz'] ?? ''),
        'deleted_user' => tp_sanitize_user((string)($entry['app']['deleted_benutzer'] ?? '')),
        'comments' => $comments,
        'recordings' => tp_recording_segments($entry, $fileName),
    ];
}

function tp_list_entries(bool $includeDeleted = true): array
{
    if (!is_dir(TELEPRAXIS_INBOX_DIR)) {
        return [];
    }

    $paths = glob(TELEPRAXIS_INBOX_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [];
    rsort($paths, SORT_STRING);

    $entries = [];
    foreach ($paths as $path) {
        $data = tp_read_json_file($path);
        if (!is_array($data)) {
            continue;
        }
        $view = tp_build_entry_view($data, basename($path));
        if (!$includeDeleted && $view['deleted']) {
            continue;
        }
        $entries[] = $view;
    }
    return $entries;
}

function tp_collect_stats(array $entries): array
{
    $stats = ['neu' => 0, 'in_bearbeitung' => 0, 'abgeschlossen' => 0, 'deleted' => 0, 'urgent' => 0, 'total' => 0];
    foreach ($entries as $entry) {
        $stats['total']++;
        if (!empty($entry['deleted'])) {
            $stats['deleted']++;
            continue;
        }
        if (isset($stats[$entry['status']])) {
            $stats[$entry['status']]++;
        }
        if (!empty($entry['urgent'])) {
            $stats['urgent']++;
        }
    }
    return $stats;
}

function tp_inbox_file_path(string $fileName): string
{
    $safeFile = basename($fileName);
    if (!preg_match('/^[A-Za-z0-9._-]+\.json$/', $safeFile)) {
        throw new RuntimeException('Ungültiger Dateiname.');
    }

    return TELEPRAXIS_INBOX_DIR . DIRECTORY_SEPARATOR . $safeFile;
}

/** Stable lock shared with the SMS worker, including atomic replacements and purge. */
function tp_inbox_lock()
{
    $path = TELEPRAXIS_INBOX_DIR . DIRECTORY_SEPARATOR . '.telepraxis-inbox.lock';
    clearstatcache(true, $path);
    if (is_link($path) || (file_exists($path) && !is_file($path))) {
        throw new RuntimeException('Inbox-Sperre nicht verfügbar.');
    }
    $oldUmask = umask(0007);
    try {
        $lock = @fopen($path, 'c+b');
    } finally {
        umask($oldUmask);
    }
    if (!is_resource($lock)) {
        throw new RuntimeException('Inbox-Sperre nicht verfügbar.');
    }
    $opened = fstat($lock);
    $current = @lstat($path);
    if (!is_array($opened) || !is_array($current) || is_link($path)
        || ($opened['mode'] & 0170000) !== 0100000
        || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
        fclose($lock);
        throw new RuntimeException('Inbox-Sperre nicht verfügbar.');
    }
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        throw new RuntimeException('Inbox-Sperre fehlgeschlagen.');
    }
    return $lock;
}

function tp_write_entry_atomic(string $path, array $entry): void
{
    $json = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('JSON konnte nicht gespeichert werden.');
    }
    $json .= "\n";
    $tempPath = TELEPRAXIS_INBOX_DIR . DIRECTORY_SEPARATOR . '.app-' . bin2hex(random_bytes(16)) . '.tmp';
    $handle = false;
    try {
        $oldUmask = umask(0007);
        try {
            $handle = @fopen($tempPath, 'x+b');
        } finally {
            umask($oldUmask);
        }
        if (!is_resource($handle)) {
            throw new RuntimeException('Vorgang konnte nicht gespeichert werden.');
        }
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = @fwrite($handle, substr($json, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Vorgang konnte nicht gespeichert werden.');
            }
            $offset += $written;
        }
        $mode = @fileperms($path);
        if ($mode === false || !@chmod($tempPath, $mode & 0777) || !@fflush($handle)
            || (function_exists('fsync') && !@fsync($handle))) {
            throw new RuntimeException('Vorgang konnte nicht gespeichert werden.');
        }
        fclose($handle);
        $handle = false;
        if (!@rename($tempPath, $path)) {
            throw new RuntimeException('Vorgang konnte nicht gespeichert werden.');
        }
        if (function_exists('fsync')) {
            $directory = @fopen(TELEPRAXIS_INBOX_DIR, 'rb');
            if (!is_resource($directory)) {
                throw new RuntimeException('Speicherung konnte nicht bestätigt werden. Bitte Ansicht aktualisieren.');
            }
            try {
                if (!@fsync($directory)) {
                    throw new RuntimeException('Speicherung konnte nicht bestätigt werden. Bitte Ansicht aktualisieren.');
                }
            } finally {
                fclose($directory);
            }
        }
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (is_file($tempPath)) {
            @unlink($tempPath);
        }
    }
}

function tp_update_file(string $fileName, callable $mutator): array
{
    $path = tp_inbox_file_path($fileName);
    $lock = tp_inbox_lock();
    try {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Datei nicht gefunden.');
        }
        $decoded = tp_read_json_file($path);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON konnte nicht gelesen werden.');
        }
        $updated = $mutator(tp_ensure_entry_app($decoded));
        if (!is_array($updated)) {
            throw new RuntimeException('Interner Fehler beim Aktualisieren.');
        }
        tp_write_entry_atomic($path, $updated);
        return $updated;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function tp_action_response(array $entry, string $file): void
{
    tp_json_response([
        'ok' => true,
        'entry' => tp_build_entry_view($entry, $file),
        'csrf' => tp_get_csrf_token(),
    ]);
}

function tp_apply_status(array $entry, string $status, string $workplace, string $user): array
{
    $entry = tp_ensure_entry_app($entry);
    $entry['app']['status'] = $status;
    $entry['app']['status_updated_at'] = tp_now_iso();
    $entry['app']['status_updated_arbeitsplatz'] = $workplace;
    $entry['app']['status_updated_benutzer'] = $user;
    $entry['app']['last_action'] = 'status_' . $status;

    if ($status === 'in_bearbeitung') {
        $entry['app']['in_bearbeitung_at'] = tp_now_iso();
    }
    if ($status === 'abgeschlossen') {
        $entry['app']['completed_at'] = tp_now_iso();
    }
    return $entry;
}

function tp_append_comment(array $entry, string $text, string $workplace, string $lastAction, string $user): array
{
    $entry = tp_ensure_entry_app($entry);
    if (!isset($entry['app']['comments']) || !is_array($entry['app']['comments'])) {
        $entry['app']['comments'] = [];
    }
    $entry['app']['comments'][] = [
        'text' => $text,
        'created_at' => tp_now_iso(),
        'workplace' => $workplace,
        'benutzer' => $user,
    ];
    $entry['app']['status_updated_at'] = tp_now_iso();
    $entry['app']['status_updated_arbeitsplatz'] = $workplace;
    $entry['app']['status_updated_benutzer'] = $user;
    $entry['app']['last_action'] = $lastAction;
    return $entry;
}

function tp_handle_api(): void
{
    $action = (string)($_REQUEST['ajax'] ?? $_POST['action'] ?? '');

    if ($action === 'list') {
        $entries = tp_list_entries(true);
        tp_json_response([
            'ok' => true,
            'entries' => $entries,
            'stats' => tp_collect_stats($entries),
            'is_admin' => tp_is_admin(),
            'csrf' => tp_get_csrf_token(),
        ]);
    }

    if ($action === 'admin_login') {
        tp_require_csrf();
        $password = (string)($_POST['password'] ?? '');
        if (!tp_is_admin_enabled()) {
            tp_json_response(['ok' => false, 'error' => 'Admin-Zugang ist deaktiviert.'], 403);
        }
        if (!hash_equals(tp_admin_password_value(), $password)) {
            tp_json_response(['ok' => false, 'error' => 'Admin-Passwort ist falsch.'], 403);
        }
        $_SESSION['telepraxis_admin'] = true;
        tp_json_response(['ok' => true, 'message' => 'Admin-Zugang aktiviert.', 'csrf' => tp_get_csrf_token()]);
    }

    if ($action === 'admin_logout') {
        tp_require_csrf();
        unset($_SESSION['telepraxis_admin']);
        tp_json_response(['ok' => true, 'message' => 'Admin-Zugang beendet.', 'csrf' => tp_get_csrf_token()]);
    }

    tp_require_csrf();
    $file = (string)($_POST['file'] ?? '');
    $workplace = tp_sanitize_workplace((string)($_POST['workplace'] ?? ''));
    // Alte Clients können weiterhin workplace als Bearbeiter senden. Ein explizit
    // leeres Benutzerfeld der neuen App darf jedoch niemals den Platz übernehmen.
    $user = tp_sanitize_user((string)($_POST['benutzer'] ?? $workplace));
    if ($user === '' && in_array($action, ['set_status', 'toggle_urgent', 'soft_delete', 'add_comment', 'send_sms', 'restore', 'purge'], true)) {
        tp_json_response(['ok' => false, 'error' => 'Bitte zuerst einen Benutzer eintragen.'], 400);
    }

    try {
        if ($action === 'set_status') {
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, ['neu', 'in_bearbeitung', 'abgeschlossen'], true)) {
                tp_json_response(['ok' => false, 'error' => 'Ungültiger Status.'], 400);
            }
            $updated = tp_update_file($file, function (array $entry) use ($status, $workplace, $user): array {
                return tp_apply_status($entry, $status, $workplace, $user);
            });
            tp_action_response($updated, $file);
        }

        if ($action === 'toggle_urgent') {
            $updated = tp_update_file($file, function (array $entry) use ($workplace, $user): array {
                $entry = tp_ensure_entry_app($entry);
                $entry['app']['dringend'] = !$entry['app']['dringend'];
                $entry['app']['status_updated_at'] = tp_now_iso();
                $entry['app']['status_updated_arbeitsplatz'] = $workplace;
                $entry['app']['status_updated_benutzer'] = $user;
                $entry['app']['last_action'] = $entry['app']['dringend'] ? 'urgent_on' : 'urgent_off';
                return $entry;
            });
            tp_action_response($updated, $file);
        }

        if ($action === 'soft_delete') {
            $updated = tp_update_file($file, function (array $entry) use ($workplace, $user): array {
                $entry = tp_ensure_entry_app($entry);
                $entry['app']['deleted'] = true;
                $entry['app']['deleted_at'] = tp_now_iso();
                $entry['app']['deleted_arbeitsplatz'] = $workplace;
                $entry['app']['deleted_benutzer'] = $user;
                $entry['app']['status_updated_at'] = tp_now_iso();
                $entry['app']['status_updated_arbeitsplatz'] = $workplace;
                $entry['app']['status_updated_benutzer'] = $user;
                $entry['app']['last_action'] = 'soft_delete';
                return $entry;
            });
            tp_action_response($updated, $file);
        }

        if ($action === 'add_comment') {
            $commentText = trim((string)($_POST['comment_text'] ?? ''));
            if ($commentText === '') {
                tp_json_response(['ok' => false, 'error' => 'Kommentar fehlt.'], 400);
            }
            $updated = tp_update_file($file, function (array $entry) use ($workplace, $commentText, $user): array {
                return tp_append_comment($entry, $commentText, $workplace, 'add_comment', $user);
            });
            tp_action_response($updated, $file);
        }

        if ($action === 'send_sms') {
            $smsText = trim((string)($_POST['sms_text'] ?? ''));
            if ($smsText === '') {
                tp_json_response(['ok' => false, 'error' => 'SMS-Text fehlt.'], 400);
            }

            $safeFile = basename($file);
            $path = tp_inbox_file_path($safeFile);
            $entry = tp_read_json_file($path);
            if (!is_array($entry)) {
                tp_json_response(['ok' => false, 'error' => 'Datei konnte nicht gelesen werden.'], 400);
            }
            $entry = tp_ensure_entry_app($entry);
            $view = tp_build_entry_view($entry, $safeFile);
            if (!empty($view['deleted']) || $view['status'] !== 'in_bearbeitung') {
                tp_json_response(['ok' => false, 'error' => 'SMS nur für Karten in Bearbeitung möglich.'], 400);
            }
            if ($view['last_user'] !== '' && $view['last_user'] !== $user) {
                tp_json_response(['ok' => false, 'error' => 'SMS nur durch den aktuell bearbeitenden Benutzer möglich.'], 400);
            }

            $callbackRaw = tp_entry_callback_phone_raw($entry);
            $recipient = tp_valid_tel_href($callbackRaw);
            if ($recipient === '') {
                tp_json_response(['ok' => false, 'error' => 'Keine gültige Rückrufnummer für SMS vorhanden.'], 400);
            }

            $requestId = (string)($_POST['sms_request_id'] ?? '');
            if ($requestId !== '' && !preg_match('/^[a-f0-9]{32,64}$/D', $requestId)) {
                tp_json_response(['ok' => false, 'error' => 'Ungültige SMS-Auftragskennung.'], 400);
            }
            // Queue-Schema beibehalten: workplace ist dort die Autorenspalte.
            $context = ['source_file' => $safeFile, 'workplace' => $user];
            if ($requestId !== '') {
                $context['request_id'] = $requestId;
            }
            // Nach CSRF-Prüfung Sitzungsdaten sichern und die Sitzung freigeben.
            // Auch direkte Provider dürfen andere Anfragen dieses Browsers nicht blockieren.
            $csrfToken = tp_get_csrf_token();
            session_write_close();
            $smsResult = tp_sms_send_default($recipient, $smsText, $context);
            if (($smsResult['provider'] ?? '') === 'none') {
                tp_json_response(['ok' => false, 'error' => 'SMS-Versand ist deaktiviert.'], 400);
            }

            if (!empty($smsResult['queued'])) {
                // Der Queue-Auftrag ist zugleich der dauerhafte SMS-Verlauf.
                // Keine zweite Schreiboperation in der Vorgangsdatei nach erfolgreichem Enqueue.
                tp_json_response([
                    'ok' => true,
                    'message' => !empty($smsResult['duplicate'])
                        ? 'SMS-Auftrag bereits vorhanden. Der aktuelle Status steht bei der Karte.'
                        : 'SMS zum Versand vorgemerkt.',
                    'provider' => 'queue',
                    'queued' => true,
                    'job_id' => (string)$smsResult['job_id'],
                    'csrf' => $csrfToken,
                ], 202);
            }

            $commentText = 'SMS an Rückrufnummer ' . (tp_normalize_phone($callbackRaw) ?: $recipient) . ":\n" . $smsText;
            $updated = tp_update_file($safeFile, function (array $entry) use ($workplace, $commentText, $user): array {
                return tp_append_comment($entry, $commentText, $workplace, 'send_sms', $user);
            });
            tp_json_response([
                'ok' => true,
                'message' => 'SMS gesendet und als Kommentar gespeichert.',
                'entry' => tp_build_entry_view($updated, $safeFile),
                'provider' => (string)($smsResult['provider'] ?? ''),
                'csrf' => $csrfToken,
            ]);
        }

        if ($action === 'restore') {
            if (!tp_is_admin()) {
                tp_json_response(['ok' => false, 'error' => 'Admin erforderlich.'], 403);
            }
            $updated = tp_update_file($file, function (array $entry) use ($workplace, $user): array {
                $entry = tp_ensure_entry_app($entry);
                $entry['app']['deleted'] = false;
                $entry['app']['deleted_at'] = null;
                $entry['app']['deleted_arbeitsplatz'] = null;
                $entry['app']['deleted_benutzer'] = null;
                $entry['app']['status_updated_at'] = tp_now_iso();
                $entry['app']['status_updated_arbeitsplatz'] = $workplace;
                $entry['app']['status_updated_benutzer'] = $user;
                $entry['app']['last_action'] = 'restore';
                return $entry;
            });
            tp_action_response($updated, $file);
        }

        if ($action === 'purge') {
            if (!tp_is_admin()) {
                tp_json_response(['ok' => false, 'error' => 'Admin erforderlich.'], 403);
            }
            $path = tp_inbox_file_path($file);
            $lock = tp_inbox_lock();
            try {
                $entry = is_link($path) ? null : tp_read_json_file($path);
                if (!is_array($entry)) {
                    tp_json_response(['ok' => false, 'error' => 'Datei konnte nicht gelesen werden.'], 400);
                }
                $entry = tp_ensure_entry_app($entry);
                if (empty($entry['app']['deleted'])) {
                    tp_json_response(['ok' => false, 'error' => 'Endlöschung nur aus dem Papierkorb erlaubt.'], 400);
                }
                if (!@unlink($path)) {
                    tp_json_response(['ok' => false, 'error' => 'Datei konnte nicht endgültig gelöscht werden.'], 500);
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            tp_json_response(['ok' => true, 'message' => 'Datei endgültig gelöscht.', 'csrf' => tp_get_csrf_token()]);
        }

        tp_json_response(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
    } catch (Throwable $e) {
        tp_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if (isset($_REQUEST['ajax']) || isset($_POST['action'])) {
    tp_handle_api();
}

$initialWorkplace = tp_sanitize_workplace((string)($_GET['arbeitsplatz'] ?? ''));
$initialUser = tp_sanitize_user((string)($_GET['benutzer'] ?? ''));
$identityFromUrl = array_key_exists('arbeitsplatz', $_GET) || array_key_exists('benutzer', $_GET);
$csrfToken = tp_get_csrf_token();
$adminEnabled = tp_is_admin_enabled();
$isAdmin = tp_is_admin();
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title><?= tp_h(TELEPRAXIS_APP_NAME) ?> v<?= tp_h(TELEPRAXIS_APP_VERSION) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #f4f6f9;
            --panel: #ffffff;
            --panel-border: #d7dce3;
            --text: #1c2430;
            --muted: #677487;
            --green-bg: #eef8ef;
            --yellow-bg: #fff8dd;
            --gray-bg: #f0f2f5;
            --red: #c34c4c;
            --shadow: 0 8px 18px rgba(17, 28, 45, 0.08);
            --radius: 14px;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        }
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 30;
            background: rgba(255,255,255,0.96);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--panel-border);
        }
        .topbar-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            min-width: 0;
            flex-wrap: nowrap;
        }
        .topbar-title {
            display: inline-flex;
            align-items: baseline;
            gap: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 0 0 auto;
            min-width: 0;
            max-width: min(72vw, 720px);
        }
        .topbar-title-app {
            font-size: 1.08rem;
            font-weight: 700;
            color: var(--text);
            flex: 0 0 auto;
        }
        .topbar-title-meta {
            font-size: 0.96rem;
            font-weight: 400;
            color: var(--muted);
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
        }
        .topbar-summary {
            flex: 1 1 auto;
            min-width: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            overflow: hidden;
            white-space: nowrap;
        }
        .summary-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 0.84rem;
            color: var(--muted);
            flex: 0 0 auto;
            white-space: nowrap;
        }
        .summary-chip strong {
            color: var(--text);
            font-weight: 600;
        }
        .summary-toggle-chip {
            gap: 8px;
        }
        .summary-toggle-chip input {
            margin: 0;
            flex: 0 0 auto;
        }
        .hamburger-btn {
            border: 1px solid var(--panel-border);
            background: #fff;
            color: var(--text);
            border-radius: 12px;
            width: 42px;
            height: 42px;
            font-size: 1.15rem;
            cursor: pointer;
            flex: 0 0 auto;
        }
        .menu-shell {
            position: relative;
            margin-left: auto;
            flex: 0 0 auto;
        }
        .menu-panel {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: min(360px, calc(100vw - 24px));
            background: rgba(255,255,255,0.98);
            border: 1px solid var(--panel-border);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .menu-section {
            display: flex;
            flex-direction: column;
            gap: 8px;
            border-top: 1px solid rgba(215,220,227,0.8);
            padding-top: 12px;
        }
        .menu-section:first-child {
            border-top: 0;
            padding-top: 0;
        }
        .menu-heading {
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            font-weight: 700;
        }
        .menu-line {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .menu-place-input-line {
            width: 100%;
            flex-wrap: nowrap;
        }
        .menu-place-action-line {
            width: 100%;
            flex-wrap: nowrap;
        }
        .menu-line input[type="text"],
        .menu-line input[type="password"],
        .place-setup-row input[type="text"] {
            border: 1px solid var(--panel-border);
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 0.92rem;
            min-width: 0;
            flex: 1 1 160px;
        }
        .menu-toggle-line,
        .menu-stat-line,
        .menu-admin-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 0.92rem;
        }
        .menu-toggle-line label,
        .menu-admin-line label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .menu-stat-line strong {
            font-weight: 600;
            color: var(--text);
        }
        .place-setup-row { flex: 0 0 auto; min-width: 0; }
        .menu-place-input-line label { width: 65px; flex: 0 0 65px; }
        .phone-actions { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; min-width: 0; }
        .phone-actions .btn-record { padding: 2px 7px; font-size: 0.75rem; border-radius: 6px; text-decoration: none; flex: 0 0 auto; }
        button.phone-link, button.transmitted-link { border: 0; background: none; padding: 0; font: inherit; text-decoration: underline; cursor: pointer; }
        .table-phone { margin-top: 5px; font-size: 0.85rem; }
        .recordings-block { padding: 9px 11px; min-width: 0; }
        .recordings-toggle {
            display: flex; align-items: center; justify-content: space-between;
            width: 100%; gap: 8px; padding: 2px 0; border: 0;
            background: none; color: var(--text); font: inherit;
            font-size: 0.9rem; font-weight: 600; text-align: left;
            cursor: pointer;
        }
        .recordings-toggle:hover { text-decoration: underline; }
        .recordings-toggle .recordings-count { color: var(--muted); font-size: 0.8rem; font-weight: 400; }
        .recordings-list { display: grid; gap: 9px; padding-top: 9px; }
        .recordings-row { display: grid; gap: 4px; min-width: 0; }
        .recordings-label { font-size: 0.82rem; font-weight: 600; color: var(--text); }
        .recordings-player-slot { min-width: 0; }
        .recordings-player-slot audio { display: block; width: 100%; max-width: 100%; height: 40px; }
        .item-table .recordings-block { margin-top: 8px; max-width: 340px; }
        .place-setup-inner {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
        }
        .place-setup-inner label {
            font-size: 0.92rem;
            white-space: nowrap;
        }
        .place-setup-inner input[type="text"] {
            min-width: 70px;
            width: 140px;
            flex: 0 0 140px;
        }
        .trash-arrow-row {
            position: fixed;
            left: 50%;
            bottom: 0;
            transform: translateX(-50%);
            z-index: 35;
            display: flex;
            justify-content: center;
            padding: 0;
            pointer-events: none;
        }
        .trash-arrow-row .btn {
            pointer-events: auto;
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
        }
        .trash-arrow-slot {
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }
        .trash-arrow-slot:empty {
            display: none;
        }
        .btn {
            border: 1px solid var(--panel-border);
            background: #fff;
            color: var(--text);
            border-radius: 10px;
            padding: 8px 11px;
            font-size: 0.88rem;
            cursor: pointer;
            line-height: 1.2;
        }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .btn-primary { background: #edf4ff; border-color: #c4d8f5; }
        .btn-danger { background: #fff3f3; border-color: #f0c9c9; }
        .btn-admin { background: #f4efff; border-color: #d7c9f0; }
        .btn-icon {
            padding: 7px 9px;
            min-width: 38px;
            text-align: center;
            font-size: 1rem;
        }
        .btn.active {
            background: #edf4ff;
            border-color: #c4d8f5;
            font-weight: 600;
        }
        .app-shell {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 14px 16px 18px 16px;
            flex: 1;
            min-height: 0;
        }
        .message {
            display: none;
            padding: 11px 14px;
            border-radius: 12px;
            border: 1px solid var(--panel-border);
            background: #fff;
            box-shadow: var(--shadow);
        }
        .message.show { display: block; }
        .message.error { border-color: #e2b8b8; background: #fff3f3; }
        .message.success { border-color: #bfd6bf; background: #f1fbf1; }
        .columns {
            display: grid;
            grid-template-columns: minmax(310px, 1.1fr) minmax(450px, 1.9fr) minmax(280px, 1fr);
            gap: 16px;
            min-height: 0;
            flex: 1;
        }
        .columns.hide-completed {
            grid-template-columns: minmax(310px, 1.1fr) minmax(450px, 2fr);
        }
        .column, .trash-panel {
            background: rgba(255,255,255,0.55);
            border: 1px solid var(--panel-border);
            border-radius: 18px;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .column.hidden, .trash-panel.hidden { display: none; }
        .column-header {
            padding: 14px 16px;
            border-bottom: 1px solid var(--panel-border);
            background: rgba(255,255,255,0.75);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .column-head-main {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }
        .column-title { margin: 0; font-size: 1rem; }
        .column-count { color: var(--muted); font-size: 0.92rem; }
        .column-tools {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: flex-end;
        }
        .view-switch {
            display: inline-flex;
            gap: 4px;
            background: rgba(255,255,255,0.8);
            border: 1px solid var(--panel-border);
            border-radius: 999px;
            padding: 4px;
        }
        .view-switch .btn {
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 0.82rem;
        }
        .column-body, .trash-body {
            padding: 14px;
            overflow: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-height: 0;
        }
        .middle-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px;
        }
        .table-wrap {
            width: 100%;
            overflow: auto;
        }
        .item-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 10px;
        }
        .item-table thead th {
            text-align: left;
            font-size: 0.84rem;
            color: var(--muted);
            font-weight: 600;
            padding: 0 10px 6px 10px;
        }
        .item-table tbody td {
            background: var(--panel);
            border-top: 1px solid var(--panel-border);
            border-bottom: 1px solid var(--panel-border);
            padding: 10px;
            vertical-align: top;
            font-size: 0.92rem;
        }
        .item-table tbody td:first-child {
            border-left: 1px solid var(--panel-border);
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
            width: 38px;
        }
        .item-table tbody td:last-child {
            border-right: 1px solid var(--panel-border);
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
            width: 1%;
            white-space: nowrap;
        }
        .item-table tbody tr.row-status-neu td { background: var(--green-bg); }
        .item-table tbody tr.row-status-in_bearbeitung td { background: var(--yellow-bg); }
        .item-table tbody tr.row-status-abgeschlossen td,
        .item-table tbody tr.row-status-trash td { background: var(--gray-bg); }
        .item-table tbody tr.urgent-row td {
            border-top-width: 2px;
            border-bottom-width: 2px;
            border-top-color: var(--red);
            border-bottom-color: var(--red);
        }
        .item-table tbody tr.urgent-row td:first-child { border-left-width: 2px; border-left-color: var(--red); }
        .item-table tbody tr.urgent-row td:last-child { border-right-width: 2px; border-right-color: var(--red); }
        .table-check { display: flex; justify-content: center; align-items: center; }
        .table-patient { min-width: 170px; }
        .table-name {
            font-weight: 700;
            line-height: 1.25;
            text-align: left;
        }
        .table-received {
            margin-top: 4px;
            color: var(--muted);
            font-size: 0.82rem;
        }
        .table-preview {
            white-space: pre-wrap;
            line-height: 1.35;
            min-width: 220px;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .table-preview.table-preview-clamped {
            overflow: hidden;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 3;
            max-height: 4.1em;
        }
        .table-actions {
            display: flex;
            gap: 6px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        .card {
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .card.status-neu { background: var(--green-bg); }
        .card.status-in_bearbeitung { background: var(--yellow-bg); }
        .card.status-abgeschlossen, .card.status-trash { background: var(--gray-bg); }
        .card.urgent { border: 2px solid var(--red); }
        .middle-grid { align-items: start; }
        .middle-grid .card { min-height: 320px; height: auto; overflow: visible; }
        .left-column .column-body > .card,
        .left-column .column-body > .empty {
            flex: 0 0 auto;
        }
        .left-column .card { min-height: 0; height: auto; overflow: visible; flex: 0 0 auto; }
        .left-column .card-body,
        .left-column .detail-block,
        .left-column .transmitted-row,
        .left-column .actions,
        .left-column .comments-list,
        .left-column .comment-item,
        .left-column .comment-editor {
            flex: 0 0 auto;
            min-height: 0;
        }
        .left-column .transmitted-row,
        .left-column .actions {
            margin-top: 0;
        }
        .right-column .card { min-height: 230px; }
        .selection-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--muted);
        }
        .selection-row input { margin: 0; }
        .card-header-box {
            width: 100%;
            border: 1px solid var(--panel-border);
            border-radius: 12px;
            background: rgba(255,255,255,0.72);
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 0 0 auto;
        }
        .header-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            min-width: 0;
        }
        .header-left {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            flex: 1;
        }
        .name-line, .name-line.name-line-left { justify-content: flex-start; }
        .name-button {
            appearance: none;
            border: 0;
            background: transparent;
            padding: 0;
            margin: 0;
            font: inherit;
            color: inherit;
            cursor: pointer;
            max-width: 100%;
            display: inline-flex;
            align-items: baseline;
            gap: 6px;
            min-width: 0;
            text-align: left;
        }
        .name-main {
            font-weight: 700;
            font-size: 1rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
            text-align: left;
        }
        .name-birth {
            font-weight: 400;
            font-size: 0.98rem;
            color: inherit;
            white-space: nowrap;
            flex: 0 0 auto;
            cursor: pointer;
        }
        .category-tag {
            display: inline-block;
            border: 1px solid var(--panel-border);
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 0.8rem;
            font-weight: 400;
            background: rgba(255,255,255,0.86);
            white-space: nowrap;
        }
        .phone-link, .header-date, .workplace-chip, .transmitted-link {
            color: var(--text);
            text-decoration: none;
        }
        .phone-link, .workplace-chip, .header-date {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .header-date {
            color: var(--muted);
            flex: 0 0 auto;
            text-align: right;
        }
        .workplace-chip { color: var(--muted); min-width: 0; }
        .urgent-mark {
            color: var(--red);
            font-weight: 700;
            flex: 0 0 auto;
        }
        .card-body {
            color: var(--text);
            font-size: 0.95rem;
            line-height: 1.38;
            text-align: left;
        }
        .body-preview {
            overflow: hidden;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 4;
            white-space: pre-wrap;
            text-overflow: ellipsis;
            overflow-wrap: anywhere;
            word-break: break-word;
            min-height: 5.55em;
            max-height: 5.55em;
        }
        .body-full {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .detail-block {
            border: 1px solid var(--panel-border);
            border-radius: 12px;
            background: rgba(255,255,255,0.58);
            padding: 10px 12px;
        }
        .detail-block h4 { margin: 0 0 8px 0; font-size: 0.92rem; }
        .detail-block p {
            margin: 8px 0 0 0;
            white-space: pre-wrap;
            line-height: 1.4;
            text-align: left;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .detail-block details { display: block; }
        .detail-block summary {
            list-style: none;
            cursor: pointer;
            font-size: 0.92rem;
            font-weight: 700;
            outline: none;
        }
        .detail-block summary::-webkit-details-marker { display: none; }
        .comments-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 8px;
        }
        .comment-item {
            border: 1px solid var(--panel-border);
            border-radius: 10px;
            background: rgba(255,255,255,0.7);
            padding: 8px 10px;
        }
        .comment-meta {
            font-size: 0.84rem;
            color: var(--muted);
            margin-bottom: 4px;
        }
        .comment-item.comment-item-sms {
            background: #dbeafe;
            border: 1px solid #60a5fa;
            border-left: 5px solid #1d4ed8;
        }
        .comment-item-sms .comment-meta { color: #1e3a8a; }
        .comment-sms-label { font-weight: 800; }
        .comment-text {
            white-space: pre-wrap;
            line-height: 1.35;
            color: var(--text);
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .comment-editor {
            border: 1px solid var(--panel-border);
            border-radius: 12px;
            background: rgba(255,255,255,0.58);
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .comment-editor textarea {
            width: 100%;
            min-height: 4.8em;
            border: 1px solid var(--panel-border);
            border-radius: 8px;
            padding: 8px 10px;
            font: inherit;
            color: var(--text);
            background: #fff;
            resize: vertical;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .comment-editor-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .transmitted-row {
            margin-top: auto;
            padding-top: 2px;
            font-size: 0.9rem;
            color: var(--muted);
            text-align: left;
        }
        .transmitted-row strong { color: var(--text); }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: auto;
            align-items: center;
        }
        .empty {
            border: 1px dashed var(--panel-border);
            border-radius: 14px;
            background: rgba(255,255,255,0.72);
            color: var(--muted);
            padding: 18px;
            text-align: center;
        }
        [hidden] { display: none !important; }
        @media (max-width: 1200px) {
            .columns, .columns.hide-completed { grid-template-columns: 1fr; }
            .middle-grid { grid-template-columns: 1fr; }
            .middle-grid .card { max-height: none; min-height: 260px; }
            .column-tools { justify-content: flex-start; }
            .topbar-row { gap: 8px; padding: 10px 10px; }
            .topbar-title-app { font-size: 0.98rem; }
            .topbar-title-meta { font-size: 0.84rem; }
            .summary-chip { padding: 5px 8px; font-size: 0.78rem; }
            .menu-panel { width: min(340px, calc(100vw - 16px)); }
        }
        @media (max-width: 1500px) {
            .topbar-row { display: grid; grid-template-columns: minmax(0, auto) minmax(0, 1fr) auto auto; }
            .topbar-title { grid-column: 1; grid-row: 1; }
            .topbar-summary { grid-column: 2; grid-row: 1; }
            .topbar-row > .summary-toggle-chip { grid-column: 3; grid-row: 1; }
            .menu-shell { grid-column: 4; grid-row: 1; }
            .place-setup-row { grid-column: 1 / -1; grid-row: 2; }
            .place-setup-inner { justify-content: flex-start; }
            .place-setup-inner input[type="text"] { flex: 0 1 180px; }
        }
        @media (max-width: 580px) {
            .topbar-row { grid-template-columns: minmax(0, 1fr) auto; }
            .menu-shell { grid-column: 2; }
            .topbar-summary, .topbar-row > .summary-toggle-chip { display: none; }
            .topbar-title { max-width: 100%; }
            .topbar-title-meta { font-size: 0.75rem; }
            .place-setup-inner { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; }
            .place-setup-inner input[type="text"] { width: 100%; min-width: 0; }
            .place-setup-inner label { grid-column: 1; }
            .place-setup-inner button { grid-column: 3; grid-row: 1 / 3; }
            .place-setup-inner input { grid-column: 2; }
        }
    </style>
</head>
<body>
<div class="topbar">
    <div class="topbar-row">
        <div class="topbar-title"><span class="topbar-title-app"><?= tp_h(TELEPRAXIS_APP_NAME) ?></span><span class="topbar-title-meta">v<?= tp_h(TELEPRAXIS_APP_VERSION) ?> von Dr. Thomas Kienzle</span></div>
        <div class="place-setup-row" id="place-setup-row">
            <div class="place-setup-inner">
                <label for="user-input-setup">Benutzer</label>
                <input type="text" id="user-input-setup" value="<?= tp_h($initialUser) ?>" maxlength="<?= TELEPRAXIS_USER_MAXLEN ?>" placeholder="z.B. Julia" autocomplete="off">
                <label for="workplace-input-setup">Platz</label>
                <input type="text" id="workplace-input-setup" value="<?= tp_h($initialWorkplace) ?>" maxlength="<?= TELEPRAXIS_WORKPLACE_MAXLEN ?>" placeholder="z.B. empfang-1" autocomplete="off">
                <button class="btn btn-primary" id="save-workplace-btn-setup" type="button">Speichern</button>
            </div>
        </div>
        <div class="topbar-summary" id="header-summary"></div>
        <label class="summary-chip summary-toggle-chip" id="completed-toggle-chip"><span>Abgeschlossen anzeigen</span><input type="checkbox" id="completed-toggle-top" checked></label>
        <div class="menu-shell">
            <button class="hamburger-btn" id="menu-toggle-btn" type="button" aria-expanded="false" aria-controls="header-menu" aria-label="Menü öffnen">☰</button>
            <div class="menu-panel" id="header-menu" hidden>
                <div class="menu-section">
                    <div class="menu-heading">Benutzer und Platz</div>
                    <div class="menu-line menu-place-input-line">
                        <label for="user-input-menu">Benutzer</label>
                        <input type="text" id="user-input-menu" value="<?= tp_h($initialUser) ?>" maxlength="<?= TELEPRAXIS_USER_MAXLEN ?>" placeholder="z.B. Julia" autocomplete="off">
                    </div>
                    <div class="menu-line menu-place-input-line">
                        <label for="workplace-input-menu">Platz</label>
                        <input type="text" id="workplace-input-menu" value="<?= tp_h($initialWorkplace) ?>" maxlength="<?= TELEPRAXIS_WORKPLACE_MAXLEN ?>" placeholder="z.B. empfang-1" autocomplete="off">
                    </div>
                    <div class="menu-line menu-place-action-line">
                        <button class="btn btn-primary" id="save-workplace-btn-menu" type="button">Speichern</button>
                        <button class="btn" id="bookmark-link-btn" type="button">Bookmark-Link</button>
                        <button class="btn" id="bookmark-open-btn" type="button" hidden>Öffnen</button>
                    </div>
                </div>

                <div class="menu-section">
                    <div class="menu-heading">Admin</div>
                    <?php if ($adminEnabled): ?>
                        <?php if ($isAdmin): ?>
                            <div class="menu-admin-line">
                                <span>Admin aktiv</span>
                                <button class="btn btn-admin" id="admin-logout-btn" type="button">Abmelden</button>
                            </div>
                        <?php else: ?>
                            <div class="menu-line">
                                <input type="password" id="admin-password" placeholder="Passwort">
                                <button class="btn btn-admin" id="admin-login-btn" type="button">Anmelden</button>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="menu-admin-line"><span>Admin deaktiviert</span></div>
                    <?php endif; ?>
                </div>

                <div class="menu-section">
                    <label class="menu-toggle-line"><span>Benachrichtigungs-Ton</span><input type="checkbox" id="sound-toggle" checked></label>
                    <label class="menu-toggle-line"><span>Abgeschlossen anzeigen</span><input type="checkbox" id="completed-toggle" checked></label>
                    <?php if ($isAdmin): ?>
                        <label class="menu-toggle-line"><span>Papierkorb anzeigen</span><input type="checkbox" id="trash-toggle"></label>
                    <?php endif; ?>
                </div>

                <div class="menu-section" id="menu-stats"></div>
            </div>
        </div>
    </div>
</div>

<div class="app-shell">
    <div class="trash-arrow-row" id="trash-arrow-row" hidden>
        <button class="btn" id="trash-arrow-btn" type="button">Papierkorb (↑)</button>
    </div>
    <div class="message" id="message"></div>

    <div class="columns" id="columns">
        <section class="column left-column">
            <div class="column-header">
                <div class="column-head-main">
                    <h2 class="column-title">In Bearbeitung</h2>
                    <div class="column-count" id="count-left">0</div>
                </div>
            </div>
            <div class="column-body" id="left-column"></div>
        </section>

        <section class="column middle-column">
            <div class="column-header">
                <div class="column-head-main">
                    <h2 class="column-title">Neu</h2>
                    <div class="column-count" id="count-middle">0</div>
                </div>
                <div class="column-tools">
                    <button class="btn btn-primary" id="middle-edit-btn" type="button">Bearbeiten</button>
                    <button class="btn btn-danger" id="middle-delete-btn" type="button">Löschen</button>
                    <div class="view-switch" data-view-target="middle">
                        <button class="btn" type="button" data-view-target="middle" data-view-mode="cards">Karten</button>
                        <button class="btn" type="button" data-view-target="middle" data-view-mode="table">Tabelle</button>
                    </div>
                </div>
            </div>
            <div class="column-body" id="middle-body"></div>
        </section>

        <section class="column right-column" id="completed-column">
            <div class="column-header">
                <div class="column-head-main">
                    <h2 class="column-title">Abgeschlossen</h2>
                    <div class="column-count" id="count-right">0</div>
                </div>
                <div class="column-tools">
                    <button class="btn" id="right-select-all-btn" type="button">Alle auswählen</button>
                    <button class="btn btn-primary" id="right-restore-btn" type="button">Wiederherstellen</button>
                    <button class="btn btn-danger" id="right-delete-btn" type="button">Löschen</button>
                    <div class="view-switch" data-view-target="right">
                        <button class="btn" type="button" data-view-target="right" data-view-mode="cards">Karten</button>
                        <button class="btn" type="button" data-view-target="right" data-view-mode="table">Tabelle</button>
                    </div>
                </div>
            </div>
            <div class="column-body" id="right-body"></div>
        </section>
    </div>

    <section class="trash-panel hidden" id="trash-panel">
        <div class="column-header">
            <div class="column-head-main">
                <h2 class="column-title">Papierkorb</h2>
                <div class="column-count" id="count-trash">0</div>
            </div>
            <div class="column-tools">
                <div class="trash-arrow-slot" id="trash-arrow-slot"></div>
                <button class="btn" id="trash-select-all-btn" type="button">Alle auswählen</button>
                <button class="btn btn-primary" id="trash-restore-btn" type="button">Wiederherstellen</button>
                <button class="btn btn-danger" id="trash-delete-btn" type="button">Löschen</button>
                <div class="view-switch" data-view-target="trash">
                    <button class="btn" type="button" data-view-target="trash" data-view-mode="cards">Karten</button>
                    <button class="btn" type="button" data-view-target="trash" data-view-mode="table">Tabelle</button>
                </div>
            </div>
        </div>
        <div class="trash-body" id="trash-body"></div>
    </section>
</div>

<script>
(() => {
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const appName = <?= json_encode(TELEPRAXIS_APP_NAME, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const appVersion = <?= json_encode(TELEPRAXIS_APP_VERSION, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const t2medEnabled = <?= TELEPRAXIS_T2MED_ENABLED ? 'true' : 'false' ?>;
    const t2medBaseUrl = <?= json_encode(TELEPRAXIS_T2MED_BASE_URL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const identityFromUrl = <?= $identityFromUrl ? 'true' : 'false' ?>;
    const userMaxLength = <?= (int)TELEPRAXIS_USER_MAXLEN ?>;
    const workplaceMaxLength = <?= (int)TELEPRAXIS_WORKPLACE_MAXLEN ?>;
    let activeIdentity = {user: '', workplace: ''};
    let currentCsrf = csrfToken;
    let isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    let audioContext = null;
    let lastSeenIds = new Set();
    const seenIncomingSmsKeys = new Set();
    const openRecordingFiles = new Set();
    const recordingPlayers = new Map();
    let initialized = false;
    let lastEntries = [];
    let lastBookmarkUrl = '';
    const openSummaryFiles = new Set();
    const openCommentFiles = new Set();
    const openSmsFiles = new Set();
    const commentDrafts = new Map();
    const smsDrafts = new Map();
    const smsSendingFiles = new Set();
    const smsRequestIds = new Map();
    const selected = {
        middle: new Set(),
        right: new Set(),
        trash: new Set(),
    };
    const viewModes = {
        middle: 'cards',
        right: 'cards',
        trash: 'cards',
    };

    const els = {
        userInputMenu: document.getElementById('user-input-menu'),
        userInputSetup: document.getElementById('user-input-setup'),
        workplaceInputMenu: document.getElementById('workplace-input-menu'),
        workplaceInputSetup: document.getElementById('workplace-input-setup'),
        saveWorkplaceBtnMenu: document.getElementById('save-workplace-btn-menu'),
        saveWorkplaceBtnSetup: document.getElementById('save-workplace-btn-setup'),
        bookmarkLinkBtn: document.getElementById('bookmark-link-btn'),
        bookmarkOpenBtn: document.getElementById('bookmark-open-btn'),
        trashArrowRow: document.getElementById('trash-arrow-row'),
        trashArrowBtn: document.getElementById('trash-arrow-btn'),
        trashArrowSlot: document.getElementById('trash-arrow-slot'),
        soundToggle: document.getElementById('sound-toggle'),
        completedToggle: document.getElementById('completed-toggle'),
        trashToggle: document.getElementById('trash-toggle'),
        adminLoginBtn: document.getElementById('admin-login-btn'),
        adminLogoutBtn: document.getElementById('admin-logout-btn'),
        adminPassword: document.getElementById('admin-password'),
        menuToggleBtn: document.getElementById('menu-toggle-btn'),
        topbarRow: document.querySelector('.topbar-row'),
        completedToggleTop: document.getElementById('completed-toggle-top'),
        completedToggleChip: document.getElementById('completed-toggle-chip'),
        headerMenu: document.getElementById('header-menu'),
        headerSummary: document.getElementById('header-summary'),
        menuStats: document.getElementById('menu-stats'),
        placeSetupRow: document.getElementById('place-setup-row'),
        columns: document.getElementById('columns'),
        completedColumn: document.getElementById('completed-column'),
        leftColumn: document.getElementById('left-column'),
        middleBody: document.getElementById('middle-body'),
        rightBody: document.getElementById('right-body'),
        trashPanel: document.getElementById('trash-panel'),
        trashBody: document.getElementById('trash-body'),
        countLeft: document.getElementById('count-left'),
        countMiddle: document.getElementById('count-middle'),
        countRight: document.getElementById('count-right'),
        countTrash: document.getElementById('count-trash'),
        message: document.getElementById('message'),
        middleSelectAllBtn: document.getElementById('middle-select-all-btn'),
        middleEditBtn: document.getElementById('middle-edit-btn'),
        middleDeleteBtn: document.getElementById('middle-delete-btn'),
        rightSelectAllBtn: document.getElementById('right-select-all-btn'),
        rightRestoreBtn: document.getElementById('right-restore-btn'),
        rightDeleteBtn: document.getElementById('right-delete-btn'),
        trashSelectAllBtn: document.getElementById('trash-select-all-btn'),
        trashRestoreBtn: document.getElementById('trash-restore-btn'),
        trashDeleteBtn: document.getElementById('trash-delete-btn'),
    };

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function sanitizeWorkplaceValue(value) {
        return String(value || '').trim().replace(/[^a-zA-Z0-9_-]+/g, '').slice(0, workplaceMaxLength);
    }

    function sanitizeUserValue(value) {
        return Array.from(String(value || '').replace(/[\x00-\x1F\x7F]/g, '').trim()).slice(0, userMaxLength).join('');
    }

    function setUserInputs(value, source = null) {
        [els.userInputMenu, els.userInputSetup].forEach(input => {
            if (input && input !== source) input.value = value;
        });
    }

    function draftIdentity() {
        return {
            user: sanitizeUserValue(els.userInputMenu?.value ?? ''),
            workplace: sanitizeWorkplaceValue(els.workplaceInputMenu?.value ?? ''),
        };
    }

    function currentUser() { return activeIdentity.user; }

    function setWorkplaceInputs(value, source = null) {
        if (els.workplaceInputMenu && source !== els.workplaceInputMenu) {
            els.workplaceInputMenu.value = value;
        }
        if (els.workplaceInputSetup && source !== els.workplaceInputSetup) {
            els.workplaceInputSetup.value = value;
        }
    }

    function currentWorkplace() {
        return activeIdentity.workplace;
    }

    function setMenuOpen(isOpen) {
        if (!els.headerMenu || !els.menuToggleBtn) return;
        els.headerMenu.hidden = !isOpen;
        els.menuToggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    function updateHeaderSummaryVisibility() {
        const container = els.headerSummary;
        const row = els.topbarRow;
        if (!container || !row) return;
        const items = Array.from(container.querySelectorAll('.summary-chip'));
        items.forEach(item => { item.hidden = false; });
        if (els.completedToggleChip) {
            els.completedToggleChip.hidden = false;
        }

        let guard = 0;
        while ((row.scrollWidth > row.clientWidth || container.scrollWidth > container.clientWidth) && guard < 20) {
            guard += 1;
            const lastVisible = [...items].reverse().find(item => !item.hidden);
            if (lastVisible) {
                lastVisible.hidden = true;
                continue;
            }
            if (els.completedToggleChip && !els.completedToggleChip.hidden) {
                els.completedToggleChip.hidden = true;
                continue;
            }
            break;
        }
    }

    function setDocumentTitle(entries) {
        const neu = (entries || []).filter(entry => entry && !entry.deleted && entry.status === 'neu').length;
        document.title = `Neu: ${neu} – ${appName} v${appVersion}`;
    }

    function showMessage(text, isError = false) {
        els.message.textContent = text;
        els.message.className = `message show ${isError ? 'error' : 'success'}`;
        window.clearTimeout(showMessage._timer);
        showMessage._timer = window.setTimeout(() => {
            els.message.className = 'message';
            els.message.textContent = '';
        }, 3500);
    }

    function loadSummaryState() {
        try {
            const stored = JSON.parse(localStorage.getItem('telepraxis_open_summaries') || '[]');
            if (Array.isArray(stored)) {
                stored.forEach(file => {
                    if (typeof file === 'string' && file) openSummaryFiles.add(file);
                });
            }
        } catch (error) {
            // ignorieren
        }
    }

    function saveSummaryState() {
        try {
            localStorage.setItem('telepraxis_open_summaries', JSON.stringify(Array.from(openSummaryFiles)));
        } catch (error) {
            // ignorieren
        }
    }

    function loadLocalSettings() {
        // Ein Teil-Bookmark ist eine bewusste Vorgabe: fehlende Felder bleiben leer.
        // Der alte Schlüssel telepraxis_workplace enthielt oft einen Personennamen;
        // ihn niemals stillschweigend als neue Middleware-client_id übernehmen.
        if (!identityFromUrl) {
            setUserInputs(sanitizeUserValue(localStorage.getItem('telepraxis_user') ?? localStorage.getItem('telepraxis_workplace') ?? ''));
            setWorkplaceInputs(sanitizeWorkplaceValue(localStorage.getItem('telepraxis_client_id') || ''));
        }
        activeIdentity = draftIdentity();
        const soundEnabled = localStorage.getItem('telepraxis_sound_enabled');
        els.soundToggle.checked = soundEnabled === null ? true : soundEnabled === '1';
        const showCompleted = localStorage.getItem('telepraxis_show_completed');
        const showCompletedValue = showCompleted === null ? true : showCompleted === '1';
        els.completedToggle.checked = showCompletedValue;
        if (els.completedToggleTop) {
            els.completedToggleTop.checked = showCompletedValue;
        }
        if (els.trashToggle) {
            els.trashToggle.checked = localStorage.getItem('telepraxis_show_trash') === '1';
        }
        ['middle', 'right', 'trash'].forEach(area => {
            const mode = localStorage.getItem(`telepraxis_view_${area}`);
            if (mode === 'cards' || mode === 'table') {
                viewModes[area] = mode;
            }
        });
        loadSummaryState();
        applyVisibilitySettings();
        updateViewButtons();
    }

    function saveWorkplace() {
        activeIdentity = draftIdentity();
        setUserInputs(currentUser());
        setWorkplaceInputs(currentWorkplace());
        localStorage.setItem('telepraxis_user', currentUser());
        localStorage.setItem('telepraxis_client_id', currentWorkplace());
        hideBookmarkOpen();
        showMessage(`Gespeichert · Benutzer: ${currentUser() || '—'} · Platz: ${currentWorkplace() || '—'}`);
        render(lastEntries);
    }

    function hideBookmarkOpen() {
        lastBookmarkUrl = '';
        if (els.bookmarkOpenBtn) {
            els.bookmarkOpenBtn.hidden = true;
        }
    }

    function applyVisibilitySettings() {
        const showCompleted = !!(els.completedToggle?.checked);
        if (els.completedToggleTop && els.completedToggleTop.checked !== showCompleted) {
            els.completedToggleTop.checked = showCompleted;
        }
        if (els.completedToggle && els.completedToggle.checked !== showCompleted) {
            els.completedToggle.checked = showCompleted;
        }
        localStorage.setItem('telepraxis_show_completed', showCompleted ? '1' : '0');
        if (showCompleted) {
            els.completedColumn.classList.remove('hidden');
            els.columns.classList.remove('hide-completed');
        } else {
            els.completedColumn.classList.add('hidden');
            els.columns.classList.add('hide-completed');
        }
        const showTrash = !!(els.trashToggle && isAdmin && els.trashToggle.checked);
        if (els.trashToggle) {
            localStorage.setItem('telepraxis_show_trash', els.trashToggle.checked ? '1' : '0');
        }
        els.trashPanel.classList.toggle('hidden', !showTrash);
        if (els.trashArrowRow) {
            els.trashArrowRow.hidden = !isAdmin && !showTrash;
        }
        if (els.trashArrowBtn) {
            els.trashArrowBtn.textContent = `Papierkorb (${showTrash ? '↓' : '↑'})`;
            if (showTrash && els.trashArrowSlot && els.trashArrowBtn.parentElement !== els.trashArrowSlot) {
                els.trashArrowSlot.appendChild(els.trashArrowBtn);
            } else if (!showTrash && els.trashArrowRow && els.trashArrowBtn.parentElement !== els.trashArrowRow) {
                els.trashArrowRow.appendChild(els.trashArrowBtn);
            }
        }
    }

    function updateViewButtons() {
        document.querySelectorAll('[data-view-target][data-view-mode]').forEach(button => {
            const target = button.getAttribute('data-view-target');
            const mode = button.getAttribute('data-view-mode');
            button.classList.toggle('active', viewModes[target] === mode);
        });
    }

    function ensureAudioContext() {
        if (!audioContext) {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (Ctx) {
                audioContext = new Ctx();
            }
        }
        if (audioContext && audioContext.state === 'suspended') {
            audioContext.resume().catch(() => {});
        }
    }

    function playNotificationTone() {
        if (!els.soundToggle.checked) return;
        ensureAudioContext();
        if (!audioContext) return;

        const base = audioContext.currentTime;
        const durations = [0.08, 0.08, 0.08, 0.24];
        let offset = 0;
        durations.forEach(duration => {
            const start = base + offset;
            const gain = audioContext.createGain();
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(0.2, start + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
            gain.connect(audioContext.destination);

            const osc = audioContext.createOscillator();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(1046, start);
            osc.connect(gain);
            osc.start(start);
            osc.stop(start + duration);
            offset += duration + 0.05;
        });
    }

    function sortMiddle(a, b) {
        if (!!a.urgent !== !!b.urgent) return a.urgent ? -1 : 1;
        if (Number(a.category_order) !== Number(b.category_order)) return Number(a.category_order) - Number(b.category_order);
        return String(b.received_at || '').localeCompare(String(a.received_at || ''));
    }

    function sortLeft(a, b) {
        if (!!a.urgent !== !!b.urgent) return a.urgent ? -1 : 1;
        if (Number(a.category_order) !== Number(b.category_order)) return Number(a.category_order) - Number(b.category_order);
        return String(b.last_updated_at || '').localeCompare(String(a.last_updated_at || ''));
    }

    function sortRight(a, b) {
        if (!!a.urgent !== !!b.urgent) return a.urgent ? -1 : 1;
        return String(b.last_updated_at || '').localeCompare(String(a.last_updated_at || ''));
    }

    function getEntryByFile(file) {
        return lastEntries.find(entry => String(entry.file || '') === String(file || '')) || null;
    }

    function areaEntries(area) {
        const user = currentUser();
        if (area === 'middle') {
            return lastEntries.filter(entry => !entry.deleted && (entry.status === 'neu' || (entry.status === 'in_bearbeitung' && (user === '' || entry.last_user !== user)))).sort(sortMiddle);
        }
        if (area === 'right') {
            return lastEntries.filter(entry => !entry.deleted && entry.status === 'abgeschlossen').sort(sortRight);
        }
        if (area === 'trash') {
            return lastEntries.filter(entry => entry.deleted).sort(sortRight);
        }
        return [];
    }

    function captureTransientState() {
        const active = document.activeElement;
        const state = {
            commentFocus: null,
            smsFocus: null,
            scrolls: {
                left: els.leftColumn ? els.leftColumn.scrollTop : 0,
                middle: els.middleBody ? els.middleBody.scrollTop : 0,
                right: els.rightBody ? els.rightBody.scrollTop : 0,
                trash: els.trashBody ? els.trashBody.scrollTop : 0,
            }
        };
        if (active && active.matches && active.matches('[data-comment-input]')) {
            state.commentFocus = {
                file: String(active.getAttribute('data-comment-input') || ''),
                start: active.selectionStart ?? null,
                end: active.selectionEnd ?? null,
            };
        }
        if (active && active.matches && active.matches('[data-sms-input]')) {
            state.smsFocus = {
                file: String(active.getAttribute('data-sms-input') || ''),
                start: active.selectionStart ?? null,
                end: active.selectionEnd ?? null,
            };
        }
        return state;
    }

    function restoreTransientState(state) {
        if (!state) return;
        if (els.leftColumn) els.leftColumn.scrollTop = state.scrolls.left || 0;
        if (els.middleBody) els.middleBody.scrollTop = state.scrolls.middle || 0;
        if (els.rightBody) els.rightBody.scrollTop = state.scrolls.right || 0;
        if (els.trashBody) els.trashBody.scrollTop = state.scrolls.trash || 0;
        if (state.commentFocus && state.commentFocus.file) {
            const input = document.querySelector(`[data-comment-input="${CSS.escape(state.commentFocus.file)}"]`);
            if (input) {
                input.focus({preventScroll: true});
                if (typeof state.commentFocus.start === 'number' && typeof state.commentFocus.end === 'number') {
                    input.setSelectionRange(state.commentFocus.start, state.commentFocus.end);
                }
            }
        }
        if (state.smsFocus && state.smsFocus.file) {
            const input = document.querySelector(`[data-sms-input="${CSS.escape(state.smsFocus.file)}"]`);
            if (input) {
                input.focus({preventScroll: true});
                if (typeof state.smsFocus.start === 'number' && typeof state.smsFocus.end === 'number') {
                    input.setSelectionRange(state.smsFocus.start, state.smsFocus.end);
                }
            }
        }
    }

    function selectionChecked(area, file) {
        return selected[area] && selected[area].has(String(file || ''));
    }

    function setSelection(area, file, checked) {
        if (!selected[area]) return;
        const key = String(file || '');
        if (!key) return;
        if (checked) {
            selected[area].add(key);
        } else {
            selected[area].delete(key);
        }
    }

    function syncSelection(area, entries) {
        const valid = new Set((entries || []).map(entry => String(entry.file || '')));
        Array.from(selected[area] || []).forEach(file => {
            if (!valid.has(file)) {
                selected[area].delete(file);
            }
        });
    }

    function createSelectionControl(area, entry, tableMode = false) {
        const checked = selectionChecked(area, entry.file) ? ' checked' : '';
        if (tableMode) {
            return `<div class="table-check"><input type="checkbox" data-select-area="${escapeHtml(area)}" data-select-file="${escapeHtml(entry.file)}"${checked}></div>`;
        }
        return `<label class="selection-row"><input type="checkbox" data-select-area="${escapeHtml(area)}" data-select-file="${escapeHtml(entry.file)}"${checked}> <span>Auswählen</span></label>`;
    }

    function t2medUrl(action, rawPhone) {
        if (!t2medEnabled || !currentWorkplace()) return '';
        const phone = String(rawPhone || '').trim().replace(/[\s()./\-]/g, '');
        if (!/^\+?[0-9]{3,}$/.test(phone)) return '';
        try {
            const url = new URL(t2medBaseUrl);
            if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash) return '';
            url.pathname = `${url.pathname.replace(/\/+$/, '')}/${action}`;
            url.searchParams.set('client_id', currentWorkplace());
            url.searchParams.set('telefon', phone);
            if (action === 'waehlen') url.searchParams.set('sofort', '1');
            return url.toString();
        } catch (error) {
            return '';
        }
    }

    function createPhoneActions(display, telHref, rawPhone, cssClass = 'phone-link') {
        if (!telHref) return `<span class="${cssClass}">${escapeHtml(display || '—')}</span>`;
        if (!t2medEnabled) return `<a class="${cssClass}" href="tel:${escapeHtml(telHref)}">${escapeHtml(display || '')}</a>`;
        const callUrl = t2medUrl('waehlen', rawPhone);
        const recordUrl = t2medUrl('patient-oeffnen', rawPhone);
        const reason = !currentWorkplace() ? 'Bitte zuerst einen Platz eintragen und speichern.' : 'Middleware-Adresse oder Telefonnummer ist ungültig. Bitte Einstellungen prüfen.';
        const phone = callUrl
            ? `<a class="${cssClass}" href="${escapeHtml(callUrl)}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" title="Rückruf starten">${escapeHtml(display || '')}</a>`
            : `<button type="button" class="${cssClass}" data-t2med-unavailable="${escapeHtml(reason)}">${escapeHtml(display || '')}</button>`;
        const record = recordUrl
            ? `<a class="btn btn-record" href="${escapeHtml(recordUrl)}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" aria-label="Patientenakte öffnen" title="Patientenakte öffnen">Akte</a>`
            : `<button type="button" class="btn btn-record" data-t2med-unavailable="${escapeHtml(reason)}" title="${escapeHtml(reason)}">Akte</button>`;
        return `<span class="phone-actions">${phone}${record}</span>`;
    }

    function createHeader(entry) {
        const nameLine = entry.person_display
            ? `
                <div class="header-line name-line name-line-left">
                    <button type="button" class="name-button" data-copy-name="${escapeHtml(entry.person_copy || '')}" title="${escapeHtml(entry.person_display)}">
                        <span class="name-main">${escapeHtml(entry.person_name || '')}</span>
                    </button>
                    ${entry.person_birth_date ? `<span class="name-birth" data-copy-birth="${escapeHtml(entry.person_birth_date)}" title="${escapeHtml(entry.person_birth_date)}">${escapeHtml(entry.person_birth_date)}</span>` : ''}
                </div>`
            : '';

        const phoneNode = createPhoneActions(entry.telephone_display, entry.telephone_href, entry.telephone_raw);

        const urgentNode = entry.urgent ? '<span class="urgent-mark">!</span>' : '';
        const workplaceNode = (entry.status === 'in_bearbeitung' && entry.last_user)
            ? `<span class="workplace-chip" title="${escapeHtml(entry.last_user)}">bei ${escapeHtml(entry.last_user)}</span>`
            : '';

        return `
            <div class="card-header-box">
                ${nameLine}
                <div class="header-line">
                    <span class="category-tag">${escapeHtml(entry.category_label)}</span>
                    ${phoneNode}
                </div>
                <div class="header-line">
                    <div class="header-left">
                        ${urgentNode}
                        ${workplaceNode}
                    </div>
                    <div class="header-date">${escapeHtml(entry.received_at_display)}</div>
                </div>
            </div>`;
    }

    function createCommentItems(entry) {
        const comments = Array.isArray(entry.comments) ? entry.comments.filter(comment => comment && comment.text) : [];
        if (!comments.length) return '';
        return `
            <div class="detail-block">
                <h4>Kommentare</h4>
                <div class="comments-list">
                    ${comments.map(comment => `
                        <div class="comment-item${comment.kind === 'sms_received' ? ' comment-item-sms' : ''}">
                            <div class="comment-meta">${comment.kind === 'sms_received' ? `<span class="comment-sms-label">SMS eingegangen</span> · ${escapeHtml(comment.created_at_display || '—')} · von ${escapeHtml(comment.sms_sender || '—')}` : `${escapeHtml(comment.created_at_display || '—')} · Benutzer: ${escapeHtml(comment.benutzer || '—')}`}</div>
                            <div class="comment-text">${escapeHtml(comment.text || '')}</div>
                        </div>`).join('')}
                </div>
            </div>`;
    }

    function createCommentEditor(entry) {
        const file = String(entry.file || '');
        if (!openCommentFiles.has(file)) return '';
        const draft = commentDrafts.get(file) || '';
        return `
            <div class="comment-editor">
                <textarea rows="3" data-comment-input="${escapeHtml(file)}" placeholder="Kommentar eingeben">${escapeHtml(draft)}</textarea>
                <div class="comment-editor-actions">
                    <button class="btn btn-primary" type="button" data-save-comment="${escapeHtml(file)}">Speichern</button>
                    <button class="btn" type="button" data-toggle-comment="${escapeHtml(file)}">Schließen</button>
                </div>
            </div>`;
    }

    function createReceivedSmsHistory(entry) {
        return Array.isArray(entry.comments) && entry.comments.some(comment => comment.kind === 'sms_received')
            ? createCommentItems(entry) : '';
    }

    function createSmsEditor(entry) {
        const file = String(entry.file || '');
        if (!openSmsFiles.has(file) || !entry.sms_phone_href) return '';
        const draft = smsDrafts.get(file) || '';
        const sending = smsSendingFiles.has(file);
        return `
            <div class="comment-editor sms-editor">
                <div class="comment-meta">SMS an Rückrufnummer ${escapeHtml(entry.sms_phone_display || entry.sms_phone_href || '—')}</div>
                <textarea rows="3" data-sms-input="${escapeHtml(file)}" placeholder="SMS-Text eingeben" ${sending ? 'disabled' : ''}>${escapeHtml(draft)}</textarea>
                <div class="comment-editor-actions">
                    <button class="btn btn-primary" type="button" data-send-sms="${escapeHtml(file)}" ${sending ? 'disabled' : ''}>${sending ? 'Sende SMS...' : 'SMS senden'}</button>
                    <button class="btn" type="button" data-toggle-sms="${escapeHtml(file)}" ${sending ? 'disabled' : ''}>Schließen</button>
                </div>
            </div>`;
    }

    function createRecordings(entry) {
        const clips = Array.isArray(entry.recordings) ? entry.recordings : [];
        if (!clips.length) return '';
        const file = String(entry.file || '');
        const expanded = openRecordingFiles.has(file);
        const clipsHtml = expanded
            ? `<div class="recordings-list">${clips.map(clip => `
                <div class="recordings-row">
                    <span class="recordings-label">${escapeHtml(clip.label)}</span>
                    <div class="recordings-player-slot"
                         data-recording-key="${escapeHtml(file + '::' + clip.file)}"
                         data-recording-url="${escapeHtml(clip.url)}"
                         data-recording-label="${escapeHtml(clip.label)}"></div>
                </div>`).join('')}</div>`
            : '';
        return `<div class="detail-block recordings-block">
            <button class="recordings-toggle" type="button" data-toggle-recordings="${escapeHtml(file)}" aria-expanded="${expanded}">
                <span>🎧 Originalaufnahmen <span class="recordings-count">(${clips.length})</span></span>
                <span aria-hidden="true">${expanded ? '▴' : '▾'}</span>
            </button>
            ${clipsHtml}
        </div>`;
    }

    // Reuse real HTMLMediaElement instances across 5-second polling rerenders.
    // Recreating them on every refresh would stop playback and reset its position.
    function syncRecordingPlayers() {
        const active = new Set();
        document.querySelectorAll('[data-recording-key]').forEach(slot => {
            const key = slot.getAttribute('data-recording-key');
            const url = slot.getAttribute('data-recording-url');
            if (!key || !url) return;
            let player = recordingPlayers.get(key);
            if (!player || player.getAttribute('src') !== url) {
                if (player) player.pause();
                player = document.createElement('audio');
                player.controls = true;
                player.preload = 'none';
                player.src = url;
                player.setAttribute('aria-label', slot.getAttribute('data-recording-label') || 'Originalaufnahme');
                player.addEventListener('error', () => {
                    showMessage('Originalaufnahme nicht erreichbar. recordings-Freigabe und Dateirechte prüfen.', true);
                });
                player.addEventListener('play', () => {
                    recordingPlayers.forEach(other => {
                        if (other !== player && !other.paused) other.pause();
                    });
                });
                recordingPlayers.set(key, player);
            }
            slot.appendChild(player);
            active.add(key);
        });
        for (const [key, player] of recordingPlayers) {
            if (!active.has(key)) {
                player.pause();
                recordingPlayers.delete(key);
            }
        }
    }

    function createLeftExtras(entry) {
        const isSummaryOpen = openSummaryFiles.has(String(entry.file || ''));
        const summaryBlock = entry.summary
            ? `<div class="detail-block"><details data-summary-file="${escapeHtml(entry.file || '')}"${isSummaryOpen ? ' open' : ''}><summary>Zusammenfassung des Gesprächs</summary><p>${escapeHtml(entry.summary)}</p></details></div>`
            : '';
        const transmittedValue = entry.transmitted_phone_display || '—';
        const transmittedNode = createPhoneActions(transmittedValue, entry.transmitted_phone_href, entry.transmitted_phone_raw, 'transmitted-link');
        return `
            ${summaryBlock}
            ${createRecordings(entry)}
            <div class="transmitted-row"><strong>Übermittelte Telefonnummer:</strong> ${transmittedNode}</div>
            ${createCommentItems(entry)}
            ${createCommentEditor(entry)}
            ${createSmsEditor(entry)}`;
    }

    function createCardActions(entry, area) {
        const file = escapeHtml(entry.file);
        if (area === 'middle') {
            return `
                <div class="actions">
                    <button class="btn btn-primary" data-action="set_status" data-status="in_bearbeitung" data-file="${file}">Bearbeitung</button>
                    <button class="btn ${entry.urgent ? 'btn-danger' : ''}" data-action="toggle_urgent" data-file="${file}">Dringend</button>
                </div>`;
        }
        if (area === 'left') {
            const smsButton = entry.sms_phone_href
                ? `<button class="btn" type="button" data-toggle-sms="${file}">SMS</button>`
                : '';
            return `
                <div class="actions">
                    <button class="btn btn-primary" data-action="set_status" data-status="abgeschlossen" data-file="${file}">Fertig</button>
                    <button class="btn" data-action="set_status" data-status="neu" data-file="${file}">Zurücksetzen</button>
                    <button class="btn ${entry.urgent ? 'btn-danger' : ''}" data-action="toggle_urgent" data-file="${file}">Dringend</button>
                    <button class="btn" type="button" data-toggle-comment="${file}">Kommentar</button>
                    ${smsButton}
                    <button class="btn btn-icon" type="button" title="Drucken" data-print-card="${file}">🖨</button>
                    <button class="btn btn-icon" type="button" title="Karte in die Zwischenablage" data-copy-card="${file}">📋</button>
                </div>`;
        }
        if (area === 'right') {
            return `
                <div class="actions">
                    <button class="btn btn-primary" data-action="set_status" data-status="neu" data-file="${file}">Wiederherstellen</button>
                    <button class="btn btn-danger" data-action="soft_delete" data-file="${file}">Löschen</button>
                </div>`;
        }
        if (area === 'trash') {
            return `
                <div class="actions">
                    <button class="btn btn-primary" data-action="restore" data-file="${file}">Wiederherstellen</button>
                    <button class="btn btn-danger" data-action="purge" data-file="${file}">Löschen</button>
                </div>`;
        }
        return '';
    }

    function createCard(entry, area) {
        const classes = ['card'];
        if (entry.deleted) {
            classes.push('status-trash');
        } else {
            classes.push(`status-${entry.status}`);
        }
        if (entry.urgent) classes.push('urgent');
        const bodyClass = area === 'left' ? 'body-full' : 'body-preview';
        const deletedExtra = area === 'trash'
            ? `<div class="transmitted-row"><strong>Gelöscht:</strong> ${escapeHtml(entry.deleted_at_display || '—')}</div>`
            : '';
        return `
            <article class="${classes.join(' ')}" data-entry-file="${escapeHtml(entry.file || '')}">
                ${area === 'middle' || area === 'right' || area === 'trash' ? createSelectionControl(area, entry, false) : ''}
                ${createHeader(entry)}
                <div class="card-body ${bodyClass}" title="${escapeHtml(entry.body || '')}">${escapeHtml(entry.body || '—')}</div>
                ${area === 'left' ? createLeftExtras(entry) : createRecordings(entry) + createReceivedSmsHistory(entry)}
                ${deletedExtra}
                ${createCardActions(entry, area)}
            </article>`;
    }

    function createEmpty(text) {
        return `<div class="empty">${escapeHtml(text)}</div>`;
    }

    function tableActionButtons(entry, area) {
        const file = escapeHtml(entry.file || '');
        if (area === 'middle') {
            return `<div class="table-actions"><button class="btn ${entry.urgent ? 'btn-danger' : ''}" data-action="toggle_urgent" data-file="${file}">Dringend</button></div>`;
        }
        if (area === 'right') {
            return `<div class="table-actions"><button class="btn btn-primary" data-action="set_status" data-status="neu" data-file="${file}">Wiederherstellen</button><button class="btn btn-danger" data-action="soft_delete" data-file="${file}">Löschen</button></div>`;
        }
        if (area === 'trash') {
            return `<div class="table-actions"><button class="btn btn-primary" data-action="restore" data-file="${file}">Wiederherstellen</button><button class="btn btn-danger" data-action="purge" data-file="${file}">Löschen</button></div>`;
        }
        return '';
    }

    function createTable(area, entries) {
        const hasDeletedCol = area === 'trash';
        const header = `
            <table class="item-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Kategorie</th>
                        <th>Patient</th>
                        <th>Vorschau</th>
                        ${hasDeletedCol ? '<th>Gelöscht</th>' : ''}
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    ${entries.map(entry => {
                        const rowClass = entry.deleted ? 'row-status-trash' : `row-status-${entry.status}`;
                        const urgentClass = entry.urgent ? ' urgent-row' : '';
                        const name = escapeHtml(entry.person_name || '—');
                        const birth = entry.person_birth_date ? ` · ${escapeHtml(entry.person_birth_date)}` : '';
                        return `
                        <tr class="${rowClass}${urgentClass}">
                            <td>${createSelectionControl(area, entry, true)}</td>
                            <td>${escapeHtml(entry.category_label || '—')}</td>
                            <td class="table-patient"><div class="table-name">${name}${birth}</div><div class="table-received">${escapeHtml(entry.received_at_display || '—')}</div>${t2medEnabled ? `<div class="table-phone">${createPhoneActions(entry.telephone_display, entry.telephone_href, entry.telephone_raw)}</div>` : ''}</td>
                            <td><div class="table-preview${area === 'middle' || area === 'right' ? ' table-preview-clamped' : ''}" title="${escapeHtml(entry.body || '—')}">${escapeHtml(entry.body || '—')}</div>${createRecordings(entry)}${createReceivedSmsHistory(entry)}</td>
                            ${hasDeletedCol ? `<td class="table-received">${escapeHtml(entry.deleted_at_display || '—')}</td>` : ''}
                            <td>${tableActionButtons(entry, area)}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>`;
        return `<div class="table-wrap">${header}</div>`;
    }

    function renderStats(entries) {
        const workplace = currentWorkplace() || '—';
        const active = entries.filter(e => !e.deleted);
        const neu = active.filter(e => e.status === 'neu').length;
        const bearbeitung = active.filter(e => e.status === 'in_bearbeitung').length;
        const abgeschlossen = active.filter(e => e.status === 'abgeschlossen').length;
        const dringend = active.filter(e => e.urgent).length;
        const summaryItems = [
            ['neu', 'Neu', neu],
            ['dringend', 'Dringend', dringend],
            ['bearbeitung', 'In Bearbeitung', bearbeitung],
            ['abgeschlossen', 'Abgeschlossen', abgeschlossen],
            ['benutzer', 'Benutzer', currentUser() || '—'],
            ['platz', 'Platz', workplace],
        ];
        if (els.headerSummary) {
            els.headerSummary.innerHTML = summaryItems.map(([key, label, value]) => `<span class="summary-chip" data-summary-key="${escapeHtml(key)}"><span>${escapeHtml(label)}:</span> <strong>${escapeHtml(String(value))}</strong></span>`).join('');
            updateHeaderSummaryVisibility();
        }
        if (els.menuStats) {
            els.menuStats.innerHTML = [
                '<div class="menu-heading">Zähler</div>',
                `<div class="menu-stat-line"><span>Neu</span><strong>${escapeHtml(String(neu))}</strong></div>`,
                `<div class="menu-stat-line"><span>Dringend</span><strong>${escapeHtml(String(dringend))}</strong></div>`,
                `<div class="menu-stat-line"><span>In Bearbeitung</span><strong>${escapeHtml(String(bearbeitung))}</strong></div>`,
                `<div class="menu-stat-line"><span>Abgeschlossen</span><strong>${escapeHtml(String(abgeschlossen))}</strong></div>`
            ].join('');
        }
    }

    function renderArea(area, entries) {
        syncSelection(area, entries);
        if (!entries.length) {
            if (area === 'middle') {
                return createEmpty('Keine neuen Eingänge.');
            }
            if (area === 'right') {
                return createEmpty('Keine abgeschlossenen Vorgänge.');
            }
            return createEmpty('Papierkorb ist leer.');
        }
        if (viewModes[area] === 'table') {
            return createTable(area, entries);
        }
        if (area === 'middle') {
            return `<div class="middle-grid">${entries.map(entry => createCard(entry, 'middle')).join('')}</div>`;
        }
        return entries.map(entry => createCard(entry, area)).join('');
    }

    function render(entries) {
        const transient = captureTransientState();
        lastEntries = Array.isArray(entries) ? entries : [];
        const user = currentUser();
        const left = lastEntries.filter(entry => !entry.deleted && entry.status === 'in_bearbeitung' && user !== '' && entry.last_user === user).sort(sortLeft);
        const middle = areaEntries('middle');
        const right = areaEntries('right');
        const trash = areaEntries('trash');

        els.leftColumn.innerHTML = left.length ? left.map(entry => createCard(entry, 'left')).join('') : createEmpty(user ? 'Keine Vorgänge in Bearbeitung.' : 'Bitte zuerst einen Benutzer eintragen.');
        els.middleBody.innerHTML = renderArea('middle', middle);
        els.rightBody.innerHTML = renderArea('right', right);
        els.trashBody.innerHTML = renderArea('trash', trash);

        els.countLeft.textContent = String(left.length);
        els.countMiddle.textContent = String(middle.length);
        els.countRight.textContent = String(right.length);
        els.countTrash.textContent = String(trash.length);

        setDocumentTitle(lastEntries);
        renderStats(lastEntries);
        updateViewButtons();
        applyVisibilitySettings();
        syncRecordingPlayers();
        restoreTransientState(transient);
    }

    function buildCardText(entry) {
        if (!entry) return '';
        const lines = [];
        if (entry.urgent) {
            lines.push('DRINGEND');
            lines.push('');
        }
        lines.push(`Kategorie: ${entry.category_label || '—'}`);
        lines.push(`Name: ${entry.person_name || '—'}`);
        if (entry.person_birth_date) lines.push(`Geburtsdatum: ${entry.person_birth_date}`);
        lines.push(`Telefon: ${entry.telephone_display || '—'}`);
        lines.push(`Eingang: ${entry.received_at_display || '—'}`);
        if (entry.last_user) lines.push(`Benutzer: ${entry.last_user}`);
        if (entry.last_workplace) lines.push(`Platz: ${entry.last_workplace}`);
        lines.push('');
        lines.push('Inhalt:');
        lines.push(entry.body || '—');
        if (entry.summary) {
            lines.push('');
            lines.push('Zusammenfassung des Gesprächs:');
            lines.push(entry.summary);
        }
        lines.push('');
        lines.push(`Übermittelte Telefonnummer: ${entry.transmitted_phone_display || '—'}`);
        const comments = Array.isArray(entry.comments) ? entry.comments.filter(comment => comment && comment.text) : [];
        if (comments.length) {
            lines.push('');
            lines.push('Kommentare:');
            comments.forEach(comment => {
                lines.push(comment.kind === 'sms_received'
                    ? `SMS eingegangen · ${comment.created_at_display || '—'} · von ${comment.sms_sender || '—'}`
                    : `${comment.created_at_display || '—'} · Benutzer: ${comment.benutzer || '—'}`);
                lines.push(comment.text || '');
                lines.push('');
            });
            while (lines.length && lines[lines.length - 1] === '') {
                lines.pop();
            }
        }
        return lines.join('\n');
    }

    function printCard(entry) {
        const text = buildCardText(entry);
        if (!text) return;
        const printableText = entry.urgent ? text.replace(/^DRINGEND\n\n/, '') : text;
        const urgentBlock = entry.urgent ? '<div style="border:2px solid #000;padding:8px 10px;font-weight:700;font-size:18px;margin-bottom:12px;">DRINGEND</div>' : '';
        const html = `<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Druckansicht</title><style>@page{margin:14mm}html,body{margin:0;padding:0;background:#fff;color:#000}body{font-family:Arial,sans-serif;padding:20px}pre{white-space:pre-wrap;font:14px/1.4 Arial,sans-serif;margin:0}</style></head><body>${urgentBlock}<pre>${escapeHtml(printableText)}</pre></body></html>`;
        const frame = document.createElement('iframe');
        frame.setAttribute('aria-hidden', 'true');
        frame.style.position = 'fixed';
        frame.style.right = '0';
        frame.style.bottom = '0';
        frame.style.width = '1px';
        frame.style.height = '1px';
        frame.style.border = '0';
        frame.style.opacity = '0';
        let done = false;
        const cleanup = () => {
            window.setTimeout(() => {
                if (frame.parentNode) {
                    frame.parentNode.removeChild(frame);
                }
            }, 300);
        };
        const trigger = () => {
            if (done) return;
            done = true;
            try {
                const win = frame.contentWindow;
                if (!win) throw new Error('Kein Druckfenster verfügbar.');
                if (typeof win.addEventListener === 'function') {
                    win.addEventListener('afterprint', cleanup, {once: true});
                }
                win.focus();
                win.print();
                window.setTimeout(cleanup, 1500);
            } catch (error) {
                cleanup();
                showMessage('Druckdialog konnte nicht geöffnet werden.', true);
            }
        };
        frame.onload = () => window.setTimeout(trigger, 150);
        frame.srcdoc = html;
        document.body.appendChild(frame);
        window.setTimeout(trigger, 900);
    }

    async function copyCard(entry) {
        const text = buildCardText(entry);
        if (!text) return;
        await copyText(text);
        showMessage('Karte in die Zwischenablage kopiert.');
    }

    async function saveComment(file) {
        const draft = String(commentDrafts.get(file) || '').trim();
        if (!draft) {
            showMessage('Kommentar fehlt.', true);
            return;
        }
        const workplace = currentWorkplace();
        if (!currentUser()) {
            showMessage('Bitte zuerst einen Benutzer eintragen.', true);
            return;
        }
        const formData = new FormData();
        formData.set('csrf', currentCsrf);
        formData.set('action', 'add_comment');
        formData.set('file', file);
        formData.set('workplace', workplace);
        formData.set('benutzer', currentUser());
        formData.set('comment_text', draft);
        try {
            await apiRequest(formData);
            commentDrafts.delete(file);
            openCommentFiles.delete(file);
            await refresh();
            showMessage('Kommentar gespeichert.');
        } catch (error) {
            showMessage(error.message || 'Kommentar konnte nicht gespeichert werden.', true);
        }
    }

    async function sendSms(file) {
        if (smsSendingFiles.has(file)) return;
        const draft = String(smsDrafts.get(file) || '').trim();
        if (!draft) {
            showMessage('SMS-Text fehlt.', true);
            return;
        }
        const workplace = currentWorkplace();
        if (!currentUser()) {
            showMessage('Bitte zuerst einen Benutzer eintragen.', true);
            return;
        }

        const entry = getEntryByFile(file);
        if (!entry || !entry.sms_phone_href) {
            showMessage('Keine Rückrufnummer für SMS vorhanden.', true);
            return;
        }

        let request = smsRequestIds.get(file);
        if (!request || request.text !== draft || request.recipient !== entry.sms_phone_href || request.user !== currentUser()) {
            const bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            request = {
                id: Array.from(bytes, value => value.toString(16).padStart(2, '0')).join(''),
                text: draft, recipient: entry.sms_phone_href, user: currentUser(),
            };
            smsRequestIds.set(file, request);
        }

        const formData = new FormData();
        formData.set('csrf', currentCsrf);
        formData.set('action', 'send_sms');
        formData.set('file', file);
        formData.set('workplace', workplace);
        formData.set('benutzer', currentUser());
        formData.set('sms_text', draft);
        formData.set('sms_request_id', request.id);

        smsSendingFiles.add(file);
        render(lastEntries);
        showMessage('SMS-Auftrag wird übermittelt...');
        try {
            const result = await apiRequest(formData);
            smsDrafts.delete(file);
            smsRequestIds.delete(file);
            openSmsFiles.delete(file);
            await refresh();
            showMessage(result.message || 'SMS-Auftrag angenommen.');
        } catch (error) {
            showMessage(error.message || 'SMS konnte nicht gesendet werden.', true);
            render(lastEntries);
        } finally {
            smsSendingFiles.delete(file);
            render(lastEntries);
        }
    }

    async function apiRequest(formData) {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Unbekannter Fehler.');
        }
        if (data.csrf) currentCsrf = data.csrf;
        return data;
    }

    async function refresh() {
        try {
            const response = await fetch(`${window.location.pathname}?ajax=list&_=${Date.now()}`, { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Liste konnte nicht geladen werden.');
            if (data.csrf) currentCsrf = data.csrf;
            if (typeof data.is_admin === 'boolean') isAdmin = data.is_admin;
            const entries = Array.isArray(data.entries) ? data.entries : [];
            const ids = new Set(entries.filter(entry => !entry.deleted).map(entry => entry.file));
            let hasNewSms = false;
            for (const entry of entries) {
                for (const comment of (Array.isArray(entry.comments) ? entry.comments : [])) {
                    const key = comment?.kind === 'sms_received' ? comment.sms_received_key : '';
                    if (typeof key !== 'string' || !key || seenIncomingSmsKeys.has(key)) continue;
                    if (!entry.deleted) hasNewSms = true;
                    // Keep known keys even if an older polling response arrives later.
                    seenIncomingSmsKeys.add(key);
                }
            }
            if (initialized && (hasNewSms || [...ids].some(id => !lastSeenIds.has(id)))) {
                playNotificationTone();
            }
            lastSeenIds = ids;
            render(entries);
            initialized = true;
        } catch (error) {
            showMessage(error.message || 'Aktualisierung fehlgeschlagen.', true);
        }
    }

    async function handleAction(target) {
        const action = target.getAttribute('data-action');
        if (!action) return;
        if (action === 'soft_delete' && target.closest('#completed-column')) {
            if (!window.confirm('Eintrag in den Papierkorb verschieben?')) {
                return;
            }
        }
        if (action === 'purge') {
            if (!window.confirm('Diesen Eintrag endgültig löschen?')) {
                return;
            }
        }
        const formData = new FormData();
        formData.set('csrf', currentCsrf);
        formData.set('action', action);
        formData.set('file', target.getAttribute('data-file') || '');
        formData.set('workplace', currentWorkplace());
        formData.set('benutzer', currentUser());
        if (target.hasAttribute('data-status')) {
            formData.set('status', target.getAttribute('data-status') || '');
        }
        try {
            await apiRequest(formData);
            await refresh();
        } catch (error) {
            showMessage(error.message || 'Aktion fehlgeschlagen.', true);
        }
    }

    async function runActionOnFiles(files, action, extra = {}) {
        for (const file of files) {
            const formData = new FormData();
            formData.set('csrf', currentCsrf);
            formData.set('action', action);
            formData.set('file', file);
            formData.set('workplace', currentWorkplace());
            formData.set('benutzer', currentUser());
            Object.entries(extra).forEach(([key, value]) => formData.set(key, value));
            await apiRequest(formData);
        }
        await refresh();
    }

    async function performBatch(area, command) {
        const files = Array.from(selected[area] || []);
        if (!files.length) {
            showMessage('Bitte zuerst Einträge auswählen.', true);
            return;
        }
        try {
            if (area === 'middle' && command === 'edit') {
                await runActionOnFiles(files, 'set_status', {status: 'in_bearbeitung'});
                selected.middle.clear();
                return;
            }
            if (area === 'middle' && command === 'delete') {
                if (!window.confirm('Ausgewählte neue Einträge in den Papierkorb verschieben?')) return;
                await runActionOnFiles(files, 'soft_delete');
                selected.middle.clear();
                return;
            }
            if (area === 'right' && command === 'restore') {
                await runActionOnFiles(files, 'set_status', {status: 'neu'});
                selected.right.clear();
                return;
            }
            if (area === 'right' && command === 'delete') {
                if (!window.confirm('Ausgewählte Einträge in den Papierkorb verschieben?')) return;
                await runActionOnFiles(files, 'soft_delete');
                selected.right.clear();
                return;
            }
            if (area === 'trash' && command === 'restore') {
                await runActionOnFiles(files, 'restore');
                selected.trash.clear();
                return;
            }
            if (area === 'trash' && command === 'delete') {
                if (!window.confirm('Ausgewählte Papierkorb-Einträge endgültig löschen?')) return;
                await runActionOnFiles(files, 'purge');
                selected.trash.clear();
            }
        } catch (error) {
            showMessage(error.message || 'Sammelaktion fehlgeschlagen.', true);
        }
    }

    function selectAllInArea(area) {
        areaEntries(area).forEach(entry => selected[area].add(String(entry.file || '')));
        render(lastEntries);
    }

    async function adminLogin() {
        const password = String(els.adminPassword?.value || '');
        const formData = new FormData();
        formData.set('csrf', currentCsrf);
        formData.set('action', 'admin_login');
        formData.set('password', password);
        try {
            await apiRequest(formData);
            window.location.reload();
        } catch (error) {
            showMessage(error.message || 'Admin-Anmeldung fehlgeschlagen.', true);
        }
    }

    async function adminLogout() {
        const formData = new FormData();
        formData.set('csrf', currentCsrf);
        formData.set('action', 'admin_logout');
        try {
            await apiRequest(formData);
            window.location.reload();
        } catch (error) {
            showMessage(error.message || 'Admin-Abmeldung fehlgeschlagen.', true);
        }
    }

    async function copyText(text) {
        if (!text) return;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return;
            }
        } catch (error) {
            // fallback unten
        }
        try {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            textarea.style.pointerEvents = 'none';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        } catch (error) {
            // bewusst ohne sichtbare Zusatzmeldung
        }
    }

    async function createBookmarkLink() {
        const identity = draftIdentity();
        if (!identity.user && !identity.workplace) {
            showMessage('Bitte Benutzer oder Platz für den Bookmark-Link eintragen.', true);
            return;
        }
        const url = new URL(window.location.href);
        url.search = '';
        url.hash = '';
        if (identity.workplace) url.searchParams.set('arbeitsplatz', identity.workplace);
        if (identity.user) url.searchParams.set('benutzer', identity.user);
        lastBookmarkUrl = url.toString();
        await copyText(lastBookmarkUrl);
        els.bookmarkOpenBtn.hidden = false;
        showMessage('Bookmark-Link in die Zwischenablage kopiert.');
    }

    document.addEventListener('click', event => {
        const unavailable = event.target.closest('[data-t2med-unavailable]');
        if (unavailable) {
            event.preventDefault();
            showMessage(unavailable.dataset.t2medUnavailable, true);
            return;
        }
        const actionButton = event.target.closest('[data-action]');
        if (actionButton) {
            event.preventDefault();
            handleAction(actionButton);
            return;
        }
        const viewButton = event.target.closest('[data-view-target][data-view-mode]');
        if (viewButton) {
            event.preventDefault();
            const target = String(viewButton.getAttribute('data-view-target') || '');
            const mode = String(viewButton.getAttribute('data-view-mode') || 'cards');
            if (target && (mode === 'cards' || mode === 'table')) {
                viewModes[target] = mode;
                localStorage.setItem(`telepraxis_view_${target}`, mode);
                render(lastEntries);
            }
            return;
        }
        const selectInput = event.target.closest('[data-select-area][data-select-file]');
        if (selectInput) {
            setSelection(selectInput.getAttribute('data-select-area') || '', selectInput.getAttribute('data-select-file') || '', !!selectInput.checked);
            return;
        }
        const toggleRecordingsButton = event.target.closest('[data-toggle-recordings]');
        if (toggleRecordingsButton) {
            event.preventDefault();
            const file = String(toggleRecordingsButton.getAttribute('data-toggle-recordings') || '');
            if (!file) return;
            if (openRecordingFiles.has(file)) openRecordingFiles.delete(file);
            else openRecordingFiles.add(file);
            render(lastEntries);
            return;
        }
        const toggleCommentButton = event.target.closest('[data-toggle-comment]');
        if (toggleCommentButton) {
            event.preventDefault();
            const file = String(toggleCommentButton.getAttribute('data-toggle-comment') || '');
            if (!file) return;
            if (openCommentFiles.has(file)) {
                openCommentFiles.delete(file);
            } else {
                openCommentFiles.add(file);
            }
            render(lastEntries);
            return;
        }
        const saveCommentButton = event.target.closest('[data-save-comment]');
        if (saveCommentButton) {
            event.preventDefault();
            const file = String(saveCommentButton.getAttribute('data-save-comment') || '');
            if (file) saveComment(file);
            return;
        }
        const toggleSmsButton = event.target.closest('[data-toggle-sms]');
        if (toggleSmsButton) {
            event.preventDefault();
            const file = String(toggleSmsButton.getAttribute('data-toggle-sms') || '');
            if (!file || smsSendingFiles.has(file)) return;
            if (openSmsFiles.has(file)) {
                openSmsFiles.delete(file);
            } else {
                openSmsFiles.add(file);
            }
            render(lastEntries);
            return;
        }
        const sendSmsButton = event.target.closest('[data-send-sms]');
        if (sendSmsButton) {
            event.preventDefault();
            const file = String(sendSmsButton.getAttribute('data-send-sms') || '');
            if (file && !smsSendingFiles.has(file)) sendSms(file);
            return;
        }
        const printButton = event.target.closest('[data-print-card]');
        if (printButton) {
            event.preventDefault();
            const entry = getEntryByFile(printButton.getAttribute('data-print-card') || '');
            if (entry) printCard(entry);
            return;
        }
        const copyCardButton = event.target.closest('[data-copy-card]');
        if (copyCardButton) {
            event.preventDefault();
            const entry = getEntryByFile(copyCardButton.getAttribute('data-copy-card') || '');
            if (entry) copyCard(entry);
            return;
        }
        const birthNode = event.target.closest('[data-copy-birth]');
        if (birthNode) {
            event.preventDefault();
            copyText(birthNode.getAttribute('data-copy-birth') || '');
            return;
        }
        const copyButton = event.target.closest('[data-copy-name]');
        if (copyButton) {
            event.preventDefault();
            copyText(copyButton.getAttribute('data-copy-name') || '');
        }
    });

    document.addEventListener('change', event => {
        const selectInput = event.target.closest('[data-select-area][data-select-file]');
        if (!selectInput) return;
        setSelection(selectInput.getAttribute('data-select-area') || '', selectInput.getAttribute('data-select-file') || '', !!selectInput.checked);
    });

    document.addEventListener('input', event => {
        const commentInput = event.target.closest('[data-comment-input]');
        if (commentInput) {
            const file = String(commentInput.getAttribute('data-comment-input') || '');
            commentDrafts.set(file, commentInput.value || '');
            return;
        }
        const smsInput = event.target.closest('[data-sms-input]');
        if (smsInput) {
            const file = String(smsInput.getAttribute('data-sms-input') || '');
            smsDrafts.set(file, smsInput.value || '');
        }
    });

    document.addEventListener('toggle', event => {
        const details = event.target;
        if (!(details instanceof HTMLDetailsElement) || !details.hasAttribute('data-summary-file')) return;
        const file = String(details.getAttribute('data-summary-file') || '');
        if (!file) return;
        if (details.open) {
            openSummaryFiles.add(file);
        } else {
            openSummaryFiles.delete(file);
        }
        saveSummaryState();
    }, true);

    els.saveWorkplaceBtnMenu?.addEventListener('click', saveWorkplace);
    els.saveWorkplaceBtnSetup?.addEventListener('click', saveWorkplace);
    els.bookmarkLinkBtn?.addEventListener('click', createBookmarkLink);
    els.bookmarkOpenBtn?.addEventListener('click', () => {
        if (lastBookmarkUrl) {
            window.open(lastBookmarkUrl, '_blank', 'noopener');
        }
    });
    els.soundToggle?.addEventListener('change', () => localStorage.setItem('telepraxis_sound_enabled', els.soundToggle.checked ? '1' : '0'));
    els.completedToggle?.addEventListener('change', () => {
        if (els.completedToggleTop) {
            els.completedToggleTop.checked = els.completedToggle.checked;
        }
        applyVisibilitySettings();
    });
    els.completedToggleTop?.addEventListener('change', () => {
        if (els.completedToggle) {
            els.completedToggle.checked = els.completedToggleTop.checked;
        }
        applyVisibilitySettings();
    });
    els.trashToggle?.addEventListener('change', applyVisibilitySettings);
    els.trashArrowBtn?.addEventListener('click', () => {
        if (!isAdmin || !els.trashToggle) return;
        els.trashToggle.checked = !els.trashToggle.checked;
        applyVisibilitySettings();
    });
    els.adminLoginBtn?.addEventListener('click', adminLogin);
    els.adminLogoutBtn?.addEventListener('click', adminLogout);
    els.middleSelectAllBtn?.addEventListener('click', () => selectAllInArea('middle'));
    els.middleEditBtn?.addEventListener('click', () => performBatch('middle', 'edit'));
    els.middleDeleteBtn?.addEventListener('click', () => performBatch('middle', 'delete'));
    els.rightSelectAllBtn?.addEventListener('click', () => selectAllInArea('right'));
    els.rightRestoreBtn?.addEventListener('click', () => performBatch('right', 'restore'));
    els.rightDeleteBtn?.addEventListener('click', () => performBatch('right', 'delete'));
    els.trashSelectAllBtn?.addEventListener('click', () => selectAllInArea('trash'));
    els.trashRestoreBtn?.addEventListener('click', () => performBatch('trash', 'restore'));
    els.trashDeleteBtn?.addEventListener('click', () => performBatch('trash', 'delete'));
    function handleWorkplaceInputEvent(event) {
        if (event.target === els.userInputMenu || event.target === els.userInputSetup) {
            setUserInputs(event.target.value, event.target);
        } else {
            setWorkplaceInputs(event.target.value, event.target);
        }
        hideBookmarkOpen();
    }

    [els.userInputMenu, els.userInputSetup, els.workplaceInputMenu, els.workplaceInputSetup].forEach(input => {
        input?.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                saveWorkplace();
            }
        });
        input?.addEventListener('input', handleWorkplaceInputEvent);
    });

    els.menuToggleBtn?.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        setMenuOpen(els.headerMenu?.hidden ?? true);
    });
    els.headerMenu?.addEventListener('click', event => {
        event.stopPropagation();
    });
    document.addEventListener('click', event => {
        if (!els.headerMenu || !els.menuToggleBtn || els.headerMenu.hidden) return;
        const target = event.target;
        if (!(target instanceof Node)) return;
        if (!els.headerMenu.contains(target) && !els.menuToggleBtn.contains(target)) {
            setMenuOpen(false);
        }
    });
    window.addEventListener('resize', updateHeaderSummaryVisibility);

    document.addEventListener('pointerdown', ensureAudioContext, { once: true });
    loadLocalSettings();
    refresh();
    window.setInterval(refresh, <?= (int)TELEPRAXIS_POLL_INTERVAL_MS ?>);
})();
</script>
</body>
</html>
