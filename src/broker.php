<?php
/**
 * Bounded agent access. The HTTP router authenticates the owner and holds ac_lock()
 * for every mutation, including the whole execute call. Agent callers are always
 * authenticated with ac_broker_verify_oidc(); their JWT is never stored or logged.
 */

function ac_broker_scopes(): array
{
    return ['project.read', 'github.repository.read', 'openai.response'];
}

function ac_broker_b64url(string $value, int $limit): string
{
    if ($value === '' || strlen($value) > $limit ||
        preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) {
        throw new RuntimeException('GitHub identity verification failed.');
    }
    $bytes = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    if ($bytes === false || rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') !== $value) {
        throw new RuntimeException('GitHub identity verification failed.');
    }
    return $bytes;
}

function ac_broker_der_length(int $length): string
{
    if ($length < 128) { return chr($length); }
    $bytes = '';
    while ($length > 0) { $bytes = chr($length & 255) . $bytes; $length >>= 8; }
    return chr(128 | strlen($bytes)) . $bytes;
}

function ac_broker_der_integer(string $bytes): string
{
    $bytes = ltrim($bytes, "\0");
    if ($bytes === '') { $bytes = "\0"; }
    if (ord($bytes[0]) & 128) { $bytes = "\0" . $bytes; }
    return "\x02" . ac_broker_der_length(strlen($bytes)) . $bytes;
}

/** Trust comes from the fixed JWKS endpoint, never a JWT-supplied key or URL. */
function ac_broker_jwk_key(array $jwk): OpenSSLAsymmetricKey
{
    if (($jwk['kty'] ?? null) !== 'RSA' ||
        (isset($jwk['alg']) && $jwk['alg'] !== 'RS256') ||
        (isset($jwk['use']) && $jwk['use'] !== 'sig') ||
        (isset($jwk['key_ops']) && (!is_array($jwk['key_ops']) || !in_array('verify', $jwk['key_ops'], true)))) {
        throw new RuntimeException('GitHub identity verification failed.');
    }
    if (isset($jwk['n'], $jwk['e']) && is_string($jwk['n']) && is_string($jwk['e'])) {
        $n = ac_broker_b64url($jwk['n'], 2048);
        $e = ac_broker_b64url($jwk['e'], 16);
        if (strlen($n) < 256 || strlen($n) > 1024 || strlen($e) > 8) {
            throw new RuntimeException('GitHub identity verification failed.');
        }
        $rsa = ac_broker_der_integer($n) . ac_broker_der_integer($e);
        $rsa = "\x30" . ac_broker_der_length(strlen($rsa)) . $rsa;
        $bitString = "\x03" . ac_broker_der_length(strlen($rsa) + 1) . "\0" . $rsa;
        $spki = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00" . $bitString;
        $spki = "\x30" . ac_broker_der_length(strlen($spki)) . $spki;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    } elseif (isset($jwk['x5c'][0]) && is_string($jwk['x5c'][0]) && strlen($jwk['x5c'][0]) <= 24000) {
        $certificate = base64_decode($jwk['x5c'][0], true);
        if ($certificate === false || $certificate === '') { throw new RuntimeException('GitHub identity verification failed.'); }
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($certificate), 64, "\n") . "-----END CERTIFICATE-----\n";
    } else {
        throw new RuntimeException('GitHub identity verification failed.');
    }
    $key = @openssl_pkey_get_public($pem);
    $details = $key === false ? false : openssl_pkey_get_details($key);
    if ($key === false || $details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA ||
        $details['bits'] < 2048 || $details['bits'] > 8192) {
        throw new RuntimeException('GitHub identity verification failed.');
    }
    return $key;
}

