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

    public static function render(array $data, string $view, string $csrf, string $error, bool $passwordMode, array $config, bool $authenticated): void
    {
        $views = ['overview' => ['Overview', 'grid'], 'research' => ['Research projects', 'search'], 'admin' => ['Admin', 'lock'], 'projects' => ['Projects & tasks', 'folder'], 'runs' => ['Agent runs', 'robot'], 'systems' => ['Systems', 'plug'], 'history' => ['Activity', 'clock']];
        $subtitle = [
            'admin' => 'Private keys and notes for your workspace.',
            'research' => 'Explore ideas, check sources, and keep the conversation with the project.',
            'overview' => 'A clear view of your projects, tasks, and next steps.',
            'projects' => 'Keep the brief, the work, and its acceptance criteria together.',
            'runs' => 'Follow each request from task to a result ready for review.',
            'systems' => 'See what is available, configured, and still waiting to connect.',
            'history' => 'The work, the decisions, and what actually happened.',
        ];
        $label = $views[$view][0];
        $updateUrl = getenv('AICONTROL_UPDATE_STATUS_URL') ?: '';
        $updateAttribute = $authenticated && $updateUrl !== '' ? ' data-update-status-url="' . Ui::e($updateUrl) . '"' : '';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Ui::e($label) . ' · AIcontrol</title>' . Ui::favicon() . '<style nonce="' . Ui::e($data['asset_nonce']) . '">' . Ui::css() . '</style></head><body><a class="skip-link" href="#main-content">Skip to content</a><div class="app-layout' . '"' . $updateAttribute . '><aside class="app-sidebar">' . Ui::brand('/loader.php') . '<div class="sidebar-label">WORKSPACE</div><nav class="app-navigation" aria-label="Main navigation">';
        if ($authenticated) {
            foreach ($views as $key => [$name, $icon]) {
                echo '<a href="/loader.php?view=' . $key . '"' . ($key === $view ? ' aria-current="page"' : '') . '>' . Ui::icon($icon) . '<span>' . $name . '</span></a>';
            }
            echo '<a href="/loader.php?installer=1">' . Ui::icon('upload') . '<span>Application updates</span><span class="update-count" data-update-badge hidden>1</span></a>';
        }
        echo '</nav><div class="sidebar-foot"><div class="sidebar-note"><strong>One step at a time.</strong>Plan clearly. Run a bounded task. Review the result.</div>';
        if ($authenticated) {
            if ($passwordMode) {
                echo '<form method="post" action="/loader.php">' . $csrf . '<input type="hidden" name="action" value="logout"><button class="btn btn-sm btn-outline-secondary mt-3">Sign out</button></form>';
            } elseif ($config['access_mode'] === 'pin') {
                echo '<a class="sidebar-lock" href="/loader.php?pin=manage">' . Ui::icon('lock') . '<span>Lock panel</span></a>';
            } else {
                echo '<div class="sidebar-lock">' . Ui::icon('lock') . '<span>' . ($config['access_mode'] === 'testing' ? 'Testing workspace' : 'Hosting access') . '</span></div>';
            }
        }
        echo '</div></aside><div class="app-main"><header class="app-topbar"><div class="breadcrumbs">Workspace <span aria-hidden="true">/</span><strong>' . Ui::e($label) . '</strong></div><div class="topbar-right">' . self::pill('Testing release', 'purple');
        if ($authenticated && $config['access_mode'] === 'pin') { echo '<a class="topbar-lock" href="/loader.php?pin=manage" aria-label="Lock panel" title="Lock panel">' . Ui::icon('lock') . '</a>'; }
        echo '<span class="workspace-avatar" aria-label="Workspace">AC</span></div></header><main class="app-content" id="main-content">';
        if ($authenticated && $updateUrl !== '') {
            echo '<section class="update-notice" data-update-notice hidden aria-live="polite"><div class="update-notice-copy"><strong data-update-label>Checking for updates…</strong><p data-update-detail></p></div><button class="btn btn-outline-secondary" type="button" data-check-updates>Check again</button><a class="btn btn-primary" data-update-review hidden href="/loader.php?installer=1">Review update</a></section>';
        }
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
            echo '<div class="page-heading"><div><div class="eyebrow">YOUR WORKSPACE</div><h1>' . Ui::e($label) . '</h1><p>' . $subtitle[$view] . '</p></div>';
            if ($view === 'overview' || $view === 'projects') {
                echo '<a class="btn btn-primary" href="/loader.php?view=projects#new-project">' . Ui::icon('plus') . 'New project</a>';
            }
            echo '</div>';
            match ($view) {
                'admin' => WorkspaceView::admin($data['vault'], $csrf), 'research' => WorkspaceView::research($data['research'], $csrf),
                'overview' => self::overview($data), 'projects' => self::projects($data, $csrf),
                'runs' => self::runs($data), 'systems' => self::systems($data), 'history' => self::history($data),
            };
        }
        echo '<footer class="app-footer"><span>AIcontrol · A place for the work and its evidence.</span><span>Times in AWST · <a href="/loader.php?installer=1">Application updates</a></span></footer></main></div></div><script nonce="' . Ui::e($data['asset_nonce']) . '">' . Ui::js() . '</script></body></html>';
    }

    private static function overview(array $data): void
    {
        $review = count(array_filter($data['tasks'], static fn(array $task): bool => $task['status'] === 'in_review'));
        echo '<div class="metric-grid">';
        foreach ([['Projects', count($data['projects']), 'folder', 'Briefs with a place to grow'], ['Task proposals', count($data['tasks']), 'task', 'Work with acceptance criteria'], ['In review', $review, 'robot', 'Results waiting for a decision'], ['Provider cost', 'Unknown', 'receipt', 'No verified billing total']] as [$name, $value, $icon, $caption]) {
            echo '<section class="card metric-card"><div class="card-body"><div class="metric-top"><span>' . $name . '</span>' . Ui::icon($icon) . '</div><div class="metric-value ' . (is_string($value) ? 'word' : '') . '">' . Ui::e($value) . '</div><div class="metric-caption">' . $caption . '</div></div></section>';
        }
        echo '</div><div class="dashboard-grid"><div class="stack"><section class="card welcome-card"><div class="card-body"><div class="welcome-tag">' . Ui::icon('spark') . 'PROJECT PLANNING</div><h2>Turn a good brief into the next step.</h2><p>Give your project a home, define a task, and keep the result beside the work that led to it.</p><a class="btn btn-primary" href="/loader.php?view=projects">Manage projects ' . Ui::icon('arrow') . '</a></div></section><section class="card"><div class="card-header"><h2 class="card-title">Your projects</h2><a class="ms-auto muted" href="/loader.php?view=projects">View all</a></div>';
        self::projectsTable(array_slice($data['projects'], 0, 5), $data['tasks']);
        echo '</section></div><div class="stack"><section class="card"><div class="card-header"><h2 class="card-title">Make your first connection</h2></div><div class="card-body"><div class="checklist">';
        foreach ([['Create a project', 'Start with a brief and an outcome.', '/loader.php?view=projects#new-project', count($data['projects']) > 0], ['Add a task', 'Define what a useful result looks like.', '/loader.php?view=projects#new-task', count($data['tasks']) > 0], ['Configure your AI connection', 'Enable a bounded task response.', '/loader.php?view=systems#openai', AgentRunner::configured()]] as $index => [$title, $hint, $href, $done]) {
            echo '<div class="checklist-item"><span class="check-circle ' . ($done ? 'done' : '') . '">' . ($done ? Ui::icon('check') : $index + 1) . '</span><div><a href="' . $href . '">' . $title . '</a><p>' . $hint . '</p></div></div>';
        }
        echo '</div></div></section><section class="card"><div class="card-header"><h2 class="card-title">Recent activity</h2><a class="ms-auto muted" href="/loader.php?view=history">View all</a></div><div class="card-body pt-1 pb-1">';
        self::activity(array_slice($data['events'], 0, 3));
        echo '</div></section></div></div>';
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
        echo '<div><div class="section-title"><h2>Projects</h2><span>' . count($projects) . ' projects</span></div><div class="stack">';
        if ($projects === []) {
            echo '<section class="card">';
            self::empty('folder', $search === '' ? 'No projects yet' : 'No matching projects', $search === '' ? 'Use the new-project form to give your idea a starting point.' : 'Try another name or a word from the project brief.');
            echo '</section>';
        }
        foreach ($projects as $project) {
            $count = count(array_filter($data['tasks'], static fn(array $task): bool => (int) $task['project_id'] === (int) $project['id']));
            echo '<section class="card section-anchor" id="project-' . Ui::e($project['id']) . '"><div class="card-body"><div class="task-meta"><span class="project-avatar">' . Ui::e(mb_strtoupper(mb_substr($project['name'], 0, 1))) . '</span>' . self::pill('Planning', 'purple') . '</div><h3 class="task-title">' . Ui::e($project['name']) . '</h3><p class="task-description preserve">' . Ui::e($project['brief']) . '</p><div class="task-project">' . $count . ' tasks · Created ' . Ui::e(Ui::time($project['created_at'])) . '</div></div></section>';
        }
        echo '</div></div><div><div class="section-title"><h2>Task proposals</h2><span>' . count($tasks) . ' tasks</span></div><div class="stack">';
        if ($tasks === []) {
            echo '<section class="card">';
            self::empty('task', $search === '' ? 'Nothing in the queue yet' : 'No matching tasks', 'Add a task with acceptance criteria. A proposal does not start an agent automatically.');
            echo '</section>';
        }
        foreach ($tasks as $task) {
            echo '<section class="card task-card section-anchor" id="task-' . Ui::e($task['id']) . '"><div class="card-body"><div class="task-meta">' . self::status($task['status']) . '<span class="task-project">Task #' . Ui::e($task['id']) . '</span></div><h3 class="task-title">' . Ui::e($task['title']) . '</h3><a class="task-project" href="/loader.php?view=projects#project-' . Ui::e($task['project_id']) . '">' . Ui::e($task['project_name']) . '</a><p class="task-description preserve">' . Ui::e($task['criteria']) . '</p><div class="task-footer">';
            if (AgentRunner::configured()) {
                echo '<form method="post" action="/loader.php">' . $csrf . '<input type="hidden" name="action" value="run_agent"><input type="hidden" name="task_id" value="' . Ui::e($task['id']) . '"><button class="btn btn-primary">' . Ui::icon('spark') . 'Run AI task</button></form><p>Up to 1,000 output tokens · 3 runs/hour · cost unknown</p>';
            } else {
                echo '<button class="btn btn-outline-secondary" disabled>' . Ui::icon('robot') . 'Run AI task</button><p>Agent not connected yet. <a href="/loader.php?view=systems#openai">Set up the connection</a></p>';
            }
            echo '</div>';
            foreach ($data['runs'] as $run) {
                if ((int) $run['task_id'] === (int) $task['id']) {
                    echo '<details class="mt-3"><summary>Agent run #' . Ui::e($run['id']) . ' · ' . Ui::e(str_replace('_', ' ', $run['status'])) . '</summary><pre class="result preserve">' . Ui::e($run['output'] !== '' ? $run['output'] : 'No text result recorded.') . '</pre><p class="muted">Model: ' . Ui::e($run['model']) . ' · Cost: unknown</p></details>';
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
            echo '<section class="card"><div class="card-header"><h2 class="card-title">Run #' . Ui::e($run['id']) . '</h2><span class="ms-auto">' . self::status($run['status']) . '</span></div><div class="card-body"><h3 class="task-title"><a href="/loader.php?view=projects#task-' . Ui::e($run['task_id']) . '">' . Ui::e($task['title'] ?? 'Task #' . $run['task_id']) . '</a></h3><p class="muted">' . Ui::e($run['model']) . ' · ' . Ui::e(Ui::time((int) $run['started_at'])) . '</p><pre class="result preserve">' . Ui::e($run['output'] !== '' ? $run['output'] : 'No text result recorded.') . '</pre><p class="muted">Input tokens: ' . Ui::e($run['input_tokens'] ?? 'Unknown') . ' · Output tokens: ' . Ui::e($run['output_tokens'] ?? 'Unknown') . ' · Cost: unknown</p>';
            if ($run['provider_request_id'] !== null) { echo '<details><summary>Provider evidence</summary><code>' . Ui::e($run['provider_request_id']) . '</code></details>'; }
            echo '</div></section>';
        }
        echo '</div>';
    }

    private static function systems(array $data): void
    {
        $sha = getenv('AICONTROL_INSTALLED_SHA') ?: '';
        echo '<div class="connection-grid">';
        foreach ([
            ['openai', 'OpenAI', 'spark', AgentRunner::configured() ? 'Configured · not verified' : 'Not configured', 'Bounded task responses with a result saved for review.', ['Capability' => 'Proposed solution and validation steps', 'Run limit' => '3 requests / hour · 1,000 output tokens', 'Model' => getenv('AICONTROL_OPENAI_MODEL') ?: 'gpt-4.1-mini']],
            ['github', 'GitHub', 'github', $sha !== '' ? 'Release installed' : 'Not configured', 'Application source and checked release downloads.', ['Repository' => 'efrgdgr0024345/AIcontrol', 'Installed commit' => $sha !== '' ? $sha : 'Not available in this development view', 'Code publishing' => 'Not connected']],
            ['actions', 'GitHub Actions', 'task', $sha !== '' ? 'Checked at installation' : 'Not configured', 'Release checks for the exact commit selected by the installer.', ['Required check' => 'planning-panel', 'Project test jobs' => 'Not connected', 'Last check' => $sha !== '' ? 'Passed before this release was installed' : 'No installation evidence available']],
            ['hosting', 'Hosting', 'server', 'Panel running', 'The current PHP runtime serving your workspace.', ['PHP version' => PHP_VERSION, 'Access' => 'Managed by the loader', 'Production deployment' => 'Not connected']],
        ] as [$id, $name, $icon, $status, $description, $facts]) {
            echo '<section class="card section-anchor" id="' . $id . '"><div class="card-body"><div class="d-flex align-items-start justify-content-between"><span class="connection-icon">' . Ui::icon($icon) . '</span>' . self::pill($status, $status === 'Panel running' || $status === 'Release installed' ? 'green' : '') . '</div><h2>' . $name . '</h2><p>' . $description . '</p><dl>';
            foreach ($facts as $key => $value) { echo '<dt>' . Ui::e($key) . '</dt><dd>' . Ui::e($value) . '</dd>'; }
            echo '</dl>';
            if ($id === 'openai') {
                echo '<details><summary>Connection setup</summary><p>Open <a href="/loader.php?view=admin">Admin</a> and save a key named <code>openai_api_key</code>. A server <code>AICONTROL_OPENAI_API_KEY</code> overrides the stored key. No saved credential value is displayed here.</p><a href="https://github.com/efrgdgr0024345/AIcontrol/blob/main/docs/STANDALONE_LOADER.md#first-ai-task-connection" target="_blank" rel="noopener noreferrer">Read connection instructions</a></details>';
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
