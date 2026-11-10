<?php
declare(strict_types=1);

namespace AIcontrol;

use PDO;
use RuntimeException;
use Throwable;
require_once __DIR__ . "/UsageMeter.php";

/** Metadata-only prototype. Never stores credentials, grants or billable jobs. */
final class PanelStore
{
    private PDO $db;

    public function __construct(string $path, bool $initialize = false)
    {
        if (!$initialize && (!is_file($path) || is_link($path))) {
            throw new RuntimeException('Panel database missing.');
        }
        $this->db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
        if ($initialize) {
            $this->db->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT NOT NULL, brief TEXT NOT NULL, created_at TEXT NOT NULL);
                CREATE TABLE tasks (id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES projects(id), title TEXT NOT NULL, criteria TEXT NOT NULL, status TEXT NOT NULL DEFAULT "draft", evidence TEXT NOT NULL DEFAULT "", created_at TEXT NOT NULL);
                CREATE TABLE events (id INTEGER PRIMARY KEY, kind TEXT NOT NULL, project_id INTEGER, task_id INTEGER, detail TEXT NOT NULL, created_at TEXT NOT NULL);
                CREATE TABLE login_failures (client TEXT NOT NULL, attempted_at INTEGER NOT NULL);
                CREATE INDEX login_window ON login_failures(client, attempted_at);');
        } else {
            // Missing/damaged tables are an error, never an implicit reset.
            $this->db->query('SELECT id FROM events LIMIT 1');
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS agent_runs (
            id INTEGER PRIMARY KEY, task_id INTEGER NOT NULL REFERENCES tasks(id),
            status TEXT NOT NULL, model TEXT NOT NULL, output TEXT NOT NULL DEFAULT "",
            provider_request_id TEXT, input_tokens INTEGER, output_tokens INTEGER,
            started_at INTEGER NOT NULL, finished_at INTEGER)');
        UsageMeter::migrate($this->db,'agent_runs');
    }