/** Fixed destinations, TLS verification, no redirects, bounded response bytes. */
function ac_broker_http(string $service, #[SensitiveParameter] array $request): array
{
    $destinations = [
        'jwks' => 'https://token.actions.githubusercontent.com/.well-known/jwks',
        'github' => 'https://api.github.com/repos/' . AC_REPO,
        'openai' => 'https://api.openai.com/v1/responses',
    ];
    if (!isset($destinations[$service]) || ($request['url'] ?? null) !== $destinations[$service]) {
        throw new RuntimeException('Provider request failed.');
    }
    $maxBytes = $service === 'jwks' ? 131072 : 262144;
    $bytes = '';
    $curl = curl_init($destinations[$service]);
    if ($curl === false) { throw new RuntimeException('Provider request failed.'); }
    $headers = $request['headers'] ?? [];
    $options = [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'AIcontrol-bounded-broker',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $service === 'jwks' ? 15 : 60,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes, $maxBytes): int {
            if (strlen($bytes) + strlen($chunk) > $maxBytes) { return 0; }
            $bytes .= $chunk;
            return strlen($chunk);
        },
    ];
    if ($service === 'openai') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($request['json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    try {
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($ok === false || $status < 200 || $status >= 300) { throw new RuntimeException('Provider request failed.'); }
        $value = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new RuntimeException('Provider request failed.'); }
        return $value;
    } catch (Throwable) {
        throw new RuntimeException('Provider request failed.');
    } finally {
        curl_close($curl);
    }
}

function ac_broker_jwks(array $config): array
{
    static $memoryCache = null;
    $now = time();
    if (is_array($memoryCache) && $memoryCache['until'] > $now) { return $memoryCache['jwks']; }
    $path = null;
    if (isset($config['private_dir']) && is_string($config['private_dir'])) {
        $dir = ac_security_directory($config['private_dir']);
        $path = $dir . '/github-jwks.json';
        if (ac_security_file($path, false) !== null) {
            try {
                $cache = json_decode(ac_security_read_bytes($path, 150000), true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                // A broken cache is not an authority: refresh only the fixed URL.
                $cache = null;
            }
            if (is_array($cache) && is_int($cache['fetched_at'] ?? null) &&
                $cache['fetched_at'] <= $now && $cache['fetched_at'] > $now - 300 &&
                is_array($cache['jwks']['keys'] ?? null)) {
                $memoryCache = ['until' => $cache['fetched_at'] + 300, 'jwks' => $cache['jwks']];
                return $cache['jwks'];
            }
        }
    }
    $jwks = ac_broker_http('jwks', [
        'url' => 'https://token.actions.githubusercontent.com/.well-known/jwks',
        'headers' => ['Accept: application/json'],
    ]);
    if (!isset($jwks['keys']) || !is_array($jwks['keys']) || count($jwks['keys']) < 1 || count($jwks['keys']) > 100) {
        throw new RuntimeException('GitHub identity verification failed.');
    }
    if ($path !== null) { ac_save($path, ['fetched_at' => $now, 'jwks' => $jwks]); }
    $memoryCache = ['until' => $now + 300, 'jwks' => $jwks];
    return $jwks;
}

/** Optional keys/time are trusted test injection only; HTTP never accepts them. */
function ac_broker_verify_oidc(#[SensitiveParameter] string $jwt, array $config,
    ?array $jwks = null, ?int $now = null): array
{
    try {
        $now ??= time();
        $audience = $config['public_url'] ?? null;
        if (!is_string($audience) || !str_starts_with($audience, 'https://') ||
            strlen($jwt) > 16384 || count($parts = explode('.', $jwt)) !== 3) {
            throw new RuntimeException('Invalid identity.');
        }
        $header = json_decode(ac_broker_b64url($parts[0], 2048), true, 8, JSON_THROW_ON_ERROR);
        $claims = json_decode(ac_broker_b64url($parts[1], 12000), true, 16, JSON_THROW_ON_ERROR);
        $signature = ac_broker_b64url($parts[2], 2048);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? null) !== 'RS256' ||
            !is_string($header['kid'] ?? null) || strlen($header['kid']) > 200 || $header['kid'] === '' ||
            (isset($header['typ']) && $header['typ'] !== 'JWT') ||
            array_intersect(['jku', 'jwk', 'x5u', 'crit', 'b64'], array_keys($header))) {
            throw new RuntimeException('Invalid identity.');
        }
        $jwks ??= ac_broker_jwks($config);
        if (!is_array($jwks['keys'] ?? null) || count($jwks['keys']) > 100) { throw new RuntimeException('Invalid keys.'); }
        $matches = array_values(array_filter($jwks['keys'], static fn($key): bool =>
            is_array($key) && ($key['kid'] ?? null) === $header['kid']));
        if (count($matches) !== 1 || openssl_verify($parts[0] . '.' . $parts[1], $signature,
                ac_broker_jwk_key($matches[0]), OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Invalid signature.');
        }
        foreach (['iat', 'nbf', 'exp'] as $field) {
            if (!is_int($claims[$field] ?? null)) { throw new RuntimeException('Invalid token time.'); }
        }
        if ($claims['exp'] <= $now || $claims['iat'] > $now + 30 || $claims['nbf'] > $now + 30 ||
            $claims['iat'] < $now - 600 || $claims['nbf'] < $claims['iat'] - 60 ||
            $claims['exp'] <= $claims['iat'] || $claims['exp'] <= $claims['nbf'] ||
            $claims['exp'] - $claims['iat'] > 600) {
            throw new RuntimeException('Invalid token time.');
        }
        $expectedWorkflow = AC_REPO . '/.github/workflows/agent.yml@refs/heads/main';
        if (($claims['iss'] ?? null) !== 'https://token.actions.githubusercontent.com' ||
            ($claims['aud'] ?? null) !== $audience || ($claims['repository'] ?? null) !== AC_REPO ||
            ($claims['repository_id'] ?? null) !== AC_REPOSITORY_ID ||
            ($claims['repository_owner_id'] ?? null) !== AC_OWNER_ID ||
            ($claims['ref'] ?? null) !== 'refs/heads/main' ||
            ($claims['workflow_ref'] ?? null) !== $expectedWorkflow ||
            ($claims['event_name'] ?? null) !== 'workflow_dispatch' ||
            (isset($claims['job_workflow_ref']) && $claims['job_workflow_ref'] !== $expectedWorkflow)) {
            throw new RuntimeException('Untrusted workflow.');
        }
        $identity = [
            'repository' => $claims['repository'], 'repository_id' => (string)$claims['repository_id'],
            'owner_id' => (string)$claims['repository_owner_id'], 'ref' => $claims['ref'],
            'workflow_ref' => $claims['workflow_ref'], 'event_name' => $claims['event_name'],
            'sha' => $claims['sha'] ?? null, 'run_id' => $claims['run_id'] ?? null,
            'run_attempt' => $claims['run_attempt'] ?? null,
        ];
        return ac_broker_identity($identity);
    } catch (Throwable) {
        throw new RuntimeException('GitHub identity verification failed.');
    }
}

