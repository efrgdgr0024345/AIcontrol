<?php
declare(strict_types=1);

/** Standalone AIcontrol bootstrap. Upload this file only; no vendor or shell needed.
 * Set the installer password HASH here or in AICONTROL_INSTALL_PASSWORD_HASH.
 * Generate the hash on the server; never put a service/provider key in this file.
 */
const AICONTROL_INSTALL_PASSWORD_HASH = '';
const AICONTROL_REPOSITORY = 'efrgdgr0024345/AIcontrol';
const AICONTROL_RELEASE_REF = 'feature/control-panel-foundation';
const AICONTROL_REQUIRED_CHECK = 'planning-panel';

final class AIcontrolInstaller
{
    public const LOCATION_PREFIX = "<?php exit; ?>\n";

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
            $token = getenv('AICONTROL_GITHUB_TOKEN');
            if ($parts['host'] === 'api.github.com' && is_string($token) && $token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
            $body = '';
            $client = curl_init($url);
            curl_setopt_array($client, [
                CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'AIcontrol-standalone-loader/1',
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
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
        self::checks($sha);
        $file = json_decode(self::request($base . '/contents/release-manifest.json?ref=' . $sha), true, 32, JSON_THROW_ON_ERROR);
        $manifest = base64_decode(str_replace("\n", '', $file['content'] ?? ''), true);
        if ($manifest === false) {
            throw new RuntimeException('GitHub manifest is unavailable.');
        }
        self::manifest($manifest);
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
        if (($manifest['version'] ?? null) !== 1 || !is_array($manifest['files'] ?? null)
            || count($manifest['files']) < 3 || count($manifest['files']) > 100) {
            throw new RuntimeException('Invalid release manifest.');
        }
        foreach ($manifest['files'] as $path => $hash) {
            if (!is_string($path) || !preg_match('~^(app\.php|src/[A-Za-z0-9_-]+\.php|assets/panel\.css)$~D', $path)
                || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new RuntimeException('Unsafe release manifest entry.');
            }
        }
        foreach (['app.php', 'src/Panel.php', 'src/PanelStore.php', 'src/PrivateStorage.php', 'assets/panel.css'] as $path) {
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

    public static function loginAttempt(string $password, string $hash): bool
    {
        // Account-wide throttle survives new cookies and simultaneous requests.
        $path = sys_get_temp_dir() . '/aicontrol-login-' . hash('sha256', __FILE__);
        if (is_link($path)) {
            throw new RuntimeException('Unsafe installer throttle state.');
        }
        $file = @fopen($path, 'x+');
        if ($file === false) {
            if (!is_file($path) || (fileperms($path) & 0777) !== 0600
                || (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid())) {
                throw new RuntimeException('Unsafe installer throttle state.');
            }
            $file = fopen($path, 'r+');
        }
        if (!$file || !flock($file, LOCK_EX)) {
            throw new RuntimeException('Cannot lock installer sign-in state.');
        }
        try {
            $raw = stream_get_contents($file);
            $attempts = $raw === '' ? ['start' => time(), 'failures' => 0] : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (time() - $attempts['start'] >= 600) {
                $attempts = ['start' => time(), 'failures' => 0];
            }
            if ($attempts['failures'] >= 5) {
                throw new RuntimeException('Installer sign-in temporarily locked. Try again in ten minutes.');
            }
            $valid = strlen($password) <= 256 && password_verify($password, $hash);
            $attempts['failures'] = $valid ? 0 : $attempts['failures'] + 1;
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, json_encode($attempts, JSON_THROW_ON_ERROR));
            fflush($file);
            return $valid;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
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
    foreach (['curl', 'zip', 'mbstring', 'pdo_sqlite', 'session', 'tokenizer'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Enable the PHP ' . $extension . ' extension.');
        }
    }
    $secure = in_array($_SERVER['HTTPS'] ?? '', ['on', '1'], true);
    $local = getenv('AICONTROL_LOCAL_DEV') === '1' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$secure && !$local) {
        throw new RuntimeException('Open the installer over HTTPS.');
    }
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
        AIcontrolInstaller::recover($home);
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
            require $release . '/app.php';
            return;
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    $passwordHash = getenv('AICONTROL_INSTALL_PASSWORD_HASH') ?: AICONTROL_INSTALL_PASSWORD_HASH;
    if ((password_get_info($passwordHash)['algo'] ?? null) === null) {
        throw new RuntimeException('Installer is locked. Configure AICONTROL_INSTALL_PASSWORD_HASH on the server, or set its hash in this single loader file. See the installation guide.');
    }
    session_name('aicontrol_installer');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params(['path' => $entry, 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
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
        if ($action === 'login') {
            if (!AIcontrolInstaller::loginAttempt($input('password'), $passwordHash)) {
                throw new RuntimeException('Installer sign-in failed.');
            }
            session_regenerate_id(true);
            $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'authorized_until' => time() + 900];
        } elseif (($_SESSION['authorized_until'] ?? 0) < time()) {
            throw new RuntimeException('Sign in to the installer again.');
        } elseif ($action === 'check') {
            unset($_SESSION['selection']);
            $roots = array_values(array_unique(array_merge([__DIR__, $_SERVER['DOCUMENT_ROOT'] ?: __DIR__], array_filter(array_map('trim', explode("\n", $input('roots')))))));
            $directory = $home ?? AIcontrolInstaller::home(trim($input('home')), $roots);
            if ($home === null && file_exists($directory) && count(scandir($directory)) > 2) {
                throw new RuntimeException('Choose a new, empty private directory; existing files will not be overwritten.');
            }
            if ($home === null && (strlen($input('panel_password')) < 14 || strlen($input('panel_password')) > 256)) {
                throw new RuntimeException('Choose a separate panel password of 14–256 bytes.');
            }
            if ($home === null && password_verify($input('panel_password'), $passwordHash)) {
                throw new RuntimeException('Use a different password for the proposal-only panel.');
            }
            $selection = AIcontrolInstaller::check($input('ref'));
            $selection['home'] = $directory;
            $selection['roots'] = $roots;
            $selection['panel_hash'] = $home === null ? password_hash($input('panel_password'), PASSWORD_DEFAULT) : null;
            $_SESSION['selection'] = $selection;
            $message = 'GitHub checked. Review the exact commit below; the selection expires in ten minutes.';
        } elseif ($action === 'install') {
            $selected = $_SESSION['selection'] ?? [];
            AIcontrolInstaller::selection($selected, $input('sha'));
            unset($_SESSION['selection']); // one checked selection permits one installation attempt
            if ($home === null) {
                $home = AIcontrolInstaller::home($selected['home'], $selected['roots'], true);
                if (count(scandir($home)) > 2) {
                    throw new RuntimeException('Private directory changed since checking.');
                }
                mkdir($home . '/releases', 0700);
                AIcontrolInstaller::jsonFile($home . '/bootstrap.json', ['panel_hash' => $selected['panel_hash'], 'roots' => $selected['roots']]);
                $file = fopen($location, 'x');
                if (!$file) {
                    throw new RuntimeException('Loader location already exists.');
                }
                fwrite($file, AIcontrolInstaller::LOCATION_PREFIX . json_encode(['home' => $home, 'roots' => $selected['roots']], JSON_THROW_ON_ERROR));
                fclose($file);
            }
            $lock = fopen($home . '/deployment.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another installation is in progress.');
            }
            try {
                AIcontrolInstaller::recover($home);
                if (is_file($home . '/current.json')) {
                    $current = AIcontrolInstaller::readJson($home . '/current.json');
                    AIcontrolInstaller::verify($home . '/releases/' . AIcontrolInstaller::sha($current['sha']), $current['manifest_hash']);
                }
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
                        AIcontrolInstaller::jsonFile($runtime . '/config.json', ['version' => 1, 'public_roots' => $bootstrap['roots'], 'password_hash' => $bootstrap['panel_hash']]);
                    }
                    \AIcontrol\PrivateStorage::config($runtime);
                    (new \AIcontrol\PanelStore($runtime . '/panel.sqlite'))->snapshot();
                });
                if (is_file($home . '/bootstrap.json')) {
                    unlink($home . '/bootstrap.json');
                }
                $message = 'Installed checked commit ' . $selected['sha'] . '. Open the panel below.';
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } elseif ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
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
    if (($_SESSION['authorized_until'] ?? 0) < time()) {
        echo '<form method="post" action="' . $actionUrl . '">' . $csrf . '<input type="hidden" name="action" value="login"><label>Installer password <input type="password" name="password" autocomplete="current-password" required maxlength="256"></label><button>Sign in</button></form>';
    } else {
        echo '<p>Repository: ' . AICONTROL_REPOSITORY . '. Required check: ' . AICONTROL_REQUIRED_CHECK . '.</p><form method="post" action="' . $actionUrl . '">' . $csrf . '<input type="hidden" name="action" value="check"><p><label>GitHub ref <input name="ref" value="' . AICONTROL_RELEASE_REF . '" required></label></p>';
        if ($home === null) {
            echo '<p><label>Private directory (outside every public document root) <input name="home" required size="60"></label></p><p><label>All additional public document roots, one per line <textarea name="roots" rows="4" cols="60"></textarea></label></p><p><label>New panel password (14–256 bytes; separate from installer password) <input type="password" name="panel_password" required minlength="14" maxlength="256" autocomplete="new-password"></label></p>';
        }
        echo '<button>Check GitHub</button></form>';
        if (isset($_SESSION['selection'])) {
            $selected = $_SESSION['selection'];
            echo '<h2>Checked selection</h2><dl><dt>Commit</dt><dd>' . $escape($selected['sha']) . '</dd><dt>Manifest SHA-256</dt><dd>' . $escape($selected['manifest_hash']) . '</dd><dt>Private storage</dt><dd>' . $escape($selected['home']) . '</dd><dt>Expires (UTC)</dt><dd>' . gmdate('c', $selected['checked_at'] + 600) . '</dd></dl><form method="post" action="' . $actionUrl . '">' . $csrf . '<input type="hidden" name="action" value="install"><input type="hidden" name="sha" value="' . $escape($selected['sha']) . '"><button>Install this checked commit</button></form>';
        }
        echo '<form method="post" action="' . $actionUrl . '">' . $csrf . '<input type="hidden" name="action" value="logout"><button>Sign out of installer</button></form>';
    }
}
if ($home !== null) {
    echo '<p><a href="' . $escape($entry) . '">Open panel</a></p>';
}
echo '<p>No provider keys are needed. Trusted-device approval is deferred; protect installer access through your hosting account. This testing release does not run agents or deployments.</p></main></html>';
