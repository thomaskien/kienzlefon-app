<?php
/*
 * Persistenter lokaler SMS-Empfangsspeicher fuer Telepraxis.
 *
 * Changelog (2026-09-29):
 * - Folge-SMS werden unter einem globalen Inbox-Lock an den zuletzt angelegten
 *   offenen Vorgang derselben Rufnummer angehaengt; nur die erste SMS antwortet.
 * - Empfangsjournal, sichere Telepraxis-Ausgabe, idempotente Antworten und
 *   bestaetigte Journalbereinigung eingefuehrt.
 * - Automatische Antworten auf vollstaendige deutsche Rufnummern begrenzt.
 *
 * Diese Datei ist nur als Include gedacht und fuehrt selbst keine Routerzugriffe aus.
 */

declare(strict_types=1);

if (!defined('TELEPRAXIS_APP') && !defined('TELEPRAXIS_SMS_CONFIG')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/telepraxis-sms.php';
require_once __DIR__ . '/telepraxis-sms-queue.php';

const TP_SMS_RECEIVE_STATES = ['pending', 'exporting', 'exported', 'held', 'not_applicable'];
const TP_SMS_RECEIVE_REPLY_STATES = ['pending', 'queued', 'disabled', 'skipped', 'error', 'not_applicable'];
const TP_SMS_RECEIVE_CLEANUP_STATES = ['pending', 'done', 'held'];

function tp_sms_receive_enabled(array $settings): bool
{
    $receive = $settings['receive'] ?? null;
    return is_array($receive) && (($receive['enabled'] ?? null) === true);
}

/** @return array{path:string,host:string} */
function tp_sms_receive_target(array $settings): array
{
    $receive = $settings['receive'] ?? null;
    if (!is_array($receive) || ($receive['output_mode'] ?? null) !== 'target-local') {
        throw new RuntimeException('Der SMS-Empfang muss auf ein lokales Zielsystem konfiguriert sein.');
    }
    $configuredPath = $receive['inbox_path'] ?? '';
    if (!is_string($configuredPath) || $configuredPath === '' || str_contains($configuredPath, "\0")
        || !tp_sms_queue_is_absolute_path($configuredPath) || is_link($configuredPath)) {
        throw new RuntimeException('Der SMS-Empfangspfad ist ungueltig.');
    }
    $path = realpath($configuredPath);
    if ($path === false || !is_dir($path) || !is_writable($path)) {
        throw new RuntimeException('Der SMS-Empfangspfad fehlt oder ist nicht beschreibbar.');
    }
    $permissions = @fileperms($path);
    if ($permissions !== false && (($permissions & 0002) !== 0)) {
        throw new RuntimeException('Der SMS-Empfangspfad darf nicht fuer alle beschreibbar sein.');
    }
    $host = $settings['fritzbox']['host'] ?? null;
    if (!is_string($host) || trim($host) === '' || str_contains($host, "\0")) {
        throw new RuntimeException('Der Routerhost fuer den SMS-Empfang fehlt.');
    }
    return ['path' => $path, 'host' => trim($host)];
}

function tp_sms_receive_valid_device_key(string $deviceKey): bool
{
    return (bool)preg_match('/^[a-f0-9]{64}$/Di', $deviceKey);
}

function tp_sms_receive_initialize_schema(PDO $pdo): void
{
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sms_receive_meta (
                singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
                device_key TEXT NOT NULL,
                inbox_path TEXT NOT NULL,
                router_host TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sms_receive_journal (
                receive_order INTEGER PRIMARY KEY AUTOINCREMENT,
                record_key TEXT NOT NULL UNIQUE,
                device_key TEXT NOT NULL,
                fingerprint TEXT NOT NULL,
                uid TEXT,
                kind TEXT NOT NULL CHECK (kind IN ('received', 'sent', 'unknown')),
                journal_status INTEGER,
                message_date TEXT,
                phone TEXT,
                message_text TEXT,
                answerable INTEGER CHECK (answerable IS NULL OR answerable IN (0, 1)),
                raw_json TEXT NOT NULL,
                problem TEXT,
                export_status TEXT NOT NULL CHECK (export_status IN ('pending', 'exporting', 'exported', 'held', 'not_applicable')),
                export_failure_code TEXT,
                export_file TEXT,
                reply_first INTEGER NOT NULL DEFAULT 1 CHECK (reply_first IN (0, 1)),
                reply_status TEXT NOT NULL CHECK (reply_status IN ('pending', 'queued', 'disabled', 'skipped', 'error', 'not_applicable')),
                reply_failure_code TEXT,
                reply_attempts INTEGER NOT NULL DEFAULT 0 CHECK (reply_attempts >= 0),
                reply_next_attempt_at TEXT NOT NULL,
                reply_snapshot_json TEXT NOT NULL,
                cleanup_status TEXT NOT NULL CHECK (cleanup_status IN ('pending', 'done', 'held')),
                cleanup_failure_code TEXT,
                cleanup_attempts INTEGER NOT NULL DEFAULT 0 CHECK (cleanup_attempts >= 0),
                cleanup_next_attempt_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )"
        );
        $columns = [];
        foreach ($pdo->query('PRAGMA table_info(sms_receive_journal)') as $column) {
            if (is_array($column) && is_string($column['name'] ?? null)) {
                $columns[$column['name']] = true;
            }
        }
        if (!isset($columns['export_file'])) {
            $pdo->exec('ALTER TABLE sms_receive_journal ADD COLUMN export_file TEXT');
        }
        if (!isset($columns['reply_first'])) {
            $pdo->exec(
                'ALTER TABLE sms_receive_journal ADD COLUMN reply_first INTEGER NOT NULL DEFAULT 1
                 CHECK (reply_first IN (0, 1))'
            );
        }
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS sms_receive_export_idx
             ON sms_receive_journal(kind, export_status, receive_order)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS sms_receive_reply_idx
             ON sms_receive_journal(reply_status, reply_next_attempt_at, receive_order)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS sms_receive_cleanup_idx
             ON sms_receive_journal(cleanup_status, cleanup_next_attempt_at, receive_order)'
        );
        $pdo->exec('COMMIT');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tp_sms_queue_database_error();
    }
}

function tp_sms_receive_open(array $settings): PDO
{
    $pdo = tp_sms_queue_open($settings, true);
    if (!$pdo instanceof PDO) {
        tp_sms_queue_database_error();
    }
    tp_sms_receive_initialize_schema($pdo);
    return $pdo;
}

