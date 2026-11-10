<?php
declare(strict_types=1);

// Test-only transport replaces namespaced curl calls. No external request occurs.
namespace AIcontrol {
    final class TestTransport {
        public static array $options = [];
        public static int $status = 200;
        public static bool $succeeds = true;
        public static string $response = '';
        public static int $calls = 0;
    }
    function curl_init(string $url): object {
        if ($url !== 'https://api.openai.com/v1/responses') { throw new \RuntimeException('Unexpected endpoint'); }
        return new \stdClass();
    }
    function curl_setopt_array(object $handle, array $options): bool { TestTransport::$options = $options; return true; }
    function curl_exec(object $handle): bool {
        TestTransport::$calls++;
        (TestTransport::$options[CURLOPT_WRITEFUNCTION])($handle, TestTransport::$response);
        return TestTransport::$succeeds;
    }
    function curl_getinfo(object $handle, int $option): int { return TestTransport::$status; }
    function curl_close(object $handle): void {}
}

namespace {
    require dirname(__DIR__) . '/src/PanelStore.php';
    require dirname(__DIR__) . '/src/AgentRunner.php';
    umask(0077);
    $path = sys_get_temp_dir() . '/aicontrol-adapter-' . bin2hex(random_bytes(8)) . '.sqlite';
    putenv('AICONTROL_OPENAI_API_KEY=synthetic-test-credential');
    try {
        $store = new \AIcontrol\PanelStore($path, true);
        $project = $store->createProject('Adapter test', 'Synthetic brief');
        $task = $store->createTask($project, 'Return a patch', 'Proposed patch only');
        \AIcontrol\TestTransport::$response = json_encode(['id' => 'resp_test', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Proposed solution']]]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 40]], JSON_THROW_ON_ERROR);
        \AIcontrol\AgentRunner::run($store, $task);
        $run = $store->snapshot()['runs'][0];
        if ($run['status'] !== 'in_review' || $run['output'] !== 'Proposed solution' || $run['provider_request_id'] !== 'resp_test' || $run['output_tokens'] !== 40) {
            throw new \RuntimeException('Response parsing or persistence failed');
        }
        $request = json_decode(\AIcontrol\TestTransport::$options[CURLOPT_POSTFIELDS], true, 32, JSON_THROW_ON_ERROR);
        if ($request['max_output_tokens'] !== 1000 || $request['store'] !== false || !str_contains($request['input'], 'Return a patch')) {
            throw new \RuntimeException('Bounded request shape failed');
        }
        \AIcontrol\TestTransport::$status = 401;
        \AIcontrol\TestTransport::$response = '{"error":"synthetic-test-credential"}';
        \AIcontrol\AgentRunner::run($store, $task);
        $run = $store->snapshot()['runs'][0];
        if ($run['status'] !== 'failed' || str_contains($run['output'], 'synthetic-test-credential')) {
            throw new \RuntimeException('Provider refusal or error redaction failed');
        }
        \AIcontrol\TestTransport::$status = 0;
        \AIcontrol\TestTransport::$succeeds = false;
        \AIcontrol\TestTransport::$response = '';
        \AIcontrol\AgentRunner::run($store, $task);
        if ($store->snapshot()['runs'][0]['status'] !== 'outcome_uncertain' || \AIcontrol\TestTransport::$calls !== 3) {
            throw new \RuntimeException('Timeout handling or retry bound failed');
        }
        echo "PASS: adapter success, request bounds, refusal redaction and uncertain timeout (test transport only)\n";
    } finally {
        putenv('AICONTROL_OPENAI_API_KEY');
        unset($store);
        if (file_exists($path)) { unlink($path); }
    }
}
