<?php
declare(strict_types=1);

namespace AIcontrol;

/** Saved research contributions and their presentation metadata. */
final class ResearchIdeas
{
    public const TYPES = ['dream_claim', 'question', 'model_idea', 'association', 'counter', 'edge_case', 'reframing'];
    private const ROLES = ['hypothesis', 'mechanism', 'intervention', 'measurement', 'counterexample', 'boundary_condition', 'alternative_explanation', 'connection'];
    private const FACETS = ['topics', 'mechanisms', 'outcomes', 'conditions'];

    public static function data(array $node): array
    {
        $data = json_decode($node['data_json'] ?? '{}', true);
        return is_array($data) ? $data : [];
    }

    public static function classificationSchema(): array
    {
        $properties = ['role' => ['type' => 'string', 'enum' => self::ROLES]];
        foreach (self::FACETS as $facet) {
            $properties[$facet] = ['type' => 'array', 'maxItems' => 3,
                'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64]];
        }
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public static function classificationInstructions(): string
    {
        return 'Classify each idea by its contribution role, not confidence or importance. Use up to three specific, reusable noun phrases per facet: topics are the subject, mechanisms are how an effect could happen, outcomes are what may change, conditions are where the idea holds or fails. Leave irrelevant facets empty. Prefer consistent technical names for the same concept within this project, while preserving meaningful distinctions. Never tag every word or turn a suggested question into an idea.';
    }

    public static function classification(array $node): array
    {
        $data = self::data($node);
        return self::normalizeClassification($data['classification'] ?? null, $node['type'] ?? '');
    }

    public static function normalizeClassification(mixed $raw, string $type): array
    {
        $raw = is_array($raw) ? $raw : [];
        $default = match ($type) {
            'counter' => 'counterexample',
            'edge_case' => 'boundary_condition',
            'association' => 'connection',
            'reframing' => 'alternative_explanation',
            'question' => 'measurement',
            default => 'hypothesis',
        };
        $result = ['role' => in_array($raw['role'] ?? null, self::ROLES, true) ? $raw['role'] : $default];
        foreach (self::FACETS as $facet) {
            $labels = [];
            foreach (is_array($raw[$facet] ?? null) ? $raw[$facet] : [] as $label) {
                if (!is_string($label)) continue;
                $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '', " \t\n\r\0\x0B,.;:");
                if ($label === '' || mb_strlen($label, 'UTF-8') > 64 || !preg_match('/[\p{L}\p{N}]/u', $label)) continue;
                $key = mb_convert_case($label, MB_CASE_FOLD, 'UTF-8');
                if (!isset($labels[$key])) $labels[$key] = $label;
                if (count($labels) === 3) break;
            }
            $result[$facet] = array_values($labels);
        }
        return $result;
    }

    /** The embedding input is the contribution itself. Auxiliary research records stay in SQLite. */
    public static function document(array $node): ?string
    {
        if (($node['status'] ?? '') !== 'complete' || !in_array($node['type'] ?? '', self::TYPES, true)) return null;
        $data = self::data($node);
        if (isset($data['grounding_node_id'])) return null;
        $title = self::statement($node['title'] ?? '');
        $idea = self::statement($data['idea'] ?? $data['claim'] ?? '');
        if ($node['type'] === 'question' && $idea === '') return null;
        if ($idea === '') {
            $content = preg_split('/\R(?:Possible test|Uncertainty|Confidence|Verify|Priority):/iu', $node['content'] ?? '', 2)[0] ?? '';
            $content = preg_replace('/^(?:Provisional model idea — unchecked\.|Unchecked prior\. Claim:)\s*/iu', '', $content) ?? '';
            $idea = self::statement($content);
        }
        $parts = array_values(array_unique(array_filter([$title, $idea])));
        if (!$parts) return null;
        return mb_strcut(implode("\n", $parts), 0, 1600, 'UTF-8');
    }

    private static function statement(mixed $text): string
    {
        if (!is_string($text)) return '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return preg_match('/[?？]$/u', $text) ? '' : $text;
    }

    private static function question(mixed $text): ?string
    {
        if (!is_string($text)) return null;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return mb_strlen($text, 'UTF-8') <= 700 && preg_match('/[?？]$/u', $text) ? $text : null;
    }

    private static function short(string $text, int $length = 150): string
    {
        return mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $text) ?? '', " \t\n\r\0\x0B.!?"), 0, $length, '…', 'UTF-8');
    }

    public static function conceptParent(array $node, array $byId): ?int
    {
        $seen = [(int)$node['id'] => true];
        $parent = (int)($node['parent_id'] ?? 0);
        while (isset($byId[$parent]) && !isset($seen[$parent])) {
            $seen[$parent] = true;
            $ancestor = $byId[$parent];
            if (in_array($ancestor['type'], self::TYPES, true)) return $parent;
            $parent = (int)($ancestor['parent_id'] ?? 0);
        }
        return null;
    }

    /**
     * Choose a current suggestion from the idea's own branch. New descendants or
     * source cards supersede its older suggestion without rewriting saved ideas.
     */
    public static function suggestions(array $nodes, array $sources): array
    {
        $byId = array_column($nodes, null, 'id');
        $parents = $possible = $order = [];
        foreach ($nodes as $node) {
            if (!in_array($node['type'], self::TYPES, true)) continue;
            $id = (int)$node['id'];
            $parents[$id] = self::conceptParent($node, $byId);
        }
        $save = static function (int $branch, int $at, int $record, string $text, string $basis) use (&$possible, &$order): void {
            if (isset($order[$branch]) && $order[$branch] >= [$at, $record]) return;
            $order[$branch] = [$at, $record];
            $possible[$branch] = ['text' => $text, 'basis' => self::short($basis), 'node_id' => $record];
        };
        foreach ($parents as $id => $parent) {
            $node = $byId[$id];
            $data = self::data($node);
            $title = self::short($node['title']);
            $uncertainty = is_string($data['uncertainty'] ?? null) ? self::short($data['uncertainty']) : '';
            $test = $data['test'] ?? $data['verify'] ?? '';
            $own = self::question($data['next_question'] ?? null)
                ?? ($uncertainty !== '' ? 'What would resolve this uncertainty: '.$uncertainty.'?'
                    : 'What observation would change your mind about “'.$title.'”?');
            $basis = is_string($test) && trim($test) !== '' && !self::question($data['next_question'] ?? null) ? $test : $node['title'];
            $at = (int)($node['updated_at'] ?? $node['created_at'] ?? 0);
            $save($id, $at, $id, $own, $basis);
            if ($node['status'] !== 'complete' || isset($data['grounding_node_id']) || ($node['type'] === 'question' && self::document($node) === null)) continue;
            $followUp = self::question($data['parent_question'] ?? null) ?? $own;
            $seen = [$id => true];
            while ($parent !== null && !isset($seen[$parent])) {
                $seen[$parent] = true;
                $save($parent, $at, $id, $followUp, $node['title']);
                $parent = $parents[$parent] ?? null;
            }
        }
        foreach ($sources as $source) {
            $location = $byId[(int)$source['node_id']] ?? null;
            if (!$location) continue;
            $parent = isset($possible[$location['id']]) ? (int)$location['id'] : self::conceptParent($location, $byId);
            $seen = [];
            while ($parent !== null && !isset($seen[$parent])) {
                $seen[$parent] = true;
                $save($parent, (int)$source['retrieved_at'], (int)$location['id'],
                    'How would the material in “'.self::short($source['title']).'” help distinguish the explanations on this branch?', $source['title']);
                $parent = $parents[$parent] ?? null;
            }
        }
        return $possible;
    }
}