/** @return mixed */
function tp_sms_receive_canonical_value(mixed $value): mixed
{
    if (is_object($value)) {
        $value = get_object_vars($value);
    }
    if (!is_array($value)) {
        if (is_resource($value)) {
            throw new RuntimeException('SMS-Journaldaten sind nicht serialisierbar.');
        }
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('tp_sms_receive_canonical_value', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = tp_sms_receive_canonical_value($item);
    }
    return $value;
}

function tp_sms_receive_json(mixed $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    );
}

function tp_sms_receive_fingerprint(array $entry): string
{
    $kind = $entry['kind'] ?? null;
    if (in_array($kind, ['received', 'sent'], true)) {
        $value = [
            'uid' => $entry['uid'] ?? null,
            'kind' => $kind,
            'date' => $entry['date'] ?? null,
            'phone' => $entry['phone'] ?? null,
            'text' => $entry['text'] ?? null,
        ];
    } else {
        $value = tp_sms_receive_canonical_value($entry['raw'] ?? null);
    }
    return hash('sha256', tp_sms_receive_json($value));
}

/** @return array{messages:mixed,provider:mixed,max_text_length:mixed} */
function tp_sms_receive_reply_snapshot(array $settings): array
{
    $defaults = tp_sms_default_settings();
    $autoReply = array_key_exists('auto_reply', $settings) ? $settings['auto_reply'] : $defaults['auto_reply'];
    $messages = is_array($autoReply) && array_key_exists('messages', $autoReply)
        ? $autoReply['messages'] : (is_array($autoReply) ? $defaults['auto_reply']['messages'] : $autoReply);
    $queue = $settings['queue'] ?? null;
    $provider = is_array($queue) && array_key_exists('delivery_provider', $queue)
        ? $queue['delivery_provider'] : $defaults['queue']['delivery_provider'];
    $sms = $settings['sms'] ?? null;
    $maxLength = is_array($sms) && array_key_exists('max_text_length', $sms)
        ? $sms['max_text_length'] : $defaults['sms']['max_text_length'];
    return ['messages' => $messages, 'provider' => $provider, 'max_text_length' => $maxLength];
}

/** @return array{device_key:string,inbox_path:string,router_host:string}|null */
function tp_sms_receive_binding(PDO $pdo): ?array
{
    $row = $pdo->query(
        'SELECT device_key, inbox_path, router_host FROM sms_receive_meta WHERE singleton = 1'
    )->fetch();
    return is_array($row) ? $row : null;
}

function tp_sms_receive_assert_binding(PDO $pdo, array $target, ?string $deviceKey = null): array
{
    $binding = tp_sms_receive_binding($pdo);
    if (!is_array($binding)) {
        throw new RuntimeException('Der SMS-Empfang ist noch nicht an ein Geraet gebunden.');
    }
    if ($binding['inbox_path'] !== $target['path'] || $binding['router_host'] !== $target['host']) {
        throw new RuntimeException('Die gebundene SMS-Empfangskonfiguration wurde geaendert.');
    }
    if ($deviceKey !== null && !hash_equals((string)$binding['device_key'], $deviceKey)) {
        throw new RuntimeException('Der SMS-Empfang ist an ein anderes Geraet gebunden.');
    }
    return $binding;
}

