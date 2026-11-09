<?php
/**
 * Private credential storage and audit primitives for AIcontrol (PHP 8.2+).
 *
 * The caller supplies an absolute directory outside the document root. Its owner
 * must be the PHP worker; directories are 0700 and state files are 0600. Root's
 * atomic ac_write() / ac_mkdir() helpers are required. A separate, re-entrant
 * lock also serializes reads that append credential-use events.
 *
 * This is tamper-evident relative to the current server checkpoint, not tamper
 * proof: someone controlling this account and its master key can forge history
 * or restore an older log AND checkpoint. External immutable checkpoints would
 * be needed to detect that. A crash between log append and checkpoint write
 * deliberately fails closed; restore a verified, consistent backup to recover.
 * No audit history is silently truncated or automatically repaired.
 *
 * AEAD: PHP sodium XChaCha20-Poly1305, fresh 24-byte nonces; metadata is AAD.
 * TOTP: RFC 6238 / RFC 4226, SHA-1, 6 digits, 30-second periods.
 */

function ac_security_require(): void
{
    if (PHP_INT_SIZE < 8 || !extension_loaded('sodium') ||
        !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        throw new RuntimeException('Security storage requires 64-bit PHP and sodium.');
    }
}

function ac_security_directory(string $dir, bool $create = false): string
{
    $dir = preg_replace('~/+~', '/', rtrim($dir, '/'));
    if ($dir === '' || $dir[0] !== '/' || str_contains($dir, "\0") ||
        preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $dir)) {
        throw new RuntimeException('Invalid private security directory.');
    }
    $cursor = '';
    foreach (explode('/', $dir) as $part) {
        if ($part === '') { continue; }
        $cursor .= '/' . $part;
        clearstatcache(true, $cursor);
        $stat = @lstat($cursor);
        if ($stat !== false && (($stat['mode'] & 0170000) !== 0040000)) {
            throw new RuntimeException('Security directory cannot contain symlinks or non-directories.');
        }
    }
    if (!is_dir($dir)) {
        if (!$create) { throw new RuntimeException('Private security directory is missing.'); }
        ac_mkdir($dir, 0700);
    }
    clearstatcache(true, $dir);
    $stat = @lstat($dir);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 ||
        ($stat['mode'] & 0777) !== 0700 ||
        (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
        throw new RuntimeException('Private security directory ownership or permissions are unsafe.');
    }
    return $dir;
}

