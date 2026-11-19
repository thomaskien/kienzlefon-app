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

function kft_middleware(string $url, string $token, ?string $payload): array
{
    $curl = curl_init($url);
    $data = '';
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token, 'Content-Type: application/json'],
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

function kft_handle(): never
{
    $action = $_GET['action'] ?? '';
    $endpoints = ['bootstrap' => '', 'status' => '/api/v1/telephony/overview', 'routing' => '/api/v1/extension-routing',
        'request' => '/api/v1/extension-routing/requests/'];
    if (!is_string($action) || !isset($endpoints[$action])) kft_error('not_found', 'Telefonie-Aktion unbekannt.', 404);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $allowed = $action === 'routing' ? 'POST' : 'GET';
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
    if ($action === 'bootstrap') {
        $_SESSION['telephony_csrf'] ??= bin2hex(random_bytes(32));
        $csrf = $_SESSION['telephony_csrf'];
        session_write_close();
        kft_reply(['schema_version' => 1, 'csrf_token' => $csrf, 'clients' => array_values($clients)]);
    }
    $csrf = $_SESSION['telephony_csrf'] ?? null;
    session_write_close();
    $path = $endpoints[$action];
    $body = null;
    if ($action === 'routing') {
        if (!kft_same_origin()) kft_error('forbidden_origin', 'Fremder Web-Ursprung.', 403);
        if (!is_string($csrf) || !hash_equals($csrf, $_SERVER['HTTP_X_KIENZLEFON_CSRF'] ?? '')) {
            kft_error('csrf_failed', 'Telefonie-Sitzung abgelaufen.', 403);
        }
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            kft_error('json_required', 'JSON wird erwartet.', 415);
        }
        $raw = (string)file_get_contents('php://input', false, null, 0, 8193);
        if (strlen($raw) > 8192) kft_error('payload_too_large', 'Telefonie-Auftrag ist zu groß.', 413);
        try {
            $command = kft_project(json_decode($raw, false, 16), $defs['RoutingCommand'], $defs, true);
            kft_require(explode(':', $command->expected_revision)[1] === $command->pbx_epoch);
        } catch (UnexpectedValueException $error) {
            kft_error('invalid_request', 'Ungültiger Telefonie-Auftrag.', 400);
        }
        if (!isset($clients[$command->client_id])) kft_error('client_forbidden', 'Arbeitsplatz nicht freigegeben.', 403);
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
    kft_require(kft_outside_webroot($tokenPath, $root) && (fileperms($tokenPath) & 0777) === 0600);
    $token = @file_get_contents($tokenPath, false, null, 0, 67);
    kft_require(is_string($token) && preg_match('/^[a-fA-F0-9]{64}(?:\r?\n)?$/D', $token) === 1);
    kft_require(function_exists('curl_init'));
    [$status, $age, $result] = kft_middleware(rtrim($url, '/') . $path, trim($token), $body);
    if ($status === 401 || ($status === 403 && in_array($result->error->code ?? '', ['forbidden_network', 'forbidden_origin'], true))) {
        kft_error('upstream_authentication_failed', 'Middleware lehnt den Dienstzugang ab.', 503);
    }
    if ($action === 'status' && $status === 200) {
        kft_reply(kft_overview(kft_project($result, $defs['Overview'], $defs), $age));
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
                && isset($clients[$answer->request->client_id]));
        }
        kft_reply($answer, $status);
    }
    kft_require($status >= 400);
    kft_reply(kft_project($result, $defs['ErrorResponse'], $defs), $status);
}

try {
    kft_handle();
} catch (Throwable $error) {
    kft_error('state_unavailable', 'Telefonie derzeit nicht verfügbar.', 503);
}
