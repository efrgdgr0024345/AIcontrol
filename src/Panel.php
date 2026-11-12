<?php
declare(strict_types=1);

namespace AIcontrol;

use RuntimeException;
use Throwable;

final class Panel
{
    private static function entry(): string
    {
        $entry = $_SERVER['SCRIPT_NAME'] ?? '/loader.php';
        return is_string($entry) && preg_match('~^/[A-Za-z0-9_./-]+\.php$~D', $entry) ? $entry : '/loader.php';
    }
    private static function escape(string|int $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function input(string $name): string
    {
        $value = $_POST[$name] ?? '';
        if (!is_string($value)) {
            throw new RuntimeException('Invalid input.');
        }
        return $value;
    }

    public static function serve(string $directory, string $path, string $method): void
    {
        umask(0077);
        $config = PrivateStorage::config($directory);
        if (getenv('AICONTROL_PIN_VERIFIED') === '1') {
            $config['access_mode'] = 'pin';
        } elseif (getenv('AICONTROL_PUBLIC_TESTING') === '1') {
            $config['access_mode'] = 'testing';
        }
        $passwordMode = $config['access_mode'] === 'password';
        if (!$passwordMode && !AccessPolicy::permits($config['access_mode'], $_SERVER, getenv('AICONTROL_LOCAL_DEV') === '1')) {
            http_response_code(403);
            header('Cache-Control: no-store');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Host-protected access is required. Configure server authentication for this directory.';
            return;
        }
        $store = new PanelStore($directory . '/panel.sqlite');
        $secure = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTPS'] ?? '') === '1';
        $local = getenv('AICONTROL_LOCAL_DEV') === '1'
            && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        if (!$secure && !$local) {
            throw new RuntimeException('HTTPS required.');
        }
        header('Cache-Control: no-store');
        $assetNonce = base64_encode(random_bytes(18));
        header("Content-Security-Policy: default-src 'none'; style-src 'self' 'nonce-{$assetNonce}'; script-src 'self' 'nonce-{$assetNonce}'; connect-src 'self'; img-src data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        if (!in_array($path, ['/', '/loader.php', '/health'], true)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo '{"error":"not_found"}';
            return;
        }
        if (!in_array($method, ['GET', 'HEAD', 'POST'], true) || ($path === '/health' && $method === 'POST')) {
            http_response_code(405);
            header('Allow: GET, HEAD' . ($path === '/health' ? '' : ', POST'));
            return;
        }
        if ($path === '/health') {
            header('Content-Type: application/json');
            if ($method !== 'HEAD') {
                echo '{"status":"ok","scope":"metadata_panel_only","broker_enabled":false,"deployment_enabled":false}';
            }
            return;
        }
        session_name('aicontrol_panel');
        $panelCookie = $_COOKIE['aicontrol_panel'] ?? '';
        session_id(is_string($panelCookie) && preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $panelCookie) ? $panelCookie : '');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_save_path($directory . '/sessions');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
        if (!session_start()) {
            throw new RuntimeException('Session storage unavailable.');
        }
        if (!$passwordMode) {
            $identity = hash('sha256', $config['access_mode'] . ':' . ($_SERVER['REMOTE_USER'] ?? 'loopback'));
            if (($_SESSION['host_identity'] ?? '') !== $identity) {
                session_regenerate_id(true);
                $_SESSION = ['host_identity' => $identity];
            }
        }
        if (($_SESSION['authenticated'] ?? false) && (($_SESSION['expires'] ?? 0) < time())) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        if (!$passwordMode) {
            // Recheck server identity on every request; an old app cookie cannot bypass its removal.
            $_SESSION['authenticated'] = true;
            $_SESSION['expires'] = time() + 1800;
        }
        $error = '';
        if (($_GET['research_api'] ?? '') === 'status' && $method === 'GET') {
            if (!($_SESSION['authenticated'] ?? false) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
                Diagnostics::record('research.status.denied',['authenticated'=>(bool)($_SESSION['authenticated']??false),'csrf_header_present'=>isset($_SERVER['HTTP_X_CSRF_TOKEN'])]);
                http_response_code(403); header('Content-Type: application/json'); echo '{"error":"Research session verification failed."}'; return;
            }
            session_write_close();
            ResearchApi::status($directory, (int)($_GET['run_id'] ?? 0));
            return;
        }
        if ($method === 'POST') {
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
                http_response_code(413);
                return;
            }
            if (!hash_equals($_SESSION['csrf'], self::input('csrf'))) {
                Diagnostics::record('panel.csrf.denied',['method'=>'POST','action'=>substr(self::input('action'),0,80)]);
                http_response_code(403);
                echo 'Request verification failed.';
                return;
            }
            $action = self::input('action');
            if ($action === 'login' && $passwordMode) {
                $client = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
                if (!$store->loginAllowed($client)) {
                    http_response_code(429);
                    header('Retry-After: 600');
                    $error = 'Too many attempts. Try again in ten minutes.';
                } elseif (strlen(self::input('password')) <= 256 && password_verify(self::input('password'), $config['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION = ['authenticated' => true, 'expires' => time() + 1800, 'csrf' => bin2hex(random_bytes(32))];
                    header('Location: ' . self::entry(), true, 303);
                    return;
                } else {
                    $store->failedLogin($client);
                    http_response_code(401);
                    $error = 'Sign-in failed.';
                }
            } elseif (!($_SESSION['authenticated'] ?? false)) {
                http_response_code(401);
                $error = 'Sign in to record a proposal.';
            } elseif ($action === 'admin_data') {
                try {
                    $raw=self::input('record_id');
                    if(!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$raw))throw new RuntimeException('Choose a valid record.');
                    (new AdminData($directory))->change(self::input('operation'),(int)$raw,self::input('confirmation'));
                    $_SESSION['csrf']=bin2hex(random_bytes(32));
                    $_SESSION['flash']='Saved data updated. Refresh any other open workspace pages. Provider charges are unchanged.';
                    header('Location: '.self::entry().'?view=admin',true,303);return;
                } catch(Throwable $failure) {
                    Diagnostics::record('admin.data.failed',['operation'=>is_string($_POST['operation']??null)?substr($_POST['operation'],0,80):'invalid'],$failure);
                    http_response_code(422);
                    $safe=['Confirmation did not match. No data was changed.','Choose a valid record.','Unknown data operation.','The selected record no longer exists. Refresh Admin.','Database relationships require repair; no data was changed.','Another AI request is finishing. Wait before asking for suggestions or starting research.'];
                    $error=in_array($failure->getMessage(),$safe,true)?$failure->getMessage():'Data management did not complete. Review the saved records and Admin diagnostic log.';
                    if(str_starts_with($failure->getMessage(),'Another AI request is finishing.'))$error='An AI request is still running. Wait for it to finish before changing saved data.';
                }
            } elseif ($action === 'diagnostic_client') {
                $last=(int)($_SESSION['diagnostic_client_at']??0);
                if (time()-$last<5) { http_response_code(429); return; }
                $_SESSION['diagnostic_client_at']=time();
                $responseDetails=[];
                foreach(['http_status','content_type','redirected','response_path','error_kind'] as $field)$responseDetails[$field]=substr(self::input($field),0,160);
                Diagnostics::record('browser.research.failed',array_merge($responseDetails,['stage'=>substr(self::input('stage'),0,80),'message'=>substr(self::input('message'),0,1000),'run_id'=>(int)self::input('run_id')]));
                http_response_code(204);return;
            } elseif ($action === 'research_brief_suggest') {
                session_write_close();
                require_once __DIR__.'/BriefSuggestions.php';
                BriefSuggestions::serve($directory,$_POST['draft']??'',$_POST['name']??'');
                return;
            } elseif ($action === 'research_status') {
                session_write_close();
                ResearchApi::status($directory,(int)self::input('run_id'));
                return;
            } elseif ($action === 'logout' && $passwordMode) {
                $_SESSION = [];
                session_destroy();
                setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
                header('Location: ' . self::entry(), true, 303);
                return;
            } elseif (in_array($action, ['research_settings','research_rank','research_start','research_continue','research_finish','research_repair','research_step','research_stop','research_learn','research_export'], true)) {
                // Release this session before provider I/O so polling and Stop can proceed.
                session_write_close();
                ResearchApi::action($directory, $action, self::entry(), ($_GET['research_api'] ?? '') === '1');
                return;
            } else {
                try {
                    if ($action === 'vault_save' || $action === 'vault_delete') {
                        (new Vault($directory))->change(self::input('name'), self::input('type'), self::input('value'), self::input('share') === '1', $action === 'vault_delete');
                        $_SESSION['flash'] = 'Private vault updated.';
                    } elseif ($action === 'research_project') {
                        $projectId=(new Research($directory))->create(self::input('name'), self::input('brief'));
                        header('Location: '.self::entry().'?view=research&project='.$projectId,true,303);return;
                    } elseif ($action === 'research_chat') {
                        (new Research($directory))->chat((int)self::input('project_id'), self::input('message'), self::input('web') === '1');
                    } elseif ($action === 'research_resolve') {
                        (new Research($directory))->resolve((int)self::input('message_id'));
                    } elseif ($action === 'project') {
                        $store->createProject(self::input('name'), self::input('brief'));
                        $_SESSION['flash'] = 'Project created. Add its first task when you are ready.';
                    } elseif ($action === 'task') {
                        $project = filter_var(self::input('project_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($project === false) {
                            throw new RuntimeException('Invalid project.');
                        }
                        $store->createTask($project, self::input('title'), self::input('criteria'));
                        $_SESSION['flash'] = 'Task proposal saved.';
                    } elseif ($action === 'run_agent') {
                        $task = filter_var(self::input('task_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($task === false) {
                            throw new RuntimeException('Invalid task.');
                        }
                        AgentRunner::run($store, $task);
                    } else {
                        throw new RuntimeException('Operation unavailable.');
                    }
                    header('Location: ' . self::entry() . '?view=' . (str_starts_with($action, 'vault_') ? 'admin' : (str_starts_with($action, 'research_') ? 'research' : 'projects')), true, 303);
                    return;
                } catch (Throwable $failure) {
                    Diagnostics::record('panel.action.failed',['action'=>$action],$failure);
                    http_response_code(422);
                    $error = 'Operation could not be completed. Check the selected project and field lengths.';
                    if (str_starts_with($action, 'vault_')) { $error = 'Vault could not be updated. Use a lowercase name with underscores, and a nonempty value up to 8000 bytes. Existing private data was retained.'; }
                    if (str_starts_with($action, 'research_')) { $error = 'Research request could not be started. Check the provider connection, field lengths, pending requests and ten-request hourly limit.'; }
                    if ($action === 'research_project') { $error='Enter a working title (up to 160 bytes) and a research brief (up to 4,000 bytes). Your draft is retained.'; }
                    if ($action === 'run_agent') {
                        $safeMessages = ['An agent run is already active.', 'Testing limit reached: three runs per hour.', 'Task not found.', 'Invalid server model configuration.', 'OpenAI is not connected. Configure AICONTROL_OPENAI_API_KEY directly on the server.'];
                        $error = in_array($failure->getMessage(), $safeMessages, true)
                            ? $failure->getMessage() : 'The task could not be run. Review its current status and the server connection before trying again.';
                    }
                }
            }
        }
        header('Content-Type: text/html; charset=utf-8');
        if ($method === 'HEAD') {
            return;
        }
        $entry = self::entry();
        ob_start(static fn(string $html): string => str_replace(
            ['href="/loader.php', 'action="/loader.php', 'href="/assets/panel.css"', 'src="/assets/panel.js"'],
            ['href="' . $entry, 'action="' . $entry, 'href="' . $entry . '?asset=panel.css"', 'src="' . $entry . '?asset=panel.js"'],
            $html
        ));
        $csrf = '<input type="hidden" name="csrf" value="' . self::escape($_SESSION['csrf']) . '">';
        $views = ['overview', 'projects', 'runs', 'systems', 'history', 'admin', 'research'];
        $view = is_string($_GET['view'] ?? null) && in_array($_GET['view'], $views, true) ? $_GET['view'] : 'overview';
        $authenticated = (bool) ($_SESSION['authenticated'] ?? false);
        $data = $authenticated ? $store->snapshot() : ['projects' => [], 'tasks' => [], 'events' => [], 'runs' => []];
        if ($authenticated && $view === 'admin') { $data['vault'] = (new Vault($directory))->entries(); }
        if ($authenticated && $view === 'research') { $data['research'] = (new Research($directory))->snapshot(); $data['research']['workspace'] = (new Investigation($directory))->workspace((int)($_GET['project'] ?? 0), (int)($_GET['run'] ?? 0),($_GET['new']??'')==='1'||(($action??'')==='research_project'&&$error!=='')); }
        if(($action??'')==='research_project'&&$error!==''&&isset($data['research']))$data['research']['create_values']=['name'=>self::input('name'),'brief'=>self::input('brief')];
        $data['asset_nonce'] = $assetNonce;
        PanelView::render($data, $view, $csrf, $error, $passwordMode, $config, $authenticated);
    }
}
