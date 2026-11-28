<?php
declare(strict_types=1);

namespace AIcontrol;

use PDO;
use RuntimeException;

/** Private SQLite vector storage with exact cosine nearest neighbours within a run. */
final class IdeaVectorIndex
{
    public const MODEL = 'text-embedding-3-small';
    public const DIMENSIONS = 256;
    public const BATCH = 24;
    private const VERSION = 'idea-v1';
    private const NEIGHBOURS = 4;
    private const LINK_MINIMUM = 0.56;
    private const OUTLIER_MAXIMUM = 0.50;

    public function __construct(private PDO $db)
    {
    }

    public static function migrate(PDO $db): void
    {
        UsageMeter::columns($db, 'research_projects', ['semantic_index_enabled' => 'INTEGER NOT NULL DEFAULT 0']);
        $db->exec('CREATE TABLE IF NOT EXISTS research_idea_vectors (
            node_id INTEGER PRIMARY KEY REFERENCES research_nodes(id) ON DELETE CASCADE,
            run_id INTEGER NOT NULL REFERENCES research_runs(id) ON DELETE CASCADE,
            fingerprint TEXT NOT NULL, embedding BLOB NOT NULL, indexed_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS research_idea_vectors_run ON research_idea_vectors(run_id);
        CREATE TABLE IF NOT EXISTS research_idea_neighbours (
            run_id INTEGER PRIMARY KEY REFERENCES research_runs(id) ON DELETE CASCADE,
            neighbours_json TEXT NOT NULL
        );');
    }

    private function rows(string $sql, array $values): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function documents(array $nodes): array
    {
        $documents = [];
        foreach ($nodes as $node) {
            $text = ResearchIdeas::document($node);
            if ($text === null) continue;
            $documents[(int)$node['id']] = [
                'text' => $text,
                'fingerprint' => hash('sha256', self::VERSION."\n".self::MODEL."\n".self::DIMENSIONS."\n".$text),
            ];
        }
        return $documents;
    }

    private function fingerprints(int $run): array
    {
        return array_column($this->rows('SELECT node_id,fingerprint FROM research_idea_vectors WHERE run_id=?', [$run]), 'fingerprint', 'node_id');
    }

    public function missing(int $run, array $documents, int $after = 0): array
    {
        $indexed = $this->fingerprints($run);
        return array_slice(array_filter($documents, static fn(array $document, int $id): bool =>
            $id > $after && ($indexed[$id] ?? null) !== $document['fingerprint'], ARRAY_FILTER_USE_BOTH), 0, self::BATCH, true);
    }

    private function savedNeighbours(int $run): array
    {
        $row = $this->rows('SELECT neighbours_json FROM research_idea_neighbours WHERE run_id=?', [$run])[0] ?? [];
        return json_decode($row['neighbours_json'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
    }

    /**
     * Atomically called with the usage record. Existing neighbours are exact:
     * a new vector is compared with all vectors, and invalidated lists are rebuilt.
     */
    public function replace(int $run, array $documents, array $batch, array $vectors): void
    {
        $write = $this->db->prepare('INSERT INTO research_idea_vectors(node_id,run_id,fingerprint,embedding,indexed_at)
            VALUES(?,?,?,?,?) ON CONFLICT(node_id) DO UPDATE SET fingerprint=excluded.fingerprint,embedding=excluded.embedding,indexed_at=excluded.indexed_at');
        foreach (array_keys($batch) as $offset => $id) {
            $write->bindValue(1, $id, PDO::PARAM_INT);
            $write->bindValue(2, $run, PDO::PARAM_INT);
            $write->bindValue(3, $batch[$id]['fingerprint']);
            $write->bindValue(4, $vectors[$offset], PDO::PARAM_LOB);
            $write->bindValue(5, time(), PDO::PARAM_INT);
            $write->execute();
        }
        $catalog = [];
        foreach ($this->rows('SELECT node_id,fingerprint,embedding FROM research_idea_vectors WHERE run_id=?', [$run]) as $row) {
            $doc = $documents[$row['node_id']] ?? null;
            if (!$doc || $doc['fingerprint'] !== $row['fingerprint'] || strlen($row['embedding']) !== self::DIMENSIONS * 4) continue;
            $catalog[(int)$row['node_id']] = unpack('g*', $row['embedding']);
        }
        $changed = array_intersect_key($catalog, $batch);
        $nearest = self::nearest($catalog, $changed, $this->savedNeighbours($run));
        $this->db->prepare('INSERT INTO research_idea_neighbours(run_id,neighbours_json) VALUES(?,?)
            ON CONFLICT(run_id) DO UPDATE SET neighbours_json=excluded.neighbours_json')->execute([$run, json_encode($nearest, JSON_THROW_ON_ERROR)]);
    }

    private static function nearest(array $catalog, array $changed, array $previous): array
    {
        $nearest = [];
        foreach ($catalog as $id => $vector) {
            $old = $previous[$id] ?? [];
            $valid = array_filter($old, static fn(array $pair): bool => isset($catalog[$pair[0]]) && !isset($changed[$pair[0]]));
            $rebuild = isset($changed[$id]) || !isset($previous[$id]) || count($old) !== count($valid);
            $best = $rebuild ? [] : $valid;
            foreach ($rebuild ? $catalog : $changed as $otherId => $other) {
                if ($otherId === $id) continue;
                $similarity = 0.0;
                foreach ($vector as $i => $value) $similarity += $value * $other[$i];
                $score = round(max(-1.0, min(1.0, $similarity)), 5);
                if (count($best) >= self::NEIGHBOURS) {
                    [$worstId, $worstScore] = $best[array_key_last($best)];
                    if ($score < $worstScore || ($score === $worstScore && $otherId > $worstId)) continue;
                    array_pop($best);
                }
                $best[] = [$otherId, $score];
                usort($best, static fn(array $a, array $b): int => $b[1] <=> $a[1] ?: $a[0] <=> $b[0]);
            }
            $nearest[$id] = $best;
        }
        return $nearest;
    }

    public function snapshot(array $run, array $nodes, array $requests): array
    {
        $documents = self::documents($nodes);
        $enabled = (bool)$run['semantic_index_enabled'];
        $semantic = ['enabled' => $enabled, 'eligible' => count($documents), 'indexed' => 0, 'status' => 'idle', 'edges' => [], 'outliers' => []];
        if (!$enabled) return $semantic;
        $fingerprints = $this->fingerprints((int)$run['id']);
        $valid = array_filter($documents, static fn(array $doc, int $id): bool =>
            ($fingerprints[$id] ?? null) === $doc['fingerprint'], ARRAY_FILTER_USE_BOTH);
        $semantic['indexed'] = count($valid);
        foreach (array_reverse($requests) as $request) {
            if ($request['kind'] !== 'idea_index') continue;
            $semantic['status'] = $request['status'];
            break;
        }
        if (count($valid) < 2) return $semantic;
        $saved = $this->savedNeighbours((int)$run['id']);
        $nearest = array_intersect_key($saved, $valid);
        $candidates = [];
        $closest = array_filter(array_map(static fn(array $pairs): ?float => $pairs[0][1] ?? null, $nearest),
            static fn(?float $score): bool => $score !== null);
        $distribution = array_values($closest);
        sort($distribution, SORT_NUMERIC);
        // A distant idea needs both a weak nearest match and separation from the map's normal spread.
        if (count($valid) >= 5 && count($valid) === count($documents) && count($valid) === count($saved) && count($valid) === count($closest)) {
            $cutoff = min(self::OUTLIER_MAXIMUM, $distribution[intdiv(count($distribution), 2)] - 0.09);
            $semantic['outliers'] = array_keys(array_filter($closest, static fn(float $score): bool => $score < $cutoff));
        }
        foreach ($nearest as $id => $pairs) {
            foreach (array_slice($pairs, 0, 3) as [$other, $score]) {
                if (!isset($valid[$other]) || $score < self::LINK_MINIMUM) continue;
                $reciprocal = in_array($id, array_column(array_slice($nearest[$other] ?? [], 0, 3), 0), true);
                if (!$reciprocal && $score < 0.69) continue;
                $from = min($id, $other);
                $to = max($id, $other);
                $candidates[$from.':'.$to] = ['from' => $from, 'to' => $to, 'score' => $score];
            }
        }
        usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['from'] <=> $b['from'] ?: $a['to'] <=> $b['to']);
        $degrees = [];
        foreach ($candidates as $edge) {
            $from = $edge['from']; $to = $edge['to'];
            if (($degrees[$from] ?? 0) >= self::NEIGHBOURS || ($degrees[$to] ?? 0) >= self::NEIGHBOURS) continue;
            $semantic['edges'][] = $edge;
            $degrees[$from] = ($degrees[$from] ?? 0) + 1;
            $degrees[$to] = ($degrees[$to] ?? 0) + 1;
        }
        return $semantic;
    }

    public static function embed(array $body): array
    {
        $response = '';
        $client = curl_init('https://api.openai.com/v1/embeddings');
        try {
            curl_setopt_array($client, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer '.AgentRunner::apiKey()],
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 384000) return 0;
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($client);
            $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($client);
        }
        Diagnostics::record('research.idea_index.provider', ['http_status' => $status, 'transport_ok' => $ok !== false, 'ideas' => count($body['input'])]);
        if ($ok === false || $status === 0 || $status === 408 || $status >= 500) return ['status' => 'uncertain'];
        if ($status !== 200) return ['status' => 'failed', 'input' => 0, 'output' => 0];
        $result = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        if (($result['model'] ?? null) !== self::MODEL) throw new RuntimeException('Unexpected embedding model.');
        $values = $result['data'] ?? [];
        if (!is_array($values) || count($values) !== count($body['input'])) throw new RuntimeException('Incomplete embedding batch.');
        $vectors = [];
        foreach ($values as $value) {
            $index = $value['index'] ?? null;
            $coordinates = $value['embedding'] ?? null;
            if (!is_int($index) || !isset($body['input'][$index]) || isset($vectors[$index])
                || !is_array($coordinates) || !array_is_list($coordinates) || count($coordinates) !== self::DIMENSIONS) {
                throw new RuntimeException('Invalid embedding shape.');
            }
            $length = 0.0;
            foreach ($coordinates as $coordinate) {
                if ((!is_float($coordinate) && !is_int($coordinate)) || !is_finite($coordinate)) throw new RuntimeException('Invalid embedding value.');
                $length += $coordinate * $coordinate;
            }
            if ($length <= 0.0 || !is_finite($length)) throw new RuntimeException('Invalid embedding length.');
            $norm = sqrt($length);
            $vectors[$index] = pack('g*', ...array_map(static fn(float $item): float => $item / $norm, $coordinates));
        }
        ksort($vectors);
        $tokens = $result['usage']['prompt_tokens'] ?? null;
        return ['status' => 'complete', 'vectors' => $vectors, 'input' => is_int($tokens) && $tokens >= 0 ? $tokens : null, 'output' => 0];
    }
}
