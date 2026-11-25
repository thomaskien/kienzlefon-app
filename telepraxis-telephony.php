<?php
/** Same-origin proxy for the kienzlefon telephony contract 1.0.2. */
declare(strict_types=1);

ini_set('display_errors', '0');

function kft_reply($body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function kft_error(string $code, string $message, int $status): never
{
    kft_reply(['error' => [
        'code' => $code, 'message' => $message, 'retryable' => $status >= 500,
        'current_revision' => null, 'current_pbx_epoch' => null,
    ]], $status);
}

function kft_require(bool $condition): void
{
    if (!$condition) throw new UnexpectedValueException('Telefonie-Daten entsprechen nicht dem Vertrag.');
}

function kft_datetime(string $value, bool $dateOnly): bool
{
    $ymd = '(\d{4})-(\d{2})-(\d{2})';
    $time = 'T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)';
    return preg_match('/^' . $ymd . ($dateOnly ? '' : $time) . '$/D', $value, $parts) === 1
        && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
}

/** Project only the fields described by the bundled contract, then check constraints. */
function kft_project($value, array $schema, array $defs, bool $strict = false)
{
    if (isset($schema['$ref'])) {
        return kft_project($value, $defs[basename($schema['$ref'])], $defs, $strict);
    }
    if (isset($schema['anyOf'])) {
        $valid = false;
        foreach ($schema['anyOf'] as $alternative) {
            try {
                $selected = kft_project($value, $alternative, $defs, $strict);
                $valid = true;
                break;
            } catch (UnexpectedValueException $error) {
                // Try the next alternative.
            }
        }
        kft_require($valid);
        if (count($schema) === 1) return $selected;
    }
    if (array_key_exists('const', $schema)) kft_require($schema['const'] === $value);
    if (isset($schema['enum'])) kft_require(in_array($value, $schema['enum'], true));
    if (isset($schema['not'])) kft_require(!kft_matches($value, $schema['not'], $defs));
    foreach ($schema['allOf'] ?? [] as $rule) {
        if (isset($rule['if'])) {
            $branch = kft_matches($value, $rule['if'], $defs) ? 'then' : 'else';
            if (isset($rule[$branch])) kft_project($value, $rule[$branch], $defs);
        } else {
            kft_project($value, $rule, $defs);
        }
    }
    $type = $schema['type'] ?? null;
    kft_require(match ($type) {
        'object' => $value instanceof stdClass,
        'array' => is_array($value),
        'boolean' => is_bool($value),
        'string' => is_string($value),
        'integer' => is_int($value),
        'null' => $value === null,
        default => true,
    });
    if (is_string($value)) {
        if (isset($schema['pattern'])) kft_require(preg_match('~' . $schema['pattern'] . '~D', $value) === 1);
        if (isset($schema['format'])) kft_require(kft_datetime($value, $schema['format'] === 'date'));
        if (isset($schema['minLength']) || isset($schema['maxLength'])) {
            $length = preg_match_all('/./us', $value);
            kft_require($length !== false && $length >= ($schema['minLength'] ?? 0)
                && $length <= ($schema['maxLength'] ?? PHP_INT_MAX));
        }
    }
    if (is_int($value)) kft_require($value >= ($schema['minimum'] ?? PHP_INT_MIN) && $value <= ($schema['maximum'] ?? PHP_INT_MAX));
    if (is_array($value) && isset($schema['items'])) {
        kft_require(count($value) >= ($schema['minItems'] ?? 0) && count($value) <= ($schema['maxItems'] ?? PHP_INT_MAX));
        return array_map(static fn($item) => kft_project($item, $schema['items'], $defs, $strict), $value);
    }
    if ($value instanceof stdClass) {
        foreach ($schema['required'] ?? [] as $key) kft_require(property_exists($value, $key));
        if (isset($schema['properties'])) {
            if ($strict && ($schema['additionalProperties'] ?? true) === false) {
                kft_require(array_diff_key(get_object_vars($value), $schema['properties']) === []);
            }
            $projected = new stdClass();
            foreach ($schema['properties'] as $key => $property) {
                if (property_exists($value, $key)) $projected->$key = kft_project($value->$key, $property, $defs, $strict);
            }
            return $projected;
        }
    }
    return $value;
}

function kft_matches($value, array $schema, array $defs): bool
{
    try {
        kft_project($value, $schema, $defs);
        return true;
    } catch (UnexpectedValueException $error) {
        return false;
    }
}

function kft_unknown_permission(string $reason): stdClass
{
    return (object)['can_enable' => false, 'can_disable' => false,
        'enable_reason' => $reason, 'disable_reason' => $reason];
}

function kft_overview(stdClass $overview, int $transit): stdClass
{
    $live = $overview->live_counters;
    $routing = $overview->routing_control;
    $groups = array_column($routing->groups, 'available', 'id');
    kft_require(count($groups) === 2);
    if ($routing->available) {
        kft_require(explode(':', $routing->revision)[1] === $routing->pbx_epoch);
    }
    if ($live->status === 'current' && $routing->available) kft_require($live->pbx_epoch === $routing->pbx_epoch);
    if ($live->status === 'current' && ($live->age_ms += $transit) >= 10000) {
        $live->status = 'unavailable';
        $live->reason_code = 'stale';
        $live->waiting = $live->ivr = $live->ai = $live->age_ms = $live->as_of = null;
    }
    if ($routing->available && ($routing->age_ms += $transit) >= 10000) {
        $routing->available = false;
        $routing->reason_code = 'stale';
        $routing->revision = $routing->age_ms = $routing->as_of = null;
    }
    $seen = [];
    foreach ($overview->extensions as $ext) {
        kft_require(!isset($seen[$ext->id]));
        $seen[$ext->id] = true;
        if ($ext->state_age_ms !== null && ($ext->state_age_ms += $transit) >= 10000) {
            $ext->state = 'unknown';
        }
        foreach ($groups as $group => $available) {
            if (!$routing->available) {
                $ext->routing->$group = null;
                $ext->routing->permissions->$group = kft_unknown_permission('state_unknown');
            } elseif ($ext->routing->$group === null || !$available || $routing->enabled !== true || $overview->source !== 'live') {
                $reason = $ext->routing->$group === null ? 'state_unknown' : (!$available ? 'route_unavailable' : 'global_disabled');
                $ext->routing->permissions->$group = kft_unknown_permission($reason);
            }
        }
    }
    return $overview;
}

function kft_outside_webroot($file, ?string $root): bool
{
    if (!is_string($file) || !is_file($file) || is_link($file)) return false;
    $real = realpath($file);
    return $real !== false && ($root === null || !str_starts_with($real, $root . DIRECTORY_SEPARATOR));
}

function kft_middleware(string $url, string $token, ?string $payload, array $routingHeaders = []): array
{
    $curl = curl_init($url);
    $data = '';
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Kienzlefon-Display: 1', 'Authorization: Bearer ' . $token, 'Content-Type: application/json', ...$routingHeaders],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 8,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$data): int {
            if (strlen($data) + strlen($chunk) > 2097152) return 0;
            $data .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    }
    $started = hrtime(true);
    $success = curl_exec($curl);
    $age = (int)ceil((hrtime(true) - $started) / 1000000);
    $errno = curl_errno($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    unset($curl);
    if ($success === false) {
        if (defined('TELEPRAXIS_LOOKUP_JOB')) throw new RuntimeException('Middleware nicht erreichbar.');
        if ($errno === CURLE_OPERATION_TIMEDOUT) kft_error('upstream_timeout', 'Zeitüberschreitung bei der Middleware.', 504);
        if ($errno === CURLE_WRITE_ERROR) kft_error('state_unavailable', 'Middleware-Antwort ist unbrauchbar.', 503);
        kft_error('upstream_unreachable', 'Middleware ist nicht erreichbar.', 503);
    }
    return [$status, $age, json_decode($data, false, 16)];
}

function kft_same_origin(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $local = parse_url($protocol . '://' . ($_SERVER['HTTP_HOST'] ?? ''));
    $supplied = parse_url($origin);
    return ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') !== 'cross-site' && is_array($local) && is_array($supplied)
        && isset($local['host'], $supplied['host'], $supplied['scheme'])
        && $protocol === $supplied['scheme'] && strcasecmp($local['host'], $supplied['host']) === 0
        && ($local['port'] ?? ($protocol === 'https' ? 443 : 80)) === ($supplied['port'] ?? ($protocol === 'https' ? 443 : 80))
        && !isset($supplied['user']) && !isset($supplied['pass']);
}

/** Null denotes a legacy installation. A failed scope lookup never grants access. */
function kft_routing_access(array $config, array $clients, string $workplace, string $url, string $token, array $defs): ?array
{
    $pbx = $config['pbx_id'] ?? '';
    if ($pbx === '') return null;
    kft_require(kft_matches($pbx, $defs['PbxId'], $defs));
    [$status, , $scope] = kft_middleware($url . '/api/v1/extension-routing/workplaces', $token, null);
    if ($status === 501 && ($scope->error->code ?? null) === 'not_supported') return null;
    kft_require($status === 200 && ($scope->schema_version ?? null) === 1 && ($scope->pbx_id ?? null) === $pbx
        && is_bool($scope->remote_enabled ?? null) && in_array($scope->mode ?? null, ['all','selected'], true)
        && is_array($scope->client_ids ?? null) && ($scope->mode !== 'all' || $scope->client_ids === []));
    foreach ($scope->client_ids as $id) kft_require(kft_matches($id, $defs['ClientId'], $defs));
    $mode = $config['workplace_mode'] ?? 'fon';
    kft_require(in_array($mode, ['fon', 'all', 'selected'], true));
    $localAllowed = ($mode !== 'selected' && $clients === []) || isset($clients[$workplace]);
    $allowed = $scope->remote_enabled && $localAllowed
        && ($scope->mode === 'all' || in_array($workplace, $scope->client_ids, true));
    return ['client_id' => $allowed ? $workplace : null, 'pbx_id' => $pbx];
}

function kft_handle(): never
{
    $action = $_GET['action'] ?? '';
    $endpoints = ['bootstrap' => '', 'routing-access' => '', 'status' => '/api/v1/telephony/overview', 'routing' => '/api/v1/extension-routing',
        'request' => '/api/v1/extension-routing/requests/'];
    $desk = in_array($action, ['desk','dial','patient','transfer','cancel','complete'], true);
    if ($desk) $endpoints[$action] = '/api/v1/practice/' . $action;
    if (!is_string($action) || !isset($endpoints[$action])) kft_error('not_found', 'Telefonie-Aktion unbekannt.', 404);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $allowed = ($desk || $action === 'routing') ? 'POST' : 'GET';
    if ($method !== $allowed) {
        header('Allow: ' . $allowed);
        kft_error('method_not_allowed', 'Methode nicht erlaubt.', 405);
    }
    $root = realpath(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: __DIR__) ?: null;
    $configPath = getenv('KIENZLEFON_TELEPHONY_CONFIG') ?: '/etc/kienzlefon-app/telephony-config.php';
    if (!kft_outside_webroot($configPath, $root)) kft_error('not_supported', 'Telefonie ist nicht eingerichtet.', 501);
    $config = require $configPath;
    if (($config['enabled'] ?? false) !== true) kft_error('not_supported', 'Telefonie ist deaktiviert.', 501);
    $schema = json_decode((string)@file_get_contents(__DIR__ . '/telepraxis-telephony-schema.json'), true);
    $defs = $schema['$defs'] ?? [];
    kft_require(isset($defs['Overview'], $defs['RoutingCommand']));
    $clients = [];
    foreach ($config['clients'] ?? [] as $client) {
        $client = kft_project((object)$client, $defs['AppClient'], $defs, true);
        kft_require(!isset($clients[$client->id]));
        $clients[$client->id] = $client;
    }
    session_start([
        'cookie_httponly' => true, 'cookie_samesite' => 'Strict',
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'use_strict_mode' => true,
    ]);
    $routingAction = in_array($action, ['routing-access', 'routing', 'request'], true);
    $workplace = '';
    if ($routingAction && !empty($config['pbx_id'])) {
        // The editable Telefon field controls the desk phone. It cannot attest a routing identity.
        // A deployment can bind a named station in the web server (never an HTTP header).
        $workplace = $_SERVER['KIENZLEFON_WORKPLACE_ID'] ?? getenv('KIENZLEFON_WORKPLACE_ID');
        if ($workplace === false || $workplace === '') {
            $_SESSION['kft_routing_station'] ??= 'app-' . bin2hex(random_bytes(16));
            $workplace = $_SESSION['kft_routing_station'];
        }
        kft_require(kft_matches($workplace, $defs['ClientId'], $defs));
    }
    if ($action === 'bootstrap') {
        $_SESSION['telephony_csrf'] ??= bin2hex(random_bytes(32));
        $csrf = $_SESSION['telephony_csrf'];
        session_write_close();
        $boot = ['schema_version' => 1, 'csrf_token' => $csrf, 'clients' => array_values($clients)];
        if (($_GET['display'] ?? null) === '1') {
            $boot['workplace_actions'] = isset($config['token_file']) && is_readable($config['token_file'] . '.workplace');
        }
        kft_reply($boot);
    }
    $csrf = $_SESSION['telephony_csrf'] ?? null;
    session_write_close();
    if ($action === 'routing-access' && empty($config['pbx_id'])) kft_reply(['legacy' => true]);
    if ($routingAction && !empty($config['pbx_id']) && !is_string($csrf)) {
        kft_error('csrf_failed', 'Telefonie-Sitzung abgelaufen.', 403);
    }
    if (isset($config['asterisk_snapshot'])) {
        if ($action !== 'status') kft_error('not_supported', 'Die lokale Nebenstellenanzeige ist schreibgeschützt.', 501);
        $snapshot = $config['asterisk_snapshot'];
        if (!kft_outside_webroot($snapshot, $root)) kft_error('state_unavailable', 'Asterisk-Status fehlt.', 503);
        $raw = @file_get_contents($snapshot, false, null, 0, 2097153);
        kft_require(is_string($raw) && strlen($raw) <= 2097152);
        $view = kft_project(json_decode($raw, false, 16), $defs['Overview'], $defs);
        $generated = new DateTimeImmutable($view->generated_at);
        $age = (int)ceil((microtime(true) - (float)$generated->format('U.u')) * 1000);
        if ($age < 0 || $age >= 10000) kft_error('state_unavailable', 'Asterisk-Status ist veraltet.', 503);
        kft_reply(kft_overview($view, $age));
    }
    $path = $endpoints[$action];
    $body = null;
    if ($action === 'routing' || $desk) {
        if (!kft_same_origin()) kft_error('forbidden_origin', 'Fremder Web-Ursprung.', 403);
        if (!is_string($csrf) || !hash_equals($csrf, $_SERVER['HTTP_X_KIENZLEFON_CSRF'] ?? '')) {
            kft_error('csrf_failed', 'Telefonie-Sitzung abgelaufen.', 403);
        }
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            kft_error('json_required', 'JSON wird erwartet.', 415);
        }
        $raw = (string)file_get_contents('php://input', false, null, 0, 8193);
        if (strlen($raw) > 8192) kft_error('payload_too_large', 'Telefonie-Auftrag ist zu groß.', 413);
        if ($desk) {
            $data = json_decode($raw);
            if (!is_object($data) || !is_string($data->client_id ?? null) || !kft_matches($data->client_id,$defs['ClientId'],$defs)) {
                kft_error('invalid_request', 'Bitte das registrierte Telefon auswählen.', 400);
            }
        } else {
            try {
                $command = kft_project(json_decode($raw, false, 16), $defs['RoutingCommand'], $defs, true);
                kft_require(explode(':', $command->expected_revision)[1] === $command->pbx_epoch);
            } catch (UnexpectedValueException $error) {
                kft_error('invalid_request', 'Ungültiger Telefonie-Auftrag.', 400);
            }
        }
        $body = $raw;
    } elseif ($action === 'request') {
        $id = $_GET['request_id'] ?? '';
        if (!kft_matches($id, $defs['UUID'], $defs)) kft_error('invalid_request', 'Ungültige Auftrags-ID.', 400);
        $path .= $id;
    }
    $url = $config['middleware_url'] ?? '';
    $parsed = is_string($url) ? parse_url($url) : false;
    kft_require(is_array($parsed) && in_array($parsed['scheme'] ?? null, ['http', 'https'], true)
        && isset($parsed['host']) && !array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parsed)));
    $tokenPath = $config['token_file'] ?? null;
    if ($desk && is_string($tokenPath)) $tokenPath .= '.workplace';
    kft_require(kft_outside_webroot($tokenPath, $root) && (fileperms($tokenPath) & 0777) === 0600);
    $token = @file_get_contents($tokenPath, false, null, 0, 67);
    kft_require(is_string($token) && preg_match('/^[a-fA-F0-9]{64}(?:\r?\n)?$/D', $token) === 1);
    kft_require(function_exists('curl_init'));
    $url = rtrim($url, '/');
    $token = trim($token);
    $headers = [];
    $access = null;
    if ($routingAction) {
        $access = kft_routing_access($config, $clients, $workplace, $url, $token, $defs);
        if ($action === 'routing-access') kft_reply($access === null ? ['legacy' => true] : ['client_id' => $access['client_id']]);
        if ($access !== null) {
            if ($access['client_id'] === null || ($action === 'routing' && $command->client_id !== $workplace)) {
                kft_error('client_forbidden', 'Diese Sitzung ist für Rufgruppen nicht freigegeben.', 403);
            }
            $headers = ['X-Kienzlefon-Pbx-Id: ' . $access['pbx_id'], 'X-Kienzlefon-Workplace: ' . $workplace];
        } elseif ($action === 'routing' && !isset($clients[$command->client_id])) {
            kft_error('client_forbidden', 'Keine Freigabe zum Ändern der Rufgruppen.', 403);
        }
    }
    [$status, $age, $result] = kft_middleware($url . $path, $token, $body, $headers);
    if ($desk) {
        if ($status >= 200 && $status < 300 && is_object($result)) kft_reply($result, $status);
        $message = is_string($result->error ?? null) ? $result->error : ($result->error->message ?? 'Telefonfunktion nicht verfügbar.');
        kft_error('workplace_error', $message, in_array($status, [400,403,404,409,501,503], true) ? $status : 503);
    }
    if ($status === 401 || ($status === 403 && in_array($result->error->code ?? '', ['forbidden_network', 'forbidden_origin'], true))) {
        kft_error('upstream_authentication_failed', 'Middleware lehnt den Dienstzugang ab.', 503);
    }
    if ($action === 'status' && $status === 200) {
        $view = kft_overview(kft_project($result, $defs['Overview'], $defs), $age);
        if (($_GET['display'] ?? null) === '1') $view->ai_enabled = ($result->ai_enabled ?? false) === true;
        kft_reply($view);
    }
    if ($status === 404 && !($result instanceof stdClass) && $action !== 'request') {
        kft_error('not_supported', 'Middleware unterstützt diese Telefonie-Schnittstelle noch nicht.', 501);
    }
    kft_require(in_array($status, [200, 202, 400, 403, 404, 405, 409, 413, 415, 501, 503, 504], true));
    if (is_object($result) && property_exists($result, 'request')) {
        $answer = kft_project($result, $defs['CommandResponse'], $defs);
        if ($answer->request !== null) {
            kft_require(($answer->error == $answer->request->error)
                && explode(':', $answer->request->expected_revision)[1] === $answer->request->pbx_epoch
                && ($answer->request->result_revision === null || explode(':', $answer->request->result_revision)[1] === $answer->request->pbx_epoch)
                && ($action !== 'request' || $answer->request->request_id === $id)
                && ($action !== 'routing' || $answer->request->request_id === $command->request_id)
                && ($access !== null ? $answer->request->client_id === $workplace : isset($clients[$answer->request->client_id])));
        }
        kft_reply($answer, $status);
    }
    kft_require($status >= 400);
    kft_reply(kft_project($result, $defs['ErrorResponse'], $defs), $status);
}

