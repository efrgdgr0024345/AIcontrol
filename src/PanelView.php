<?php
declare(strict_types=1);
namespace AIcontrol;

use AIcontrolWorkspaceUi as Ui;

/** Server-rendered Tabler workspace; links/forms remain functional without JS. */
final class PanelView
{
    private static function pill(string $label, string $color = ''): string
    {
        return '<span class="status-pill ' . Ui::e($color) . '">' . Ui::e($label) . '</span>';
    }

    private static function status(string $status): string
    {
        return self::pill(match ($status) {
            'draft' => 'Draft · not dispatched', 'working' => 'Working', 'in_review' => 'In review',
            'failed' => 'Failed', 'outcome_uncertain' => 'Outcome uncertain', default => ucfirst(str_replace('_', ' ', $status)),
        }, match ($status) { 'in_review' => 'purple', 'working' => 'green', 'failed' => 'red', 'outcome_uncertain' => 'amber', default => '' });
    }

    private static function empty(string $icon, string $heading, string $description): void
    {
        echo '<div class="empty-state"><span class="empty-icon">' . Ui::icon($icon) . '</span><h3>' . Ui::e($heading) . '</h3><p>' . Ui::e($description) . '</p></div>';
    }

    private static function activity(array $events): void
    {
        if ($events === []) {
            self::empty('clock', 'Your story starts here', 'Project proposals, task requests and results will appear in this timeline.');
            return;
        }
        echo '<div class="activity-list">';
        foreach ($events as $event) {
            $task = $event['task_id'] !== null;
            $target = $task ? 'task-' . $event['task_id'] : 'project-' . $event['project_id'];
            $title = match ($event['kind']) {
                'project.proposed' => 'Project created', 'task.proposed' => 'Task proposal added',
                'agent.started' => 'AI task started', 'agent.in_review' => 'Result ready for review',
                'agent.failed' => 'AI task failed', 'agent.outcome_uncertain' => 'AI task outcome needs review',
                default => ucfirst(str_replace(['.', '_'], ' ', $event['kind'])),
            };
            echo '<article class="activity-item" data-event="' . Ui::e($event['kind']) . '"><span class="activity-icon">' . Ui::icon(str_starts_with($event['kind'], 'agent.') ? 'robot' : ($task ? 'task' : 'folder')) . '</span><div class="activity-copy"><a href="/loader.php?view=projects#' . Ui::e($target) . '">' . Ui::e($title) . '</a><p>' . Ui::e($event['detail']) . '</p><time datetime="' . Ui::e($event['created_at']) . '">' . Ui::e(Ui::time($event['created_at'])) . '</time></div></article>';
        }
        echo '</div>';
    }