/** @return array{stored:int,duplicates:int} */
function tp_sms_receive_store(array $settings, string $deviceKey, array $entries): array
{
    if (!tp_sms_receive_valid_device_key($deviceKey)) {
        throw new RuntimeException('Der SMS-Geraeteschluessel ist ungueltig.');
    }
    if (!array_is_list($entries)) {
        throw new RuntimeException('Das SMS-Journal muss eine Liste sein.');
    }
    $target = tp_sms_receive_target($settings);
    try {
        $replySnapshotJson = tp_sms_receive_json(tp_sms_receive_reply_snapshot($settings));
    } catch (Throwable $exception) {
        // Eine defekte Antwortkonfiguration darf die dauerhafte Eingangssicherung
        // nicht verhindern; sie wird beim Antwortschritt als Fehler sichtbar.
        $replySnapshotJson = tp_sms_receive_json([
            'invalid' => true, 'messages' => null, 'provider' => null, 'max_text_length' => null,
        ]);
    }
    $prepared = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('Ein SMS-Journaleintrag ist ungueltig.');
        }
        $kind = $entry['kind'] ?? null;
        if (!in_array($kind, ['received', 'sent', 'unknown'], true)) {
            throw new RuntimeException('Ein SMS-Journaleintrag hat einen ungueltigen Typ.');
        }
        $fingerprint = tp_sms_receive_fingerprint($entry);
        $recordKey = hash('sha256', $deviceKey . ':' . $fingerprint);
        $uid = $entry['uid'] ?? null;
        $validUid = is_string($uid) && $uid !== '' && !str_contains($uid, "\0");
        $prepared[] = [
            'record_key' => $recordKey,
            'fingerprint' => $fingerprint,
            'uid' => $validUid ? $uid : null,
            'kind' => $kind,
            'status' => is_int($entry['status'] ?? null) ? $entry['status'] : null,
            'date' => is_string($entry['date'] ?? null) ? $entry['date'] : null,
            'phone' => is_string($entry['phone'] ?? null) ? $entry['phone'] : null,
            'text' => is_string($entry['text'] ?? null) ? $entry['text'] : null,
            'answerable' => is_bool($entry['answerable'] ?? null) ? ($entry['answerable'] ? 1 : 0) : null,
            'raw_json' => tp_sms_receive_json($entry['raw'] ?? null),
            'problem' => is_string($entry['problem'] ?? null) ? $entry['problem'] : null,
            'export_status' => $kind === 'received' ? 'pending' : 'not_applicable',
            'reply_status' => $kind === 'received' ? 'pending' : 'not_applicable',
            'cleanup_status' => $validUid ? 'pending' : 'held',
            'cleanup_failure_code' => $validUid ? null : 'missing_uid',
        ];
    }

    $pdo = tp_sms_receive_open($settings);
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $binding = tp_sms_receive_binding($pdo);
        $now = tp_sms_queue_timestamp();
        if ($binding === null) {
            $insertMeta = $pdo->prepare(
                'INSERT INTO sms_receive_meta
                 (singleton, device_key, inbox_path, router_host, created_at, updated_at)
                 VALUES (1, :device_key, :inbox_path, :router_host, :created_at, :updated_at)'
            );
            $insertMeta->execute([
                ':device_key' => $deviceKey,
                ':inbox_path' => $target['path'],
                ':router_host' => $target['host'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } elseif (!hash_equals((string)$binding['device_key'], $deviceKey)
            || $binding['inbox_path'] !== $target['path'] || $binding['router_host'] !== $target['host']) {
            throw new RuntimeException('Die gebundene SMS-Empfangskonfiguration wurde geaendert.');
        }
        $insert = $pdo->prepare(
            'INSERT INTO sms_receive_journal
             (record_key, device_key, fingerprint, uid, kind, journal_status, message_date, phone,
              message_text, answerable, raw_json, problem, export_status, reply_status,
              reply_next_attempt_at, reply_snapshot_json, cleanup_status, cleanup_failure_code,
              cleanup_next_attempt_at, created_at, updated_at)
             VALUES
             (:record_key, :device_key, :fingerprint, :uid, :kind, :journal_status, :message_date, :phone,
              :message_text, :answerable, :raw_json, :problem, :export_status, :reply_status,
              :reply_next_attempt_at, :reply_snapshot_json, :cleanup_status, :cleanup_failure_code,
              :cleanup_next_attempt_at, :created_at, :updated_at)
             ON CONFLICT(record_key) DO NOTHING'
        );
        $stored = 0;
        foreach ($prepared as $row) {
            $insert->execute([
                ':record_key' => $row['record_key'], ':device_key' => $deviceKey,
                ':fingerprint' => $row['fingerprint'], ':uid' => $row['uid'], ':kind' => $row['kind'],
                ':journal_status' => $row['status'], ':message_date' => $row['date'], ':phone' => $row['phone'],
                ':message_text' => $row['text'], ':answerable' => $row['answerable'],
                ':raw_json' => $row['raw_json'], ':problem' => $row['problem'],
                ':export_status' => $row['export_status'], ':reply_status' => $row['reply_status'],
                ':reply_next_attempt_at' => $now, ':reply_snapshot_json' => $replySnapshotJson,
                ':cleanup_status' => $row['cleanup_status'],
                ':cleanup_failure_code' => $row['cleanup_failure_code'], ':cleanup_next_attempt_at' => $now,
                ':created_at' => $now, ':updated_at' => $now,
            ]);
            $inserted = $insert->rowCount();
            $stored += $inserted;
            if ($inserted === 0 && $row['uid'] !== null) {
                // Eine erneut beobachtete Routerkopie erneut bereinigen, ohne Ausgabe/Antwort zu wiederholen.
                $again = $pdo->prepare(
                    "UPDATE sms_receive_journal SET cleanup_status='pending', cleanup_failure_code=NULL,
                     cleanup_attempts=0, cleanup_next_attempt_at=:now, updated_at=:now
                     WHERE record_key=:key AND cleanup_status='done'"
                );
                $again->execute([':now' => $now, ':key' => $row['record_key']]);
            }
        }
        $pdo->commit();
        return ['stored' => $stored, 'duplicates' => count($prepared) - $stored];
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (str_contains($exception->getMessage(), 'gebundene SMS-Empfangskonfiguration')) {
            throw $exception;
        }
        tp_sms_queue_database_error();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tp_sms_queue_database_error();
    }
}

function tp_sms_receive_output_matches(string $path, string $recordKey): bool
{
    $data = tp_sms_receive_read_output($path);
    if (!is_array($data)) {
        return false;
    }
    if (is_array($data['sms'] ?? null) && ($data['sms']['received_key'] ?? null) === $recordKey) {
        return true;
    }
    $comments = is_array($data['app'] ?? null) ? ($data['app']['comments'] ?? null) : null;
    if (!is_array($comments)) {
        return false;
    }
    foreach ($comments as $comment) {
        if (is_array($comment) && ($comment['kind'] ?? null) === 'sms_received'
            && ($comment['sms_received_key'] ?? null) === $recordKey) {
            return true;
        }
    }
    return false;
}

function tp_sms_receive_set_export(PDO $pdo, string $recordKey, string $status, ?string $failureCode): void
{
    if (!in_array($status, TP_SMS_RECEIVE_STATES, true)) {
        throw new RuntimeException('Ungueltiger Empfangsausgabezustand.');
    }
    $update = $pdo->prepare(
        'UPDATE sms_receive_journal
         SET export_status = :status, export_failure_code = :failure_code, updated_at = :updated_at
         WHERE record_key = :record_key'
    );
    $update->execute([
        ':status' => $status, ':failure_code' => $failureCode,
        ':updated_at' => tp_sms_queue_timestamp(), ':record_key' => $recordKey,
    ]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('SMS-Empfangseintrag wurde nicht gefunden.');
    }
}

function tp_sms_receive_commit_export(PDO $pdo, string $recordKey, string $status, ?string $failureCode): void
{
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        tp_sms_receive_set_export($pdo, $recordKey, $status, $failureCode);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tp_sms_queue_database_error();
    }
}

function tp_sms_receive_fsync_directory(string $directory): void
{
    if (!function_exists('fsync')) {
        throw new RuntimeException('directory_sync_unavailable');
    }
    $handle = @fopen($directory, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException('directory_open_failed');
    }
    try {
        if (!@fsync($handle)) {
            throw new RuntimeException('directory_sync_failed');
        }
    } finally {
        fclose($handle);
    }
}

function tp_sms_receive_confirm_output(string $path, string $directory): void
{
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException('SMS-Ausgabe konnte nicht dauerhaft bestaetigt werden.');
    }
    try {
        if (!function_exists('fsync') || !@fsync($handle)) {
            throw new RuntimeException('SMS-Ausgabe konnte nicht dauerhaft bestaetigt werden.');
        }
    } finally {
        fclose($handle);
    }
    tp_sms_receive_fsync_directory($directory);
}

/** @return array{handle:resource,path:string} */
function tp_sms_receive_inbox_lock(string $directory): array
{
    $path = $directory . DIRECTORY_SEPARATOR . '.telepraxis-inbox.lock';
    clearstatcache(true, $path);
    $existed = file_exists($path) || is_link($path);
    if (is_link($path) || ($existed && !is_file($path))) {
        throw new RuntimeException('Die Inbox-Sperrdatei ist ungueltig.');
    }
    $oldUmask = umask(0007);
    try {
        $handle = @fopen($path, 'c+b');
    } finally {
        umask($oldUmask);
    }
    if (!is_resource($handle)) {
        throw new RuntimeException('Die Inbox-Sperrdatei konnte nicht geoeffnet werden.');
    }
    $stat = fstat($handle);
    $pathStat = @lstat($path);
    if (is_link($path) || !is_array($stat) || !is_array($pathStat)
        || (($stat['mode'] & 0170000) !== 0100000)
        || $stat['dev'] !== $pathStat['dev'] || $stat['ino'] !== $pathStat['ino']) {
        fclose($handle);
        throw new RuntimeException('Die Inbox-Sperrdatei ist ungueltig.');
    }
    if (!$existed) {
        $owned = !function_exists('posix_geteuid') || $stat['uid'] === posix_geteuid();
        if (!$owned || !@chmod($path, 0660)) {
            fclose($handle);
            throw new RuntimeException('Die Inbox-Sperrdatei hat ungueltige Rechte.');
        }
    }
    clearstatcache(true, $path);
    $permissions = @fileperms($path);
    if ($permissions === false || (($permissions & 0777) !== 0660)) {
        fclose($handle);
        throw new RuntimeException('Die Inbox-Sperrdatei hat ungueltige Rechte.');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Die Inbox konnte nicht gesperrt werden.');
    }
    return ['handle' => $handle, 'path' => $path];
}