/** Process only open local entries. Network I/O never holds the shared inbox lock. */
function tp_lookup_numbers(array $entry): array
{
    $numbers = [];
    $payload = tp_payload($entry);
    foreach (['telefon', 'anrufer_id', 'id'] as $field) {
        $raw = $payload[$field] ?? null;
        if (!is_string($raw) || !preg_match('~^\+?[\d\s()./-]+$~D', trim($raw))) continue;
        $number = preg_replace('~[\s()./-]+~', '', trim($raw));
        if (!preg_match('/^\+?\d{5,17}$/D', $number)) continue;
        $number = preg_replace('/^(\+49|0049)0(?=\d)/', '$1', $number);
        if (str_starts_with($number, '00')) $number = '+' . substr($number, 2);
        elseif (str_starts_with($number, '0')) $number = '+49' . substr($number, 1);
        elseif (str_starts_with($number, '49') && strlen($number) >= 9 && $number[2] !== '0') $number = '+' . $number;
        $numbers[$number][] = $field;
    }
    return $numbers;
}

function tp_saved_lookups(array $entry): array
{
    $rows = $entry['app']['rufnummernaufloesung'] ?? null;
    return is_array($rows) ? array_column($rows, null, 'nummer') : [];
}

function tp_lookup_open(?array $entry): bool
{
    return $entry !== null && empty($entry['app']['deleted'])
        && in_array($entry['app']['status'] ?? 'neu', ['neu', 'in_bearbeitung'], true);
}

