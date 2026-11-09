<?php
declare(strict_types=1);

/** Standalone bootstrap. First visit sets a PIN; later visits sign in with it. */
const AICONTROL_REPOSITORY = 'efrgdgr0024345/AIcontrol';
const AICONTROL_RELEASE_REF = 'feature/control-panel-foundation';
const AICONTROL_REQUIRED_CHECK = 'planning-panel';

final class AIcontrolPin
{
    private const PREFIX = "<?php exit; ?>\n";

    public static function read(string $marker): array
    {
        if (is_link($marker) || !is_file($marker) || (fileperms($marker) & 0777) !== 0600) {
            throw new RuntimeException('PIN configuration is missing or unsafe. Server recovery is required.');
        }
        $raw = file_get_contents($marker);
        if (!str_starts_with($raw, self::PREFIX)) {
            throw new RuntimeException('PIN configuration is damaged. Server recovery is required.');
        }
        $pointer = json_decode(substr($raw, strlen(self::PREFIX)), true, 8, JSON_THROW_ON_ERROR);
        $directory = $pointer['directory'] ?? '';
        if (!is_string($directory) || realpath($directory) !== $directory || is_link($directory) || (fileperms($directory) & 0777) !== 0700) {
            throw new RuntimeException('PIN storage is missing or unsafe. Server recovery is required.');
        }
        $state = AIcontrolInstaller::readJson($directory . '/auth.json');
        if (($state['version'] ?? null) !== 1 || !is_string($state['hash'] ?? null) || (password_get_info($state['hash'])['algo'] ?? null) === null) {
            throw new RuntimeException('PIN record is damaged. Server recovery is required.');
        }
        return ['directory' => $directory, 'state' => $state];
    }

    public static function relocate(string $marker, string $home): void
    {
        $record = self::read($marker);
        $destination = $home . '/pin';
        if ($record['directory'] === $destination) {
            return;
        }
        if (file_exists($destination) || !mkdir($destination, 0700)) {
            throw new RuntimeException('Existing PIN destination needs server inspection.');
        }
        AIcontrolInstaller::jsonFile($destination . '/auth.json', $record['state']);
        AIcontrolInstaller::writeLocation($marker, ['directory' => $destination]);
        // Old bootstrap copy is no longer authoritative; remove only known files.
        unlink($record['directory'] . '/auth.json');
        if (is_file($record['directory'] . '/attempts.lock')) {
            unlink($record['directory'] . '/attempts.lock');
        }
        @rmdir($record['directory']);
    }

