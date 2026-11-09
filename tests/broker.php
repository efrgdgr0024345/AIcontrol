<?php
/** Isolated broker checks. Keys, credentials, outputs and state are test-only. */
function ac_test_broker(string $tempdir, callable $assert): void
{
    $reject = static function (callable $operation, string $message) use ($assert): void {
        $rejected = false;
        try { $operation(); } catch (RuntimeException | InvalidArgumentException) { $rejected = true; }
        $assert($rejected, $message);
    };
    $b64 = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    if ($key === false) { throw new RuntimeException('Could not create the test signing key.'); }
    $details = openssl_pkey_get_details($key);
    $jwk = ['kty' => 'RSA', 'kid' => 'test-github-key', 'use' => 'sig', 'alg' => 'RS256',
        'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];
    $jwks = ['keys' => [$jwk]];
    $now = time();
    $config = ['public_url' => 'https://example.test/AIcontrol/loader.php', 'openai_model' => 'gpt-6.1-sol'];
    $claims = ['iss' => 'https://token.actions.githubusercontent.com', 'aud' => $config['public_url'],
        'repository' => AC_REPO, 'repository_id' => AC_REPOSITORY_ID,
        'repository_owner_id' => AC_OWNER_ID, 'ref' => 'refs/heads/main',
        'workflow_ref' => AC_REPO . '/.github/workflows/agent.yml@refs/heads/main',
        'event_name' => 'workflow_dispatch', 'sha' => str_repeat('a', 40),
        'run_id' => '81001', 'run_attempt' => '1', 'iat' => $now, 'nbf' => $now - 5, 'exp' => $now + 300];
    $sign = static function (array $values, ?array $header = null) use ($key, $b64): string {
        $header ??= ['alg' => 'RS256', 'kid' => 'test-github-key', 'typ' => 'JWT'];
        $input = $b64(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . $b64(json_encode($values, JSON_THROW_ON_ERROR));
        if (!openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) { throw new RuntimeException('Test signing failed.'); }
        return $input . '.' . $b64($signature);
    };
    $token = $sign($claims);
    $identity = ac_broker_verify_oidc($token, $config, $jwks, $now);
    $assert($identity['run_id'] === '81001' && !isset($identity['jwt'], $identity['aud']), 'OIDC returns only safe identity claims');
    foreach ([
        'aud' => 'https://attacker.test/', 'iss' => 'https://attacker.test/',
        'repository' => 'someone/fork', 'repository_id' => '1450838310',
        'repository_owner_id' => '263261717', 'ref' => 'refs/heads/other',
        'workflow_ref' => AC_REPO . '/.github/workflows/other.yml@refs/heads/main',
        'event_name' => 'pull_request', 'sha' => '../../main', 'run_id' => '-1', 'run_attempt' => '0',
    ] as $field => $value) {
        $bad = $claims; $bad[$field] = $value;
        $reject(fn() => ac_broker_verify_oidc($sign($bad), $config, $jwks, $now), 'OIDC rejects wrong ' . $field);
    }
    foreach ([['exp' => $now], ['iat' => $now + 31], ['nbf' => $now + 31],
        ['iat' => $now - 601], ['exp' => $now + 601], ['exp' => (string)($now + 300)]] as $times) {
        $reject(fn() => ac_broker_verify_oidc($sign(array_replace($claims, $times)), $config, $jwks, $now), 'OIDC rejects invalid time bounds');
    }
    $reject(fn() => ac_broker_verify_oidc($sign($claims, ['alg' => 'HS256', 'kid' => 'test-github-key']), $config, $jwks, $now), 'OIDC rejects algorithm confusion');
    $reject(fn() => ac_broker_verify_oidc($sign($claims, ['alg' => 'RS256', 'kid' => 'other']), $config, $jwks, $now), 'OIDC rejects unknown kid');
    $reject(fn() => ac_broker_verify_oidc($sign($claims, ['alg' => 'RS256', 'kid' => 'test-github-key', 'jku' => 'https://attacker.test/keys']), $config, $jwks, $now), 'OIDC rejects caller key URLs');
    $reject(fn() => ac_broker_verify_oidc($token, $config, ['keys' => [$jwk, $jwk]], $now), 'OIDC rejects ambiguous matching keys');
    $parts = explode('.', $token);
    $signature = ac_broker_b64url($parts[2], 2048);
    $signature[0] = chr(ord($signature[0]) ^ 1);
    $reject(fn() => ac_broker_verify_oidc($parts[0] . '.' . $parts[1] . '.' . $b64($signature), $config, $jwks, $now), 'OIDC rejects invalid signatures');
    $reject(fn() => ac_broker_verify_oidc($token, $config, $jwks, $now + 301), 'OIDC rejects expired signed tokens');
    $reject(fn() => ac_broker_verify_oidc(str_repeat('a', 17000), $config, $jwks, $now), 'OIDC bounds token length');

    $csr = openssl_csr_new(['commonName' => 'AIcontrol test signing fixture'], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($certificate, $certificatePem);
    $certificateBody = str_replace(["-----BEGIN CERTIFICATE-----", "-----END CERTIFICATE-----", "\r", "\n"], '', $certificatePem);
    $x5c = $jwk; unset($x5c['n'], $x5c['e']); $x5c['x5c'] = [$certificateBody];
    $assert(ac_broker_verify_oidc($token, $config, ['keys' => [$x5c]], $now) === $identity, 'OIDC supports trusted x5c public keys');

    $dir = $tempdir . '/broker';
    ac_vault_init($dir);
    $reason = 'Review <script>alert(1)</script> before approval';
    $request = ac_broker_request($dir, $identity, ['scope' => 'project.read', 'reason' => $reason]);
    $id = $request['id'];
    $assert($request['status'] === 'pending' && $request['expires_at'] - $request['created_at'] === 3600, 'Requests require approval and expire in one hour');
    $assert(!isset($request['input'], $request['reason'], $request['identity']), 'Agent status hides submitted input and review text');
    $reject(fn() => ac_broker_request($dir, $identity, ['scope' => 'project.read', 'reason' => 'Duplicate']), 'One active request per workflow identity');
    $other = $identity; $other['run_id'] = '81002';
    $reject(fn() => ac_broker_status($dir, $other, $id), 'Different runs cannot inspect a request');
    $otherAttempt = $identity; $otherAttempt['run_attempt'] = '2';
    $reject(fn() => ac_broker_status($dir, $otherAttempt, $id), 'Reruns cannot reuse an earlier attempt identity');
    $otherCommit = $identity; $otherCommit['sha'] = str_repeat('b', 40);
    $reject(fn() => ac_broker_status($dir, $otherCommit, $id), 'Another commit cannot reuse approval');
    $reject(fn() => ac_broker_execute($dir, $identity, $id, $config), 'Pending requests cannot execute');
    ac_broker_decide($dir, $id, false, 'owner');
    $assert(ac_broker_status($dir, $identity, $id)['status'] === 'denied', 'Denial is persisted');
    $reject(fn() => ac_broker_decide($dir, $id, true, 'owner'), 'Denied requests cannot be approved later');
    $reject(fn() => ac_broker_execute($dir, $identity, $id, $config), 'Denied requests cannot execute');

    $request = ac_broker_request($dir, $identity, ['scope' => 'project.read', 'reason' => 'Approved metadata']);
    $id = $request['id'];
    ac_broker_decide($dir, $id, true, 'owner');
    $status = ac_broker_status($dir, $identity, $id);
    $assert($status['uses_remaining'] === 1 && $status['grant_expires_at'] <= time() + 300, 'Approval grants one use for five minutes');
    $reject(fn() => ac_broker_request($dir, $identity, ['scope' => 'project.read', 'reason' => 'Another grant']), 'Approved grants also count toward the active-request limit');
    $reject(fn() => ac_broker_execute($dir, $other, $id, $config), 'Approval cannot move between workflow runs');
    $result = ac_broker_execute($dir, $identity, $id, $config);
    $assert(array_keys($result) === ['version', 'repository', 'status'] && $result['repository'] === AC_REPO, 'Project metadata has a bounded shape');
    $reject(fn() => ac_broker_execute($dir, $identity, $id, $config), 'Consumed approval cannot replay');

    $request = ac_broker_request($dir, $identity, ['scope' => 'project.read', 'reason' => 'Expired pending']);
    $state = ac_read($dir . '/requests.json');
    $state['requests'][$request['id']]['expires_at'] = time() - 1;
    ac_save($dir . '/requests.json', $state);
    $assert(ac_broker_status($dir, $identity, $request['id'])['status'] === 'expired', 'Pending request expiry is enforced');
    $reject(fn() => ac_broker_decide($dir, $request['id'], true, 'owner'), 'Expired pending request cannot be approved');
    $request = ac_broker_request($dir, $identity, ['scope' => 'project.read', 'reason' => 'Expired grant']);
    ac_broker_decide($dir, $request['id'], true, 'owner');
    $state = ac_read($dir . '/requests.json');
    $state['requests'][$request['id']]['grant_expires_at'] = time() - 1;
    ac_save($dir . '/requests.json', $state);
    $reject(fn() => ac_broker_execute($dir, $identity, $request['id'], $config), 'Expired approval cannot execute');

    foreach ([['scope' => 'secrets.read', 'reason' => 'No'], ['scope' => 'project.read', 'reason' => str_repeat('x', 301)],
        ['scope' => 'openai.response', 'reason' => 'Too big', 'input' => str_repeat('x', 8001)],
        ['scope' => 'openai.response', 'reason' => 'Missing input'],
        ['scope' => 'project.read', 'reason' => 'No URL override', 'url' => 'https://attacker.test/'],
        ['scope' => 'openai.response', 'reason' => 'No model override', 'input' => 'Hi', 'model' => 'other']] as $body) {
        $reject(fn() => ac_broker_request($dir, $identity, $body), 'Request fields and scope are bounded');
    }

    $fakeSecret = 'unit-test-openai-secret-never-send';
    ac_vault_put($dir, 'openai', 'openai', 'Mock OpenAI', $fakeSecret, 'owner');
    $prompt = 'test-only-prompt-do-not-log';
    $request = ac_broker_request($dir, $identity, ['scope' => 'openai.response', 'reason' => 'Approved prompt', 'input' => $prompt]);
    $id = $request['id'];
    ac_broker_decide($dir, $id, true, 'owner');
    $calls = 0;
    $mock = static function (string $service, array $sent) use (&$calls, $assert, $dir, $identity, $id, $prompt, $fakeSecret): array {
        ++$calls;
        $assert(ac_broker_status($dir, $identity, $id)['status'] === 'spent', 'Grant is persisted as spent before provider execution');
        $assert($service === 'openai' && $sent['url'] === 'https://api.openai.com/v1/responses', 'OpenAI destination is fixed');
        $assert($sent['json'] === ['model' => 'gpt-6.1-sol', 'input' => $prompt, 'max_output_tokens' => 1000, 'store' => false], 'Provider receives only approved input with fixed bounds and no tools');
        $assert(in_array('Authorization: Bearer ' . $fakeSecret, $sent['headers'], true), 'Credential is used only by broker transport');
        return ['id' => 'resp_fixture', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Mock result']]]],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3, 'total_tokens' => 8, 'secret' => $fakeSecret],
            'headers' => ['Authorization' => $fakeSecret]];
    };
    $result = ac_broker_execute($dir, $identity, $id, $config, $mock);
    $assert($result === ['id' => 'resp_fixture', 'output_text' => 'Mock result',
        'usage' => ['input_tokens' => 5, 'output_tokens' => 3, 'total_tokens' => 8]], 'OpenAI result strips unrelated provider fields');
    $reject(fn() => ac_broker_execute($dir, $identity, $id, $config, $mock), 'Provider is not called twice');
    $assert($calls === 1, 'Only one mocked provider call occurred');

    $request = ac_broker_request($dir, $identity, ['scope' => 'openai.response', 'reason' => 'Failure test', 'input' => $prompt]);
    $id = $request['id'];
    ac_broker_decide($dir, $id, true, 'owner');
    $error = '';
    try {
        ac_broker_execute($dir, $identity, $id, $config, static function (): array {
            throw new RuntimeException('Provider secret: unit-test-openai-secret-never-send');
        });
    } catch (RuntimeException $failure) { $error = $failure->getMessage(); }
    $assert($error !== '' && !str_contains($error, $fakeSecret) && !str_contains($error, 'Provider secret'), 'Provider errors are never echoed');
    $assert(ac_broker_status($dir, $identity, $id)['status'] === 'failed', 'Failed calls consume their approval');
    $reject(fn() => ac_broker_execute($dir, $identity, $id, $config, $mock), 'Failure cannot be replayed');

    $request = ac_broker_request($dir, $identity, ['scope' => 'openai.response', 'reason' => 'Bound input', 'input' => $prompt]);
    $id = $request['id'];
    ac_broker_decide($dir, $id, true, 'owner');
    $state = ac_read($dir . '/requests.json');
    $state['requests'][$id]['input'] = 'Different unapproved input';
    ac_save($dir . '/requests.json', $state);
    $reject(fn() => ac_broker_execute($dir, $identity, $id, $config, $mock), 'Approval binds the exact input');
    $state['requests'][$id]['input'] = $prompt;
    $state['requests'][$id]['scope'] = 'github.repository.read';
    ac_save($dir . '/requests.json', $state);
    $reject(fn() => ac_broker_execute($dir, $identity, $id, $config, $mock), 'Approval binds the exact operation');
    $state['requests'][$id]['scope'] = 'openai.response';
    $state['requests'][$id]['grant_expires_at'] = time() - 1;
    ac_save($dir . '/requests.json', $state);

    ac_vault_put($dir, 'github_read', 'github', 'Mock GitHub', 'unit-test-github-secret', 'owner');
    $request = ac_broker_request($dir, $identity, ['scope' => 'github.repository.read', 'reason' => 'Read fixed repository']);
    $id = $request['id'];
    ac_broker_decide($dir, $id, true, 'owner');
    $result = ac_broker_execute($dir, $identity, $id, $config, static function (string $service, array $sent) use ($assert): array {
        $assert($service === 'github' && $sent['url'] === 'https://api.github.com/repos/' . AC_REPO, 'GitHub repository destination is fixed');
        return ['id' => (int)AC_REPOSITORY_ID, 'full_name' => AC_REPO, 'private' => false,
            'default_branch' => 'main', 'pushed_at' => '2026-11-09T00:00:00Z', 'secret' => 'unit-test-github-secret'];
    });
    $assert(!isset($result['secret']) && $result['repository'] === AC_REPO, 'GitHub result is bounded metadata');
    $history = ac_broker_list($dir);
    $assert(count($history) >= 8 && !isset($history[0]['binding'], $history[0]['approved_digest']), 'Owner can review bounded approval history');
    $audit = file_get_contents($dir . '/audit.jsonl');
    $assert(!str_contains($audit, $prompt) && !str_contains($audit, $fakeSecret) && !str_contains($audit, $token), 'Audit excludes prompt bodies, credentials and JWTs');
    $assert(str_contains($audit, 'broker.approved') && str_contains($audit, 'broker.denied') &&
        str_contains($audit, 'broker.used') && str_contains($audit, 'broker.failed') && str_contains($audit, 'vault.used'), 'Approval and credential use are auditable');
    $assert(ac_audit_verify($dir)['ok'] === true, 'Broker activity preserves audit integrity');

    $wrongProviderDir = $tempdir . '/broker-wrong-provider';
    ac_vault_init($wrongProviderDir);
    ac_vault_put($wrongProviderDir, 'openai', 'github', 'Wrong provider fixture', 'wrong-provider-test-secret', 'owner');
    $request = ac_broker_request($wrongProviderDir, $identity, ['scope' => 'openai.response', 'reason' => 'Provider binding', 'input' => 'Test']);
    ac_broker_decide($wrongProviderDir, $request['id'], true, 'owner');
    $providerCalls = 0;
    $reject(fn() => ac_broker_execute($wrongProviderDir, $identity, $request['id'], $config,
        static function () use (&$providerCalls): array { ++$providerCalls; return []; }), 'Credentials are bound to their intended provider');
    $assert($providerCalls === 0, 'Wrong-provider credentials are never sent');

    $queueDir = $tempdir . '/broker-queue';
    ac_vault_init($queueDir);
    $pending = [];
    for ($i = 1; $i <= 200; ++$i) {
        $queueIdentity = $identity; $queueIdentity['run_id'] = (string)(90000 + $i);
        $queueId = str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
        $pending[$queueId] = ['id' => $queueId, 'scope' => 'project.read', 'reason' => 'Queue fixture', 'input' => '',
            'identity' => $queueIdentity, 'binding' => ac_broker_binding($queueIdentity),
            'status' => 'pending', 'created_at' => $now, 'expires_at' => $now + 3600];
    }
    ac_broker_save($queueDir, $pending);
    $reject(fn() => ac_broker_request($queueDir, $identity, ['scope' => 'project.read', 'reason' => 'Overflow']), 'The pending queue cannot exceed 200 requests');
    $terminal = [];
    for ($i = 1; $i <= 500; ++$i) {
        $queueIdentity = $identity; $queueIdentity['run_id'] = (string)(90000 + $i);
        $queueId = str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
        $terminal[$queueId] = ['id' => $queueId, 'scope' => 'project.read', 'reason' => 'History fixture', 'input' => '',
            'identity' => $queueIdentity, 'binding' => ac_broker_binding($queueIdentity),
            'status' => 'denied', 'created_at' => $now - 1000 + $i, 'expires_at' => $now + 3600];
    }
    ac_broker_save($queueDir, $terminal);
    $request = ac_broker_request($queueDir, $identity, ['scope' => 'project.read', 'reason' => 'Bounded history']);
    $retained = ac_broker_records($queueDir);
    $assert(count($retained) === 500 && isset($retained[$request['id']]) && !isset($retained[str_repeat('0', 31) . '1']), 'Old terminal records are pruned at 500 while audit history is separate');
}
