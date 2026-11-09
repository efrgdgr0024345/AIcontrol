<?php
/** HTTP/CLI entry points. All persistent mutations run under the site lock. */
final class ACUserError extends RuntimeException {}

function ac_form_string(array $values, string $key, int $limit = 2048): string
{
    $value = $values[$key] ?? '';
    if (!is_string($value) || strlen($value) > $limit || str_contains($value, "\0")) {
        throw new ACUserError('A form field is missing or too long.');
    }
    return $value;
}

function ac_public_url(string $url): string
{
    $parts = parse_url($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts) ||
        ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
        isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass']) ||
        isset($parts['query']) || isset($parts['fragment']) ||
        (isset($parts['port']) && $parts['port'] !== 443) ||
        !str_ends_with($parts['path'] ?? '', '/loader.php') ||
        preg_match('/[\x00-\x20\x7f]/', $url)) {
        throw new ACUserError('Use the complete HTTPS address ending in /loader.php, without a query or fragment.');
    }
    return 'https://' . strtolower($parts['host']) . $parts['path'];
}

function ac_app_storage(string $root, string $docroot): string
{
    $base = ac_safe_private((string)AC_PRIVATE_ROOT, $docroot);
    ac_security_directory($base);
    $dir = $base . '/site-' . substr(hash('sha256', (string)realpath($root)), 0, 16);
    $dir = ac_safe_private($dir, $docroot);
    ac_security_directory($dir);
    return $dir;
}

function ac_app_read(string $dir, string $name): array
{
    if (!preg_match('/\A[a-z-]+\.json\z/D', $name)) { throw new RuntimeException('Invalid state name.'); }
    $path = $dir . '/' . $name;
    if (ac_security_file($path, false) === null) { return []; }
    return ac_security_decode(ac_security_read_bytes($path));
}

function ac_app_save(string $dir, string $name, array $value): void
{
    if (!preg_match('/\A[a-z-]+\.json\z/D', $name)) { throw new RuntimeException('Invalid state name.'); }
    ac_security_write($dir . '/' . $name, ac_security_json($value) . "\n");
}

