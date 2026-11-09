<?php
declare(strict_types=1);

namespace AIcontrol;

use RuntimeException;
use Throwable;

/** One bounded task response. Generated text is never executed as code. */
final class AgentRunner
{
    public static function configured(): bool
    {
        return is_string(getenv('AICONTROL_OPENAI_API_KEY')) && getenv('AICONTROL_OPENAI_API_KEY') !== '';
    }

    public static function run(PanelStore $store, int $task): void
    {
        if (!self::configured()) {
            throw new RuntimeException('OpenAI is not connected. Configure AICONTROL_OPENAI_API_KEY directly on the server.');
        }
        $model = getenv('AICONTROL_OPENAI_MODEL') ?: 'gpt-4.1-mini';
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $model)) {
            throw new RuntimeException('Invalid server model configuration.');
        }
        $run = $store->startAgent($task, $model);
        try {
            $body = json_encode([
                'model' => $model, 'store' => false, 'max_output_tokens' => 1000,
                'instructions' => 'You are a coding assistant. Work on the supplied task. Return a concise proposed solution or patch and validation steps. You have no tools or repository access. Clearly identify missing context. Do not claim code was executed, committed, or deployed.',
                'input' => 'Project brief: ' . $run['task']['brief'] . "\nTask: " . $run['task']['title'] . "\nAcceptance criteria: " . $run['task']['criteria'],
            ], JSON_THROW_ON_ERROR);
            $client = curl_init('https://api.openai.com/v1/responses');
            $response = '';
            curl_setopt_array($client, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . getenv('AICONTROL_OPENAI_API_KEY')],
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 256000) {
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($client);
            $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE);
            curl_close($client);
            if ($ok === false || $status >= 500 || $status === 408) {
                $store->finishAgent($run['id'], 'outcome_uncertain', 'Provider outcome is uncertain after a transport error or server timeout. No automatic retry.');
                return;
            }
            if ($status !== 200) {
                $store->finishAgent($run['id'], 'failed', 'OpenAI returned HTTP ' . $status . '. Check the server-side provider connection and selected model.');
                return;
            }
            $result = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
            $output = [];
            foreach ($result['output'] ?? [] as $item) {
                foreach ($item['content'] ?? [] as $content) {
                    if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                        $output[] = $content['text'];
                    }
                }
            }
            $text = implode("\n", $output);
            if ($text === '') {
                $store->finishAgent($run['id'], 'failed');
                return;
            }
            $providerId = is_string($result['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $result['id']) ? $result['id'] : null;
            $input = $result['usage']['input_tokens'] ?? null;
            $outputCount = $result['usage']['output_tokens'] ?? null;
            $store->finishAgent($run['id'], 'in_review', $text, $providerId, is_int($input) ? $input : null, is_int($outputCount) ? $outputCount : null);
        } catch (Throwable) {
            // A response-parsing or recording failure does not prove no provider work occurred.
            $store->finishAgent($run['id'], 'outcome_uncertain');
        }
    }
}