function tp_sms_receive_absolute_date(?string $value): ?DateTimeImmutable
{
    if ($value === null
        || !preg_match(
            '/^[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}:[0-9]{2}'
            . '(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',
            $value
        )) {
        return null;
    }
    try {
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return null;
        }
        return $date;
    } catch (Throwable $exception) {
        return null;
    }
}

function tp_sms_receive_valid_output_name(string $name): bool
{
    return (bool)preg_match('/^[A-Za-z0-9._-]+\.json$/D', $name)
        && $name === basename($name) && !in_array($name, ['.', '..'], true);
}

/** @return array<string,mixed>|null */
function tp_sms_receive_read_output(string $path): ?array
{
    if (is_link($path) || !is_file($path)) {
        return null;
    }
    $pathStat = @lstat($path);
    $handle = @fopen($path, 'rb');
    if (!is_array($pathStat) || (($pathStat['mode'] & 0170000) !== 0100000) || !is_resource($handle)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return null;
    }
    $stat = fstat($handle);
    if (is_link($path) || !is_array($stat) || (($stat['mode'] & 0170000) !== 0100000)
        || $stat['dev'] !== $pathStat['dev'] || $stat['ino'] !== $pathStat['ino']) {
        fclose($handle);
        return null;
    }
    $raw = stream_get_contents($handle);
    fclose($handle);
    if (!is_string($raw)) {
        return null;
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return null;
    }
    return is_array($data) ? $data : null;
}

function tp_sms_receive_document_has_sms(array $data): bool
{
    if (is_array($data['sms'] ?? null)
        && is_string($data['sms']['received_key'] ?? null)
        && $data['sms']['received_key'] !== '') {
        return true;
    }
    $comments = is_array($data['app'] ?? null) ? ($data['app']['comments'] ?? null) : null;
    if (!is_array($comments)) {
        return false;
    }
    foreach ($comments as $comment) {
        if (is_array($comment) && ($comment['kind'] ?? null) === 'sms_received'
            && is_string($comment['sms_received_key'] ?? null)
            && $comment['sms_received_key'] !== '') {
            return true;
        }
    }
    return false;
}

function tp_sms_receive_journal_exported_to(PDO $pdo, string $fileName, string $recordKey): bool
{
    $query = $pdo->prepare(
        "SELECT 1 FROM sms_receive_journal
         WHERE COALESCE(export_file, 'sms-' || record_key || '.json') = :file
           AND record_key <> :record_key AND export_status = 'exported' LIMIT 1"
    );
    $query->execute([':file' => $fileName, ':record_key' => $recordKey]);
    return $query->fetchColumn() !== false;
}