/** Validated non-secret claims only; issued-at/expiry are deliberately not bound. */
function ac_broker_identity(array $identity): array
{
    $fixed = ['repository' => AC_REPO, 'repository_id' => AC_REPOSITORY_ID,
        'owner_id' => AC_OWNER_ID, 'ref' => 'refs/heads/main',
        'workflow_ref' => AC_REPO . '/.github/workflows/agent.yml@refs/heads/main',
        'event_name' => 'workflow_dispatch'];
    foreach ($fixed as $key => $expected) {
        if (($identity[$key] ?? null) !== $expected) { throw new RuntimeException('Untrusted workflow identity.'); }
    }
    if (!is_string($identity['sha'] ?? null) || preg_match('/\A[a-f0-9]{40}\z/D', $identity['sha']) !== 1) {
        throw new RuntimeException('Invalid workflow commit.');
    }
    foreach (['run_id', 'run_attempt'] as $field) {
        if ((!is_string($identity[$field] ?? null) && !is_int($identity[$field] ?? null)) ||
            preg_match('/\A[1-9][0-9]{0,19}\z/D', (string)$identity[$field]) !== 1) {
            throw new RuntimeException('Invalid workflow run.');
        }
        $fixed[$field] = (string)$identity[$field];
    }
    $fixed['sha'] = $identity['sha'];
    return $fixed;
}

