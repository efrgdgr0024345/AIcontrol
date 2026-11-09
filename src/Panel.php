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
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
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
        if ($method === 'POST') {
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
                http_response_code(413);
                return;
            }
            if (!hash_equals($_SESSION['csrf'], self::input('csrf'))) {
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
            } elseif ($action === 'logout' && $passwordMode) {
                $_SESSION = [];
                session_destroy();
                setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
                header('Location: ' . self::entry(), true, 303);
                return;
            } else {
                try {
                    if ($action === 'project') {
                        $store->createProject(self::input('name'), self::input('brief'));
                    } elseif ($action === 'task') {
                        $project = filter_var(self::input('project_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($project === false) {
                            throw new RuntimeException('Invalid project.');
                        }
                        $store->createTask($project, self::input('title'), self::input('criteria'));
                    } elseif ($action === 'run_agent') {
                        $task = filter_var(self::input('task_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($task === false) {
                            throw new RuntimeException('Invalid task.');
                        }
                        AgentRunner::run($store, $task);
                    } else {
                        throw new RuntimeException('Operation unavailable.');
                    }
                    header('Location: ' . self::entry() . '?view=projects', true, 303);
                    return;
                } catch (Throwable) {
                    http_response_code(422);
                    $error = 'Proposal could not be saved. Check the selected project and field lengths.';
                }
            }
        }
        header('Content-Type: text/html; charset=utf-8');
        if ($method === 'HEAD') {
            return;
        }
        $entry = self::entry();
        ob_start(static fn(string $html): string => str_replace(
            ['href="/loader.php', 'action="/loader.php', 'href="/assets/panel.css"'],
            ['href="' . $entry, 'action="' . $entry, 'href="' . $entry . '?asset=panel.css"'],
            $html
        ));
        $e = self::escape(...);
        $csrf = '<input type="hidden" name="csrf" value="' . $e($_SESSION['csrf']) . '">';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AIcontrol</title><link rel="stylesheet" href="/assets/panel.css"></head><body>';
        echo '<aside><a class="brand" href="/loader.php">AIcontrol</a><p>Project control panel</p>';
        $views = ['overview' => 'Overview', 'projects' => 'Projects & tasks', 'systems' => 'Systems', 'history' => 'Activity'];
        $view = is_string($_GET['view'] ?? null) && isset($views[$_GET['view']]) ? $_GET['view'] : 'overview';
        if ($_SESSION['authenticated'] ?? false) {
            foreach ($views as $key => $label) {
                echo '<a class="nav ' . ($view === $key ? 'selected' : '') . '" href="/loader.php?view=' . $key . '">' . $label . '</a>';
            }
            if ($passwordMode) {
                echo '<form method="post" action="/loader.php">' . $csrf . '<input type="hidden" name="action" value="logout"><button class="secondary">Sign out</button></form>';
            } else {
                echo $config['access_mode'] === 'pin' ? '<p><a href="/loader.php?pin=manage">PIN session / Lock panel</a></p>' : ($config['access_mode'] === 'testing' ? '<p>Public testing mode</p>' : '<p>Access managed by hosting</p>');
            }
        }
        echo '</aside><main><div class="notice">Testing workspace · Projects, tasks and bounded AI responses. Code execution and deployment are not connected.</div>';
        if ($error !== '') {
            echo '<p role="alert" class="error">' . $e($error) . '</p>';
        }
        if (!($_SESSION['authenticated'] ?? false)) {
            echo '<h1>Your projects, in one place.</h1><p>Sign in to plan work and follow recorded proposals.</p><section class="login"><h2>Sign in</h2><form method="post" action="/loader.php">' . $csrf
                . '<input type="hidden" name="action" value="login"><label>Panel password<input type="password" name="password" autocomplete="current-password" required maxlength="256"></label><button>Sign in</button></form></section>';
        } else {
            $data = $store->snapshot();
            echo '<header><p class="eyebrow">WORKSPACE</p><h1>' . $views[$view] . '</h1></header>';
            if ($view === 'overview') {
                echo '<div class="stats"><section><span>Projects</span><strong>' . count($data['projects']) . '</strong></section><section><span>Task proposals</span><strong>' . count($data['tasks']) . '</strong></section><section><span>AI connection</span><strong>' . (AgentRunner::configured() ? 'Configured' : 'Not configured') . '</strong></section><section><span>Provider cost</span><strong>Unknown</strong></section></div>';
                echo '<section><h2>Start with a clear brief</h2><p>Create a project, then add a task and its acceptance criteria. A configured AI connection can return a proposed solution for review.</p><a class="button" href="/loader.php?view=projects">Manage projects</a></section><section><h2>Next connection</h2><p>Connect OpenAI on the server to run a bounded task response. Repository checkout, isolated code execution and pull-request publishing are not connected yet.</p></section>';
            } elseif ($view === 'projects') {
                echo '<div class="columns"><section><h2>New project</h2><form method="post" action="/loader.php">' . $csrf . '<input type="hidden" name="action" value="project"><label>Name<input name="name" required maxlength="160"></label><label>Brief and intended outcome<textarea name="brief" required maxlength="4000"></textarea></label><button>Create project</button></form></section>';
                echo '<section><h2>Propose a task</h2>';
                if ($data['projects'] === []) {
                    echo '<p>Create a project first.</p>';
                } else {
                    echo '<form method="post" action="/loader.php">' . $csrf . '<input type="hidden" name="action" value="task"><label>Project<select name="project_id">';
                    foreach ($data['projects'] as $project) {
                        echo '<option value="' . $e($project['id']) . '">' . $e($project['name']) . '</option>';
                    }
                    echo '</select></label><label>Task<input name="title" required maxlength="160"></label><label>Acceptance criteria<textarea name="criteria" required maxlength="4000"></textarea></label><button>Save proposal</button></form>';
                }
                echo '</section></div><h2>Projects</h2>';
                if ($data['projects'] === []) {
                    echo '<section><p>No projects yet.</p></section>';
                }
                foreach ($data['projects'] as $project) {
                    echo '<section id="project-' . $e($project['id']) . '"><h3>' . $e($project['name']) . '</h3><p class="preserve">' . $e($project['brief']) . '</p></section>';
                }
                echo '<h2>Task proposals</h2>';
                if ($data['tasks'] === []) {
                    echo '<section><p>No tasks yet. No agent is running.</p></section>';
                }
                foreach ($data['tasks'] as $task) {
                    echo '<section id="task-' . $e($task['id']) . '"><span class="badge">' . ($task['status'] === 'draft' ? 'Draft · not dispatched' : $e($task['status'])) . '</span><h3>' . $e($task['title']) . '</h3><p>Project: ' . $e($task['project_name']) . '</p><p class="preserve">' . $e($task['criteria']) . '</p>';
                    if (AgentRunner::configured()) {
                        echo '<form method="post" action="/loader.php">' . $csrf . '<input type="hidden" name="action" value="run_agent"><input type="hidden" name="task_id" value="' . $e($task['id']) . '"><button>Run AI task</button><p>One response, up to 1,000 output tokens. Testing limit: three runs per hour. Cost unknown.</p></form>';
                    } else {
                        echo '<p>Agent not connected yet. Configure the OpenAI connection on the server to run this task.</p>';
                    }
                    foreach ($data['runs'] as $run) {
                        if ((int) $run['task_id'] === (int) $task['id']) {
                            echo '<article><h3>Agent run #' . $e($run['id']) . ' · ' . $e($run['status']) . '</h3><p>Model: ' . $e($run['model']) . ' · Cost: unknown</p><pre class="preserve">' . $e($run['output']) . '</pre></article>';
                        }
                    }
                    echo '</section>';
                }
            } elseif ($view === 'systems') {
                echo '<section><h2>Available integrations</h2><p>These are planned integrations. No connection has been configured or verified.</p><table><thead><tr><th>System</th><th>Status</th><th>Intended use</th></tr></thead><tbody>';
                foreach (['OpenAI' => 'Bounded provider operations', 'GitHub' => 'Repository and pull request evidence', 'GitHub Actions' => 'Isolated test jobs', 'cPanel' => 'Hosting and reviewed deployments'] as $name => $purpose) {
                    echo '<tr><td>' . $name . '</td><td>' . ($name === 'OpenAI' && AgentRunner::configured() ? 'Configured · not verified' : 'Not configured') . '</td><td>' . $purpose . '</td></tr>';
                }
                echo '</tbody></table></section>';
            } else {
                echo '<section><h2>Recent activity</h2><p>Latest 100 recorded events, newest first. All timestamps are UTC. Includes AI task requests and outcomes when connected.</p>';
                if ($data['events'] === []) {
                    echo '<p>No activity recorded.</p>';
                }
                foreach ($data['events'] as $event) {
                    $target = $event['task_id'] === null ? 'project-' . $event['project_id'] : 'task-' . $event['task_id'];
                    echo '<article><time>' . $e($event['created_at']) . '</time><h3><a href="/loader.php?view=projects#' . $e($target) . '">' . $e($event['kind']) . '</a></h3><p>' . $e($event['detail']) . '</p></article>';
                }
                echo '</section>';
            }
        }
        echo '<footer>AIcontrol · Testing release · No autonomous code execution or production deployment</footer></main></body></html>';
    }
}