    public static function gate(string $entry, string $marker, bool $secure): ?array
    {
        session_name('aicontrol_pin');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params(['lifetime' => 0, 'path' => $entry, 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
        if (!session_start()) {
            throw new RuntimeException('PIN session storage is unavailable.');
        }
        $_SESSION['pin_csrf'] ??= bin2hex(random_bytes(32));
        $record = file_exists($marker) || is_link($marker) ? self::read($marker) : null;
        if ($record === null) {
            $bootstrap = realpath(sys_get_temp_dir()) . '/aicontrol-pin-' . hash('sha256', __FILE__);
            $location = dirname($marker) . '/.aicontrol-location.php';
            $knownEnrollment = file_exists($bootstrap);
            if (is_file($location)) {
                $raw = file_get_contents($location);
                if (!str_starts_with($raw, self::PREFIX)) {
                    throw new RuntimeException('Installation configuration needs server recovery.');
                }
                $existing = json_decode(substr($raw, strlen(self::PREFIX)), true, 16, JSON_THROW_ON_ERROR);
                $knownEnrollment = $knownEnrollment || ($existing['pin_required'] ?? false)
                    || (is_string($existing['home'] ?? null) && is_dir($existing['home'] . '/pin'));
            }
            if ($knownEnrollment) {
                throw new RuntimeException('PIN enrollment marker is missing. Server recovery is required.');
            }
        }
        $error = '';
        $saved = false;
        if (isset($_POST['pin_action'])) {
            $field = static fn(string $name): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096 || !hash_equals($_SESSION['pin_csrf'], $field('pin_csrf'))) {
                http_response_code(403);
                $error = 'Form expired. Reload and try again.';
            } elseif ($field('pin_action') === 'setup' && $record === null) {
                $pin = $field('pin');
                if (!preg_match('/^[0-9]{6,12}$/D', $pin) || !hash_equals($pin, $field('pin_confirm'))) {
                    http_response_code(422);
                    $error = 'Choose a matching PIN of 6–12 digits.';
                } else {
                    $base = realpath(sys_get_temp_dir());
                    foreach ([__DIR__, $_SERVER['DOCUMENT_ROOT'] ?? __DIR__] as $root) {
                        $public = realpath($root);
                        if ($base === false || $public === false || $public === '/' || $base === $public || str_starts_with($base . '/', $public . '/')) {
                            throw new RuntimeException('Server temporary storage must be outside the website.');
                        }
                    }
                    $directory = $base . '/aicontrol-pin-' . hash('sha256', __FILE__);
                    $enrollment = @fopen($marker, 'x');
                    if (!$enrollment) {
                        throw new RuntimeException('A PIN has already been set. Reload to sign in.');
                    }
                    chmod($marker, 0600);
                    try {
                        if (file_exists($directory) || !mkdir($directory, 0700)) {
                            throw new RuntimeException('Existing PIN bootstrap storage needs server inspection.');
                        }
                        AIcontrolInstaller::jsonFile($directory . '/auth.json', ['version' => 1, 'hash' => password_hash($pin, PASSWORD_DEFAULT), 'failures' => 0, 'window' => time()]);
                        $pointer = self::PREFIX . json_encode(['directory' => $directory], JSON_THROW_ON_ERROR);
                        if (fwrite($enrollment, $pointer) !== strlen($pointer) || !fflush($enrollment)) {
                            throw new RuntimeException('PIN enrollment could not be retained.');
                        }
                    } finally {
                        fclose($enrollment);
                        unset($pin);
                    }
                    $_SESSION = ['pin_csrf' => bin2hex(random_bytes(32))];
                    session_regenerate_id(true);
                    $saved = true; // Do not authenticate enrollment: the next visit signs in.
                    $record = self::read($marker);
                }
            } elseif ($field('pin_action') === 'login' && $record !== null) {
                $lockPath = $record['directory'] . '/attempts.lock';
                if (is_link($lockPath)) {
                    throw new RuntimeException('Unsafe PIN attempt state.');
                }
                $lock = fopen($lockPath, 'c');
                if (!$lock || !flock($lock, LOCK_EX)) {
                    throw new RuntimeException('PIN attempt state is unavailable.');
                }
                try {
                    $state = AIcontrolInstaller::readJson($record['directory'] . '/auth.json');
                    if (time() - $state['window'] >= 600) {
                        $state['window'] = time();
                        $state['failures'] = 0;
                    }
                    if ($state['failures'] >= 5) {
                        http_response_code(429);
                        header('Retry-After: 600');
                        $error = 'Too many incorrect PINs. Try again in ten minutes.';
                    } elseif (preg_match('/^[0-9]{6,12}$/D', $field('pin')) && password_verify($field('pin'), $state['hash'])) {
                        $state['failures'] = 0;
                        session_regenerate_id(true);
                        $_SESSION = ['pin_csrf' => bin2hex(random_bytes(32)), 'pin_identity' => hash('sha256', $state['hash']), 'pin_until' => time() + 1800];
                        AIcontrolInstaller::jsonFile($record['directory'] . '/auth.json', $state);
                        session_write_close();
                        header('Location: ' . $entry, true, 303);
                        return null;
                    } else {
                        $state['failures']++;
                        http_response_code(401);
                        $error = 'Incorrect PIN.';
                    }
                    AIcontrolInstaller::jsonFile($record['directory'] . '/auth.json', $state);
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            } elseif ($field('pin_action') === 'logout') {
                $_SESSION = ['pin_csrf' => bin2hex(random_bytes(32))];
                session_regenerate_id(true);
            } else {
                http_response_code(409);
                $error = 'PIN setup is already complete. Sign in with the existing PIN.';
            }
        }
        if (!$saved && $record !== null && ($_SESSION['pin_until'] ?? 0) > time()
            && hash_equals(hash('sha256', $record['state']['hash']), $_SESSION['pin_identity'] ?? '')) {
            if (($_GET['pin'] ?? '') === 'manage') {
                $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                header('Content-Type: text/html; charset=utf-8');
                echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>PIN session</title><main><h1>PIN session</h1><form method="post" action="' . $escape($entry) . '"><input type="hidden" name="pin_action" value="logout"><input type="hidden" name="pin_csrf" value="' . $escape($_SESSION['pin_csrf']) . '"><button>Lock panel</button></form><p><a href="' . $escape($entry) . '">Return to panel</a></p></main></html>';
                session_write_close();
                return null;
            }
            $result = ['identity' => $_SESSION['pin_identity'], 'csrf' => $_SESSION['pin_csrf']];
            session_write_close();
            session_id(''); // Installer/panel sessions must not reuse and overwrite the PIN session file.
            return $result;
        }
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        header('Content-Type: text/html; charset=utf-8');
        $setup = $record === null;
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AIcontrol PIN</title><main><h1>' . ($saved ? 'PIN saved' : ($setup ? 'Set your PIN' : 'Enter your PIN')) . '</h1>';
        if ($saved) {
            echo '<p>Your PIN is set. On your next visit, sign in to set up the application.</p><p><a href="' . $escape($entry) . '">Continue to sign in</a></p>';
        } else {
            echo '<p>' . $escape($error) . '</p><form method="post" action="' . $escape($entry) . '"><input type="hidden" name="pin_action" value="' . ($setup ? 'setup' : 'login') . '"><input type="hidden" name="pin_csrf" value="' . $escape($_SESSION['pin_csrf']) . '"><label>PIN <input type="password" inputmode="numeric" name="pin" pattern="[0-9]{6,12}" minlength="6" maxlength="12" autocomplete="' . ($setup ? 'new-password' : 'current-password') . '" required></label>';
            if ($setup) {
                echo '<p><label>Confirm PIN <input type="password" inputmode="numeric" name="pin_confirm" pattern="[0-9]{6,12}" minlength="6" maxlength="12" autocomplete="new-password" required></label></p><p>Choose 6–12 digits.</p>';
            }
            echo '<button>' . ($setup ? 'Set PIN' : 'Sign in') . '</button></form>';
        }
        echo '</main></html>';
        session_write_close();
        return null;
    }
}

/** Server-held installation identity. Never serialize or return its private key. */
final class AIcontrolSigning
{
    private string $secret;
    private string $public;
    private string $home;

    public function __construct(string $home, ?string $pinnedPublic, bool $allowCreation = false)
    {
        $this->home = $home;
        $directory = $home . '/signing';
        if (!is_dir($directory)) {
            if (!$allowCreation || $pinnedPublic !== null || file_exists($directory) || is_link($directory) || !mkdir($directory, 0700)) {
                throw new RuntimeException('Signing identity is missing; refusing to replace it.');
            }
            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            $public = sodium_crypto_sign_publickey($pair);
            $file = fopen($directory . '/identity.json', 'x');
            if (!$file) {
                throw new RuntimeException('Cannot create signing identity.');
            }
            chmod($directory . '/identity.json', 0600);
            $json = json_encode(['version' => 1, 'algorithm' => 'Ed25519', 'public_key' => base64_encode($public), 'private_key' => base64_encode($secret)], JSON_THROW_ON_ERROR);
            $expectedLength = strlen($json);
            $written = fwrite($file, $json);
            fflush($file);
            fclose($file);
            sodium_memzero($pair);
            sodium_memzero($secret);
            sodium_memzero($json);
            if ($written !== $expectedLength) {
                throw new RuntimeException('Cannot store signing identity.');
            }
            mkdir($home . '/receipts', 0700);
        }
        foreach ([$directory, $home . '/receipts'] as $path) {
            clearstatcache(true, $path);
            if (is_link($path) || realpath($path) !== $path || (fileperms($path) & 0777) !== 0700) {
                throw new RuntimeException('Unsafe signing storage.');
            }
        }
        $keyFile = $directory . '/identity.json';
        if (!is_file($keyFile) || is_link($keyFile)) {
            throw new RuntimeException('Signing identity is missing; refusing to replace it.');
        }
        if (function_exists('posix_geteuid') && fileowner($keyFile) !== posix_geteuid()) {
            throw new RuntimeException('Signing identity owner mismatch.');
        }
        $identity = AIcontrolInstaller::readJson($keyFile);
        $secret = base64_decode($identity['private_key'] ?? '', true);
        $public = base64_decode($identity['public_key'] ?? '', true);
        if (($identity['version'] ?? null) !== 1 || ($identity['algorithm'] ?? '') !== 'Ed25519'
            || $secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || $public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('Signing identity is damaged.');
        }
        $derived = sodium_crypto_sign_seed_keypair(substr($secret, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $matches = hash_equals(sodium_crypto_sign_secretkey($derived), $secret)
            && hash_equals(sodium_crypto_sign_publickey($derived), $public);
        sodium_memzero($derived);
        if (!$matches || ($pinnedPublic !== null && !hash_equals($pinnedPublic, base64_encode($public)))) {
            throw new RuntimeException('Signing identity does not match its pinned public key.');
        }
        $this->secret = $secret;
        $this->public = $public;
        unset($identity);
    }

    public function __destruct()
    {
        if (isset($this->secret)) {
            sodium_memzero($this->secret);
        }
    }

    public function identity(): array
    {
        return ['version' => 1, 'algorithm' => 'Ed25519', 'key_id' => hash('sha256', $this->public), 'public_key' => base64_encode($this->public)];
    }

    public static function canonical(array $payload): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (is_array($value)) {
                if (!array_is_list($value)) {
                    ksort($value, SORT_STRING);
                }
                foreach ($value as $key => $item) {
                    $value[$key] = $sort($item);
                }
            } elseif (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) {
                throw new RuntimeException('Unsupported signing payload.');
            }
            return $value;
        };
        return json_encode($sort($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function sign(array $payload): array
    {
        $raw = self::canonical($payload);
        return $this->identity() + ['payload' => base64_encode($raw), 'signature' => base64_encode(sodium_crypto_sign_detached("AIcontrol/receipt/v1\0" . $raw, $this->secret))];
    }

    /** A separately pinned key is mandatory; never trust the envelope's key alone. */
    public static function verify(array $envelope, string $pinnedPublic): ?array
    {
        try {
            $public = base64_decode($pinnedPublic, true);
            $payload = base64_decode($envelope['payload'] ?? '', true);
            $signature = base64_decode($envelope['signature'] ?? '', true);
            if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || $payload === false || strlen($payload) > 32768 || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
                || ($envelope['version'] ?? null) !== 1 || ($envelope['algorithm'] ?? '') !== 'Ed25519'
                || !hash_equals($pinnedPublic, $envelope['public_key'] ?? '')
                || !hash_equals(hash('sha256', $public), $envelope['key_id'] ?? '')
                || !sodium_crypto_sign_verify_detached($signature, "AIcontrol/receipt/v1\0" . $payload, $public)) {
                return null;
            }
            $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) && self::canonical($decoded) === $payload ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function record(array $payload): array
    {
        $id = bin2hex(random_bytes(16));
        $envelope = $this->sign($payload + ['receipt_id' => $id, 'recorded_at' => gmdate('c')]);
        $file = fopen($this->home . '/receipts/' . $id . '.json', 'x');
        if (!$file) {
            throw new RuntimeException('Cannot retain a signed receipt.');
        }
        try {
            $json = json_encode($envelope, JSON_THROW_ON_ERROR);
            if (fwrite($file, $json) !== strlen($json) || !fflush($file)) {
                throw new RuntimeException('Cannot retain a complete signed receipt.');
            }
        } finally {
            fclose($file);
        }
        return $envelope;
    }
}

final class AIcontrolInstaller
{
    public const LOCATION_PREFIX = "<?php exit; ?>\n";
    public static ?AIcontrolSigning $signer = null;
    public static array $operation = [];

    public static function writeLocation(string $location, array $config): void
    {
        $temporary = $location . '.' . bin2hex(random_bytes(8));
        $data = self::LOCATION_PREFIX . json_encode($config, JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $data, LOCK_EX) !== strlen($data) || !chmod($temporary, 0600) || !rename($temporary, $location)) {
            throw new RuntimeException('Cannot retain loader location and signing pin.');
        }
    }

    public static function sha(string $sha): string
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $sha)) {
            throw new RuntimeException('A full Git commit SHA is required.');
        }
        return $sha;
    }

    public static function jsonFile(string $file, array $value): void
    {
        $temporary = $file . '.' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Cannot write installation state.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $file)) {
            unlink($temporary);
            throw new RuntimeException('Cannot activate installation state.');
        }
    }

    public static function readJson(string $file): array
    {
        if (is_link($file) || !is_file($file) || (fileperms($file) & 0777) !== 0600) {
            throw new RuntimeException('Missing or unsafe installation state.');
        }
        $value = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('Invalid installation state.');
        }
        return $value;
    }

    public static function home(string $path, array $roots, bool $create = false): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || rtrim($path, '/') !== $path) {
            throw new RuntimeException('Use a canonical absolute private-directory path.');
        }
        $parent = realpath(dirname($path));
        if ($parent === false || $parent . '/' . basename($path) !== $path || is_link($path)) {
            throw new RuntimeException('The private directory parent must already exist without symlink aliases.');
        }
        foreach ($roots as $root) {
            if (!is_string($root) || ($public = realpath($root)) === false || $public === '/'
                || $path === $public || str_starts_with($path . '/', $public . '/')) {
                throw new RuntimeException('Private storage must be outside every document root.');
            }
        }
        if ($create && !file_exists($path) && !mkdir($path, 0700)) {
            throw new RuntimeException('Cannot create private storage.');
        }
        if (file_exists($path) && (!is_dir($path) || realpath($path) !== $path || (fileperms($path) & 0777) !== 0700)) {
            throw new RuntimeException('Private storage must have permissions 0700.');
        }
        return $path;
    }

    public static function request(string $url): string
    {
        for ($redirect = 0; $redirect < 4; $redirect++) {
            $parts = parse_url($url);
            if (($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', ['api.github.com', 'codeload.github.com'], true)
                || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
                throw new RuntimeException('GitHub returned an unapproved download destination.');
            }
            $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
            if (self::$signer === null) {
                throw new RuntimeException('A server signing identity is required before GitHub requests.');
            }
            $requestId = bin2hex(random_bytes(16));
            $started = microtime(true);
            $proof = self::$signer->record(self::$operation + [
                'event' => 'github.request', 'request_id' => $requestId, 'method' => 'GET',
                'destination_host' => $parts['host'], 'url_sha256' => hash('sha256', $url),
                'issued_at' => time(), 'expires_at' => time() + 120, 'nonce' => bin2hex(random_bytes(16)),
            ]);
            $headers[] = 'X-AIcontrol-Key-Id: ' . $proof['key_id'];
            $headers[] = 'X-AIcontrol-Payload: ' . $proof['payload'];
            $headers[] = 'X-AIcontrol-Signature: ' . $proof['signature'];
            $token = getenv('AICONTROL_GITHUB_TOKEN');
            if ($parts['host'] === 'api.github.com' && is_string($token) && $token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
            $body = '';
            $providerRequestId = null;
            $client = curl_init($url);
            curl_setopt_array($client, [
                CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'AIcontrol-standalone-loader/1',
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$providerRequestId): int {
                    if (preg_match('/^x-github-request-id:\s*([A-Za-z0-9:-]{1,128})\s*$/i', trim($line), $match)) {
                        $providerRequestId = $match[1];
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 10 * 1024 * 1024) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($client);
            $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE);
            $next = curl_getinfo($client, CURLINFO_REDIRECT_URL);
            curl_close($client);
            self::$signer->record(self::$operation + [
                'event' => 'github.response', 'request_id' => $requestId,
                'request_receipt_id' => AIcontrolSigning::verify($proof, $proof['public_key'])['receipt_id'],
                'http_status' => $status, 'transport_succeeded' => $ok !== false,
                'provider_request_id' => $providerRequestId, 'body_sha256' => hash('sha256', $body),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
            if ($ok === false) {
                throw new RuntimeException('GitHub download failed TLS, size, timeout or connectivity checks.');
            }
            if ($status === 200) {
                return $body;
            }
            if (in_array($status, [301, 302, 303, 307, 308], true) && $next !== '') {
                $url = $next;
                continue;
            }
            throw new RuntimeException('GitHub request failed (HTTP ' . $status . '). Check repository access and API limits.');
        }
        throw new RuntimeException('Too many GitHub redirects.');
    }

    public static function check(string $ref): array
    {
        if (!preg_match('~^[A-Za-z0-9_./-]{1,160}$~D', $ref)) {
            throw new RuntimeException('Invalid GitHub ref.');
        }
        $base = 'https://api.github.com/repos/' . AICONTROL_REPOSITORY;
        $commit = json_decode(self::request($base . '/commits/' . rawurlencode($ref)), true, 64, JSON_THROW_ON_ERROR);
        $sha = self::sha($commit['sha'] ?? '');
        self::$operation['source_commit'] = $sha;
        self::checks($sha);
        $file = json_decode(self::request($base . '/contents/release-manifest.json?ref=' . $sha), true, 32, JSON_THROW_ON_ERROR);
        $manifest = base64_decode(str_replace("\n", '', $file['content'] ?? ''), true);
        if ($manifest === false) {
            throw new RuntimeException('GitHub manifest is unavailable.');
        }
        self::manifest($manifest);
        if (json_decode($manifest, true, 32, JSON_THROW_ON_ERROR)['version'] !== 2) {
            throw new RuntimeException('Select a current password-free release (manifest version 2).');
        }
        return ['sha' => $sha, 'manifest_hash' => hash('sha256', $manifest), 'checked_at' => time(), 'ref' => $ref];
    }

    public static function checks(string $sha): void
    {
        $base = 'https://api.github.com/repos/' . AICONTROL_REPOSITORY;
        $checks = json_decode(self::request($base . '/commits/' . self::sha($sha) . '/check-runs?per_page=100'), true, 64, JSON_THROW_ON_ERROR);
        $found = false;
        foreach ($checks['check_runs'] ?? [] as $check) {
            if (($check['name'] ?? '') === AICONTROL_REQUIRED_CHECK) {
                if (($check['head_sha'] ?? '') !== $sha || ($check['status'] ?? '') !== 'completed'
                    || ($check['conclusion'] ?? '') !== 'success' || ($check['app']['slug'] ?? '') !== 'github-actions') {
                    throw new RuntimeException('The required GitHub Actions check has not passed for this commit.');
                }
                $found = true;
            }
        }
        if (!$found) {
            throw new RuntimeException('Required check planning-panel is missing. Wait for GitHub Actions, then check again.');
        }
    }

    public static function manifest(string $raw): array
    {
        $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!in_array($manifest['version'] ?? null, [1, 2], true) || !is_array($manifest['files'] ?? null)
            || count($manifest['files']) < 3 || count($manifest['files']) > 100) {
            throw new RuntimeException('Invalid release manifest.');
        }
        foreach ($manifest['files'] as $path => $hash) {
            if (!is_string($path) || !preg_match('~^(app\.php|src/[A-Za-z0-9_-]+\.php|assets/panel\.css)$~D', $path)
                || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new RuntimeException('Unsafe release manifest entry.');
            }
        }
        $required = ['app.php', 'src/Panel.php', 'src/PanelStore.php', 'src/PrivateStorage.php', 'src/Bootstrap.php', 'src/HostingRequirements.php', 'assets/panel.css'];
        if ($manifest['version'] === 2) {
            $required[] = 'src/AccessPolicy.php';
        }
        foreach ($required as $path) {
            if (!isset($manifest['files'][$path])) {
                throw new RuntimeException('Release manifest is incomplete.');
            }
        }
        return $manifest['files'];
    }

    public static function selection(array $selection, string $sha): void
    {
        if (($selection['sha'] ?? '') !== self::sha($sha) || !is_int($selection['checked_at'] ?? null)
            || time() - $selection['checked_at'] >= 600 || $selection['checked_at'] > time()
            || !preg_match('/^[a-f0-9]{64}$/D', $selection['manifest_hash'] ?? '')) {
            throw new RuntimeException('Selection changed or expired. Check GitHub again.');
        }
    }

    public static function selectionPayload(array $selection): array
    {
        return [
            'event' => 'selection.checked', 'repository' => AICONTROL_REPOSITORY,
            'source_commit' => self::sha($selection['sha']), 'manifest_hash' => $selection['manifest_hash'],
            'destination_sha256' => hash('sha256', $selection['home']),
            'roots_sha256' => hash('sha256', AIcontrolSigning::canonical($selection['roots'])),
            'access_mode' => $selection['access_mode'], 'host_identity' => $selection['host_identity'],
            'checked_at' => $selection['checked_at'], 'expires_at' => $selection['checked_at'] + 600,
            'correlation_id' => $selection['correlation_id'], 'ref' => $selection['ref'], 'uses' => 1,
        ];
    }

    public static function verifySelection(array $selection, AIcontrolSigning $signer): void
    {
        $payload = AIcontrolSigning::verify($selection['proof'] ?? [], $signer->identity()['public_key']);
        if ($payload === null) {
            throw new RuntimeException('Checked selection signature is invalid.');
        }
        unset($payload['receipt_id'], $payload['recorded_at']);
        if (AIcontrolSigning::canonical($payload) !== AIcontrolSigning::canonical(self::selectionPayload($selection))) {
            throw new RuntimeException('Checked selection changed after signing. Check GitHub again.');
        }
    }

    public static function stage(string $zipFile, string $stage, array $selection): void
    {
        self::selection($selection, $selection['sha'] ?? '');
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('Invalid GitHub archive.');
        }
        try {
            $first = $zip->statIndex(0);
            $folder = explode('/', $first['name'] ?? '')[0];
            if (!preg_match('/^(?:AIcontrol|efrgdgr0024345-AIcontrol)-([a-f0-9]{7,40})$/D', $folder, $match)
                || !str_starts_with($selection['sha'], $match[1])) {
                throw new RuntimeException('Archive root does not match the checked commit.');
            }
            $prefix = $folder . '/';
            $names = [];
            $size = 0;
            if ($zip->numFiles > 1000) {
                throw new RuntimeException('Archive has too many entries.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'];
                $zip->getExternalAttributesIndex($index, $os, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if (!str_starts_with($name, $prefix) || !preg_match('~^[A-Za-z0-9_./-]+$~D', $name)
                    || preg_match('~(^|/)\.{1,2}(/|$)~', $name) || str_contains($name, '//') || isset($names[$name])
                    || !in_array($type, [0, 0100000, 0040000], true) || $stat['size'] > 2 * 1024 * 1024) {
                    throw new RuntimeException('Archive contains an unsafe, duplicate or oversized entry.');
                }
                $size += $stat['size'];
                if ($size > 20 * 1024 * 1024) {
                    throw new RuntimeException('Archive expands beyond the size limit.');
                }
                $names[$name] = $index;
            }
            $manifestName = $prefix . 'release-manifest.json';
            $raw = isset($names[$manifestName]) ? $zip->getFromIndex($names[$manifestName]) : false;
            if ($raw === false || !hash_equals($selection['manifest_hash'], hash('sha256', $raw))) {
                throw new RuntimeException('Archive does not match the checked manifest.');
            }
            $files = self::manifest($raw);
            if (file_exists($stage) || !mkdir($stage, 0700)) {
                throw new RuntimeException('Cannot create a fresh staging directory.');
            }
            foreach ($files as $path => $hash) {
                $data = isset($names[$prefix . $path]) ? $zip->getFromIndex($names[$prefix . $path]) : false;
                if ($data === false || !hash_equals($hash, hash('sha256', $data))) {
                    throw new RuntimeException('A release file failed its checksum.');
                }
                if (str_ends_with($path, '.php')) {
                    token_get_all($data, TOKEN_PARSE);
                }
                $destination = $stage . '/' . $path;
                if (!is_dir(dirname($destination))) {
                    mkdir(dirname($destination), 0700, true);
                }
                if (file_put_contents($destination, $data) !== strlen($data)) {
                    throw new RuntimeException('Cannot write a staged release file.');
                }
            }
            file_put_contents($stage . '/release-manifest.json', $raw);
        } finally {
            $zip->close();
        }
    }

    public static function verify(string $release, string $manifestHash): void
    {
        if (is_link($release) || !is_dir($release)) {
            throw new RuntimeException('Release directory is unsafe.');
        }
        $raw = file_get_contents($release . '/release-manifest.json');
        if (!hash_equals($manifestHash, hash('sha256', $raw))) {
            throw new RuntimeException('Installed manifest was modified.');
        }
        $files = self::manifest($raw);
        $seen = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($release, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($release) + 1);
            if ($file->isLink() || !($file->isFile()) || ($relative !== 'release-manifest.json' && !isset($files[$relative]))) {
                throw new RuntimeException('Unexpected local release modification.');
            }
            $seen[$relative] = true;
        }
        foreach ($files as $path => $hash) {
            if (!isset($seen[$path]) || !hash_equals($hash, hash_file('sha256', $release . '/' . $path))) {
                throw new RuntimeException('Installed release was modified.');
            }
        }
    }

    public static function removeStage(string $stage): void
    {
        if (!preg_match('/^stage-[a-f0-9]{16}$/D', basename($stage)) || !is_dir($stage) || is_link($stage)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($stage);
    }

    public static function recover(string $home): void
    {
        if (!file_exists($home . '/journal.json')) {
            return;
        }
        $journal = self::readJson($home . '/journal.json');
        if (($journal['phase'] ?? '') === 'activating') {
            if (is_array($journal['previous'] ?? null)) {
                self::jsonFile($home . '/current.json', $journal['previous']);
            } elseif (is_file($home . '/current.json')) {
                unlink($home . '/current.json');
            }
            $journal['phase'] = 'recovered';
            self::jsonFile($home . '/journal.json', $journal);
        }
    }

    public static function activate(string $home, array $target, callable $health): void
    {
        $previous = is_file($home . '/current.json') ? self::readJson($home . '/current.json') : null;
        self::jsonFile($home . '/journal.json', ['phase' => 'activating', 'previous' => $previous, 'target' => $target]);
        self::jsonFile($home . '/current.json', $target);
        try {
            $health();
            self::jsonFile($home . '/journal.json', ['phase' => 'healthy', 'previous' => $previous, 'target' => $target]);
        } catch (Throwable $error) {
            self::recover($home);
            throw new RuntimeException('Health check failed; previous code restored. Private data was retained.', 0, $error);
        }
    }
}

if (defined('AICONTROL_LOADER_TEST')) {
    return;
}

ini_set('display_errors', '0');
umask(0077);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$entry = $_SERVER['SCRIPT_NAME'] ?? '/loader.php';
$entry = preg_match('~^/[A-Za-z0-9_./-]+\.php$~D', $entry) ? $entry : '/loader.php';
$location = __DIR__ . '/.aicontrol-location.php';
$home = null;
$message = '';
try {
    if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID >= 80600) {
        throw new RuntimeException('PHP 8.2–8.5 is required.');
    }
    foreach (['curl', 'zip', 'mbstring', 'pdo_sqlite', 'session', 'tokenizer', 'sodium'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Enable the PHP ' . $extension . ' extension.');
        }
    }
    $secure = in_array($_SERVER['HTTPS'] ?? '', ['on', '1'], true);
    $local = getenv('AICONTROL_LOCAL_DEV') === '1' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$secure && !$local) {
        throw new RuntimeException('Open the installer over HTTPS.');
    }
    $pinMarker = __DIR__ . '/.aicontrol-pin.php';
    $pinAccess = AIcontrolPin::gate($entry, $pinMarker, $secure);
    if ($pinAccess === null) {
        return;
    }
    $accessMode = 'pin';
    putenv('AICONTROL_PIN_VERIFIED=1');
    if (file_exists($location)) {
        if (is_link($location) || (fileperms($location) & 0777) !== 0600) {
            throw new RuntimeException('Unsafe loader location file.');
        }
        $raw = file_get_contents($location);
        if (!str_starts_with($raw, AIcontrolInstaller::LOCATION_PREFIX)) {
            throw new RuntimeException('Invalid loader location file.');
        }
        $config = json_decode(substr($raw, strlen(AIcontrolInstaller::LOCATION_PREFIX)), true, 32, JSON_THROW_ON_ERROR);
        $home = AIcontrolInstaller::home($config['home'], array_merge([__DIR__, $_SERVER['DOCUMENT_ROOT'] ?: __DIR__], $config['roots']));
        if (!is_dir($home)) {
            throw new RuntimeException('Private installation state is missing.');
        }
        if (realpath($home . '/releases') !== $home . '/releases' || (fileperms($home . '/releases') & 0777) !== 0700) {
            throw new RuntimeException('Unsafe releases directory.');
        }
        $lockPath = $home . '/deployment.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Unsafe deployment lock.');
        }
        $lock = fopen($lockPath, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('An installation is in progress. Try again shortly.');
        }
        AIcontrolPin::relocate($pinMarker, $home);
        if (!($config['pin_required'] ?? false)) {
            $config['pin_required'] = true;
            AIcontrolInstaller::writeLocation($location, $config);
        }
        $signer = new AIcontrolSigning($home, $config['signing_public_key'] ?? null, !isset($config['signing_public_key']));
        AIcontrolInstaller::$signer = $signer;
        if (!isset($config['signing_public_key'])) {
            $config['signing_public_key'] = $signer->identity()['public_key'];
            AIcontrolInstaller::writeLocation($location, $config);
        }
        $wasInterrupted = is_file($home . '/journal.json') && (AIcontrolInstaller::readJson($home . '/journal.json')['phase'] ?? '') === 'activating';
        AIcontrolInstaller::recover($home);
        if ($wasInterrupted) {
            $signer->record(['event' => 'update.recovered', 'repository' => AICONTROL_REPOSITORY, 'outcome' => 'prior_code_pointer_restored']);
        }
        if (isset($_GET['identity']) || isset($_GET['receipt'])) {
            $export = $signer->identity();
            if (isset($_GET['receipt'])) {
                if (!is_string($_GET['receipt']) || !preg_match('/^[a-f0-9]{32}$/D', $_GET['receipt'])) {
                    throw new RuntimeException('Invalid receipt reference.');
                }
                $export = AIcontrolInstaller::readJson($home . '/receipts/' . $_GET['receipt'] . '.json');
                if (AIcontrolSigning::verify($export, $signer->identity()['public_key']) === null) {
                    throw new RuntimeException('Receipt verification failed.');
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            header('Content-Type: application/json');
            echo json_encode($export, JSON_THROW_ON_ERROR);
            return;
        }
        if (!isset($_GET['installer']) && is_file($home . '/current.json')) {
            $current = AIcontrolInstaller::readJson($home . '/current.json');
            $release = $home . '/releases/' . AIcontrolInstaller::sha($current['sha']);
            AIcontrolInstaller::verify($release, $current['manifest_hash']);
            flock($lock, LOCK_UN);
            fclose($lock);
            if (($_GET['asset'] ?? '') === 'panel.css') {
                header('Content-Type: text/css; charset=utf-8');
                readfile($release . '/assets/panel.css');
                return;
            }
            putenv('AICONTROL_PRIVATE_DIR=' . $home . '/runtime');
            putenv('AICONTROL_PIN_VERIFIED=1');
            require $release . '/app.php';
            return;
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    session_name('aicontrol_installer');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params(['path' => $entry, 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $identity = $pinAccess['identity'];
    if (($_SESSION['host_identity'] ?? '') !== $identity) {
        session_regenerate_id(true);
        $_SESSION = ['host_identity' => $identity];
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $input = static function (string $name): string {
        if (!is_string($_POST[$name] ?? '')) {
            throw new RuntimeException('Invalid form input.');
        }
        return $_POST[$name] ?? '';
    };
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384 || !hash_equals($_SESSION['csrf'], $input('csrf'))) {
            throw new RuntimeException('Request verification failed.');
        }
        $action = $input('action');
        if ($action === 'check') {
            unset($_SESSION['selection']);
            $roots = array_values(array_unique(array_merge([__DIR__, $_SERVER['DOCUMENT_ROOT'] ?: __DIR__], array_filter(array_map('trim', explode("\n", $input('roots')))))));
            if ($home !== null) {
                $roots = $config['roots'];
            }
            $directory = $home ?? AIcontrolInstaller::home(trim($input('home')), $roots);
            if ($home === null && file_exists($directory) && count(scandir($directory)) > 2) {
                throw new RuntimeException('Choose a new, empty private directory; existing files will not be overwritten.');
            }
            if ($home === null) {
                $home = AIcontrolInstaller::home($directory, $roots, true);
                if (count(scandir($home)) > 2) {
                    throw new RuntimeException('Private directory changed before initialization.');
                }
                mkdir($home . '/releases', 0700);
                AIcontrolInstaller::jsonFile($home . '/bootstrap.json', ['access_mode' => $accessMode, 'roots' => $roots]);
                AIcontrolPin::relocate($pinMarker, $home);
                $signer = new AIcontrolSigning($home, null, true);
                AIcontrolInstaller::$signer = $signer;
                if (file_exists($location)) {
                    throw new RuntimeException('A loader location already exists.');
                }
                AIcontrolInstaller::writeLocation($location, ['home' => $home, 'roots' => $roots, 'signing_public_key' => $signer->identity()['public_key'], 'pin_required' => true]);
            }
            $correlationId = bin2hex(random_bytes(16));
            AIcontrolInstaller::$operation = ['correlation_id' => $correlationId, 'repository' => AICONTROL_REPOSITORY, 'source_commit' => null];
            $selection = AIcontrolInstaller::check($input('ref'));
            $selection['home'] = $directory;
            $selection['roots'] = $roots;
            $selection['access_mode'] = $accessMode;
            $selection['host_identity'] = $identity;
            $selection['correlation_id'] = $correlationId;
            $selection['proof'] = $signer->record(AIcontrolInstaller::selectionPayload($selection));
            $_SESSION['selection'] = $selection;
            $message = 'GitHub checked. Review the exact commit below; the selection expires in ten minutes.';
        } elseif ($action === 'install') {
            $selected = $_SESSION['selection'] ?? [];
            AIcontrolInstaller::selection($selected, $input('sha'));
            if ($home === null || !isset($signer) || ($selected['host_identity'] ?? '') !== $identity) {
                throw new RuntimeException('The checked signing identity is unavailable. Check GitHub again.');
            }
            AIcontrolInstaller::verifySelection($selected, $signer);
            unset($_SESSION['selection']); // one checked selection permits one installation attempt
            $lock = fopen($home . '/deployment.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another installation is in progress.');
            }
            AIcontrolInstaller::$operation = ['correlation_id' => $selected['correlation_id'], 'repository' => AICONTROL_REPOSITORY, 'source_commit' => $selected['sha'], 'manifest_hash' => $selected['manifest_hash']];
            $activated = false;
            try {
                AIcontrolInstaller::recover($home);
                if (is_file($home . '/current.json')) {
                    $current = AIcontrolInstaller::readJson($home . '/current.json');
                    AIcontrolInstaller::verify($home . '/releases/' . AIcontrolInstaller::sha($current['sha']), $current['manifest_hash']);
                }
                $signer->record(AIcontrolInstaller::$operation + ['event' => 'update.requested', 'prior_commit' => $current['sha'] ?? null]);
                AIcontrolInstaller::checks($selected['sha']);
                $archive = AIcontrolInstaller::request('https://api.github.com/repos/' . AICONTROL_REPOSITORY . '/zipball/' . $selected['sha']);
                $zipPath = $home . '/download-' . bin2hex(random_bytes(8)) . '.zip';
                file_put_contents($zipPath, $archive);
                $stage = $home . '/releases/stage-' . bin2hex(random_bytes(8));
                try {
                    AIcontrolInstaller::stage($zipPath, $stage, $selected);
                } catch (Throwable $error) {
                    AIcontrolInstaller::removeStage($stage);
                    throw $error;
                } finally {
                    unlink($zipPath);
                }
                $release = $home . '/releases/' . $selected['sha'];
                if (file_exists($release)) {
                    AIcontrolInstaller::removeStage($stage);
                    AIcontrolInstaller::verify($release, $selected['manifest_hash']);
                } elseif (!rename($stage, $release)) {
                    throw new RuntimeException('Cannot retain the staged release.');
                }
                $target = ['sha' => $selected['sha'], 'manifest_hash' => $selected['manifest_hash']];
                AIcontrolInstaller::activate($home, $target, static function () use ($home, $release, $selected): void {
                    AIcontrolInstaller::verify($release, $selected['manifest_hash']);
                    require_once $release . '/src/PrivateStorage.php';
                    require_once $release . '/src/PanelStore.php';
                    $runtime = $home . '/runtime';
                    if (!is_dir($runtime)) {
                        $journal = AIcontrolInstaller::readJson($home . '/journal.json');
                        if (!is_file($home . '/bootstrap.json') || ($journal['previous'] ?? null) !== null) {
                            throw new RuntimeException('Existing runtime is missing; refusing to recreate private state.');
                        }
                        $bootstrap = AIcontrolInstaller::readJson($home . '/bootstrap.json');
                        mkdir($runtime, 0700);
                        new \AIcontrol\PanelStore($runtime . '/panel.sqlite', true);
                        mkdir($runtime . '/sessions', 0700);
                        AIcontrolInstaller::jsonFile($runtime . '/config.json', ['version' => 2, 'public_roots' => $bootstrap['roots'], 'access_mode' => $bootstrap['access_mode']]);
                    }
                    $runtimeConfig = \AIcontrol\PrivateStorage::config($runtime);
                    if ($runtimeConfig['access_mode'] === 'password') {
                        // Explicit no-app-password upgrade. Retain the legacy hash/version
                        // for compatibility with retained old code; it is not used now.
                        $runtimeConfig['access_mode'] = $selected['access_mode'];
                        AIcontrolInstaller::jsonFile($runtime . '/config.json', $runtimeConfig);
                    }
                    (new \AIcontrol\PanelStore($runtime . '/panel.sqlite'))->snapshot();
                });
                $activated = true;
                $completed = $signer->record(AIcontrolInstaller::$operation + ['event' => 'update.completed', 'outcome' => 'local_readiness_passed']);
                if (is_file($home . '/bootstrap.json')) {
                    unlink($home . '/bootstrap.json');
                }
                $message = 'Installed checked commit ' . $selected['sha'] . '. Open the panel below.';
                $completedReceipt = AIcontrolSigning::verify($completed, $signer->identity()['public_key'])['receipt_id'];
            } catch (Throwable $error) {
                $signer->record(AIcontrolInstaller::$operation + ['event' => 'update.stopped', 'outcome' => $activated ? 'code_activated_receipt_or_cleanup_failed' : 'installation_failed_or_recovered']);
                throw $error;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } else {
            throw new RuntimeException('Unknown installer operation.');
        }
    }
} catch (Throwable $error) {
    http_response_code(503);
    // Only our bounded, nonsecret diagnostic messages are shown; never HTTP bodies, tokens or stack traces.
    $message = $error instanceof RuntimeException && !($error instanceof PDOException)
        ? $error->getMessage() : 'Installation failed safely. Inspect server configuration and retained private state.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    return;
}
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AIcontrol installer</title><main><h1>AIcontrol installer</h1><p>Single-file bootstrap · PHP hosting · Testing release</p>';
echo '<p>' . $escape($message) . '</p>';
if (isset($_SESSION['csrf'])) {
    $csrf = '<input type="hidden" name="csrf" value="' . $escape($_SESSION['csrf']) . '">';
    $actionUrl = $escape($entry . '?installer=1');
    {
        echo '<p>Signed in with your PIN.</p>';
        echo '<p>Repository: ' . AICONTROL_REPOSITORY . '. Required check: ' . AICONTROL_REQUIRED_CHECK . '.</p><form method="post" action="' . $actionUrl . '">' . $csrf . '<input type="hidden" name="action" value="check"><p><label>GitHub ref <input name="ref" value="' . AICONTROL_RELEASE_REF . '" required></label></p>';
        if ($home === null) {
            echo '<p><label>Private directory (outside every public document root) <input name="home" required size="60"></label></p><p><label>All additional public document roots, one per line <textarea name="roots" rows="4" cols="60"></textarea></label></p>';
        }
        echo '<button>Check GitHub</button></form>';
        if (isset($_SESSION['selection'])) {
            $selected = $_SESSION['selection'];
            echo '<h2>Checked selection</h2><dl><dt>Commit</dt><dd>' . $escape($selected['sha']) . '</dd><dt>Manifest SHA-256</dt><dd>' . $escape($selected['manifest_hash']) . '</dd><dt>Private storage</dt><dd>' . $escape($selected['home']) . '</dd><dt>Expires (UTC)</dt><dd>' . gmdate('c', $selected['checked_at'] + 600) . '</dd></dl><form method="post" action="' . $actionUrl . '">' . $csrf . '<input type="hidden" name="action" value="install"><input type="hidden" name="sha" value="' . $escape($selected['sha']) . '"><button>Install this checked commit</button></form>';
        }
    }
}
if ($home !== null) {
    echo '<p><a href="' . $escape($entry) . '">Open panel</a></p>';
    if (isset($signer)) {
        echo '<h2>Server signing identity</h2><p>Ed25519 key fingerprint: <code>' . $escape($signer->identity()['key_id']) . '</code></p><p><a href="' . $escape($entry . '?identity=1') . '">Public verification key</a></p>';
    }
    if (isset($completedReceipt)) {
        echo '<p><a href="' . $escape($entry . '?receipt=' . $completedReceipt) . '">Signed installation result</a></p>';
    }
}
echo '<p>Installation needs no provider key. AI task responses need a provider connection on the server. Autonomous code execution and production deployment are not connected.</p></main></html>';

if (isset($pinAccess)) {
    echo '<form method="post" action="' . $escape($entry) . '"><input type="hidden" name="pin_action" value="logout"><input type="hidden" name="pin_csrf" value="' . $escape($pinAccess['csrf']) . '"><button>Lock panel</button></form>';
}