    private function transaction(callable $work): int
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $id = $work();
            $this->db->exec('COMMIT');
            return $id;
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    private function event(string $kind, int $project, ?int $task, string $detail): void
    {
        $this->db->prepare('INSERT INTO events(kind, project_id, task_id, detail, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$kind, $project, $task, $detail, gmdate('c')]);
    }

    private static function text(string $text, int $max): string
    {
        $text = trim($text);
        if ($text === '' || strlen($text) > $max || !mb_check_encoding($text, 'UTF-8')) {
            throw new RuntimeException('Text is empty, too long or invalid.');
        }
        return $text;
    }

    public function createProject(string $name, string $brief): int
    {
        $name = self::text($name, 160);
        $brief = self::text($brief, 4000);
        return $this->transaction(function () use ($name, $brief): int {
            $this->db->prepare('INSERT INTO projects(name, brief, created_at) VALUES (?, ?, ?)')->execute([$name, $brief, gmdate('c')]);
            $id = (int) $this->db->lastInsertId();
            $this->event('project.proposed', $id, null, 'Project proposal recorded by panel user.');
            return $id;
        });
    }

    public function createTask(int $project, string $title, string $criteria): int
    {
        $title = self::text($title, 160);
        $criteria = self::text($criteria, 4000);
        return $this->transaction(function () use ($project, $title, $criteria): int {
            $this->db->prepare('INSERT INTO tasks(project_id, title, criteria, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$project, $title, $criteria, gmdate('c')]);
            $id = (int) $this->db->lastInsertId();
            $this->event('task.proposed', $project, $id, 'Task proposal recorded; no agent dispatched.');
            return $id;
        });
    }

    public function snapshot(): array
    {
        $projects=$this->db->query('SELECT * FROM projects ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $all=$this->db->query('SELECT a.*,t.project_id FROM agent_runs a JOIN tasks t ON t.id=a.task_id')->fetchAll(PDO::FETCH_ASSOC);
        foreach($projects as &$project)$project['usage']=UsageMeter::summary(array_values(array_filter($all,static fn(array $r):bool=>(int)$r['project_id']===(int)$project['id'])));unset($project);
        return [
            'projects' => $projects,
            'tasks' => $this->db->query('SELECT tasks.*, projects.name AS project_name FROM tasks JOIN projects ON projects.id=tasks.project_id ORDER BY tasks.id DESC')->fetchAll(PDO::FETCH_ASSOC),
            'events' => $this->db->query('SELECT * FROM events ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC),
            'runs' => $this->db->query('SELECT * FROM agent_runs ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function startAgent(int $task, string $model): array
    {
        $run = [];
        $this->transaction(function () use ($task, $model, &$run): int {
            $query = $this->db->prepare('SELECT tasks.*, projects.brief FROM tasks JOIN projects ON projects.id=tasks.project_id WHERE tasks.id=?');
            $query->execute([$task]);
            $record = $query->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                throw new RuntimeException('Task not found.');
            }
            $stale = $this->db->prepare('SELECT agent_runs.id,agent_runs.task_id,tasks.project_id FROM agent_runs JOIN tasks ON tasks.id=agent_runs.task_id WHERE agent_runs.status="working" AND agent_runs.started_at<?');
            $stale->execute([time() - 90]);
            foreach ($stale->fetchAll(PDO::FETCH_ASSOC) as $old) {
                $this->db->prepare('UPDATE agent_runs SET status="outcome_uncertain",finished_at=? WHERE id=? AND status="working"')->execute([time(), $old['id']]);
                $this->db->prepare('UPDATE tasks SET status="outcome_uncertain" WHERE id=?')->execute([$old['task_id']]);
                $this->event('agent.outcome_uncertain', (int) $old['project_id'], (int) $old['task_id'], 'Worker stopped reporting. Provider outcome is unverified; no automatic retry.');
            }
            if ((int) $this->db->query('SELECT COUNT(*) FROM agent_runs WHERE status="working"')->fetchColumn() > 0) {
                throw new RuntimeException('An agent run is already active.');
            }
            $limit = $this->db->prepare('SELECT COUNT(*) FROM agent_runs WHERE started_at>?');
            $limit->execute([time() - 3600]);
            if ((int) $limit->fetchColumn() >= 3) {
                throw new RuntimeException('Testing limit reached: three runs per hour.');
            }
            $this->db->prepare('INSERT INTO agent_runs(task_id,status,model,started_at) VALUES (?,"working",?,?)')->execute([$task, $model, time()]);
            $id = (int) $this->db->lastInsertId();
            $this->db->prepare('UPDATE tasks SET status="working" WHERE id=?')->execute([$task]);
            $this->event('agent.started', (int) $record['project_id'], $task, 'Bounded OpenAI task response requested; model ' . $model . '.');
            $run = ['id' => $id, 'task' => $record];
            return $id;
        });
        return $run;
    }

    public function finishAgent(int $id, string $status, string $output = '', ?string $providerId = null, ?int $inputTokens = null, ?int $outputTokens = null,?array $billing=null): void
    {
        if (!in_array($status, ['in_review', 'failed', 'outcome_uncertain'], true)) {
            throw new RuntimeException('Invalid agent outcome.');
        }
        $this->transaction(function () use ($id, $status, $output, $providerId, $inputTokens, $outputTokens,$billing): int {
            $query = $this->db->prepare('SELECT agent_runs.task_id,agent_runs.model,tasks.project_id FROM agent_runs JOIN tasks ON tasks.id=agent_runs.task_id WHERE agent_runs.id=? AND agent_runs.status="working"');
            $query->execute([$id]);
            $record = $query->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                throw new RuntimeException('Agent run is no longer active.');
            }
            $this->db->prepare('UPDATE agent_runs SET status=?,output=?,provider_request_id=?,input_tokens=?,output_tokens=?,finished_at=? WHERE id=? AND status="working"')
                ->execute([$status, substr($output, 0, 16000), $providerId, $inputTokens, $outputTokens, time(), $id]);
            $billing??=UsageMeter::measure(['usage'=>['input_tokens'=>$inputTokens,'output_tokens'=>$outputTokens]],$record['model']);
            $this->db->prepare('UPDATE agent_runs SET model=?,estimated_usd=?,cached_tokens=?,tool_calls=?,billing_json=? WHERE id=?')->execute([$billing['model'],$billing['estimated_usd'],$billing['cached_tokens'],$billing['tool_calls'],json_encode($billing,JSON_THROW_ON_ERROR),$id]);
            $this->db->prepare('UPDATE tasks SET status=? WHERE id=?')->execute([$status, $record['task_id']]);
            $this->event('agent.' . $status, (int) $record['project_id'], (int) $record['task_id'], $status === 'in_review' ? 'AI response recorded for review. No code executed or published. Usage and estimated cost recorded when returned.' : 'Run stopped. No automatic retry; review outcome before another request.');
            return $id;
        });
    }

    public function loginAllowed(string $client): bool
    {
        $query = $this->db->prepare('SELECT COUNT(*) FROM login_failures WHERE client=? AND attempted_at>?');
        $query->execute([$client, time() - 600]);
        return (int) $query->fetchColumn() < 5;
    }

    public function failedLogin(string $client): void
    {
        $this->db->prepare('DELETE FROM login_failures WHERE attempted_at<?')->execute([time() - 600]);
        $this->db->prepare('INSERT INTO login_failures(client, attempted_at) VALUES (?, ?)')->execute([$client, time()]);
    }
}