/** Returns lstat metadata, or null only when a genuinely absent file is allowed. */
function ac_security_file(string $path, bool $required = true): ?array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat === false) {
        if ($required) { throw new RuntimeException('Required security state is missing.'); }
        return null;
    }
    if (($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600 ||
        $stat['nlink'] !== 1 ||
        (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
        throw new RuntimeException('Security state ownership, permissions or file type are unsafe.');
    }
    return $stat;
}

function ac_security_open(string $path, string $mode)
{
    $before = ac_security_file($path);
    $handle = @fopen($path, $mode);
    if ($handle === false) { throw new RuntimeException('Unable to open security state.'); }
    $after = fstat($handle);
    if ($after === false || $before['dev'] !== $after['dev'] ||
        $before['ino'] !== $after['ino'] || ($after['mode'] & 0777) !== 0600 ||
        ($after['mode'] & 0170000) !== 0100000 || $after['nlink'] !== 1) {
        fclose($handle);
        throw new RuntimeException('Security state changed while being opened.');
    }
    return $handle;
}

function ac_security_read_bytes(string $path, int $limit = 8388608): string
{
    $handle = ac_security_open($path, 'rb');
    try { $bytes = stream_get_contents($handle, $limit + 1); }
    finally { fclose($handle); }
    if ($bytes === false || strlen($bytes) > $limit) {
        throw new RuntimeException('Security state cannot be read safely.');
    }
    return $bytes;
}

function ac_security_write(string $path, #[SensitiveParameter] string $bytes): void
{
    ac_security_file($path, false);
    ac_write($path, $bytes, 0600);
    ac_security_file($path);
}

function ac_security_canonical(mixed $value): mixed
{
    if (!is_array($value)) { return $value; }
    if (!array_is_list($value)) { ksort($value, SORT_STRING); }
    foreach ($value as $key => $entry) { $value[$key] = ac_security_canonical($entry); }
    return $value;
}

function ac_security_json(mixed $value): string
{
    try {
        return json_encode(ac_security_canonical($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Security metadata cannot be encoded.');
    }
}

function ac_security_decode(string $bytes): array
{
    try { $value = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new RuntimeException('Security state is corrupt.'); }
    if (!is_array($value)) { throw new RuntimeException('Security state is corrupt.'); }
    return $value;
}

function ac_security_fields(array $value, array $fields): bool
{
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($fields, SORT_STRING);
    return $actual === $fields;
}

function ac_security_lock(string $dir, callable $callback): mixed
{
    static $held = [];
    $dir = ac_security_directory($dir);
    if (isset($held[$dir])) { return $callback(); }
    $path = $dir . '/security.lock';
    if (ac_security_file($path, false) === null) {
        $mask = umask(0077);
        try { $created = @fopen($path, 'x+b'); }
        finally { umask($mask); }
        if ($created !== false) { fclose($created); }
    }
    $handle = ac_security_open($path, 'r+b');
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Unable to lock security state.');
    }
    $held[$dir] = true;
    try { return $callback(); }
    finally { unset($held[$dir]); flock($handle, LOCK_UN); fclose($handle); }
}

/** Independent, purpose-bound keys; callers erase them in finally blocks. */
function ac_security_keys(string $dir): array
{
    ac_security_require();
    $master = ac_security_read_bytes($dir . '/master.key', 32);
    if (strlen($master) !== 32) { throw new RuntimeException('Master key is corrupt.'); }
    try {
        $keys = [];
        foreach (['encryption', 'audit', 'fingerprint', 'vault-integrity'] as $purpose) {
            $keys[$purpose] = hash_hkdf('sha256', $master, 32,
                'AIcontrol/security/v1/' . $purpose, 'AIcontrol/security/v1');
        }
        return $keys;
    } finally { sodium_memzero($master); }
}

function ac_security_erase_keys(#[SensitiveParameter] array &$keys): void
{
    foreach ($keys as &$key) { if (is_string($key)) { sodium_memzero($key); } }
    unset($key);
    $keys = [];
}

function ac_security_id(string $id): string
{
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/D', $id)) {
        throw new InvalidArgumentException('Invalid credential identifier.');
    }
    return $id;
}

function ac_security_provider(string $provider): string
{
    if (!preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $provider)) {
        throw new InvalidArgumentException('Invalid credential provider.');
    }
    return $provider;
}

function ac_security_label(string $label): string
{
    $label = trim($label);
    if ($label === '' || strlen($label) > 160 ||
        preg_match('/[\x00-\x1f\x7f]/', $label) || preg_match('//u', $label) !== 1) {
        throw new InvalidArgumentException('Invalid credential label.');
    }
    return $label;
}

/** Event and actor are identifiers, never request bodies or user-supplied secrets. */
function ac_security_identity(string $value, string $kind): string
{
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:@+\/ -]{0,127}\z/D', $value)) {
        throw new InvalidArgumentException('Invalid audit ' . $kind . ' identifier.');
    }
    return $value;
}

/**
 * Drop all unknown/nested detail fields. Never pass passwords, credential values,
 * HTTP headers, raw requests, raw errors or response bodies to the audit API.
 */
function ac_security_audit_details(array $details): array
{
    $strings = ['id', 'credential_id', 'provider', 'purpose', 'operation', 'result',
        'reason', 'status', 'method', 'type', 'fingerprint', 'sha256', 'vault_hash',
        'commit', 'request_id', 'run_id', 'release', 'tag'];
    $integers = ['version', 'revision', 'count', 'bytes', 'duration_ms', 'http_status'];
    $booleans = ['success', 'replayed'];
    $safe = [];
    foreach ($details as $key => $value) {
        if (in_array($key, $strings, true) && is_string($value) && strlen($value) <= 128 &&
            preg_match('/\A[A-Za-z0-9_.:@+\/ -]*\z/D', $value)) {
            $safe[$key] = $value;
        } elseif (in_array($key, $integers, true) && is_int($value) && $value >= 0) {
            $safe[$key] = $value;
        } elseif (in_array($key, $booleans, true) && is_bool($value)) {
            $safe[$key] = $value;
        }
    }
    ksort($safe, SORT_STRING);
    return $safe;
}