function tp_lookup_needs_names(array $row): bool
{
    if (!empty($row['namensfelder_geprueft'])) return false;
    foreach ($row['treffer'] ?? [] as $hit) {
        if (!isset($hit['vorname'], $hit['nachname'])) return true;
    }
    return false;
}

/** Enrich the original matches without changing their membership, order or timestamp. */
function tp_lookup_add_names(array $row, array $matches): array
{
    foreach ($row['treffer'] as &$hit) {
        if (isset($hit['vorname'], $hit['nachname'])) continue;
        $same = array_values(array_filter($matches, static fn($p) =>
            isset($p->vorname, $p->nachname) && $p->name === ($hit['name'] ?? null)
            && $p->patientennummer === ($hit['patientennummer'] ?? null)
            && ($p->patientennummer !== null || $p->geburtsdatum === ($hit['geburtsdatum'] ?? null))));
        if (count($same) === 1) {
            $hit['vorname'] = $same[0]->vorname;
            $hit['nachname'] = $same[0]->nachname;
        }
    }
    unset($hit);
    $row['namensfelder_geprueft'] = true;
    return $row;
}

function tp_resolve_inbox(): void
{
    $configPath = getenv('KIENZLEFON_TELEPHONY_CONFIG') ?: '/etc/kienzlefon-app/telephony-config.php';
    if (!kft_outside_webroot($configPath, __DIR__)) return;
    $config = require $configPath;
    if (empty($config['enabled']) || empty($config['token_file']) || empty($config['middleware_url'])) return;
    $tokenPath = $config['token_file'] . '.lookup';
    if (!is_file($tokenPath)) return;
    $url = parse_url($config['middleware_url']);
    if (!kft_outside_webroot($tokenPath, __DIR__) || (fileperms($tokenPath) & 0777) !== 0600 ||
        !isset($url['host']) || !in_array($url['scheme'] ?? '', ['http','https'], true) ||
        array_intersect(['user','pass','fragment','query'], array_keys($url))) throw new RuntimeException('Konfiguration ungültig.');
    $token = trim((string)file_get_contents($tokenPath, false, null, 0, 67));
    if (!preg_match('/^[a-f0-9]{64}$/Di', $token)) throw new RuntimeException('Lesezugang ungültig.');
    $worker = fopen(__FILE__, 'rb');
    if (!$worker || !flock($worker, LOCK_EX | LOCK_NB)) return;
    $failed = false;
    $nameFieldsAvailable = null;
    $lookupUrl = rtrim($config['middleware_url'], '/') . '/api/v1/practice/lookup';
    foreach (glob(TELEPRAXIS_INBOX_DIR . '/*.json') ?: [] as $path) {
        if (is_link($path) || !is_file($path)) continue;
        $entry = tp_read_json_file($path);
        if ($entry === null || !empty($entry['app']['deleted'])) continue;
        $open = tp_lookup_open($entry);
        $saved = tp_saved_lookups($entry);
        $entryNumbers = tp_lookup_numbers($entry);
        $numbers = $open ? $entryNumbers : [];
        foreach ($saved as $number => $row) {
            if (tp_lookup_needs_names($row)) $numbers[$number] ??= $row['nummernfelder'] ?? [];
        }
        if ($open && array_diff_key($entryNumbers, $saved)) {
            $lock = tp_inbox_lock();
            try {
                clearstatcache(true, $path);
                $latest = !is_link($path) && is_file($path) ? tp_read_json_file($path) : null;
                if (!tp_lookup_open($latest) || tp_lookup_numbers($latest) !== $entryNumbers) continue;
                $saved = tp_saved_lookups($latest);
                foreach (array_diff_key($entryNumbers, $saved) as $number => $fields) {
                    $saved[$number] = ['nummer'=>(string)$number, 'nummernfelder'=>$fields, 'quelle'=>'T2med',
                        'stand'=>null, 'status'=>'ausstehend', 'treffer'=>[]];
                }
                if (!is_array($latest['app'] ?? null)) $latest['app'] = [];
                $latest['app']['rufnummernaufloesung'] = array_values($saved);
                tp_write_entry_atomic($path, $latest);
                $entry = $latest;
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        foreach ($numbers as $number => $fields) {
            $number = (string)$number;
            $legacy = isset($saved[$number]) && tp_lookup_needs_names($saved[$number]);
            if (!$legacy && (!$open || (isset($saved[$number]) && ($saved[$number]['status'] ?? '') !== 'ausstehend'))) continue;
            if ($legacy && $nameFieldsAvailable === null) {
                [$probeStatus, , $probe] = kft_middleware($lookupUrl, $token, '{"abfrage":"namensfelder"}');
                $nameFieldsAvailable = $probeStatus === 200 && ($probe->namensfelder ?? false) === true;
            }
            if ($legacy && !$nameFieldsAvailable) continue;
            [$status, , $result] = kft_middleware($lookupUrl, $token, json_encode(['number'=>$number]));
            if ($status === 400) {
                $outcome = 'keine_externe_nummer';
                $matches = [];
            } else {
                if (!in_array($status, [200,503,504], true)) throw new RuntimeException('Lesezugang nicht verfügbar.');
                $outcome = ['resolved'=>'ein_treffer','ambiguous'=>'mehrere_treffer','not_found'=>'kein_treffer'][$result->status ?? ''] ?? null;
                if ($status !== 200 || !$outcome || !is_array($result->treffer ?? null)) { $failed = true; continue; }
                $matches = $result->treffer;
                $nameFieldsAvailable = ($result->namensfelder ?? false) === true;
            }
            $lock = tp_inbox_lock();
            try {
                clearstatcache(true, $path);
                $latest = is_file($path) && !is_link($path) ? tp_read_json_file($path) : null;
                if ($latest === null || !empty($latest['app']['deleted']) || (!$legacy && !tp_lookup_open($latest))
                    || ($entry['id'] ?? null) !== ($latest['id'] ?? null) || ($entry['received_at'] ?? null) !== ($latest['received_at'] ?? null)
                    || tp_lookup_numbers($latest) !== $entryNumbers) continue;
                $items = tp_saved_lookups($latest);
                if ($legacy) {
                    if (($items[$number] ?? null) !== $saved[$number]) continue;
                    if ($status !== 400 && !$nameFieldsAvailable) continue;
                    $items[$number] = tp_lookup_add_names($saved[$number], $matches);
                } else {
                    $items[$number] = ['nummer'=>$number, 'nummernfelder'=>$fields, 'quelle'=>'T2med',
                        'stand'=>tp_now_iso(), 'status'=>$outcome, 'treffer'=>$matches];
                }
                $latest['app']['rufnummernaufloesung'] = array_values($items);
                tp_write_entry_atomic($path, $latest);
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
    if ($failed) throw new RuntimeException('Offene Auflösungen werden nachgeholt.');
}

if (defined('TELEPRAXIS_LOOKUP_JOB')) return;
try {
    kft_handle();
} catch (Throwable $error) {
    kft_error('state_unavailable', 'Telefonie derzeit nicht verfügbar.', 503);
}