/** @return array{file:string,path:string,data:array,has_sms:bool}|null */
function tp_sms_receive_find_target(PDO $pdo, string $directory, ?string $phone, string $recordKey): ?array
{
    $normalized = tp_sms_receive_normalize_phone($phone);
    if ($normalized === null) {
        return null;
    }
    $names = @scandir($directory);
    if (!is_array($names)) {
        throw new RuntimeException('Die SMS-Inbox konnte nicht gelesen werden.');
    }
    $candidates = [];
    foreach ($names as $name) {
        if (!is_string($name) || !tp_sms_receive_valid_output_name($name)) {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        $data = tp_sms_receive_read_output($path);
        if ($data === null) {
            continue;
        }
        $app = $data['app'] ?? null;
        if ($app !== null && !is_array($app)) {
            continue;
        }
        if (is_array($app) && array_key_exists('comments', $app) && !is_array($app['comments'])) {
            continue;
        }
        $status = is_array($app) ? ($app['status'] ?? 'neu') : 'neu';
        $deletedSafe = !is_array($app) || !array_key_exists('deleted', $app) || $app['deleted'] === false;
        if (!in_array($status, ['neu', 'in_bearbeitung'], true) || !$deletedSafe) {
            continue;
        }
        $payload = $data['payload'] ?? null;
        if (!is_array($payload)) {
            continue;
        }
        $matches = false;
        foreach (['telefon', 'id', 'anrufer_id'] as $field) {
            $candidatePhone = is_string($payload[$field] ?? null)
                ? tp_sms_receive_normalize_phone($payload[$field]) : null;
            if ($candidatePhone !== null && hash_equals($normalized, $candidatePhone)) {
                $matches = true;
                break;
            }
        }
        $received = is_string($data['received_at'] ?? null)
            ? tp_sms_receive_absolute_date($data['received_at']) : null;
        if (!$matches || $received === null) {
            continue;
        }
        $candidates[] = [
            'file' => $name, 'path' => $path, 'data' => $data,
            'date' => (float)$received->format('U.u'),
            'has_sms' => tp_sms_receive_document_has_sms($data)
                || tp_sms_receive_journal_exported_to($pdo, $name, $recordKey),
        ];
    }
    if ($candidates === []) {
        return null;
    }
    usort($candidates, static function (array $left, array $right): int {
        $date = $right['date'] <=> $left['date'];
        return $date !== 0 ? $date : strcmp($right['file'], $left['file']);
    });
    return $candidates[0];
}

/** @param array<string,mixed> $data */
function tp_sms_receive_append_comment(
    array $data,
    string $recordKey,
    ?string $messageText,
    ?string $messageDate,
    ?string $phone
): array {
    if (!is_array($data['app'] ?? null)) {
        $data['app'] = [];
    }
    $comments = is_array($data['app']['comments'] ?? null) ? $data['app']['comments'] : [];
    $comments[] = [
        'text' => $messageText ?? '',
        'created_at' => $messageDate,
        'workplace' => 'SMS',
        'kind' => 'sms_received',
        'sms_received_key' => $recordKey,
        'sms_sender' => $phone,
    ];
    $decorated = [];
    foreach ($comments as $index => $comment) {
        $date = is_array($comment) && is_string($comment['created_at'] ?? null)
            ? tp_sms_receive_absolute_date($comment['created_at']) : null;
        $decorated[] = [
            'value' => $comment, 'index' => $index,
            'valid' => $date !== null, 'date' => $date === null ? 0.0 : (float)$date->format('U.u'),
        ];
    }
    usort($decorated, static function (array $left, array $right): int {
        if ($left['valid'] !== $right['valid']) {
            return $left['valid'] ? 1 : -1;
        }
        $date = $left['date'] <=> $right['date'];
        return $date !== 0 ? $date : ($left['index'] <=> $right['index']);
    });
    $data['app']['comments'] = array_column($decorated, 'value');
    return $data;
}

function tp_sms_receive_prepare_export(
    PDO $pdo,
    string $recordKey,
    string $fileName,
    bool $replyFirst
): void {
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $update = $pdo->prepare(
            "UPDATE sms_receive_journal
             SET export_status = 'exporting', export_failure_code = NULL, export_file = :file,
                 reply_first = :reply_first, updated_at = :updated_at
             WHERE record_key = :record_key AND export_status = 'pending'"
        );
        $update->execute([
            ':file' => $fileName, ':reply_first' => $replyFirst ? 1 : 0,
            ':updated_at' => tp_sms_queue_timestamp(), ':record_key' => $recordKey,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('SMS-Empfangseintrag wurde nicht vorbereitet.');
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tp_sms_queue_database_error();
    }
}

function tp_sms_receive_reset_safe_export(PDO $pdo, string $recordKey): void
{
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $update = $pdo->prepare(
            "UPDATE sms_receive_journal
             SET export_status = 'pending', export_failure_code = 'output_write_failed',
                 export_file = NULL, reply_first = 1, updated_at = :updated_at
             WHERE record_key = :record_key AND export_status = 'exporting'"
        );
        $update->execute([':updated_at' => tp_sms_queue_timestamp(), ':record_key' => $recordKey]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tp_sms_queue_database_error();
    }
}

function tp_sms_receive_atomic_output(string $directory, string $fileName, string $json, bool $replace): void
{
    $finalPath = $directory . DIRECTORY_SEPARATOR . $fileName;
    $tempPath = $directory . DIRECTORY_SEPARATOR . '.sms-' . bin2hex(random_bytes(16)) . '.tmp';
    $handle = false;
    $renamed = false;
    try {
        $oldUmask = umask(0007);
        try {
            $handle = @fopen($tempPath, 'x+b');
        } finally {
            umask($oldUmask);
        }
        if (!is_resource($handle)) {
            throw new RuntimeException('temp_open_failed');
        }
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = @fwrite($handle, substr($json, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('write_failed');
            }
            $offset += $written;
        }
        if (!@chmod($tempPath, 0660) || !@fflush($handle) || !function_exists('fsync') || !@fsync($handle)) {
            throw new RuntimeException('sync_failed');
        }
        fclose($handle);
        $handle = false;
        clearstatcache(true, $finalPath);
        if ((!$replace && (file_exists($finalPath) || is_link($finalPath)))
            || ($replace && (is_link($finalPath) || !is_file($finalPath)))) {
            throw new RuntimeException('collision');
        }
        if (!@rename($tempPath, $finalPath)) {
            throw new RuntimeException('rename_failed');
        }
        $renamed = true;
        tp_sms_receive_fsync_directory($directory);
    } catch (Throwable $exception) {
        if ($renamed) {
            throw new RuntimeException('output_published_uncertain', 0, $exception);
        }
        throw $exception;
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (is_file($tempPath) && !is_link($tempPath)) {
            @unlink($tempPath);
        }
    }
}

/** @return array<string,mixed>|null */
function tp_sms_receive_next_export(PDO $pdo): ?array
{
    $rows = $pdo->query(
        "SELECT receive_order, record_key, device_key, message_date, phone, message_text,
                export_status, export_file, reply_first
         FROM sms_receive_journal
         WHERE kind = 'received' AND export_status IN ('pending', 'exporting')
         ORDER BY receive_order ASC"
    )->fetchAll();
    if (!is_array($rows) || $rows === []) {
        return null;
    }
    usort($rows, static function (array $left, array $right): int {
        $leftRecovery = $left['export_status'] === 'exporting';
        $rightRecovery = $right['export_status'] === 'exporting';
        if ($leftRecovery !== $rightRecovery) {
            return $leftRecovery ? -1 : 1;
        }
        $leftDate = tp_sms_receive_absolute_date(is_string($left['message_date']) ? $left['message_date'] : null);
        $rightDate = tp_sms_receive_absolute_date(is_string($right['message_date']) ? $right['message_date'] : null);
        if (($leftDate !== null) !== ($rightDate !== null)) {
            return $leftDate !== null ? -1 : 1;
        }
        if ($leftDate !== null && $rightDate !== null) {
            $date = (float)$leftDate->format('U.u') <=> (float)$rightDate->format('U.u');
            if ($date !== 0) {
                return $date;
            }
        }
        return (int)$left['receive_order'] <=> (int)$right['receive_order'];
    });
    return $rows[0];
}

/** @return array{record_key:string,state:string,failure_code:?string}|null */
function tp_sms_receive_export_one(array $settings): ?array
{
    $target = tp_sms_receive_target($settings);
    $pdo = tp_sms_receive_open($settings);
    tp_sms_receive_assert_binding($pdo, $target);
    $row = tp_sms_receive_next_export($pdo);
    if (!is_array($row)) {
        return null;
    }
    $recordKey = (string)$row['record_key'];
    $lock = tp_sms_receive_inbox_lock($target['path']);
    try {
        if ($row['export_status'] === 'exporting') {
            $fileName = is_string($row['export_file']) && tp_sms_receive_valid_output_name($row['export_file'])
                ? $row['export_file'] : 'sms-' . $recordKey . '.json';
            $finalPath = $target['path'] . DIRECTORY_SEPARATOR . $fileName;
            clearstatcache(true, $finalPath);
            // A published case may have been moved to the trash before recovery.
            if (!file_exists($finalPath) && !is_link($finalPath)) {
                $trashDirectory = $target['path'] . DIRECTORY_SEPARATOR . 'papierkorb';
                clearstatcache(true, $trashDirectory);
                if (is_dir($trashDirectory) && !is_link($trashDirectory)) {
                    $archivedPath = $trashDirectory . DIRECTORY_SEPARATOR . $fileName;
                    clearstatcache(true, $archivedPath);
                    if (is_file($archivedPath) && !is_link($archivedPath)) {
                        $finalPath = $archivedPath;
                    }
                }
            }
            if (!file_exists($finalPath) && !is_link($finalPath)) {
                tp_sms_receive_commit_export($pdo, $recordKey, 'held', 'output_missing_after_interruption');
                return ['record_key' => $recordKey, 'state' => 'held', 'failure_code' => 'output_missing_after_interruption'];
            }
            if (tp_sms_receive_output_matches($finalPath, $recordKey)) {
                tp_sms_receive_confirm_output($finalPath, dirname($finalPath));
                tp_sms_receive_commit_export($pdo, $recordKey, 'exported', null);
                return ['record_key' => $recordKey, 'state' => 'exported', 'failure_code' => null];
            }
            tp_sms_receive_commit_export($pdo, $recordKey, 'held', 'output_collision');
            return ['record_key' => $recordKey, 'state' => 'held', 'failure_code' => 'output_collision'];
        }

        $candidate = tp_sms_receive_find_target(
            $pdo,
            $target['path'],
            is_string($row['phone']) ? $row['phone'] : null,
            $recordKey
        );
        $replace = is_array($candidate);
        $fileName = $replace ? $candidate['file'] : 'sms-' . $recordKey . '.json';
        $replyFirst = !$replace || !$candidate['has_sms'];
        $finalPath = $target['path'] . DIRECTORY_SEPARATOR . $fileName;
        clearstatcache(true, $finalPath);
        if (!$replace && (file_exists($finalPath) || is_link($finalPath))) {
            tp_sms_receive_prepare_export($pdo, $recordKey, $fileName, $replyFirst);
            tp_sms_receive_commit_export($pdo, $recordKey, 'held', 'output_collision');
            return ['record_key' => $recordKey, 'state' => 'held', 'failure_code' => 'output_collision'];
        }
        if ($replace) {
            $payload = tp_sms_receive_append_comment(
                $candidate['data'],
                $recordKey,
                is_string($row['message_text']) ? $row['message_text'] : null,
                is_string($row['message_date']) ? $row['message_date'] : null,
                is_string($row['phone']) ? $row['phone'] : null
            );
        } else {
            $payload = [
                'received_at' => $row['message_date'],
                'remote_ip' => '',
                'user_agent' => 'FRITZ!Box SMS',
                'typ' => 'sonstiges',
                'payload' => [
                    'typ' => 'sonstiges', 'id' => $row['phone'], 'telefon' => $row['phone'],
                    'anliegen' => $row['message_text'], 'quelle' => 'sms',
                ],
                'sms' => ['received_key' => $recordKey, 'device_key' => $row['device_key']],
            ];
        }
        $json = tp_sms_receive_json($payload);
        tp_sms_receive_prepare_export($pdo, $recordKey, $fileName, $replyFirst);
        $published = false;
        try {
            tp_sms_receive_atomic_output($target['path'], $fileName, $json, $replace);
            $published = true;
            tp_sms_receive_commit_export($pdo, $recordKey, 'exported', null);
            return ['record_key' => $recordKey, 'state' => 'exported', 'failure_code' => null];
        } catch (Throwable $exception) {
            if ($published || $exception->getMessage() === 'output_published_uncertain') {
                throw new RuntimeException('SMS-Ausgabe wurde veroeffentlicht, ihr Datenbankstatus ist jedoch unklar.');
            }
            tp_sms_receive_reset_safe_export($pdo, $recordKey);
            return ['record_key' => $recordKey, 'state' => 'held', 'failure_code' => 'output_write_failed'];
        }
    } finally {
        flock($lock['handle'], LOCK_UN);
        fclose($lock['handle']);
    }
}

function tp_sms_receive_normalize_phone(?string $phone, string $countryCode = '49'): ?string
{
    if ($phone === null || !preg_match('/^[1-9][0-9]{0,2}$/D', $countryCode)) {
        return null;
    }
    $phone = trim($phone);
    if ($phone === '' || preg_match('/^[+0-9()\s\/-]+$/Du', $phone) !== 1) {
        return null;
    }
    $parentheses = 0;
    foreach (str_split($phone) as $character) {
        if ($character === '(') {
            if ($parentheses !== 0) {
                return null;
            }
            $parentheses = 1;
        } elseif ($character === ')') {
            if ($parentheses !== 1) {
                return null;
            }
            $parentheses = 0;
        }
    }
    if ($parentheses !== 0) {
        return null;
    }
    $compact = preg_replace('/[\s\/-]+/u', '', $phone);
    if (!is_string($compact)) {
        return null;
    }
    if (preg_match('/^(?:\+|00)' . preg_quote($countryCode, '/') . '\(0\)([1-9][0-9()]*)$/D', $compact, $match)) {
        $compact = '+' . $countryCode . $match[1];
    }
    $compact = str_replace(['(', ')'], '', $compact);
    if (preg_match('/^\+([1-9][0-9]{7,14})$/D', $compact, $match)) {
        return '+' . $match[1];
    }
    if (preg_match('/^00([1-9][0-9]{7,14})$/D', $compact, $match)) {
        return '+' . $match[1];
    }
    if (preg_match('/^0([1-9][0-9]+)$/D', $compact, $match)) {
        $digits = $countryCode . $match[1];
        if (strlen($digits) >= 8 && strlen($digits) <= 15) {
            return '+' . $digits;
        }
    }
    return null;
}

/** Konservative Antwortfreigabe: Deutschland, keine Kurzwahlen oder Dienstkennungen. */
function tp_sms_receive_reply_recipient(?string $phone): ?string
{
    $recipient = tp_sms_receive_normalize_phone($phone, '49');
    // Mindestens acht nationale Ziffern; die Landesvorwahl allein reicht nicht.
    // Unklare/kurze Kennungen bleiben als Eingang erhalten, ohne automatische SMS.
    return $recipient !== null && preg_match('/^\+49[1-9][0-9]{7,12}$/D', $recipient)
        ? $recipient : null;
}

/** @return array{settings:array,messages:array}|null */
function tp_sms_receive_frozen_reply_settings(array $settings, string $snapshotJson): ?array
{
    try {
        $snapshot = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return null;
    }
    if (!is_array($snapshot) || !array_key_exists('messages', $snapshot)
        || !is_array($snapshot['messages']) || !array_is_list($snapshot['messages'])) {
        return null;
    }
    foreach ($snapshot['messages'] as $message) {
        if (!is_string($message) || trim($message) === '') {
            return null;
        }
    }
    if ($snapshot['messages'] !== []
        && (!is_string($snapshot['provider'] ?? null) || !in_array($snapshot['provider'], ['fritz', 'seven'], true)
            || !is_int($snapshot['max_text_length'] ?? null) || $snapshot['max_text_length'] < 0)) {
        return null;
    }
    if ($snapshot['messages'] !== []) {
        try {
            foreach ($snapshot['messages'] as $message) {
                tp_sms_validate_provider_text($snapshot['provider'], $message);
                if ($snapshot['max_text_length'] > 0 && tp_sms_text_length($message) > $snapshot['max_text_length']) {
                    return null;
                }
            }
        } catch (Throwable $exception) {
            return null;
        }
    }
    $frozen = $settings;
    $frozen['auto_reply'] = ['messages' => $snapshot['messages']];
    $frozen['queue'] = is_array($frozen['queue'] ?? null) ? $frozen['queue'] : [];
    if (is_string($snapshot['provider'] ?? null)) {
        $frozen['queue']['delivery_provider'] = $snapshot['provider'];
    }
    $frozen['sms'] = is_array($frozen['sms'] ?? null) ? $frozen['sms'] : [];
    if (is_int($snapshot['max_text_length'] ?? null)) {
        $frozen['sms']['max_text_length'] = $snapshot['max_text_length'];
    }
    return ['settings' => $frozen, 'messages' => $snapshot['messages']];
}

function tp_sms_receive_update_reply(
    PDO $pdo,
    string $recordKey,
    string $status,
    ?string $failureCode,
    int $attempts,
    string $nextAttemptAt
): void {
    $update = $pdo->prepare(
        'UPDATE sms_receive_journal
         SET reply_status = :status, reply_failure_code = :failure_code, reply_attempts = :attempts,
             reply_next_attempt_at = :next_attempt_at, updated_at = :updated_at
         WHERE record_key = :record_key'
    );
    $update->execute([
        ':status' => $status, ':failure_code' => $failureCode, ':attempts' => $attempts,
        ':next_attempt_at' => $nextAttemptAt, ':updated_at' => tp_sms_queue_timestamp(),
        ':record_key' => $recordKey,
    ]);
}

/** @return array{record_key:string,reply_status:string,failure_code:?string}|null */
function tp_sms_receive_reply_one(array $settings): ?array
{
    $target = tp_sms_receive_target($settings);
    $pdo = tp_sms_receive_open($settings);
    tp_sms_receive_assert_binding($pdo, $target);
    $query = $pdo->prepare(
        "SELECT record_key, phone, answerable, reply_snapshot_json, reply_attempts,
                export_file, reply_first
         FROM sms_receive_journal
         WHERE kind = 'received' AND export_status = 'exported' AND reply_status = 'pending'
           AND reply_next_attempt_at <= :now
         ORDER BY receive_order ASC LIMIT 1"
    );
    $query->execute([':now' => tp_sms_queue_timestamp()]);
    $row = $query->fetch();
    $query->closeCursor();
    if (!is_array($row)) {
        return null;
    }
    $recordKey = (string)$row['record_key'];
    $attempts = (int)$row['reply_attempts'];
    if ((int)$row['reply_first'] !== 1) {
        tp_sms_receive_update_reply($pdo, $recordKey, 'skipped', null, $attempts, tp_sms_queue_timestamp());
        return ['record_key' => $recordKey, 'reply_status' => 'skipped', 'failure_code' => null];
    }
    $frozen = tp_sms_receive_frozen_reply_settings($settings, (string)$row['reply_snapshot_json']);
    if ($frozen === null) {
        tp_sms_receive_update_reply($pdo, $recordKey, 'error', 'invalid_reply_configuration', $attempts, tp_sms_queue_timestamp());
        return ['record_key' => $recordKey, 'reply_status' => 'error', 'failure_code' => 'invalid_reply_configuration'];
    }
    if ($frozen['messages'] === []) {
        tp_sms_receive_update_reply($pdo, $recordKey, 'disabled', null, $attempts, tp_sms_queue_timestamp());
        return ['record_key' => $recordKey, 'reply_status' => 'disabled', 'failure_code' => null];
    }
    $recipient = tp_sms_receive_reply_recipient(is_string($row['phone']) ? $row['phone'] : null);
    if ((int)$row['answerable'] !== 1 || $recipient === null) {
        tp_sms_receive_update_reply($pdo, $recordKey, 'skipped', null, $attempts, tp_sms_queue_timestamp());
        return ['record_key' => $recordKey, 'reply_status' => 'skipped', 'failure_code' => null];
    }
    try {
        $result = tp_sms_queue_auto_reply(
            $frozen['settings'],
            $recipient,
            $recordKey,
            [
                'source_file' => is_string($row['export_file']) && tp_sms_receive_valid_output_name($row['export_file'])
                    ? $row['export_file'] : 'sms-' . $recordKey . '.json',
                'workplace' => 'SMS',
            ]
        );
        if (($result['queued'] ?? false) !== true) {
            throw new RuntimeException('queue_not_confirmed');
        }
        tp_sms_receive_update_reply($pdo, $recordKey, 'queued', null, $attempts, tp_sms_queue_timestamp());
        return ['record_key' => $recordKey, 'reply_status' => 'queued', 'failure_code' => null];
    } catch (Throwable $exception) {
        $attempts++;
        tp_sms_receive_update_reply(
            $pdo,
            $recordKey,
            'pending',
            'reply_enqueue_failed',
            $attempts,
            tp_sms_queue_cleanup_retry_at($attempts)
        );
        return ['record_key' => $recordKey, 'reply_status' => 'pending', 'failure_code' => 'reply_enqueue_failed'];
    }
}

function tp_sms_receive_mark_old_cleanup_done(PDO $pdo, string $host, string $uid): void
{
    $update = $pdo->prepare(
        "UPDATE sms_queue_cleanup
         SET status = 'done', failure_code = NULL, updated_at = :updated_at
         WHERE fritzbox_host = :host AND message_uid = :uid AND status = 'pending'
           AND EXISTS (
               SELECT 1 FROM sms_queue AS job
               WHERE job.id = sms_queue_cleanup.job_id AND job.status <> 'sending'
           )"
    );
    $update->execute([':updated_at' => tp_sms_queue_timestamp(), ':host' => $host, ':uid' => $uid]);
}

/** @return array{record_key:string,cleanup_status:string,failure_code:?string}|null */
function tp_sms_receive_cleanup_one(
    array $settings,
    string $deviceKey,
    callable $deleter
): ?array {
    if (!tp_sms_receive_valid_device_key($deviceKey)) {
        throw new RuntimeException('Der SMS-Geraeteschluessel ist ungueltig.');
    }
    $target = tp_sms_receive_target($settings);
    $pdo = tp_sms_receive_open($settings);
    tp_sms_receive_assert_binding($pdo, $target, $deviceKey);
    $query = $pdo->prepare(
        "SELECT record_key, uid, fingerprint, cleanup_attempts
         FROM sms_receive_journal
         WHERE cleanup_status = 'pending' AND uid IS NOT NULL AND cleanup_next_attempt_at <= :now
         ORDER BY cleanup_next_attempt_at ASC, receive_order ASC LIMIT 1"
    );
    $query->execute([':now' => tp_sms_queue_timestamp()]);
    $row = $query->fetch();
    $query->closeCursor();
    if (!is_array($row)) {
        return null;
    }

    $failureCode = null;
    try {
        $result = $deleter($settings, $deviceKey, (string)$row['uid'], (string)$row['fingerprint']);
        if (!is_array($result)
            || (($result['deleted'] ?? false) !== true && ($result['absent'] ?? false) !== true)) {
            $failureCode = 'delete_not_confirmed';
        }
    } catch (Throwable $exception) {
        $failureCode = 'delete_exception';
    }
    $done = $failureCode === null;
    $attempts = (int)$row['cleanup_attempts'] + ($done ? 0 : 1);
    $nextAttempt = $done ? tp_sms_queue_timestamp() : tp_sms_queue_cleanup_retry_at($attempts);
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $update = $pdo->prepare(
            'UPDATE sms_receive_journal
             SET cleanup_status = :status, cleanup_failure_code = :failure_code,
                 cleanup_attempts = :attempts, cleanup_next_attempt_at = :next_attempt_at,
                 updated_at = :updated_at WHERE record_key = :record_key'
        );
        $update->execute([
            ':status' => $done ? 'done' : 'pending', ':failure_code' => $failureCode,
            ':attempts' => $attempts, ':next_attempt_at' => $nextAttempt,
            ':updated_at' => tp_sms_queue_timestamp(), ':record_key' => $row['record_key'],
        ]);
        if ($done) {
            tp_sms_receive_mark_old_cleanup_done($pdo, $target['host'], (string)$row['uid']);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tp_sms_queue_database_error();
    }
    return [
        'record_key' => (string)$row['record_key'],
        'cleanup_status' => $done ? 'done' : 'pending',
        'failure_code' => $failureCode,
    ];
}

function tp_sms_receive_reconcile_absent(array $settings, string $deviceKey, array $entries): void
{
    if (!tp_sms_receive_valid_device_key($deviceKey)) {
        throw new RuntimeException('Der SMS-Geraeteschluessel ist ungueltig.');
    }
    if (!array_is_list($entries)) {
        throw new RuntimeException('Das SMS-Journal muss eine Liste sein.');
    }
    $target = tp_sms_receive_target($settings);
    $pdo = tp_sms_receive_open($settings);
    tp_sms_receive_assert_binding($pdo, $target, $deviceKey);
    $uids = [];
    $recordKeys = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('Ein SMS-Journaleintrag ist ungueltig.');
        }
        if (!is_string($entry['uid'] ?? null) || $entry['uid'] === '') {
            // Mit unbekannten UIDs ist die Abwesenheit alter IDs nicht nachgewiesen.
            return;
        }
        $recordKeys[] = hash('sha256', $deviceKey . ':' . tp_sms_receive_fingerprint($entry));
        if (is_string($entry['uid'] ?? null) && $entry['uid'] !== '') {
            $uids[$entry['uid']] = true;
        }
    }
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $stored = $pdo->prepare(
            'SELECT 1 FROM sms_receive_journal WHERE record_key = :record_key AND device_key = :device_key'
        );
        foreach ($recordKeys as $recordKey) {
            $stored->execute([':record_key' => $recordKey, ':device_key' => $deviceKey]);
            if ($stored->fetchColumn() === false) {
                throw new RuntimeException('snapshot_not_stored');
            }
            $stored->closeCursor();
        }
        $parameters = [':host' => $target['host'], ':updated_at' => tp_sms_queue_timestamp()];
        $notIn = '';
        if ($uids !== []) {
            $placeholders = [];
            foreach (array_keys($uids) as $index => $uid) {
                $name = ':uid_' . $index;
                $placeholders[] = $name;
                $parameters[$name] = $uid;
            }
            $notIn = ' AND cleanup.message_uid NOT IN (' . implode(', ', $placeholders) . ')';
        }
        $update = $pdo->prepare(
            "UPDATE sms_queue_cleanup AS cleanup
             SET status = 'done', failure_code = NULL, updated_at = :updated_at
             WHERE cleanup.fritzbox_host = :host AND cleanup.status = 'pending'" . $notIn . "
               AND EXISTS (
                   SELECT 1 FROM sms_queue AS job
                   WHERE job.id = cleanup.job_id AND job.status <> 'sending'
               )"
        );
        $update->execute($parameters);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tp_sms_queue_database_error();
    }
}