function ac_security_checkpoint(int $count, string $head, #[SensitiveParameter] string $key): array
{
    $payload = ['schema' => 1, 'count' => $count, 'head' => $head];
    return $payload + ['mac' => hash_hmac('sha256', "audit-checkpoint-v1\0" . ac_security_json($payload), $key)];
}

/** Fully verify the chain AND its current checkpoint before returning any rows. */
function ac_security_audit_scan(string $dir, #[SensitiveParameter] array $keys, int $limit = 0): array
{
    $checkpoint = ac_security_decode(ac_security_read_bytes($dir . '/audit.head', 4096));
    if (!ac_security_fields($checkpoint, ['schema', 'count', 'head', 'mac']) ||
        $checkpoint['schema'] !== 1 || !is_int($checkpoint['count']) || $checkpoint['count'] < 0 ||
        !is_string($checkpoint['head']) || !preg_match('/\A[a-f0-9]{64}\z/D', $checkpoint['head']) ||
        !is_string($checkpoint['mac'])) {
        throw new RuntimeException('Audit checkpoint is corrupt.');
    }
    $expected = ac_security_checkpoint($checkpoint['count'], $checkpoint['head'], $keys['audit']);
    if (!hash_equals($expected['mac'], $checkpoint['mac'])) {
        throw new RuntimeException('Audit checkpoint authentication failed.');
    }
    $count = 0;
    $head = str_repeat('0', 64);
    $tail = [];
    $handle = ac_security_open($dir . '/audit.jsonl', 'rb');
    try {
        while (($line = fgets($handle, 16385)) !== false) {
            if (!str_ends_with($line, "\n") || strlen($line) > 16384) {
                throw new RuntimeException('Audit history contains an incomplete or oversized record.');
            }
            $row = ac_security_decode(substr($line, 0, -1));
            if (!ac_security_fields($row, ['seq', 'at', 'event', 'actor', 'details', 'prev', 'mac']) ||
                $row['seq'] !== $count + 1 || !is_string($row['at']) ||
                !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $row['at']) ||
                !is_string($row['event']) || !is_string($row['actor']) ||
                !is_array($row['details']) || !is_string($row['prev']) ||
                !is_string($row['mac']) || !hash_equals($head, $row['prev']) ||
                !hash_equals(ac_security_json($row) . "\n", $line)) {
                throw new RuntimeException('Audit history is corrupt.');
            }
            ac_security_identity($row['event'], 'event');
            ac_security_identity($row['actor'], 'actor');
            if ($row['details'] !== ac_security_audit_details($row['details'])) {
                throw new RuntimeException('Audit history contains invalid metadata.');
            }
            $payload = $row;
            unset($payload['mac']);
            $mac = hash_hmac('sha256', "audit-record-v1\0" . ac_security_json($payload), $keys['audit']);
            if (!hash_equals($mac, $row['mac'])) {
                throw new RuntimeException('Audit history authentication failed.');
            }
            $head = $mac;
            ++$count;
            if ($limit > 0) {
                $tail[] = $row;
                if (count($tail) > $limit) { array_shift($tail); }
            }
        }
        if (!feof($handle)) { throw new RuntimeException('Audit history could not be read completely.'); }
    } finally { fclose($handle); }
    if ($count !== $checkpoint['count'] || !hash_equals($head, $checkpoint['head'])) {
        throw new RuntimeException('Audit history does not match its checkpoint; restore a verified backup.');
    }
    return ['ok' => true, 'count' => $count, 'head' => $head, 'tail' => array_reverse($tail)];
}

function ac_security_audit_append(string $dir, string $event, string $actor, array $details,
    #[SensitiveParameter] array $keys): void
{
    $event = ac_security_identity($event, 'event');
    $actor = ac_security_identity($actor, 'actor');
    $state = ac_security_audit_scan($dir, $keys);
    $row = ['seq' => $state['count'] + 1, 'at' => gmdate('Y-m-d\TH:i:s\Z'),
        'event' => $event, 'actor' => $actor, 'details' => ac_security_audit_details($details),
        'prev' => $state['head']];
    $row['mac'] = hash_hmac('sha256', "audit-record-v1\0" . ac_security_json($row), $keys['audit']);
    $line = ac_security_json($row) . "\n";
    if (strlen($line) > 16384) { throw new RuntimeException('Audit metadata is too large.'); }
    $handle = ac_security_open($dir . '/audit.jsonl', 'ab');
    try {
        $offset = 0;
        while ($offset < strlen($line)) {
            $written = fwrite($handle, substr($line, $offset));
            if ($written === false || $written === 0) { throw new RuntimeException('Audit append failed.'); }
            $offset += $written;
        }
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
            throw new RuntimeException('Audit persistence failed.');
        }
    } finally { fclose($handle); }
    ac_security_write($dir . '/audit.head', ac_security_json(
        ac_security_checkpoint($row['seq'], $row['mac'], $keys['audit'])));
}

function ac_audit(string $dir, string $event, string $actor, array $details = []): void
{
    ac_security_lock($dir, function () use ($dir, $event, $actor, $details): void {
        $keys = ac_security_keys($dir);
        try { ac_security_audit_append($dir, $event, $actor, $details, $keys); }
        finally { ac_security_erase_keys($keys); }
    });
}

function ac_audit_verify(string $dir): array
{
    try {
        return ac_security_lock($dir, function () use ($dir): array {
            $keys = ac_security_keys($dir);
            try {
                $state = ac_security_audit_scan($dir, $keys);
                unset($state['tail']);
                return $state;
            } finally { ac_security_erase_keys($keys); }
        });
    } catch (Throwable $error) { return ['ok' => false, 'count' => 0, 'head' => '']; }
}

/** Verified metadata only, newest first. */
function ac_audit_tail(string $dir, int $limit = 50): array
{
    if ($limit < 0 || $limit > 500) { throw new InvalidArgumentException('Invalid audit result limit.'); }
    return ac_security_lock($dir, function () use ($dir, $limit): array {
        $keys = ac_security_keys($dir);
        try { return ac_security_audit_scan($dir, $keys, $limit)['tail']; }
        finally { ac_security_erase_keys($keys); }
    });
}

function ac_security_pack_vault(array $payload, #[SensitiveParameter] array $keys): string
{
    unset($payload['mac']);
    $payload['mac'] = hash_hmac('sha256', "vault-v1\0" . ac_security_json($payload), $keys['vault-integrity']);
    return ac_security_json($payload);
}

function ac_security_metadata(array $record): array
{
    $metadata = [];
    foreach (['id', 'provider', 'label', 'version', 'created_at', 'updated_at', 'fingerprint'] as $field) {
        $metadata[$field] = $record[$field];
    }
    return $metadata;
}

function ac_security_aad(array $record): string
{
    return "AIcontrol/vault-record/v1\0" . ac_security_json(ac_security_metadata($record));
}

function ac_security_decrypt_record(array $record, #[SensitiveParameter] array $keys): string
{
    $nonce = base64_decode($record['nonce'], true);
    $ciphertext = base64_decode($record['ciphertext'], true);
    if ($nonce === false || $ciphertext === false || strlen($nonce) !== 24 ||
        strlen($ciphertext) < 17 || strlen($ciphertext) > 65552 ||
        base64_encode($nonce) !== $record['nonce'] || base64_encode($ciphertext) !== $record['ciphertext']) {
        throw new RuntimeException('Vault ciphertext is corrupt.');
    }
    $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        $ciphertext, ac_security_aad($record), $nonce, $keys['encryption']);
    if ($plain === false) { throw new RuntimeException('Vault credential authentication failed.'); }
    $fingerprint = 'hmac-sha256:' . substr(hash_hmac('sha256',
        "credential-v1\0" . $record['provider'] . "\0" . $plain, $keys['fingerprint']), 0, 32);
    if (!hash_equals($fingerprint, $record['fingerprint'])) {
        sodium_memzero($plain);
        throw new RuntimeException('Vault credential fingerprint is corrupt.');
    }
    return $plain;
}

function ac_security_load_vault(string $dir, #[SensitiveParameter] array $keys): array
{
    $vault = ac_security_decode(ac_security_read_bytes($dir . '/vault.json'));
    if (!ac_security_fields($vault, ['schema', 'revision', 'records', 'mac']) ||
        $vault['schema'] !== 1 || !is_int($vault['revision']) || $vault['revision'] < 0 ||
        !is_array($vault['records']) || !is_string($vault['mac'])) {
        throw new RuntimeException('Vault structure is corrupt.');
    }
    $mac = $vault['mac'];
    unset($vault['mac']);
    $expected = hash_hmac('sha256', "vault-v1\0" . ac_security_json($vault), $keys['vault-integrity']);
    if (!hash_equals($expected, $mac)) { throw new RuntimeException('Vault authentication failed.'); }
    foreach ($vault['records'] as $id => $record) {
        if (!is_array($record) || !ac_security_fields($record, ['id', 'provider', 'label', 'version',
            'created_at', 'updated_at', 'fingerprint', 'nonce', 'ciphertext']) ||
            !is_string($record['id']) || (string)$id !== $record['id'] ||
            !is_string($record['provider']) || !is_string($record['label']) ||
            !is_int($record['version']) || $record['version'] < 1 ||
            !is_string($record['created_at']) || !is_string($record['updated_at']) ||
            !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $record['created_at']) ||
            !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $record['updated_at']) ||
            !is_string($record['fingerprint']) ||
            !preg_match('/\Ahmac-sha256:[a-f0-9]{32}\z/D', $record['fingerprint']) ||
            !is_string($record['nonce']) || !is_string($record['ciphertext'])) {
            throw new RuntimeException('Vault record structure is corrupt.');
        }
        ac_security_id($record['id']);
        ac_security_provider($record['provider']);
        ac_security_label($record['label']);
        // Verify every encrypted record, even when a caller only asks for a list.
        $plain = ac_security_decrypt_record($record, $keys);
        sodium_memzero($plain);
    }
    return $vault;
}

/** Explicit setup only. Never silently replace a lost master key. */
function ac_vault_init(string $dir): void
{
    ac_security_require();
    $dir = ac_security_directory($dir, true);
    ac_security_lock($dir, function () use ($dir): void {
        $masterExists = ac_security_file($dir . '/master.key', false) !== null;
        $present = 0;
        foreach (['vault.json', 'audit.jsonl', 'audit.head'] as $file) {
            if (ac_security_file($dir . '/' . $file, false) !== null) { ++$present; }
        }
        if (!$masterExists && $present !== 0) {
            throw new RuntimeException('Master key is missing; restore it from a verified backup.');
        }
        if ($masterExists) {
            if ($present !== 3) { throw new RuntimeException('Security initialization is incomplete; restore a verified backup.'); }
            $keys = ac_security_keys($dir);
            try { ac_security_audit_scan($dir, $keys); ac_security_load_vault($dir, $keys); }
            finally { ac_security_erase_keys($keys); }
            return;
        }
        $master = random_bytes(32);
        try { ac_security_write($dir . '/master.key', $master); }
        finally { sodium_memzero($master); }
        $keys = ac_security_keys($dir);
        try {
            ac_security_write($dir . '/vault.json', ac_security_pack_vault(
                ['schema' => 1, 'revision' => 0, 'records' => []], $keys));
            ac_security_write($dir . '/audit.jsonl', '');
            ac_security_write($dir . '/audit.head', ac_security_json(
                ac_security_checkpoint(0, str_repeat('0', 64), $keys['audit'])));
            ac_security_audit_append($dir, 'vault.initialized', 'setup', [], $keys);
        } finally { ac_security_erase_keys($keys); }
    });
}

function ac_vault_put(string $dir, string $id, string $provider, string $label,
    #[SensitiveParameter] string $plaintext, string $actor): array
{
    ac_security_id($id);
    ac_security_provider($provider);
    $label = ac_security_label($label);
    if ($plaintext === '' || strlen($plaintext) > 65536) {
        throw new InvalidArgumentException('Credential must contain between 1 and 65536 bytes.');
    }
    return ac_security_lock($dir, function () use ($dir, $id, $provider, $label, $plaintext, $actor): array {
        $keys = ac_security_keys($dir);
        try {
            ac_security_audit_scan($dir, $keys);
            $vault = ac_security_load_vault($dir, $keys);
            $old = $vault['records'][$id] ?? null;
            if ($old !== null && $old['provider'] !== $provider) {
                throw new InvalidArgumentException('Credential provider cannot change during rotation.');
            }
            if ($vault['revision'] === PHP_INT_MAX || ($old !== null && $old['version'] === PHP_INT_MAX)) {
                throw new RuntimeException('Vault revision limit reached.');
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $record = ['id' => $id, 'provider' => $provider, 'label' => $label,
                'version' => $old === null ? 1 : $old['version'] + 1,
                'created_at' => $old['created_at'] ?? $now, 'updated_at' => $now,
                'fingerprint' => 'hmac-sha256:' . substr(hash_hmac('sha256',
                    "credential-v1\0" . $provider . "\0" . $plaintext, $keys['fingerprint']), 0, 32)];
            $nonce = random_bytes(24);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext, ac_security_aad($record), $nonce, $keys['encryption']);
            $record['nonce'] = base64_encode($nonce);
            $record['ciphertext'] = base64_encode($ciphertext);
            $vault['records'][$id] = $record;
            ++$vault['revision'];
            $event = $old === null ? 'vault.created' : 'vault.rotated';
            $details = ['id' => $id, 'provider' => $provider, 'version' => $record['version'],
                'fingerprint' => $record['fingerprint'], 'revision' => $vault['revision']];
            // Record an intent before changing state, then record completion.
            ac_security_audit_append($dir, $event, $actor, $details + ['status' => 'prepared'], $keys);
            ac_security_write($dir . '/vault.json', ac_security_pack_vault($vault, $keys));
            ac_security_audit_append($dir, $event, $actor, $details + ['status' => 'committed'], $keys);
            return ac_security_metadata($record);
        } finally { ac_security_erase_keys($keys); }
    });
}

function ac_vault_list(string $dir): array
{
    return ac_security_lock($dir, function () use ($dir): array {
        $keys = ac_security_keys($dir);
        try {
            ac_security_audit_scan($dir, $keys);
            $vault = ac_security_load_vault($dir, $keys);
            $result = array_map('ac_security_metadata', array_values($vault['records']));
            usort($result, fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
            return $result;
        } finally { ac_security_erase_keys($keys); }
    });
}

/** Internal use only: never expose the return value in HTTP responses or logs. */
function ac_vault_get(string $dir, string $id, string $actor, string $purpose): string
{
    ac_security_id($id);
    ac_security_identity($purpose, 'purpose');
    return ac_security_lock($dir, function () use ($dir, $id, $actor, $purpose): string {
        $keys = ac_security_keys($dir);
        $plain = null;
        try {
            ac_security_audit_scan($dir, $keys);
            $vault = ac_security_load_vault($dir, $keys);
            if (!isset($vault['records'][$id])) { throw new RuntimeException('Credential was not found.'); }
            $record = $vault['records'][$id];
            $plain = ac_security_decrypt_record($record, $keys);
            ac_security_audit_append($dir, 'vault.used', $actor,
                ['id' => $id, 'provider' => $record['provider'], 'version' => $record['version'],
                    'purpose' => $purpose, 'fingerprint' => $record['fingerprint']], $keys);
            return $plain;
        } finally {
            if (is_string($plain)) { sodium_memzero($plain); }
            ac_security_erase_keys($keys);
        }
    });
}

function ac_vault_delete(string $dir, string $id, string $actor): void
{
    ac_security_id($id);
    ac_security_lock($dir, function () use ($dir, $id, $actor): void {
        $keys = ac_security_keys($dir);
        try {
            ac_security_audit_scan($dir, $keys);
            $vault = ac_security_load_vault($dir, $keys);
            if (!isset($vault['records'][$id])) { throw new RuntimeException('Credential was not found.'); }
            if ($vault['revision'] === PHP_INT_MAX) { throw new RuntimeException('Vault revision limit reached.'); }
            $record = $vault['records'][$id];
            $details = ['id' => $id, 'provider' => $record['provider'], 'version' => $record['version'],
                'fingerprint' => $record['fingerprint'], 'revision' => $vault['revision'] + 1];
            ac_security_audit_append($dir, 'vault.deleted', $actor, $details + ['status' => 'prepared'], $keys);
            unset($vault['records'][$id]);
            ++$vault['revision'];
            ac_security_write($dir . '/vault.json', ac_security_pack_vault($vault, $keys));
            ac_security_audit_append($dir, 'vault.deleted', $actor, $details + ['status' => 'committed'], $keys);
        } finally { ac_security_erase_keys($keys); }
    });
}

function ac_vault_generate(string $dir, string $id, string $label, string $actor): array
{
    $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    try { return ac_vault_put($dir, $id, 'generated', $label, $secret, $actor); }
    finally { sodium_memzero($secret); }
}

function ac_totp_new_secret(): string
{
    ac_security_require();
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bytes = random_bytes(20);
    $buffer = 0;
    $bits = 0;
    $result = '';
    foreach (unpack('C*', $bytes) as $byte) {
        $buffer = ($buffer << 8) | $byte;
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $result .= $alphabet[($buffer >> $bits) & 31];
        }
        $buffer &= (1 << $bits) - 1;
    }
    if ($bits > 0) { $result .= $alphabet[($buffer << (5 - $bits)) & 31]; }
    sodium_memzero($bytes);
    return $result;
}

function ac_security_base32_decode(#[SensitiveParameter] string $secret): string
{
    $secret = strtoupper($secret);
    if (strlen($secret) > 256 || !preg_match('/\A[A-Z2-7]+={0,6}\z/D', $secret)) {
        throw new InvalidArgumentException('Invalid authenticator secret.');
    }
    $secret = rtrim($secret, '=');
    if (!in_array(strlen($secret) % 8, [0, 2, 4, 5, 7], true)) {
        throw new InvalidArgumentException('Invalid authenticator secret.');
    }
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $decoded = '';
    for ($i = 0; $i < strlen($secret); ++$i) {
        $buffer = ($buffer << 5) | strpos($alphabet, $secret[$i]);
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $decoded .= chr(($buffer >> $bits) & 255);
        }
        $buffer &= (1 << $bits) - 1;
    }
    if ($decoded === '' || $buffer !== 0) { throw new InvalidArgumentException('Invalid authenticator secret.'); }
    return $decoded;
}

function ac_totp_code(#[SensitiveParameter] string $secret, int $counter): string
{
    ac_security_require();
    if (PHP_INT_SIZE < 8 || $counter < 0) { throw new InvalidArgumentException('Invalid authenticator counter.'); }
    $key = ac_security_base32_decode($secret);
    try {
        $message = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $message, $key, true);
        $offset = ord($hash[19]) & 15;
        $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
    } finally { sodium_memzero($key); }
}

/** Caller must atomically persist the returned counter before accepting login. */
function ac_totp_verify(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code,
    int $now, ?int $lastCounter): ?int
{
    if ($now < 0 || !preg_match('/\A[0-9]{6}\z/D', $code)) { return null; }
    $lastCounter ??= -1;
    $current = intdiv($now, 30);
    $match = null;
    foreach ([-1, 0, 1] as $offset) {
        $counter = $current + $offset;
        if ($counter < 0) { continue; }
        $candidate = ac_totp_code($secret, $counter);
        if (hash_equals($candidate, $code) && $counter > $lastCounter) { $match = $counter; }
    }
    return $match;
}
