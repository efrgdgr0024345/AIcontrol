<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/PanelStore.php';
require dirname(__DIR__) . '/src/AgentRunner.php';
umask(0077);
$file = sys_get_temp_dir() . '/aicontrol-agent-' . bin2hex(random_bytes(8)) . '.sqlite';
$count = 0;
function agentCheck(bool $condition, string $label): void {
    global $count;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    $count++;
}
try {
    $store = new \AIcontrol\PanelStore($file, true);
    $project = $store->createProject('Agent test', 'Synthetic brief.');
    $task = $store->createTask($project, 'Task', 'Proposed patch and validation steps.');
    putenv('AICONTROL_OPENAI_API_KEY');
    try { \AIcontrol\AgentRunner::run($store, $task); agentCheck(false, 'missing connection rejected'); }
    catch (RuntimeException) { agentCheck(count($store->snapshot()['runs']) === 0, 'no run or provider work with missing connection'); }
    $first = $store->startAgent($task, 'test-model');
    agentCheck($store->snapshot()['tasks'][0]['status'] === 'working', 'active run changes task state');
    try { $store->startAgent($task, 'test-model'); agentCheck(false, 'concurrent run rejected'); }
    catch (RuntimeException) { agentCheck(count($store->snapshot()['runs']) === 1, 'only one active run'); }
    $store->finishAgent($first['id'], 'in_review', '<script>untrusted output</script>', 'resp_test', 12, 30);
    $snapshot = $store->snapshot();
    agentCheck($snapshot['tasks'][0]['status'] === 'in_review', 'AI text requires review, not completion');
    agentCheck($snapshot['runs'][0]['input_tokens'] === 12, 'measured usage retained');
    try { $store->finishAgent($first['id'], 'failed'); agentCheck(false, 'late outcome rejected'); }
    catch (RuntimeException) { agentCheck($store->snapshot()['runs'][0]['status'] === 'in_review', 'late result cannot overwrite finished run'); }
    $second = $store->startAgent($task, 'test-model');
    $store->finishAgent($second['id'], 'outcome_uncertain');
    agentCheck($store->snapshot()['runs'][0]['status'] === 'outcome_uncertain', 'uncertain outcome preserved');
    $third = $store->startAgent($task, 'test-model');
    $store->finishAgent($third['id'], 'failed');
    try { $store->startAgent($task, 'test-model'); agentCheck(false, 'hourly bound rejected'); }
    catch (RuntimeException) { agentCheck(count($store->snapshot()['runs']) === 3, 'three-run testing limit enforced'); }
    echo "PASS: $count agent lifecycle checks; no provider calls made\n";
} finally {
    unset($store);
    if (file_exists($file)) { unlink($file); }
}