function ac_broker_binding(array $identity): string
{
    return hash('sha256', json_encode(ac_broker_identity($identity), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function ac_broker_actor(array $identity): string
{
    return 'github:run/' . $identity['run_id'] . '/attempt/' . $identity['run_attempt'];
}

function ac_broker_records(string $dir): array
{
    ac_security_directory($dir);
    ac_security_file($dir . '/requests.json', false);
    $state = ac_read($dir . '/requests.json');
    if ($state === []) { return []; }
    if (($state['schema'] ?? null) !== 1 || !is_array($state['requests'] ?? null) || count($state['requests']) > 500) {
        throw new RuntimeException('Invalid approval state.');
    }
    foreach ($state['requests'] as $id => $record) {
        if (!is_array($record) || !is_string($id) || ($record['id'] ?? null) !== $id ||
            preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1 ||
            !in_array($record['scope'] ?? null, ac_broker_scopes(), true) ||
            !is_string($record['input'] ?? null) || strlen($record['input']) > 8000 ||
            !is_string($record['reason'] ?? null) || strlen($record['reason']) > 300 ||
            !is_array($record['identity'] ?? null) || !is_string($record['binding'] ?? null) ||
            !hash_equals(ac_broker_binding($record['identity']), $record['binding']) ||
            !is_int($record['created_at'] ?? null) || !is_int($record['expires_at'] ?? null) ||
            !in_array($record['status'] ?? null, ['pending', 'approved', 'denied', 'expired', 'spent', 'succeeded', 'failed'], true)) {
            throw new RuntimeException('Invalid approval state.');
        }
    }
    return $state['requests'];
}

function ac_broker_save(string $dir, array $records): void
{
    ac_security_directory($dir);
    ac_security_file($dir . '/requests.json', false);
    ac_save($dir . '/requests.json', ['schema' => 1, 'requests' => $records]);
}

function ac_broker_effective_status(array $record, int $now): string
{
    if (($record['status'] ?? '') === 'pending' && ($record['expires_at'] ?? 0) <= $now) { return 'expired'; }
    if (($record['status'] ?? '') === 'approved' && ($record['grant_expires_at'] ?? 0) <= $now) { return 'expired'; }
    return (string)($record['status'] ?? 'invalid');
}

function ac_broker_public(array $record): array
{
    $status = ac_broker_effective_status($record, time());
    return ['id' => $record['id'], 'scope' => $record['scope'], 'status' => $status,
        'created_at' => $record['created_at'], 'expires_at' => $record['expires_at'],
        'grant_expires_at' => $record['grant_expires_at'] ?? null,
        'uses_remaining' => $status === 'approved' ? 1 : 0];
}

function ac_broker_digest(array $record): string
{
    return hash('sha256', json_encode(['binding' => $record['binding'], 'scope' => $record['scope'],
        'input' => $record['input'], 'reason' => $record['reason']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function ac_broker_id(string $id): string
{
    if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) { throw new RuntimeException('Approval request was not found.'); }
    return $id;
}

function ac_broker_request(string $dir, array $identity, array $body): array
{
    $identity = ac_broker_identity($identity);
    $scope = $body['scope'] ?? null;
    $reason = $body['reason'] ?? null;
    $input = $body['input'] ?? '';
    if (array_diff(array_keys($body), ['scope', 'reason', 'input']) ||
        !is_string($scope) || !in_array($scope, ac_broker_scopes(), true) ||
        !is_string($reason) || trim($reason) === '' || strlen($reason) > 300 || preg_match('//u', $reason) !== 1 ||
        !is_string($input) || strlen($input) > 8000 || preg_match('//u', $input) !== 1 ||
        ($scope === 'openai.response' ? trim($input) === '' : $input !== '')) {
        throw new InvalidArgumentException('Invalid bounded operation or request input.');
    }
    $binding = ac_broker_binding($identity);
    $records = ac_broker_records($dir);
    $now = time();
    $pending = 0;
    foreach ($records as &$record) {
        $record['status'] = ac_broker_effective_status($record, $now);
        if (in_array($record['status'], ['pending', 'approved'], true) && hash_equals($record['binding'], $binding)) {
            throw new RuntimeException('This workflow run already has an active request.');
        }
        if ($record['status'] === 'pending') { ++$pending; }
    }
    unset($record);
    if ($pending >= 200) { throw new RuntimeException('The approval queue is full.'); }
    uasort($records, static fn(array $a, array $b): int => $a['created_at'] <=> $b['created_at']);
    while (count($records) >= 500) {
        $removed = false;
        foreach ($records as $oldId => $record) {
            if (!in_array($record['status'], ['pending', 'approved'], true)) {
                unset($records[$oldId]); $removed = true; break;
            }
        }
        if (!$removed) { throw new RuntimeException('The approval queue is full.'); }
    }
    $id = bin2hex(random_bytes(16));
    $record = ['id' => $id, 'scope' => $scope, 'reason' => $reason, 'input' => $input,
        'identity' => $identity, 'binding' => $binding, 'status' => 'pending',
        'created_at' => $now, 'expires_at' => $now + 3600];
    $records[$id] = $record;
    ac_broker_save($dir, $records);
    ac_audit($dir, 'broker.requested', ac_broker_actor($identity),
        ['request_id' => $id, 'operation' => $scope, 'run_id' => $identity['run_id'], 'commit' => $identity['sha']]);
    return ac_broker_public($record);
}

function ac_broker_owned_record(array $records, array $identity, string $id): array
{
    ac_broker_id($id);
    $record = $records[$id] ?? null;
    if (!is_array($record) || !is_string($record['binding'] ?? null) ||
        !hash_equals($record['binding'], ac_broker_binding($identity))) {
        throw new RuntimeException('Approval request was not found.');
    }
    return $record;
}

function ac_broker_status(string $dir, array $identity, string $id): array
{
    return ac_broker_public(ac_broker_owned_record(ac_broker_records($dir), $identity, $id));
}

function ac_broker_decide(string $dir, string $id, bool $approve, string $actor): void
{
    ac_security_identity($actor, 'actor');
    ac_broker_id($id);
    $records = ac_broker_records($dir);
    $record = $records[$id] ?? null;
    if (!is_array($record) || ac_broker_effective_status($record, time()) !== 'pending') {
        throw new RuntimeException('Request is missing, expired or already decided.');
    }
    $record['status'] = $approve ? 'approved' : 'denied';
    $record['decided_at'] = time();
    $record['decided_by'] = $actor;
    if ($approve) {
        $record['grant_expires_at'] = time() + 300;
        $record['approved_digest'] = ac_broker_digest($record);
        $record['uses_remaining'] = 1;
    }
    $records[$id] = $record;
    ac_broker_save($dir, $records);
    ac_audit($dir, $approve ? 'broker.approved' : 'broker.denied', $actor,
        ['request_id' => $id, 'operation' => $record['scope'], 'run_id' => $record['identity']['run_id']]);
}

/** Owner-only metadata plus exact input for review; the router must HTML-escape. */
function ac_broker_list(string $dir): array
{
    $records = array_values(ac_broker_records($dir));
    foreach ($records as &$record) {
        $record['status'] = ac_broker_effective_status($record, time());
        unset($record['binding'], $record['approved_digest']);
    }
    unset($record);
    usort($records, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
    return $records;
}

function ac_broker_authorization(#[SensitiveParameter] string $secret): string
{
    if ($secret === '' || strlen($secret) > 4096 || preg_match('/[\x00-\x20\x7f]/', $secret)) {
        throw new RuntimeException('Provider credential is unavailable.');
    }
    return 'Authorization: Bearer ' . $secret;
}

/** A credential id cannot silently redirect another provider's credential. */
function ac_broker_credential(string $dir, string $id, string $provider, string $actor,
    string $purpose, bool $optional = false): ?string
{
    foreach (ac_vault_list($dir) as $credential) {
        if ($credential['id'] !== $id) { continue; }
        if ($credential['provider'] !== $provider) { throw new RuntimeException('Provider credential is unavailable.'); }
        return ac_vault_get($dir, $id, $actor, $purpose);
    }
    if ($optional) { return null; }
    throw new RuntimeException('Provider credential is unavailable.');
}

function ac_broker_openai_result(array $response): array
{
    if (!is_string($response['id'] ?? null) || preg_match('/\Aresp_[A-Za-z0-9_-]{1,180}\z/D', $response['id']) !== 1) {
        throw new RuntimeException('Provider request failed.');
    }
    if (isset($response['error']) || (isset($response['status']) && $response['status'] !== 'completed') ||
        !is_array($response['output'] ?? []) || !is_array($response['usage'] ?? [])) {
        throw new RuntimeException('Provider request failed.');
    }
    $text = '';
    foreach (($response['output'] ?? []) as $item) {
        if (!is_array($item) || ($item['type'] ?? null) !== 'message') { continue; }
        if (!is_array($item['content'] ?? [])) { throw new RuntimeException('Provider request failed.'); }
        foreach (($item['content'] ?? []) as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
                if (strlen($text) > 32768) { throw new RuntimeException('Provider response exceeded its bound.'); }
            }
        }
    }
    if ($text === '' && is_string($response['output_text'] ?? null)) { $text = $response['output_text']; }
    if ($text === '' || strlen($text) > 32768 || preg_match('//u', $text) !== 1) { throw new RuntimeException('Provider request failed.'); }
    $usage = [];
    foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $field) {
        $value = $response['usage'][$field] ?? null;
        if (is_int($value) && $value >= 0 && $value <= 100000000) { $usage[$field] = $value; }
    }
    return ['id' => $response['id'], 'output_text' => $text, 'usage' => $usage];
}

/**
 * No execution-time operation/input overrides. The approved record is consumed
 * and persisted before reading a credential or performing a provider request.
 * The optional transport is trusted test injection, not an HTTP parameter.
 */
function ac_broker_execute(string $dir, array $identity, string $id, array $config,
    ?callable $transport = null): array
{
    $identity = ac_broker_identity($identity);
    $records = ac_broker_records($dir);
    $record = ac_broker_owned_record($records, $identity, $id);
    if (ac_broker_effective_status($record, time()) !== 'approved' || ($record['uses_remaining'] ?? null) !== 1 ||
        !is_string($record['approved_digest'] ?? null) ||
        !hash_equals($record['approved_digest'], ac_broker_digest($record))) {
        throw new RuntimeException('A matching, unexpired, unused approval is required.');
    }
    $record['status'] = 'spent';
    $record['uses_remaining'] = 0;
    $record['used_at'] = time();
    $records[$id] = $record;
    ac_broker_save($dir, $records);
    $actor = ac_broker_actor($identity);
    $details = ['request_id' => $id, 'operation' => $record['scope'], 'run_id' => $identity['run_id']];
    ac_audit($dir, 'broker.used', $actor, $details);
    $transport ??= 'ac_broker_http';
    try {
        if ($record['scope'] === 'project.read') {
            $result = ['version' => AC_VERSION, 'repository' => AC_REPO, 'status' => 'ready'];
        } elseif ($record['scope'] === 'github.repository.read') {
            $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2026-03-10'];
            $secret = ac_broker_credential($dir, 'github_read', 'github', $actor, 'broker.github.repository.read', true);
            if ($secret !== null) {
                $headers[] = ac_broker_authorization($secret);
                sodium_memzero($secret);
            }
            $response = $transport('github', ['url' => 'https://api.github.com/repos/' . AC_REPO,
                'method' => 'GET', 'headers' => $headers]);
            if (!is_array($response) || (string)($response['id'] ?? '') !== AC_REPOSITORY_ID ||
                ($response['full_name'] ?? null) !== AC_REPO) { throw new RuntimeException('Provider request failed.'); }
            $result = ['id' => AC_REPOSITORY_ID, 'repository' => AC_REPO,
                'private' => (bool)($response['private'] ?? false), 'url' => 'https://github.com/' . AC_REPO];
            foreach (['default_branch', 'pushed_at'] as $field) {
                if (is_string($response[$field] ?? null) && strlen($response[$field]) <= 100) { $result[$field] = $response[$field]; }
            }
        } elseif ($record['scope'] === 'openai.response') {
            $model = $config['openai_model'] ?? 'gpt-6.1-sol';
            if (!is_string($model) || preg_match('/\A[A-Za-z0-9._-]{1,100}\z/D', $model) !== 1) {
                throw new RuntimeException('Provider model is unavailable.');
            }
            $secret = ac_broker_credential($dir, 'openai', 'openai', $actor, 'broker.openai.response');
            $authorization = ac_broker_authorization($secret);
            sodium_memzero($secret);
            $response = $transport('openai', ['url' => 'https://api.openai.com/v1/responses',
                'method' => 'POST', 'headers' => ['Content-Type: application/json', $authorization],
                'json' => ['model' => $model, 'input' => $record['input'], 'max_output_tokens' => 1000, 'store' => false]]);
            if (!is_array($response)) { throw new RuntimeException('Provider request failed.'); }
            $result = ac_broker_openai_result($response);
        } else {
            throw new RuntimeException('Unsupported approved operation.');
        }
        $records[$id]['status'] = 'succeeded';
        $records[$id]['completed_at'] = time();
        ac_broker_save($dir, $records);
        ac_audit($dir, 'broker.completed', $actor, $details + ['success' => true]);
        return $result;
    } catch (Throwable) {
        $records[$id]['status'] = 'failed';
        $records[$id]['completed_at'] = time();
        ac_broker_save($dir, $records);
        ac_audit($dir, 'broker.failed', $actor, $details + ['reason' => 'provider_failure', 'success' => false]);
        throw new RuntimeException('The approved operation failed. Its one-use grant has been consumed.');
    }
}
