<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/HostingRequirements.php';
require_once dirname(__DIR__) . '/src/Bootstrap.php';
require_once dirname(__DIR__) . '/src/PrivateStorage.php';
require_once dirname(__DIR__) . '/src/PanelStore.php';

use AIcontrol\Bootstrap;
use AIcontrol\HostingRequirements;

$count = 0;
function check(bool $condition, string $message): void
{
    global $count;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    $count++;
}
foreach ([80200, 80300, 80400, 80500] as $version) {
    check(HostingRequirements::failures($version, HostingRequirements::EXTENSIONS) === [], "supported PHP $version");
}
foreach ([80199, 80600] as $version) {
    check(in_array('unsupported_php_version', HostingRequirements::failures($version, HostingRequirements::EXTENSIONS), true), "reject PHP $version");
}
foreach (HostingRequirements::EXTENSIONS as $extension) {
    $loaded = array_values(array_diff(HostingRequirements::EXTENSIONS, [$extension]));
    check(HostingRequirements::failures(80400, $loaded) === ['missing_extension:' . $extension], "require $extension");
}
foreach (['/health', '/', '/loader.php'] as $path) {
    [$status, $headers, $body] = Bootstrap::response('GET', $path, []);
    check($status === 503, "$path never claims readiness");
    check($headers['Cache-Control'] === 'no-store', "$path disables cache");
    check(str_contains($headers['Content-Security-Policy'], "frame-ancestors 'none'"), "$path rejects framing");
}
[$status, , $body] = Bootstrap::response('GET', '/health', ['missing_extension:secret-sentinel']);
check(json_decode($body, true)['reason'] === 'hosting_requirements_not_met', 'health fails on missing requirements');
check(!str_contains($body, 'secret-sentinel'), 'public health hides host details');
foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
    check(Bootstrap::response($method, '/loader.php', [])[0] === 405, "reject $method");
}
foreach (['/vault', '/setup', '/approve', '/install', '/unknown'] as $path) {
    check(Bootstrap::response('GET', $path, [])[0] === 404, "no privileged route $path");
}
$temporary = sys_get_temp_dir() . '/aicontrol-test-' . bin2hex(random_bytes(12));
umask(0077);
mkdir($temporary, 0700);
try {
    $store = new \AIcontrol\PanelStore($temporary . '/panel.sqlite', true);
    $project = $store->createProject('Example <script>', 'A useful project brief.');
    $task = $store->createTask($project, 'First task', 'Existing test passes.');
    $snapshot = $store->snapshot();
    check(count($snapshot['projects']) === 1, 'project persists');
    check(count($snapshot['tasks']) === 1 && $snapshot['tasks'][0]['status'] === 'draft', 'task is only a draft');
    check(count($snapshot['events']) === 2, 'each proposal appends an event');
    check((int) $snapshot['events'][0]['task_id'] === $task, 'history links to task');
    try {
        $store->createTask(99999, 'Bad task', 'Must not persist.');
        check(false, 'invalid foreign key rejected');
    } catch (\PDOException) {
        check(count($store->snapshot()['events']) === 2, 'failed transaction leaves no event');
    }
    foreach (['', str_repeat('a', 161)] as $name) {
        try {
            $store->createProject($name, 'Brief');
            check(false, 'invalid project rejected');
        } catch (\RuntimeException) {
            check(count($store->snapshot()['projects']) === 1, 'invalid project not stored');
        }
    }
    $reopened = new \AIcontrol\PanelStore($temporary . '/panel.sqlite');
    check(count($reopened->snapshot()['tasks']) === 1, 'records survive reopening');
    for ($attempt = 0; $attempt < 5; $attempt++) {
        check($store->loginAllowed('client'), 'initial login attempts allowed');
        $store->failedLogin('client');
    }
    check(!$store->loginAllowed('client'), 'login throttled after five failures');
    check($store->loginAllowed('other'), 'throttle scoped to client');
    check(\AIcontrol\PrivateStorage::validate($temporary, [dirname(__DIR__)]) === $temporary, 'private location allowed');
    try {
        \AIcontrol\PrivateStorage::validate($temporary, [dirname($temporary)]);
        check(false, 'public root rejected');
    } catch (\RuntimeException) {
        check(true, 'public root rejected');
    }
    chmod($temporary, 0755);
    try {
        \AIcontrol\PrivateStorage::validate($temporary, []);
        check(false, 'unsafe permissions rejected');
    } catch (\RuntimeException) {
        check(true, 'unsafe permissions rejected');
    }
    chmod($temporary, 0700);
} finally {
    unset($store, $reopened);
    foreach (glob($temporary . '/*') as $file) {
        unlink($file);
    }
    rmdir($temporary);
}
echo "PASS: $count checks\n";