    private static function projectsTable(array $projects, array $tasks): void
    {
        if ($projects === []) {
            self::empty('folder', 'A fresh workspace', 'Create your first project with a clear brief and an outcome to work toward.');
            return;
        }
        echo '<div class="table-responsive"><table class="table"><thead><tr><th scope="col">Project</th><th scope="col">Tasks</th><th scope="col">Status</th></tr></thead><tbody>';
        foreach ($projects as $project) {
            $count = count(array_filter($tasks, static fn(array $task): bool => (int) $task['project_id'] === (int) $project['id']));
            echo '<tr><td><div class="project-row"><span class="project-avatar" aria-hidden="true">' . Ui::e(mb_strtoupper(mb_substr($project['name'], 0, 1))) . '</span><div><a class="project-name" href="/loader.php?view=projects#project-' . Ui::e($project['id']) . '">' . Ui::e($project['name']) . '</a><small>Project #' . Ui::e($project['id']) . '</small></div></div></td><td>' . $count . '</td><td>' . self::pill('Planning', 'purple') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function navLink(string $href, string $name, string $icon, bool $current = false): string
    {
        return '<a href="' . Ui::e($href) . '"' . ($current ? ' aria-current="page"' : '') . '>'
            . Ui::icon($icon) . '<span>' . Ui::e($name) . '</span></a>';
    }

    private static function navigation(array $views, string $view, array $config): void
    {
        foreach ($views as $key => [$label, $icon]) {
            $links = match ($key) {
                'projects' => [
                    ['/loader.php?view=projects#project-list', 'All projects', 'folder'],
                    ['/loader.php?view=projects#task-list', 'Task proposals', 'task'],
                    ['/loader.php?view=overview#review-queue', 'Review queue', 'check'],
                ],
                'admin' => $config['access_mode'] === 'pin' ? [['/loader.php?pin=manage', 'Lock panel', 'lock']] : [],
                default => [],
            };
            if ($links === []) {
                echo self::navLink('/loader.php?view=' . $key, $label, $icon, $key === $view);
                continue;
            }
            echo '<div class="sidebar-group sidebar-' . Ui::e($key) . '">'
                . self::navLink('/loader.php?view=' . $key, $label, $icon, $key === $view)
                . '<details class="sidebar-group-details"' . ($key === $view ? ' open' : '') . '>'
                . '<summary aria-label="' . Ui::e($label . ' sublist') . '"><span aria-hidden="true">›</span></summary>'
                . '<ul class="sidebar-sublist">';
            foreach ($links as [$href, $name, $childIcon]) {
                echo '<li>' . self::navLink($href, $name, $childIcon) . '</li>';
            }
            echo '</ul></details></div>';
        }
    }

    public static function render(array $data, string $view, string $csrf, string $error, bool $passwordMode, array $config, bool $authenticated): void
    {
        $views = ['overview' => ['Overview', 'grid'], 'projects' => ['Projects & tasks', 'folder'], 'research' => ['Research projects', 'search'], 'nasa' => ['Engineering', 'grid'], 'systems' => ['Systems', 'plug'], 'runs' => ['Agent runs', 'robot'], 'history' => ['Activity', 'clock'], 'admin' => ['Admin', 'lock']];
        $subtitle = [
            'nasa'=>'Follow a pathway, inspect results, and keep decisions traceable.',
            'admin' => 'Connections, diagnostics and application updates.',
            'research' => 'Explore ideas, check sources, and keep the conversation with the project.',
            'overview' => 'A clear view of your projects, tasks, and next steps.',
            'projects' => 'Keep the brief, the work, and its acceptance criteria together.',
            'runs' => 'Follow each request from task to a result ready for review.',
            'systems' => 'See what is available, configured, and still waiting to connect.',
            'history' => 'The work, the decisions, and what actually happened.',
        ];
        $label = $views[$view][0];
        $legacy = $view === 'research';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Ui::e($label) . ' · ' . ($legacy ? 'AIcontrol' : 'Project Desk') . '</title>' . Ui::favicon() . '<style nonce="' . Ui::e($data['asset_nonce']) . '">' . Ui::css() . '</style></head><body><a class="skip-link" href="#main-content">Skip to content</a><div class="app-layout' . ($legacy ? '' : ' project-desk') . '"><aside class="app-sidebar"' . ($legacy ? '' : ' id="workspace-sidebar"') . '>' . Ui::brand('/loader.php', $legacy) . ($legacy ? '<div class="sidebar-label">WORKSPACE</div>' : '') . '<nav class="app-navigation" aria-label="Main navigation">';
        if ($authenticated && !$legacy) {
            self::navigation($views, $view, $config);
        } elseif ($authenticated) {
            foreach ($views as $key => [$name, $icon]) {
                if ($key === 'admin') echo '<div class="sidebar-admin">';
                echo '<a href="/loader.php?view=' . $key . '"' . ($key === $view ? ' aria-current="page"' : '') . '>' . Ui::icon($icon) . '<span>' . $name . '</span></a>';
                if ($key === 'admin') {
                    if ($config['access_mode'] === 'pin') echo '<a class="sidebar-admin-action" href="/loader.php?pin=manage">' . Ui::icon('lock') . '<span>Lock panel</span></a>';
                    echo '</div>';
                }
            }
        }
        echo '</nav><div class="sidebar-foot">';
        if ($authenticated) {
            if ($passwordMode) {
                echo '<form method="post" action="/loader.php">' . $csrf . '<input type="hidden" name="action" value="logout"><button class="btn btn-sm btn-outline-secondary mt-3">Sign out</button></form>';
            } elseif ($config['access_mode'] !== 'pin') {
                echo '<div class="sidebar-lock">' . Ui::icon('lock') . '<span>' . ($config['access_mode'] === 'testing' ? 'Testing workspace' : 'Hosting access') . '</span></div>';
            }
        }
        echo '</div></aside><div class="app-main"><header class="app-topbar">';
        if (!$legacy) echo '<button class="btn desk-menu-toggle" type="button" aria-expanded="false" aria-controls="workspace-sidebar">' . Ui::icon('grid') . 'Menu</button>';
        echo '<div class="breadcrumbs">' . ($legacy ? 'Workspace' : 'Project Desk') . ' <span aria-hidden="true">/</span><strong>' . Ui::e($label) . '</strong></div>';
        if ($legacy) echo '<div class="topbar-right">' . self::pill('Testing release', 'purple') . '<span class="workspace-avatar" aria-label="Workspace">AC</span></div>';
        echo '</header><main class="app-content" id="main-content">';
        if ($error !== '') {
            echo '<div class="alert alert-danger" role="alert">' . Ui::e($error) . '</div>';
        }
        if (!$authenticated) {
            echo '<div class="card mx-auto mt-5 auth-card"><div class="card-body"><div class="auth-icon">' . Ui::icon('lock') . '</div><h1>Sign in</h1><p class="lead-copy">Open your workspace to plan and review the work.</p><form method="post" action="/loader.php">' . $csrf . '<input type="hidden" name="action" value="login"><label class="form-label" for="panel-password">Panel password</label><input class="form-control mb-3" id="panel-password" type="password" name="password" autocomplete="current-password" required maxlength="256"><button class="btn btn-primary w-100">Sign in</button></form></div></div>';
        } else {
            if (is_string($_SESSION['flash'] ?? null)) {
                echo '<div class="alert alert-success" role="status">' . Ui::e($_SESSION['flash']) . '</div>';
                unset($_SESSION['flash']);
            }
            echo '<div class="page-heading"><div><h1>' . Ui::e($view === 'overview' ? 'Review queue' : $label) . '</h1>' . ($view === 'overview' ? '' : '<p>' . $subtitle[$view] . '</p>') . '</div>';
            if ($view === 'overview' || $view === 'projects') {
                echo '<a class="btn btn-primary" href="/loader.php?view=projects#new-project">' . Ui::icon('plus') . 'New project</a>';
            }
            echo '</div>';
            match ($view) {
                'admin' => WorkspaceView::admin($data['vault'], $csrf), 'research' => WorkspaceView::research($data['research'], $csrf),'nasa'=>NasaView::render($data['nasa'],$csrf),
                'overview' => self::overview($data), 'projects' => self::projects($data, $csrf),
                'runs' => self::runs($data), 'systems' => self::systems($data,$csrf), 'history' => self::history($data),
            };
        }
        echo '<footer class="app-footer"><span>' . ($legacy ? 'AIcontrol · A place for the work and its evidence.' : 'Project Desk') . '</span><span>Times in AWST</span></footer></main></div></div><script nonce="' . Ui::e($data['asset_nonce']) . '">' . Ui::js() . '</script></body></html>';
    }

    private static function overview(array $data): void
    {
        $review = array_values(array_filter($data['tasks'], static fn(array $task): bool =>
            in_array($task['status'], ['in_review', 'outcome_uncertain', 'failed'], true)));
        echo '<section id="review-queue" aria-label="Review queue" class="desk-section">';
        if ($review === []) {
            echo '<p class="muted">No tasks need your attention.</p>';
        } else {
            echo '<ul class="desk-list">';
            foreach ($review as $task) {
                echo '<li><a href="/loader.php?view=projects#task-' . (int) $task['id'] . '">'
                    . Ui::icon('task') . '<span class="desk-list-title">' . Ui::e($task['title']) . '<small>' . Ui::e($task['project_name'])
                    . '</small></span>' . self::status($task['status']) . Ui::icon('arrow') . '</a></li>';
            }
            echo '</ul>';
        }
        echo '</section><section class="desk-section"><div class="section-title"><h2>Recent projects</h2><a href="/loader.php?view=projects#project-list">All projects</a></div>';
        self::projectsTable(array_slice($data['projects'], 0, 5), $data['tasks']);
        echo '</section><details class="desk-section"><summary>Workspace details</summary>';
        $usage = array_column($data['projects'], 'usage');
        $cost = number_format(array_sum(array_column($usage, 'estimated_usd')), 4);
        $unpriced = array_sum(array_column($usage, 'unknown_requests'));
        echo '<p>' . count($data['projects']) . ' projects · ' . count($data['tasks']) . ' task proposals · $' . $cost
            . ' USD estimated Task API cost · ' . $unpriced . ' unpriced calls</p>';
        if (!AgentRunner::configured()) echo '<p><a href="/loader.php?view=systems#openai">Configure your AI connection</a></p>';
        if ($data['projects'] !== []) echo '<p><a href="/loader.php?view=projects#new-task">Propose a task</a></p>';
        echo '<p><a href="/loader.php?view=history">View recent activity</a></p></details>';
    }

    private static function projects(array $data, string $csrf): void
    {
        $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        $matches = static fn(string $text): bool => $search === '' || str_contains(mb_strtolower($text), mb_strtolower($search));
        $projects = array_values(array_filter($data['projects'], static fn(array $project): bool => $matches($project['name'] . ' ' . $project['brief'])));
        $tasks = array_values(array_filter($data['tasks'], static fn(array $task): bool => $matches($task['title'] . ' ' . $task['criteria'] . ' ' . $task['project_name'])));
        echo '<div class="workspace-columns"><div class="stack"><form class="search-form" method="get" action="/loader.php"><input type="hidden" name="view" value="projects"><div class="search-field">' . Ui::icon('search') . '<input class="form-control" aria-label="Search projects and tasks" name="q" value="' . Ui::e($search) . '" placeholder="Search projects and tasks…" maxlength="100"></div><button class="btn btn-outline-secondary">Search</button></form>';
        if ($search !== '') {
            echo '<p class="muted">Results for “' . Ui::e($search) . '” · <a href="/loader.php?view=projects">Clear search</a></p>';
        }
        echo '<div><div class="section-title section-anchor" id="project-list"><h2>Projects</h2><span>' . count($projects) . ' projects</span></div><div class="stack">';
        if ($projects === []) {
            echo '<section class="card">';
            self::empty('folder', $search === '' ? 'No projects yet' : 'No matching projects', $search === '' ? 'Use the new-project form to give your idea a starting point.' : 'Try another name or a word from the project brief.');
            echo '</section>';
        }
        foreach ($projects as $project) {
            $count = count(array_filter($data['tasks'], static fn(array $task): bool => (int) $task['project_id'] === (int) $project['id']));
            echo '<section class="card section-anchor" id="project-' . Ui::e($project['id']) . '"><div class="card-body"><div class="task-meta"><span class="project-avatar">' . Ui::e(mb_strtoupper(mb_substr($project['name'], 0, 1))) . '</span>' . self::pill('Planning', 'purple') . '</div><h3 class="task-title">' . Ui::e($project['name']) . '</h3><p class="task-description preserve">' . Ui::e($project['brief']) . '</p><div class="task-project">' . $count . ' tasks · Created ' . Ui::e(Ui::time($project['created_at'])) . '</div><p class="project-spend">'.Ui::e(UsageMeter::label($project['usage'])).' · '.number_format($project['usage']['total_tokens']).' tokens</p></div></section>';
        }
        echo '</div></div><div><div class="section-title section-anchor" id="task-list"><h2>Task proposals</h2><span>' . count($tasks) . ' tasks</span></div><div class="stack">';
        if ($tasks === []) {
            echo '<section class="card">';
            self::empty('task', $search === '' ? 'Nothing in the queue yet' : 'No matching tasks', 'Add a task with acceptance criteria. A proposal does not start an agent automatically.');
            echo '</section>';
        }
        foreach ($tasks as $task) {
            echo '<section class="card task-card section-anchor" id="task-' . Ui::e($task['id']) . '"><div class="card-body"><div class="task-meta">' . self::status($task['status']) . '<span class="task-project">Task #' . Ui::e($task['id']) . '</span></div><h3 class="task-title">' . Ui::e($task['title']) . '</h3><a class="task-project" href="/loader.php?view=projects#project-' . Ui::e($task['project_id']) . '">' . Ui::e($task['project_name']) . '</a><p class="task-description preserve">' . Ui::e($task['criteria']) . '</p><div class="task-footer">';
            if (AgentRunner::configured()) {
                echo '<form method="post" action="/loader.php">' . $csrf . '<input type="hidden" name="action" value="run_agent"><input type="hidden" name="task_id" value="' . Ui::e($task['id']) . '"><button class="btn btn-primary">' . Ui::icon('spark') . 'Run AI task</button></form><p>Up to 1,000 output tokens · 3 runs/hour · estimated USD cost from API usage</p>';
            } else {
                echo '<button class="btn btn-outline-secondary" disabled>' . Ui::icon('robot') . 'Run AI task</button><p>Agent not connected yet. <a href="/loader.php?view=systems#openai">Set up the connection</a></p>';
            }
            echo '</div>';
            foreach ($data['runs'] as $run) {
                if ((int) $run['task_id'] === (int) $task['id']) {
                    echo '<details class="mt-3"><summary>Agent run #' . Ui::e($run['id']) . ' · ' . Ui::e(str_replace('_', ' ', $run['status'])) . '</summary><pre class="result preserve">' . Ui::e($run['output'] !== '' ? $run['output'] : 'No text result recorded.') . '</pre><p class="muted">Model: ' . Ui::e($run['model']) . ' · '.Ui::e(UsageMeter::label(UsageMeter::summary([$run]))).' · '.(int)($run['input_tokens']??0).' input / '.(int)($run['output_tokens']??0).' output tokens</p></details>';
                }
            }
            echo '</div></section>';
        }
        echo '</div></div></div><div class="stack">';
        $value = static fn(string $name): string => is_string($_POST[$name] ?? null) && !in_array($name, ['password', 'pin'], true) ? Ui::e($_POST[$name]) : '';
        echo '<section class="card section-anchor" id="new-project"><div class="card-header"><h2 class="card-title">New project</h2></div><div class="card-body"><p class="muted mb-4">A clear brief gives every task a better starting point.</p><form method="post" action="/loader.php">' . $csrf . '<input type="hidden" name="action" value="project"><div class="form-field"><label class="form-label" for="project-name">Name</label><input class="form-control" id="project-name" name="name" value="' . $value('name') . '" required maxlength="160" placeholder="e.g. Website refresh"></div><div class="form-field"><label class="form-label" for="project-brief">Brief and intended outcome</label><textarea class="form-control" id="project-brief" name="brief" required maxlength="4000" placeholder="What are we building, and what should improve?">' . $value('brief') . '</textarea></div><button class="btn btn-primary w-100">' . Ui::icon('plus') . 'Create project</button></form></div></section><section class="card section-anchor" id="new-task"><div class="card-header"><h2 class="card-title">Propose a task</h2></div><div class="card-body">';
        if ($data['projects'] === []) {
            echo '<p class="muted">Create a project first, then add its first task here.</p>';
        } else {
            echo '<form method="post" action="/loader.php">' . $csrf . '<input type="hidden" name="action" value="task"><div class="form-field"><label class="form-label" for="task-project">Project</label><select class="form-select" id="task-project" name="project_id">';
            foreach ($data['projects'] as $project) {
                echo '<option value="' . Ui::e($project['id']) . '">' . Ui::e($project['name']) . '</option>';
            }
            echo '</select></div><div class="form-field"><label class="form-label" for="task-title">Task</label><input class="form-control" id="task-title" name="title" value="' . $value('title') . '" required maxlength="160" placeholder="What needs to happen?"></div><div class="form-field"><label class="form-label" for="task-criteria">Acceptance criteria</label><textarea class="form-control" id="task-criteria" name="criteria" required maxlength="4000" placeholder="Describe the result and how it will be checked.">' . $value('criteria') . '</textarea><div class="form-hint">Specific criteria make a result easier to review.</div></div><button class="btn btn-primary w-100">Save proposal</button></form>';
        }
        echo '</div></section></div></div>';
    }

    private static function runs(array $data): void
    {
        if ($data['runs'] === []) {
            echo '<section class="card">';
            self::empty('robot', 'No agent runs yet', 'Configure the AI connection, then open a task and choose Run AI task. Results will be saved here for review.');
            echo '<div class="card-footer text-center"><a class="btn btn-primary" href="/loader.php?view=projects">Open tasks</a></div></section>';
            return;
        }
        echo '<div class="stack">';
        foreach ($data['runs'] as $run) {
            $task = array_values(array_filter($data['tasks'], static fn(array $task): bool => (int) $task['id'] === (int) $run['task_id']))[0] ?? null;
            echo '<section class="card"><div class="card-header"><h2 class="card-title">Run #' . Ui::e($run['id']) . '</h2><span class="ms-auto">' . self::status($run['status']) . '</span></div><div class="card-body"><h3 class="task-title"><a href="/loader.php?view=projects#task-' . Ui::e($run['task_id']) . '">' . Ui::e($task['title'] ?? 'Task #' . $run['task_id']) . '</a></h3><p class="muted">' . Ui::e($run['model']) . ' · ' . Ui::e(Ui::time((int) $run['started_at'])) . '</p><pre class="result preserve">' . Ui::e($run['output'] !== '' ? $run['output'] : 'No text result recorded.') . '</pre><p class="muted">Input tokens: ' . Ui::e($run['input_tokens'] ?? 'Unknown') . ' · Output tokens: ' . Ui::e($run['output_tokens'] ?? 'Unknown') . ' · '.Ui::e(UsageMeter::label(UsageMeter::summary([$run]))).'</p>';
            if ($run['provider_request_id'] !== null) { echo '<details><summary>Provider evidence</summary><code>' . Ui::e($run['provider_request_id']) . '</code></details>'; }
            echo '</div></section>';
        }
        echo '</div>';
    }

    private static function systems(array $data,string $csrf): void
    {
        SystemsView::render($data['systems'],$csrf,$data['projects']);
        $sha = getenv('AICONTROL_INSTALLED_SHA') ?: '';
        echo '<div class="connection-grid">';
        foreach ([
            ['openai', 'OpenAI', 'spark', AgentRunner::configured() ? 'API key configured' : 'Not configured', 'Bounded task responses with a result saved for review.', ['Capability' => 'Proposed solution and validation steps', 'Run limit' => '3 requests / hour · 1,000 output tokens', 'Model' => getenv('AICONTROL_OPENAI_MODEL') ?: 'gpt-4.1-mini']],
            ['github', 'GitHub', 'github', $sha !== '' ? 'Release installed' : 'Not configured', 'Application source and checked release downloads.', ['Repository' => 'efrgdgr0024345/AIcontrol', 'Installed commit' => $sha !== '' ? $sha : 'Not available in this development view', 'Code publishing' => 'Not connected']],
            ['actions', 'GitHub Actions', 'task', $sha !== '' ? 'Checked at installation' : 'Not configured', 'Release checks for the exact commit selected by the installer.', ['Required check' => 'planning-panel', 'Project test jobs' => 'Not connected', 'Last check' => $sha !== '' ? 'Passed before this release was installed' : 'No installation evidence available']],
            ['hosting', 'Hosting', 'server', 'Panel running', 'The current PHP runtime serving your workspace.', ['PHP version' => PHP_VERSION, 'Access' => 'Managed by the loader', 'Production deployment' => 'Not connected']],
        ] as [$id, $name, $icon, $status, $description, $facts]) {
            echo '<section class="card section-anchor" id="' . $id . '"><div class="card-body"><div class="d-flex align-items-start justify-content-between"><span class="connection-icon">' . Ui::icon($icon) . '</span>' . self::pill($status, $status === 'Panel running' || $status === 'Release installed' ? 'green' : '') . '</div><h2>' . $name . '</h2><p>' . $description . '</p><dl>';
            foreach ($facts as $key => $value) { echo '<dt>' . Ui::e($key) . '</dt><dd>' . Ui::e($value) . '</dd>'; }
            echo '</dl>';
            if ($id === 'openai') {
                echo '<details><summary>Connection setup</summary><p>Open <a href="/loader.php?view=admin">Admin</a> and save a key named <code>openai_api_key</code>. A server <code>AICONTROL_OPENAI_API_KEY</code> overrides the stored key.</p><p>OpenAI API billing applies to the organization and project that issued this key. A configured key alone cannot confirm billing, quota or model access.</p><p><a href="https://platform.openai.com/settings/organization/billing/overview">OpenAI API billing</a> · <a href="/loader.php?view=admin#diagnostics-report">Admin diagnostics</a></p><a href="https://github.com/efrgdgr0024345/AIcontrol/blob/main/docs/STANDALONE_LOADER.md#first-ai-task-connection" target="_blank" rel="noopener noreferrer">Read connection instructions</a></details>';
            }
            if ($id === 'github') { echo '<a class="btn btn-outline-secondary" href="/loader.php?installer=1">' . Ui::icon('upload') . 'Open application updates</a>'; }
            echo '</div></section>';
        }
        echo '</div>';
    }

    private static function history(array $data): void
    {
        $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        $events = array_values(array_filter($data['events'], static fn(array $event): bool => $search === '' || str_contains(mb_strtolower($event['kind'] . ' ' . $event['detail']), mb_strtolower($search))));
        echo '<form class="search-form" method="get" action="/loader.php"><input type="hidden" name="view" value="history"><div class="search-field">' . Ui::icon('search') . '<input class="form-control" aria-label="Search activity" name="q" maxlength="100" value="' . Ui::e($search) . '" placeholder="Search activity…"></div><button class="btn btn-outline-secondary">Search</button></form><section class="card"><div class="card-header"><h2 class="card-title">Workspace timeline</h2><span class="ms-auto muted">Latest 100 events · AWST</span></div><div class="card-body">';
        if ($events === [] && $search !== '') { self::empty('search', 'No matching activity', 'Try another event name or a word from its description.'); }
        else { self::activity($events); }
        echo '</div></section>';
    }
}
