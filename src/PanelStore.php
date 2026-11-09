<?php
declare(strict_types=1);

namespace AIcontrol;

use PDO;
use RuntimeException;
use Throwable;

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
        return [
            'projects' => $this->db->query('SELECT * FROM projects ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC),
            'tasks' => $this->db->query('SELECT tasks.*, projects.name AS project_name FROM tasks JOIN projects ON projects.id=tasks.project_id ORDER BY tasks.id DESC')->fetchAll(PDO::FETCH_ASSOC),
            'events' => $this->db->query('SELECT * FROM events ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC),
        ];
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
