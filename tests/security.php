<?php
/** Isolated security tests; root supplies helpers and loads src/security.php. */
function ac_test_security(string $tempdir, callable $assert): void
{
    $throws = static function (callable $call): bool {
        try { $call(); return false; }
        catch (Throwable $error) { return true; }
    };
    $assert(extension_loaded('sodium'), 'Security: sodium is available.');
    if (!extension_loaded('sodium')) { return; }
    $base = rtrim($tempdir, '/') . '/security-' . bin2hex(random_bytes(6));
    ac_mkdir($base, 0700);
    $dir = $base . '/main';
    ac_vault_init($dir);
    $assert(strlen(file_get_contents($dir . '/master.key')) === 32, 'Vault: 32-byte master key.');
    $assert((fileperms($dir . '/master.key') & 0777) === 0600, 'Vault: master key permissions are 0600.');
    $assert((fileperms($dir) & 0777) === 0700, 'Vault: private directory permissions are 0700.');
    $initialKey = file_get_contents($dir . '/master.key');
    ac_vault_init($dir);
    $assert(hash_equals($initialKey, file_get_contents($dir . '/master.key')), 'Vault: setup is idempotent and preserves master key.');

    $secret = 'TEST-ONLY-secret-' . bin2hex(random_bytes(24));
    $meta = ac_vault_put($dir, 'openai-main', 'openai', 'Primary OpenAI account', $secret, 'test');
    $assert($meta['id'] === 'openai-main' && $meta['version'] === 1 && $meta['provider'] === 'openai',
        'Vault: first version metadata returned.');
    $assert(array_keys($meta) === ['id', 'provider', 'label', 'version', 'created_at', 'updated_at', 'fingerprint'],
        'Vault: returned metadata has no secret or ciphertext fields.');
    $assert(ac_vault_get($dir, 'openai-main', 'test', 'roundtrip') === $secret, 'Vault: authenticated roundtrip.');
    $listed = ac_vault_list($dir);
    $assert(count($listed) === 1 && $listed[0]['fingerprint'] === $meta['fingerprint'], 'Vault: metadata-only list.');
    $assert(!str_contains(file_get_contents($dir . '/vault.json'), $secret), 'Vault: plaintext is absent from disk representation.');
    $assert(!str_contains(json_encode($listed), $secret), 'Vault: plaintext is absent from public metadata.');

    $raw = ac_security_decode(file_get_contents($dir . '/vault.json'));
    $record = $raw['records']['openai-main'];
    $keys = ac_security_keys($dir);
    try {
        $assert(count(array_unique(array_values($keys))) === 4, 'Vault: purpose keys are independent.');
        $assert(ac_security_decrypt_record($record, $keys) === $secret, 'Vault: direct record authentication succeeds.');
        foreach (['id' => 'another-id', 'provider' => 'github', 'version' => 2] as $field => $value) {
            $wrong = $record;
            $wrong[$field] = $value;
            $assert($throws(fn() => ac_security_decrypt_record($wrong, $keys)),
                'Vault: ciphertext is bound to ' . $field . ' context.');
        }
        $wrong = $record;
        $wrong['label'] = 'Changed label';
        $assert($throws(fn() => ac_security_decrypt_record($wrong, $keys)), 'Vault: label metadata is authenticated.');
    } finally { ac_security_erase_keys($keys); }

    $secondSecret = 'TEST-ONLY-rotated-' . bin2hex(random_bytes(24));
    $second = ac_vault_put($dir, 'openai-main', 'openai', 'Primary account rotated', $secondSecret, 'test');
    $assert($second['version'] === 2 && $second['created_at'] === $meta['created_at'], 'Vault: rotation increments version and preserves creation time.');
    $assert($second['fingerprint'] !== $meta['fingerprint'], 'Vault: fingerprint changes with the credential.');
    $assert(ac_vault_get($dir, 'openai-main', 'test', 'rotation-check') === $secondSecret, 'Vault: rotated credential works.');
    $assert($throws(fn() => ac_vault_put($dir, 'openai-main', 'github', 'Wrong provider', 'abc', 'test')),
        'Vault: provider cannot silently change during rotation.');

    $generated = ac_vault_generate($dir, 'internal-hook', 'Internal generated credential', 'test');
    $assert($generated['provider'] === 'generated' && !array_key_exists('secret', $generated),
        'Vault: generated credential only returns metadata.');
    $generatedValue = ac_vault_get($dir, 'internal-hook', 'test', 'internal-verification');
    $assert(strlen($generatedValue) === 43 && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $generatedValue) === 1,
        'Vault: generated secret contains 256 random bits encoded as base64url.');
    $assert(!str_contains(file_get_contents($dir . '/vault.json'), $generatedValue), 'Vault: generated secret is stored encrypted.');
    $assert(!str_contains(json_encode($generated), $generatedValue), 'Vault: generated value is never returned by generation.');

    ac_audit($dir, 'test.redaction', 'test', [
        'provider' => 'openai', 'id' => 'openai-main', 'success' => true,
        'password' => $secret, 'token' => $secondSecret, 'authorization' => 'Bearer ' . $secret,
        'plaintext' => $generatedValue, 'message' => $secret, 'body' => ['credential' => $secret],
        'purpose' => ['nested' => $secret],
    ]);
    $tail = ac_audit_tail($dir, 1);
    $assert(count($tail) === 1 && $tail[0]['event'] === 'test.redaction', 'Audit: tail is newest first.');
    $assert($tail[0]['details'] === ['id' => 'openai-main', 'provider' => 'openai', 'success' => true],
        'Audit: only allowlisted scalar metadata is retained.');
    $history = file_get_contents($dir . '/audit.jsonl');
    foreach ([$secret, $secondSecret, $generatedValue] as $privateValue) {
        $assert(!str_contains($history, $privateValue), 'Audit: plaintext secrets are absent from records.');
        $assert(!str_contains(file_get_contents($dir . '/audit.head'), $privateValue), 'Audit: plaintext secrets are absent from checkpoint.');
    }
    $good = ac_audit_verify($dir);
    $assert($good['ok'] === true && $good['count'] >= 8 && strlen($good['head']) === 64,
        'Audit: intact chain and checkpoint verify.');
    $assert(count(ac_audit_tail($dir, 0)) === 0, 'Audit: requesting zero rows returns zero rows.');
    $assert($throws(fn() => ac_audit_tail($dir, 501)), 'Audit: row count is bounded.');

    // Tampering with ciphertext must fail before returning any credential or list.
    $vaultBackup = file_get_contents($dir . '/vault.json');
    $tampered = ac_security_decode($vaultBackup);
    $cipher = $tampered['records']['openai-main']['ciphertext'];
    $cipher[0] = $cipher[0] === 'A' ? 'B' : 'A';
    $tampered['records']['openai-main']['ciphertext'] = $cipher;
    ac_write($dir . '/vault.json', ac_security_json($tampered), 0600);
    $assert($throws(fn() => ac_vault_get($dir, 'openai-main', 'test', 'tamper-check')), 'Vault: modified ciphertext fails closed.');
    $assert($throws(fn() => ac_vault_list($dir)), 'Vault: corrupted state cannot be listed.');
    ac_write($dir . '/vault.json', $vaultBackup, 0600);

    // Stronger test: an otherwise correctly MACed envelope still cannot transplant
    // ciphertext into another AAD context without its AEAD key being used.
    $keys = ac_security_keys($dir);
    try {
        $tampered = ac_security_decode($vaultBackup);
        unset($tampered['mac']);
        $tampered['records']['openai-main']['version'] = 99;
        ac_write($dir . '/vault.json', ac_security_pack_vault($tampered, $keys), 0600);
        $assert($throws(fn() => ac_vault_get($dir, 'openai-main', 'test', 'aad-transplant')),
            'Vault: authenticated outer envelope cannot bypass record AAD binding.');
    } finally {
        ac_security_erase_keys($keys);
        ac_write($dir . '/vault.json', $vaultBackup, 0600);
    }

    // Tamper with an audit entry while leaving its authentication and head intact.
    $auditBackup = file_get_contents($dir . '/audit.jsonl');
    $headBackup = file_get_contents($dir . '/audit.head');
    $lines = explode("\n", rtrim($auditBackup, "\n"));
    $changed = ac_security_decode($lines[0]);
    $changed['actor'] = 'another-actor';
    $lines[0] = ac_security_json($changed);
    ac_write($dir . '/audit.jsonl', implode("\n", $lines) . "\n", 0600);
    $assert(ac_audit_verify($dir)['ok'] === false, 'Audit: modified row is detected.');
    $assert($throws(fn() => ac_audit($dir, 'test.after-tamper', 'test')), 'Audit: append refuses a damaged chain.');
    $assert($throws(fn() => ac_vault_get($dir, 'openai-main', 'test', 'damaged-audit')),
        'Vault: no credential is released if its use cannot be audited.');
    ac_write($dir . '/audit.jsonl', $auditBackup, 0600);

    $lines = explode("\n", rtrim($auditBackup, "\n"));
    array_pop($lines);
    ac_write($dir . '/audit.jsonl', implode("\n", $lines) . "\n", 0600);
    $assert(ac_audit_verify($dir)['ok'] === false, 'Audit: truncation is detected against the preserved checkpoint.');
    $assert($throws(fn() => ac_audit_tail($dir)), 'Audit: tail refuses a truncated log.');
    ac_write($dir . '/audit.jsonl', $auditBackup, 0600);

    $head = ac_security_decode($headBackup);
    ++$head['count'];
    ac_write($dir . '/audit.head', ac_security_json($head), 0600);
    $assert(ac_audit_verify($dir)['ok'] === false, 'Audit: modified checkpoint is detected.');
    ac_write($dir . '/audit.head', $headBackup, 0600);
    $assert(ac_audit_verify($dir)['ok'] === true, 'Audit: verified backup restores a consistent chain.');

    ac_vault_delete($dir, 'internal-hook', 'test');
    $assert(count(ac_vault_list($dir)) === 1, 'Vault: deletion removes the credential.');
    $assert($throws(fn() => ac_vault_get($dir, 'internal-hook', 'test', 'deleted-check')), 'Vault: deleted credential cannot be used.');
    $assert($throws(fn() => ac_vault_put($dir, '../escape', 'openai', 'Bad ID', 'abc', 'test')), 'Vault: path-like credential IDs are rejected.');
    $assert($throws(fn() => ac_vault_put($dir, 'empty', 'openai', 'Empty credential', '', 'test')), 'Vault: empty credentials are rejected.');

    $lost = $base . '/lost-master';
    ac_vault_init($lost);
    unlink($lost . '/master.key');
    $assert($throws(fn() => ac_vault_init($lost)), 'Vault: existing history without its master cannot be reset.');
    $assert(!file_exists($lost . '/master.key'), 'Vault: missing master is never regenerated over old state.');

    $unsafe = $base . '/unsafe';
    ac_vault_init($unsafe);
    chmod($unsafe . '/master.key', 0644);
    $assert($throws(fn() => ac_vault_list($unsafe)), 'Vault: publicly readable key file is rejected.');
    chmod($unsafe . '/master.key', 0600);
    chmod($unsafe, 0755);
    $assert($throws(fn() => ac_vault_list($unsafe)), 'Vault: publicly accessible state directory is rejected.');
    chmod($unsafe, 0700);
    if (function_exists('symlink')) {
        $link = $base . '/state-link';
        if (symlink($unsafe, $link)) {
            $assert($throws(fn() => ac_vault_list($link)), 'Vault: symlinked state directory is rejected.');
            unlink($link);
        }
        $masterBytes = file_get_contents($unsafe . '/master.key');
        ac_write($base . '/elsewhere.key', $masterBytes, 0600);
        unlink($unsafe . '/master.key');
        if (symlink($base . '/elsewhere.key', $unsafe . '/master.key')) {
            $assert($throws(fn() => ac_vault_list($unsafe)), 'Vault: symlinked key file is rejected.');
            unlink($unsafe . '/master.key');
        }
        ac_write($unsafe . '/master.key', $masterBytes, 0600);
    }

    // RFC 6238 Appendix B uses eight digits; six-digit outputs are its last six.
    $rfcSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    $vectors = [59 => '287082', 1111111109 => '081804', 1111111111 => '050471',
        1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'];
    foreach ($vectors as $time => $expected) {
        $assert(ac_totp_code($rfcSecret, intdiv($time, 30)) === $expected,
            'TOTP: RFC SHA-1 vector at ' . $time . ' seconds.');
    }
    $assert(ac_totp_verify($rfcSecret, '287082', 59, null) === 1, 'TOTP: current code is accepted.');
    $assert(ac_totp_verify($rfcSecret, '287082', 59, 1) === null, 'TOTP: replay of accepted counter is rejected.');
    $previousCode = ac_totp_code($rfcSecret, 9);
    $nextCode = ac_totp_code($rfcSecret, 11);
    $assert(ac_totp_verify($rfcSecret, $previousCode, 300, 8) === 9, 'TOTP: previous step is accepted within clock window.');
    $assert(ac_totp_verify($rfcSecret, $nextCode, 300, 9) === 11, 'TOTP: next step is accepted within clock window.');
    $assert(ac_totp_verify($rfcSecret, ac_totp_code($rfcSecret, 10), 300, 11) === null,
        'TOTP: earlier counters cannot be reused after future-window acceptance.');
    $assert(ac_totp_verify($rfcSecret, $previousCode, 390, null) === null, 'TOTP: expired code is rejected.');
    $assert(ac_totp_verify($rfcSecret, '287082x', 59, null) === null, 'TOTP: non-six-digit input is rejected.');
    $assert(ac_totp_verify($rfcSecret, ' 287082', 59, null) === null, 'TOTP: whitespace is not silently accepted.');
    $assert($throws(fn() => ac_totp_code('NOT-A-BASE32-SECRET', 1)), 'TOTP: invalid Base32 secret is rejected.');
    $newSecret = ac_totp_new_secret();
    $assert(strlen($newSecret) === 32 && preg_match('/\A[A-Z2-7]{32}\z/D', $newSecret) === 1,
        'TOTP: new secret is 160-bit Base32.');
    $assert(strlen(ac_security_base32_decode($newSecret)) === 20, 'TOTP: generated secret decodes to 20 bytes.');
    $newCode = ac_totp_code($newSecret, 100);
    $assert(ac_totp_verify($newSecret, $newCode, 3000, null) === 100, 'TOTP: generated secret works.');
    $assert(ac_audit_verify($dir)['ok'] === true, 'Audit: final primary history remains valid.');
}