function ac_password_hash(#[SensitiveParameter] string $password): string
{
    if (strlen($password) < 15 || strlen($password) > 1024 || str_contains($password, "\0")) {
        throw new ACUserError('Use a password of at least 15 characters (maximum 1,024).');
    }
    return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
}

/** Called only by the cPanel Terminal command, never by an HTTP action. */
function ac_bootstrap_create(string $dir, string $url, ?int $now = null): string
{
    if (ac_app_read($dir, 'config.json') !== []) { throw new ACUserError('This installation already has an administrator.'); }
    $url = ac_public_url($url);
    ac_vault_init($dir);
    $token = bin2hex(random_bytes(32));
    ac_app_save($dir, 'bootstrap.json', ['hash' => hash('sha256', $token),
        'expires_at' => ($now ?? time()) + 1800, 'public_url' => $url]);
    ac_audit($dir, 'setup.token_created', 'server-operator', ['purpose' => 'initial-setup']);
    return $token;
}

function ac_bootstrap_claim(string $dir, #[SensitiveParameter] string $token,
    #[SensitiveParameter] string $password, ?int $now = null): array
{
    if (ac_app_read($dir, 'config.json') !== []) { throw new ACUserError('Administrator setup is already complete.'); }
    $pending = ac_app_read($dir, 'bootstrap.json');
    if (strlen($token) !== 64 || empty($pending['hash']) ||
        ($pending['expires_at'] ?? 0) <= ($now ?? time()) ||
        !hash_equals($pending['hash'], hash('sha256', $token))) {
        throw new ACUserError('The setup token is invalid or expired. Generate a new token in cPanel Terminal.');
    }
    $hash = ac_password_hash($password);
    $secret = ac_totp_new_secret();
    try { ac_vault_put($dir, 'admin_totp', 'internal', 'Administrator authenticator', $secret, 'owner'); }
    finally { sodium_memzero($secret); }
    $config = ['schema' => 1, 'public_url' => ac_public_url($pending['public_url']),
        'password_hash' => $hash, 'auth_version' => bin2hex(random_bytes(16)),
        'mfa_enabled' => false, 'last_totp_counter' => -1, 'openai_model' => 'gpt-6.1-sol',
        'created_at' => gmdate(DATE_ATOM)];
    ac_app_save($dir, 'config.json', $config);
    // A surviving token cannot be replayed: config existence is checked first.
    if (is_file($dir . '/bootstrap.json')) { unlink($dir . '/bootstrap.json'); }
    ac_audit($dir, 'setup.claimed', 'owner', ['status' => 'authenticator-required']);
    return $config;
}

function ac_authenticate(string $dir, array &$config, #[SensitiveParameter] string $password,
    #[SensitiveParameter] string $code, bool $allowEnrollment = false, ?int $now = null): bool
{
    if (!password_verify($password, $config['password_hash'] ?? '')) { return false; }
    if (empty($config['mfa_enabled']) && $allowEnrollment) { return true; }
    if (empty($config['mfa_enabled'])) { return false; }
    $secret = ac_vault_get($dir, 'admin_totp', 'owner', 'authentication');
    try { $counter = ac_totp_verify($secret, $code, $now ?? time(), (int)($config['last_totp_counter'] ?? -1)); }
    finally { sodium_memzero($secret); }
    if ($counter === null) { return false; }
    // Persist replay protection BEFORE creating a session or performing an action.
    $config['last_totp_counter'] = $counter;
    ac_app_save($dir, 'config.json', $config);
    return true;
}

function ac_session_valid(array $session, array $config, ?int $now = null): bool
{
    $now ??= time();
    return !empty($session['authenticated']) && is_string($session['auth_version'] ?? null) &&
        hash_equals($config['auth_version'] ?? '', $session['auth_version']) &&
        ($session['started_at'] ?? 0) <= $now && ($session['started_at'] ?? 0) > $now - 14400 &&
        ($session['last_seen'] ?? 0) <= $now && ($session['last_seen'] ?? 0) > $now - 900;
}

function ac_session_login(array $config, bool $enrollment = false): void
{
    session_regenerate_id(true);
    $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'authenticated' => !$enrollment,
        'enrollment' => $enrollment, 'auth_version' => $config['auth_version'],
        'started_at' => time(), 'last_seen' => time()];
}

function ac_session_start(string $dir, string $path): void
{
    $sessions = $dir . '/sessions';
    ac_mkdir($sessions);
    ac_security_directory($sessions);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '14400');
    session_save_path($sessions);
    session_name('AIcontrol_' . substr(hash('sha256', $dir), 0, 12));
    session_set_cookie_params(['lifetime' => 0, 'path' => $path,
        'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    if (!session_start()) { throw new RuntimeException('Unable to open private session.'); }
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
}

function ac_rate_key(): string
{
    // Trusted web-server address only. Forwarded headers do not change this key.
    return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function ac_rate_check(string $dir, string $key): void
{
    $state = ac_app_read($dir, 'rate-limit.json');
    $row = $state[$key] ?? [];
    if (($row['until'] ?? 0) > time() && ($row['count'] ?? 0) >= 6) {
        throw new ACUserError('Too many authentication attempts. Wait five minutes and retry.');
    }
}

function ac_rate_record(string $dir, string $key, bool $success): void
{
    $state = ac_app_read($dir, 'rate-limit.json');
    $now = time();
    foreach ($state as $id => $row) { if (($row['until'] ?? 0) <= $now) { unset($state[$id]); } }
    if ($success) { unset($state[$key]); }
    else {
        $old = $state[$key] ?? ['until' => $now + 300, 'count' => 0];
        $state[$key] = ['until' => $old['until'], 'count' => $old['count'] + 1];
        if (count($state) > 2000) { throw new RuntimeException('Authentication rate state is full.'); }
    }
    ac_app_save($dir, 'rate-limit.json', $state);
}

function ac_step_up(string $dir, array &$config, array $post): void
{
    $key = ac_rate_key();
    ac_rate_check($dir, $key);
    $valid = ac_authenticate($dir, $config, ac_form_string($post, 'confirm_password', 1024),
        ac_form_string($post, 'confirm_code', 6));
    ac_rate_record($dir, $key, $valid);
    if (!$valid) {
        ac_audit($dir, 'auth.step_up_denied', 'owner', ['result' => 'invalid-credentials']);
        throw new ACUserError('Password or authenticator code not accepted. Use a fresh code for each sensitive action.');
    }
}

function ac_origin_matches(string $url, array $server): bool
{
    $parts = parse_url($url);
    $host = strtolower((string)($server['HTTP_HOST'] ?? ''));
    $expected = strtolower((string)($parts['host'] ?? ''));
    return ($host === $expected || $host === $expected . ':443') &&
        parse_url((string)($server['REQUEST_URI'] ?? ''), PHP_URL_PATH) === ($parts['path'] ?? '');
}

function ac_response_headers(string $nonce): void
{
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'none'; style-src 'nonce-" . $nonce . "'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
}

function ac_optional_github_token(string $dir): string
{
    foreach (ac_vault_list($dir) as $row) {
        if ($row['id'] === 'github_read') { return ac_vault_get($dir, 'github_read', 'owner', 'loader-update'); }
    }
    return '';
}

function ac_project_records(string $dir): array
{
    $data = ac_app_read($dir, 'projects.json');
    return $data ?: ['projects' => [], 'tasks' => []];
}

function ac_project_action(string $dir, string $action, array $post): void
{
    $data = ac_project_records($dir);
    if ($action === 'project.add') {
        if (count($data['projects']) >= 100) { throw new ACUserError('The project limit is 100.'); }
        $name = trim(ac_form_string($post, 'name', 120));
        $repo = trim(ac_form_string($post, 'repository', 160));
        if ($name === '' || !preg_match('~\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z~D', $repo)) {
            throw new ACUserError('Enter a project name and a repository in owner/name format.');
        }
        $id = bin2hex(random_bytes(12));
        $data['projects'][$id] = ['id' => $id, 'name' => $name, 'repository' => $repo,
            'created_at' => gmdate(DATE_ATOM)];
    } elseif ($action === 'task.add') {
        if (count($data['tasks']) >= 2000) { throw new ACUserError('The task limit is 2,000.'); }
        $project = ac_form_string($post, 'project_id', 24);
        $title = trim(ac_form_string($post, 'title', 240));
        if (!isset($data['projects'][$project]) || $title === '') { throw new ACUserError('Choose a project and enter a task title.'); }
        $id = bin2hex(random_bytes(12));
        $data['tasks'][$id] = ['id' => $id, 'project_id' => $project, 'title' => $title,
            'status' => 'planned', 'created_at' => gmdate(DATE_ATOM)];
    } else {
        $id = ac_form_string($post, 'task_id', 24);
        $status = ac_form_string($post, 'status', 20);
        if (!isset($data['tasks'][$id]) || !in_array($status, ['planned','working','review','done','blocked'], true)) {
            throw new ACUserError('Choose a valid task and status.');
        }
        $data['tasks'][$id]['status'] = $status;
        $data['tasks'][$id]['updated_at'] = gmdate(DATE_ATOM);
    }
    ac_audit($dir, $action, 'owner', ['id' => $id]);
    ac_app_save($dir, 'projects.json', $data);
}

function ac_admin_action(string $root, string $dir, array &$config, string $action, array $post): string
{
    if (in_array($action, ['project.add','task.add','task.status'], true)) {
        ac_project_action($dir, $action, $post);
        return 'Project records saved.';
    }
    if ($action === 'update.check') {
        $token = ac_optional_github_token($dir);
        try { $pending = ac_remote(ac_form_string($post, 'branch', 150), $token); }
        finally { if ($token !== '') { sodium_memzero($token); } }
        ac_app_save($dir, 'pending.json', $pending);
        ac_audit($dir, 'update.checked', 'owner', ['commit' => $pending['sha'], 'success' => $pending['checks']['ok']]);
        return 'Commit checked. Review its identity and check results before installing.';
    }
    $sensitive = ['vault.save','vault.delete','vault.generate','request.approve','request.deny',
        'update.install','update.rollback','update.recover','security.password','security.model'];
    if (!in_array($action, $sensitive, true)) { throw new ACUserError('Unknown action.'); }
    ac_step_up($dir, $config, $post);
    if (str_starts_with($action, 'vault.')) {
        $id = ac_form_string($post, 'credential_id', 64);
        if ($id === 'admin_totp' || str_starts_with($id, 'admin_')) { throw new ACUserError('Administrator credentials cannot be edited here.'); }
        if ($action === 'vault.delete') { ac_vault_delete($dir, $id, 'owner'); }
        elseif ($action === 'vault.generate') { ac_vault_generate($dir, $id, ac_form_string($post, 'label', 160), 'owner'); }
        else {
            $provider = ac_form_string($post, 'provider', 32);
            if (!in_array($provider, ['openai','github','custom'], true) ||
                ($id === 'openai' && $provider !== 'openai') || ($id === 'github_read' && $provider !== 'github')) {
                throw new ACUserError('Choose a valid provider. Reserved IDs must match their provider.');
            }
            $secret = ac_form_string($post, 'credential_value', 16384);
            try { ac_vault_put($dir, $id, $provider, ac_form_string($post, 'label', 160), $secret, 'owner'); }
            finally { sodium_memzero($secret); }
        }
        return 'Credential change saved. Only metadata is displayed.';
    }
    if (str_starts_with($action, 'request.')) {
        ac_broker_decide($dir, ac_form_string($post, 'request_id', 64), $action === 'request.approve', 'owner');
        return $action === 'request.approve' ? 'Request approved for one use within five minutes.' : 'Request denied.';
    }
    if ($action === 'update.install') {
        $pending = ac_app_read($dir, 'pending.json');
        if (!hash_equals($pending['sha'] ?? '', ac_form_string($post, 'checked_sha', 40)) || empty($pending['sha'])) {
            throw new ACUserError('The selected commit changed. Check GitHub and review it again.');
        }
        $token = ac_optional_github_token($dir);
        ac_audit($dir, 'update.install_started', 'owner', ['commit' => $pending['sha']]);
        try { ac_install($root, $dir, $pending, $token, ($post['preview'] ?? '') === 'yes'); }
        finally { if ($token !== '') { sodium_memzero($token); } }
        ac_audit($dir, 'update.installed', 'owner', ['commit' => $pending['sha']]);
        return 'Checked commit installed. Credentials, projects and history were preserved.';
    }
    if ($action === 'update.rollback' || $action === 'update.recover') {
        $file = $action === 'update.recover' ? 'journal.json' : 'rollback.json';
        $journal = ac_app_read($dir, $file);
        if (!$journal) { throw new ACUserError('No matching recovery snapshot is available.'); }
        ac_audit($dir, $action . '_started', 'owner', ['id' => $journal['id'] ?? 'unknown']);
        ac_restore($root, $dir, $journal);
        unlink($dir . '/' . $file);
        ac_audit($dir, $action . '_completed', 'owner', ['id' => $journal['id']]);
        return 'Previous loader restored. Security and project records were retained.';
    }
    if ($action === 'security.password') {
        $new = ac_form_string($post, 'new_password', 1024);
        if (!hash_equals($new, ac_form_string($post, 'new_password_confirm', 1024))) { throw new ACUserError('New passwords do not match.'); }
        $config['password_hash'] = ac_password_hash($new);
        $config['auth_version'] = bin2hex(random_bytes(16));
        ac_audit($dir, 'auth.password_changed', 'owner');
        ac_app_save($dir, 'config.json', $config);
        ac_session_login($config);
        return 'Password updated. Other administrator sessions have expired.';
    }
    $model = ac_form_string($post, 'model', 80);
    if (!preg_match('/\Agpt-[a-zA-Z0-9.-]{1,70}\z/D', $model)) { throw new ACUserError('Enter a valid GPT model ID.'); }
    $config['openai_model'] = $model;
    ac_audit($dir, 'settings.model_changed', 'owner', ['id' => $model]);
    ac_app_save($dir, 'config.json', $config);
    return 'Model setting updated. Each OpenAI operation still needs its own approval.';
}

function ac_api_dispatch(string $dir, array $config): never
{
    header('Content-Type: application/json; charset=utf-8');
    $lock = null;
    try {
        if (empty($config['mfa_enabled'])) { throw new RuntimeException('Administrator setup is incomplete.'); }
        $name = ac_form_string($_GET, 'api', 20);
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($name, ['request','status','execute'], true) ||
            $method !== ($name === 'status' ? 'GET' : 'POST')) { http_response_code(405); throw new RuntimeException('Invalid method.'); }
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/\ABearer ([A-Za-z0-9_.-]{100,20000})\z/D', $authorization, $match)) {
            http_response_code(401); throw new RuntimeException('Authentication required.');
        }
        $config['private_dir'] = $dir;
        $identity = ac_broker_verify_oidc($match[1], $config);
        unset($match, $authorization);
        $body = [];
        if ($method === 'POST') {
            if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
                http_response_code(415); throw new RuntimeException('Use JSON.');
            }
            $bytes = file_get_contents('php://input', false, null, 0, 16385);
            if ($bytes === false || strlen($bytes) > 16384) { http_response_code(413); throw new RuntimeException('Request too large.'); }
            $body = ac_json($bytes);
        }
        $lock = ac_lock($dir);
        $result = match ($name) {
            'request' => ac_broker_request($dir, $identity, $body),
            'status' => ac_broker_status($dir, $identity, ac_form_string($_GET, 'request_id', 64)),
            'execute' => ac_broker_execute($dir, $identity, ac_form_string($body, 'request_id', 64), $config),
        };
        echo json_encode(['ok' => true, 'data' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $error) {
        if (http_response_code() < 400) { http_response_code(403); }
        echo json_encode(['ok' => false, 'error' => 'The operation was refused or failed. Inspect its status and the administrator history.']);
    } finally { ac_unlock($lock); }
    exit;
}

function ac_web_view(string $root, string $dir): array
{
    $config = ac_app_read($dir, 'config.json');
    $pendingSetup = $config ? [] : ac_app_read($dir, 'bootstrap.json');
    $url = $config['public_url'] ?? $pendingSetup['public_url'] ?? '';
    if ($url !== '' && !ac_origin_matches($url, $_SERVER)) {
        http_response_code(400); throw new ACUserError('Open the exact HTTPS loader address configured in cPanel Terminal.');
    }
    if (isset($_GET['api'])) { ac_api_dispatch($dir, $config); }
    if (!$config && !$pendingSetup) {
        return ['mode' => 'uninitialized', 'title' => 'Initialize on the server'];
    }
    $path = (string)parse_url($url, PHP_URL_PATH);
    ac_session_start($dir, $path);
    $view = ['error' => '', 'notice' => '', 'tab' => ac_form_string($_GET, 'tab', 20) ?: 'overview'];
    $allowedTabs = ['overview','projects','credentials','requests','updates','history','security'];
    if (!in_array($view['tab'], $allowedTabs, true)) { $view['tab'] = 'overview'; }
    $lock = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try {
            $action = ac_gate($_SESSION, 'POST', $_POST);
            $lock = ac_lock($dir);
            // Reload under the lock: setup, replay counters and grants are shared state.
            $config = ac_app_read($dir, 'config.json');
            $key = ac_rate_key();
            if ($action === 'setup' && !$config) {
                ac_rate_check($dir, $key);
                $password = ac_form_string($_POST, 'password', 1024);
                if (!hash_equals($password, ac_form_string($_POST, 'password_confirm', 1024))) { throw new ACUserError('Passwords do not match.'); }
                try { $config = ac_bootstrap_claim($dir, ac_form_string($_POST, 'setup_token', 64), $password); }
                catch (Throwable $e) { ac_rate_record($dir, $key, false); throw $e; }
                ac_rate_record($dir, $key, true);
                ac_session_login($config, true);
                $view['notice'] = 'Password saved. Enroll your authenticator to finish setup.';
            } elseif ($action === 'login' && $config) {
                ac_rate_check($dir, $key);
                $ok = ac_authenticate($dir, $config, ac_form_string($_POST, 'password', 1024), ac_form_string($_POST, 'code', 6), true);
                ac_rate_record($dir, $key, $ok);
                ac_audit($dir, $ok ? 'auth.login' : 'auth.login_denied', 'owner', ['success' => $ok]);
                if (!$ok) { throw new ACUserError('Password or authenticator code not accepted.'); }
                ac_session_login($config, empty($config['mfa_enabled']));
            } elseif ($action === 'logout') {
                if ($config) { ac_audit($dir, 'auth.logout', 'owner'); }
                $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
                session_regenerate_id(true);
                $view['notice'] = 'Signed out.';
            } elseif ($action === 'enroll' && !empty($_SESSION['enrollment']) && empty($config['mfa_enabled']) &&
                hash_equals($config['auth_version'] ?? '', $_SESSION['auth_version'] ?? '') &&
                ($_SESSION['started_at'] ?? 0) > time() - 900) {
                ac_rate_check($dir, $key);
                $secret = ac_vault_get($dir, 'admin_totp', 'owner', 'enrollment');
                try { $counter = ac_totp_verify($secret, ac_form_string($_POST, 'code', 6), time(), -1); }
                finally { sodium_memzero($secret); }
                ac_rate_record($dir, $key, $counter !== null);
                if ($counter === null) { throw new ACUserError('Authenticator code not accepted. Check the device time and try again.'); }
                $config['last_totp_counter'] = $counter;
                $config['mfa_enabled'] = true;
                ac_app_save($dir, 'config.json', $config);
                ac_audit($dir, 'auth.enrolled', 'owner');
                ac_session_login($config);
                $view['notice'] = 'Authenticator enabled. Your private console is ready.';
            } else {
                if (!ac_session_valid($_SESSION, $config) || empty($config['mfa_enabled'])) { throw new ACUserError('Sign in before making this change.'); }
                $view['notice'] = ac_admin_action($root, $dir, $config, $action, $_POST);
            }
        } catch (Throwable $error) {
            $view['error'] = $error instanceof ACUserError ? $error->getMessage() :
                'The operation stopped without authorization to continue. Check storage permissions, provider access and the selected commit checks; retry with a fresh authenticator code.';
            if ($config) {
                try { ac_audit($dir, 'operation.failed', 'owner', ['operation' => isset($action) && preg_match('/\A[a-z.]{1,40}\z/', $action) ? $action : 'unknown']); }
                catch (Throwable $ignored) { $view['error'] = 'Private security state failed verification. Stop changes and restore a consistent private backup through cPanel Terminal.'; }
            }
        } finally { ac_unlock($lock); }
    }
    $view['csrf'] = $_SESSION['csrf'];
    if (!$config) { $view['mode'] = 'setup'; return $view; }
    if (!empty($_SESSION['enrollment']) && empty($config['mfa_enabled']) &&
        hash_equals($config['auth_version'], $_SESSION['auth_version'] ?? '') &&
        ($_SESSION['started_at'] ?? 0) > time() - 900) {
        $view['mode'] = 'enroll';
        $view['totp_secret'] = ac_vault_get($dir, 'admin_totp', 'owner', 'enrollment-display');
        return $view;
    }
    if (!ac_session_valid($_SESSION, $config) || empty($config['mfa_enabled'])) { $view['mode'] = 'login'; return $view; }
    $_SESSION['last_seen'] = time();
    $view['mode'] = 'admin';
    $view['public_url'] = $config['public_url'];
    $view['model'] = $config['openai_model'];
    $view['project_data'] = ac_project_records($dir);
    $view['credentials'] = array_values(array_filter(ac_vault_list($dir), static fn(array $r): bool => $r['id'] !== 'admin_totp'));
    $view['requests'] = ac_broker_list($dir);
    $view['state'] = ac_app_read($dir, 'state.json');
    $view['pending'] = ac_app_read($dir, 'pending.json');
    $view['rollback'] = ac_app_read($dir, 'rollback.json') !== [];
    $view['recovery'] = ac_app_read($dir, 'journal.json') !== [];
    $view['audit'] = ac_audit_verify($dir);
    $view['events'] = $view['tab'] === 'history' ? ac_audit_tail($dir, 100) : [];
    return $view;
}

function ac_entry(): void
{
    ini_set('display_errors', '0');
    umask(0077);
    $nonce = base64_encode(random_bytes(24));
    $cli = PHP_SAPI === 'cli';
    if (!$cli) {
        ac_response_headers($nonce);
        if (!ac_https()) {
            http_response_code(403);
            ac_render(['mode' => 'error', 'error' => 'HTTPS is required. Open the secure address. Forwarded headers alone are not accepted.'], $nonce);
            return;
        }
    }
    try {
        foreach (['sodium','openssl','curl','session','json','zip'] as $extension) {
            if (!extension_loaded($extension)) { throw new ACUserError('Enable the PHP ' . $extension . ' extension in cPanel.'); }
        }
        $root = __DIR__;
        $docroot = $cli ? $root : (string)($_SERVER['DOCUMENT_ROOT'] ?? $root);
        $dir = ac_app_storage($root, $docroot);
        if ($cli) {
            global $argv;
            if (($argv[1] ?? '') !== 'bootstrap' || count($argv) !== 3) {
                fwrite(STDERR, "Usage: php loader.php bootstrap https://YOUR-DOMAIN/path/loader.php\nRun in cPanel Terminal on a trusted device.\n");
                exit(1);
            }
            $lock = ac_lock($dir);
            try { $token = ac_bootstrap_create($dir, $argv[2]); }
            finally { ac_unlock($lock); }
            fwrite(STDOUT, "Setup token (expires in 30 minutes; enter only at your HTTPS loader):\n" . $token . "\nPrivate directory: " . $dir . "\n");
            sodium_memzero($token);
            return;
        }
        ac_render(ac_web_view($root, $dir), $nonce);
    } catch (Throwable $error) {
        $message = $error instanceof ACUserError ? $error->getMessage() :
            'Private storage is unavailable or failed verification. Check the private directory, required extensions and server permissions. No credentials were displayed.';
        if ($cli) { fwrite(STDERR, $message . "\n"); exit(1); }
        http_response_code(503);
        ac_render(['mode' => 'error', 'error' => $message], $nonce);
    }
}
