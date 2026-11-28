<?php
declare(strict_types=1);

namespace AIcontrol;

use RuntimeException;
use Throwable;

/** Admissions and accounting for one bounded batch of idea embeddings. */
trait SemanticResearch
{
    public function indexIdeas(int $id): void
    {
        ProviderGate::run($this->directory, fn() => $this->indexIdeaBatch($id, true));
    }

    public function pauseIdeaIndex(int $id): void
    {
        $this->transaction(function () use ($id): void {
            $run = $this->run($id);
            $this->execute('UPDATE research_projects SET semantic_index_enabled=0 WHERE id=?', [$run['project_id']]);
        });
    }

    private function indexNewIdeas(int $id, array $job): void
    {
        if (!$job['run']['semantic_index_enabled'] || !in_array($job['phase'], ['dream', 'model_explore'], true)) return;
        try {
            $this->indexIdeaBatch($id, false, (int)$job['node'], (int)$job['request']);
        } catch (Throwable $error) {
            // The ideas are already committed. Indexing cannot roll back research.
            Diagnostics::record('research.idea_index.deferred', ['run_id' => $id], $error);
        }
    }

    private function indexIdeaBatch(int $id, bool $explicit, int $after = 0, int $growthRequest = 0): void
    {
        $index = new IdeaVectorIndex($this->db);
        $work = $this->transaction(function () use ($id, $explicit, $after, $growthRequest, $index): ?array {
            $run = $this->run($id);
            if (!$explicit) {
                if (!$run['semantic_index_enabled'] || $run['status'] !== 'running'
                    || !$this->rows("SELECT id FROM research_requests WHERE id=? AND run_id=? AND status='complete'", [$growthRequest, $id])) return null;
                $latest = $this->rows("SELECT status FROM research_requests WHERE run_id=? AND kind='idea_index' ORDER BY id DESC LIMIT 1", [$id])[0]['status'] ?? null;
                if (in_array($latest, ['failed', 'uncertain'], true)) return null;
            }
            if ($run['busy'] !== null
                || $this->rows("SELECT id FROM research_requests WHERE status='pending' LIMIT 1")
                || $this->rows("SELECT id FROM research_messages WHERE role='assistant' AND status='pending' LIMIT 1")) {
                throw new RuntimeException('Wait for the current research request before indexing ideas.');
            }
            $nodes = $this->rows('SELECT * FROM research_nodes WHERE run_id=? ORDER BY id', [$id]);
            $documents = IdeaVectorIndex::documents($nodes);
            $batch = $index->missing($id, $documents, $after);
            if ($explicit) $this->execute('UPDATE research_projects SET semantic_index_enabled=1 WHERE id=?', [$run['project_id']]);
            if (!$batch) return null;
            if (AgentRunner::apiKey() === '') throw new RuntimeException('Connect OpenAI in Admin first.');
            $request = $this->request($id, (int)array_key_first($batch), 'idea_index');
            $body = ['model' => IdeaVectorIndex::MODEL, 'dimensions' => IdeaVectorIndex::DIMENSIONS,
                'input' => array_column($batch, 'text'), 'encoding_format' => 'float'];
            UsageMeter::reserveProject($this->db, (int)$run['project_id'], 'research_requests', $request, $body);
            $this->execute('UPDATE research_requests SET worker_role=?,activity=? WHERE id=?', ['Idea embeddings', 'Indexing saved ideas', $request]);
            return compact('request', 'batch', 'body');
        });
        if ($work === null) return;

        try {
            $result = IdeaVectorIndex::embed($work['body']);
        } catch (Throwable $error) {
            Diagnostics::record('research.idea_index.failed', ['run_id' => $id], $error);
            $result = ['status' => 'uncertain'];
        }
        if (isset($result['input'], $result['output'])) {
            $result['billing'] = UsageMeter::measure(['usage' => ['input_tokens' => $result['input'],
                'output_tokens' => $result['output']]], IdeaVectorIndex::MODEL);
            $result['billing']['basis'] = 'Embedding input tokens × saved standard rate';
        }
        try {
            $this->transaction(function () use ($id, $index, $work, $result): void {
                $pending = $this->rows("SELECT id FROM research_requests WHERE id=? AND status='pending'", [$work['request']]);
                $status = $pending ? $result['status'] : 'uncertain';
                if ($status === 'complete' && $this->run($id)['semantic_index_enabled']) {
                    $documents = IdeaVectorIndex::documents($this->rows('SELECT * FROM research_nodes WHERE run_id=? ORDER BY id', [$id]));
                    $index->replace($id, $documents, $work['batch'], $result['vectors']);
                }
                $this->usage($id, $work['request'], $result, $status);
            });
        } catch (Throwable $error) {
            Diagnostics::record('research.idea_index.save_failed', ['run_id' => $id], $error);
            $this->transaction(fn() => $this->usage($id, $work['request'], $result, 'failed'));
        }
    }
}
