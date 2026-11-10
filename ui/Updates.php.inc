<?php
/** Embedded into the standalone loader by build-ui.php. */
final class AIcontrolProgress
{
    public static bool $stream = false;
    private static bool $started = false;

    public static function emit(array $event): void
    {
        if (!self::$stream) { return; }
        if (!self::$started) {
            header('Content-Type: application/x-ndjson; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Accel-Buffering: no');
            @ini_set('zlib.output_compression', '0');
            ignore_user_abort(true);
            self::$started = true;
        }
        echo json_encode($event + ['at' => gmdate('c')], JSON_THROW_ON_ERROR) . "\n";
        if (ob_get_level() > 0) { @ob_flush(); }
        flush();
    }

    public static function stage(int $step, string $label, string $detail): void
    {
        self::emit(['event' => 'progress', 'step' => $step, 'total' => 9, 'label' => $label, 'detail' => $detail]);
    }
}

final class AIcontrolUpdates
{
    public static function signedState(string $home, string $name): ?array
    {
        if (!is_file($home . '/' . $name)) { return null; }
        $signer = AIcontrolInstaller::$signer;
        if ($signer === null) { throw new RuntimeException('Signing identity is required.'); }
        $payload = AIcontrolSigning::verify(AIcontrolInstaller::readJson($home . '/' . $name), $signer->identity()['public_key']);
        if ($payload === null) { throw new RuntimeException('Saved update state signature is invalid.'); }
        return $payload;
    }

    public static function save(string $home, string $name, array $payload): array
    {
        $signer = AIcontrolInstaller::$signer;
        if ($signer === null) { throw new RuntimeException('Signing identity is required.'); }
        $receipt = $signer->record($payload);
        AIcontrolInstaller::jsonFile($home . '/' . $name, $receipt);
        return $receipt;
    }

    public static function baseline(string $home, string $loader): array
    {
        $state = self::signedState($home, 'bootstrap-state.json');
        if ($state !== null) {
            if (($state['loader_path_sha256'] ?? '') !== hash('sha256', $loader)
                || !preg_match('/^[a-f0-9]{64}$/D', $state['loader_sha256'] ?? '')) {
                throw new RuntimeException('Saved loader identity does not match this installation.');
            }
            return $state;
        }
        $state = ['event' => 'bootstrap.baseline', 'loader_sha256' => hash_file('sha256', $loader), 'loader_path_sha256' => hash('sha256', $loader), 'source_commit' => null];
        self::save($home, 'bootstrap-state.json', $state);
        return $state;
    }

    public static function changedFiles(array $before, array $after): array
    {
        $added = $changed = $removed = [];
        foreach ($after as $path => $hash) {
            if (!array_key_exists($path, $before)) { $added[] = $path; }
            elseif (!hash_equals($before[$path], $hash)) { $changed[] = $path; }
        }
        foreach ($before as $path => $hash) { if (!array_key_exists($path, $after)) { $removed[] = $path; } }
        return ['added' => $added, 'changed' => $changed, 'removed' => $removed];
    }

    public static function components(array $installedFiles, array $latestFiles, string $runningLoaderHash, ?string $latestLoaderHash): array
    {
        return ['app_update' => AIcontrolSigning::canonical($installedFiles) !== AIcontrolSigning::canonical($latestFiles),
            'loader_update' => $latestLoaderHash !== null && !hash_equals($runningLoaderHash, $latestLoaderHash)];
    }