/** @return array<string,int> */
function tp_sms_receive_status_counts(array $settings): array
{
    $counts = [
        'received_pending' => 0,
        'received_exported' => 0,
        'received_held' => 0,
        'reply_pending' => 0,
        'reply_error' => 0,
        'journal_cleanup_pending' => 0,
        'journal_unclassified' => 0,
        'journal_cleanup_failed' => 0,
    ];
    $pdo = tp_sms_queue_open($settings, false, true);
    if (!$pdo instanceof PDO) {
        return $counts;
    }
    try {
        $exists = $pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sms_receive_journal'"
        )->fetchColumn();
        if ($exists === false) {
            return $counts;
        }
        $row = $pdo->query(
            "SELECT
                SUM(CASE WHEN kind = 'received' AND export_status IN ('pending', 'exporting') THEN 1 ELSE 0 END) AS received_pending,
                SUM(CASE WHEN kind = 'received' AND export_status = 'exported' THEN 1 ELSE 0 END) AS received_exported,
                SUM(CASE WHEN kind = 'received' AND export_status = 'held' THEN 1 ELSE 0 END) AS received_held,
                SUM(CASE WHEN reply_status = 'pending' THEN 1 ELSE 0 END) AS reply_pending,
                SUM(CASE WHEN reply_status = 'error' THEN 1 ELSE 0 END) AS reply_error,
                SUM(CASE WHEN cleanup_status = 'pending' THEN 1 ELSE 0 END) AS journal_cleanup_pending,
                SUM(CASE WHEN kind = 'unknown' THEN 1 ELSE 0 END) AS journal_unclassified,
                SUM(CASE WHEN cleanup_status = 'pending' AND cleanup_failure_code IS NOT NULL THEN 1 ELSE 0 END) AS journal_cleanup_failed
             FROM sms_receive_journal"
        )->fetch();
        if (is_array($row)) {
            foreach ($counts as $key => $unused) {
                $counts[$key] = (int)($row[$key] ?? 0);
            }
        }
        return $counts;
    } catch (Throwable $exception) {
        tp_sms_queue_database_error();
    }
}