    public static function availability(string $home, string $loader, bool $refresh = false): array
    {
        $current = AIcontrolInstaller::readJson($home . '/current.json');
        $baseline = self::baseline($home, $loader);
        $actualHash = hash_file('sha256', $loader);
        $cached = self::signedState($home, 'update-status.json');
        if ($cached !== null && ($cached['installed_sha'] ?? '') === $current['sha']
            && ($cached['running_loader_hash'] ?? '') === $actualHash && ($cached['expires_at'] ?? 0) > time()
            && (!$refresh || ($cached['checked_at'] ?? 0) > time() - 60)) {
            return $cached;
        }
        $lockPath = $home . '/update-check.lock';
        if (is_link($lockPath)) { throw new RuntimeException('Unsafe update-check lock.'); }
        $lock = fopen($lockPath, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            return ['state' => 'checking', 'installed_sha' => $current['sha']];
        }
        try {
            AIcontrolInstaller::$operation = ['repository' => AICONTROL_REPOSITORY, 'correlation_id' => bin2hex(random_bytes(16)), 'operation' => 'update.availability'];
            try {
                $release = AIcontrolInstaller::check(AICONTROL_RELEASE_REF);
                $currentFiles = AIcontrolInstaller::manifest(file_get_contents($home . '/releases/' . AIcontrolInstaller::sha($current['sha']) . '/release-manifest.json'));
                $components = self::components($currentFiles, $release['files'], $actualHash, $release['bootstrap_hash']);
                $loaderUpdate = $components['loader_update']; $appUpdate = $components['app_update'];
                $integrityChanged = !hash_equals($baseline['loader_sha256'], $actualHash);
                $status = ['state' => $appUpdate || $loaderUpdate ? 'update_available' : 'up_to_date',
                    'installed_sha' => $current['sha'], 'latest_sha' => $release['sha'], 'app_update' => $appUpdate,
                    'loader_update' => $loaderUpdate, 'integrity_changed' => $integrityChanged,
                    'running_loader_hash' => $actualHash, 'expected_loader_hash' => $release['bootstrap_hash'],
                    'release_title' => $release['title'], 'correlation_id' => AIcontrolInstaller::$operation['correlation_id'], 'checked_at' => time(), 'expires_at' => time() + 900];
            } catch (Throwable $failure) {
                $waiting = str_contains($failure->getMessage(), 'required GitHub Actions check') || str_contains($failure->getMessage(), 'Required check planning-panel');
                $status = ['state' => $waiting ? 'checks_pending' : 'check_failed', 'installed_sha' => $current['sha'], 'running_loader_hash' => $actualHash,
                    'checked_at' => time(), 'expires_at' => time() + 60, 'message' => $waiting ? 'The latest release is waiting for required checks. Your current version remains active.' : 'A checked release could not be confirmed. Open updates to retry.'];
            }
            self::save($home, 'update-status.json', ['event' => 'update.availability'] + $status);
            return $status;
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public static function atomicLoader(string $path, string $contents, string $expected): void
    {
        if (!hash_equals($expected, hash('sha256', $contents))) { throw new RuntimeException('Loader checksum mismatch.'); }
        token_get_all($contents, TOKEN_PARSE);
        $temporary = dirname($path) . '/.aicontrol-bootstrap-' . bin2hex(random_bytes(8)) . '.php';
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !chmod($temporary, 0600)
                || !rename($temporary, $path) || !hash_equals($expected, hash_file('sha256', $path))) {
                throw new RuntimeException('Could not activate the checked loader.');
            }
            if (function_exists('opcache_invalidate')) {
                $invalidated = @opcache_invalidate($path, true);
                if (!$invalidated && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)
                    && !filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOL) && PHP_SAPI !== 'cli') {
                    throw new RuntimeException('Hosting opcode cache could not be refreshed.');
                }
            }
        } finally {
            if (is_file($temporary)) { unlink($temporary); }
        }
    }

    public static function prepareBootstrap(string $home, string $loader, string $staged, string $expected, string $sourceSha): array
    {
        $baseline = self::baseline($home, $loader);
        $actualHash = hash_file('sha256', $loader);
        if (!hash_equals($baseline['loader_sha256'], $actualHash)) {
            if (!hash_equals($expected, $actualHash)) {
                throw new RuntimeException('The current loader differs from its saved hash. It was preserved; verify the uploaded file before updating.');
            }
            self::save($home, 'bootstrap-state.json', ['event' => 'bootstrap.manual_verified', 'loader_sha256' => $actualHash, 'loader_path_sha256' => hash('sha256', $loader), 'source_commit' => $sourceSha]);
        }
        if (is_link($staged) || !is_file($staged) || !hash_equals($expected, hash_file('sha256', $staged))) {
            throw new RuntimeException('The staged loader does not match the checked release hash.');
        }
        $backups = $home . '/bootstrap-backups';
        if (!is_dir($backups) && !mkdir($backups, 0700)) { throw new RuntimeException('Cannot create loader backup storage.'); }
        if (is_link($backups) || realpath($backups) !== $backups || (fileperms($backups) & 0777) !== 0700) { throw new RuntimeException('Unsafe loader backup storage.'); }
        $backup = $backups . '/' . $actualHash . '.php';
        if (!file_exists($backup)) {
            if (file_put_contents($backup, file_get_contents($loader), LOCK_EX) === false) { throw new RuntimeException('Cannot retain prior loader.'); }
            chmod($backup, 0600);
        }
        if (is_link($backup) || !hash_equals($actualHash, hash_file('sha256', $backup))) { throw new RuntimeException('Prior loader backup failed verification.'); }
        return ['old_hash' => $actualHash, 'new_hash' => $expected, 'path_hash' => hash('sha256', $loader),
            'previous_state' => AIcontrolInstaller::readJson($home . '/bootstrap-state.json')];
    }

    public static function restoreBootstrap(string $home, string $loader, array $bootstrap): void
    {
        if (($bootstrap['path_hash'] ?? '') !== hash('sha256', $loader)) { throw new RuntimeException('Recovery loader path mismatch.'); }
        $old = $bootstrap['old_hash'] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $old)) { throw new RuntimeException('Invalid loader recovery hash.'); }
        $actual = hash_file('sha256', $loader);
        if (!hash_equals($actual, $old) && !hash_equals($actual, $bootstrap['new_hash'])) {
            throw new RuntimeException('Loader changed during recovery; existing code was preserved for operator review.');
        }
        $backup = $home . '/bootstrap-backups/' . $old . '.php';
        if (is_link($backup) || !is_file($backup) || !hash_equals($old, hash_file('sha256', $backup))) { throw new RuntimeException('Loader recovery backup is missing or modified.'); }
        if (!hash_equals($actual, $old)) { self::atomicLoader($loader, file_get_contents($backup), $old); }
        $previous = $bootstrap['previous_state'] ?? [];
        $signer = AIcontrolInstaller::$signer;
        $payload = $signer === null ? null : AIcontrolSigning::verify($previous, $signer->identity()['public_key']);
        if ($payload === null || ($payload['loader_sha256'] ?? '') !== $old || ($payload['loader_path_sha256'] ?? '') !== hash('sha256', $loader)) { throw new RuntimeException('Saved loader baseline is invalid.'); }
        AIcontrolInstaller::jsonFile($home . '/bootstrap-state.json', $previous);
    }
}
