<?php
declare(strict_types=1);
define('AICONTROL_LOADER_TEST', true);
require dirname(__DIR__) . '/loader.php';
umask(0077);
$count = 0;
function updateCheck(bool $condition, string $label): void {
    global $count;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    $count++;
}
function updateRefuses(callable $work, string $label): void {
    $refused = false;
    try { $work(); } catch (Throwable) { $refused = true; }
    updateCheck($refused, $label);
}
$temporary = sys_get_temp_dir() . '/aicontrol-update-test-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$loader = $temporary . '/loader.php';
$staged = $temporary . '/staged.php';
$old = "<?php\n// old loader\n";
$new = "<?php\n// checked new loader\n";
file_put_contents($loader, $old);
file_put_contents($staged, $new);
try {
    AIcontrolInstaller::$signer = new AIcontrolSigning($temporary, null, true);
    $key = hash_file('sha256', $temporary . '/signing/identity.json');
    file_put_contents($temporary . '/pin-sentinel', 'keep-current-private-state');
    $baseline = AIcontrolUpdates::baseline($temporary, $loader);
    updateCheck($baseline['loader_sha256'] === hash('sha256', $old), 'initial loader hash pinned');
    $previous = ['sha' => str_repeat('a', 40), 'manifest_hash' => str_repeat('b', 64)];
    AIcontrolInstaller::jsonFile($temporary . '/current.json', $previous);
    file_put_contents($loader, $old . '// unexpected edit');
    updateRefuses(fn() => AIcontrolUpdates::prepareBootstrap($temporary, $loader, $staged, hash('sha256', $new), str_repeat('c', 40)), 'unexpected public loader modification refused');
    updateCheck(str_contains(file_get_contents($loader), 'unexpected edit'), 'unexpected file is not overwritten');
    file_put_contents($loader, $old);
    updateRefuses(fn() => AIcontrolUpdates::prepareBootstrap($temporary, $loader, $staged, str_repeat('f', 64), str_repeat('c', 40)), 'wrong staged loader hash refused');
    $state = AIcontrolUpdates::prepareBootstrap($temporary, $loader, $staged, hash('sha256', $new), str_repeat('c', 40));
    $target = ['sha' => str_repeat('c', 40), 'manifest_hash' => str_repeat('d', 64)];
    AIcontrolInstaller::activate($temporary, $target, static function (): void {}, ['path' => $loader, 'staged' => $staged, 'state' => $state]);
    updateCheck(file_get_contents($loader) === $new, 'bootstrap updated to checked bytes');
    updateCheck(AIcontrolUpdates::baseline($temporary, $loader)['loader_sha256'] === hash('sha256', $new), 'new baseline saved and signed');
    updateCheck(AIcontrolInstaller::readJson($temporary . '/current.json') === $target, 'application activated');
    updateCheck(hash_file('sha256', $temporary . '/signing/identity.json') === $key, 'signing identity preserved');
    updateCheck(file_get_contents($temporary . '/pin-sentinel') === 'keep-current-private-state', 'private state preserved');
    $later = "<?php\n// later loader\n";
    file_put_contents($staged, $later);
    $plan = AIcontrolUpdates::prepareBootstrap($temporary, $loader, $staged, hash('sha256', $later), str_repeat('e', 40));
    updateRefuses(fn() => AIcontrolInstaller::activate($temporary, ['sha' => str_repeat('e', 40)], static function (): void { throw new RuntimeException('failed readiness'); }, ['path' => $loader, 'staged' => $staged, 'state' => $plan]), 'failed application health rejected');
    updateCheck(file_get_contents($loader) === $new && AIcontrolInstaller::readJson($temporary . '/current.json') === $target, 'failed health retains prior app and loader');
    AIcontrolUpdates::atomicLoader($loader, $later, hash('sha256', $later));
    AIcontrolInstaller::jsonFile($temporary . '/journal.json', ['phase' => 'activating', 'previous' => $target, 'target' => ['sha' => str_repeat('e', 40)], 'bootstrap' => $plan]);
    AIcontrolInstaller::jsonFile($temporary . '/current.json', ['sha' => str_repeat('e', 40)]);
    AIcontrolInstaller::recover($temporary, $loader);
    updateCheck(file_get_contents($loader) === $new && AIcontrolInstaller::readJson($temporary . '/current.json') === $target, 'interrupted self-update restores prior app and loader');
    updateCheck(AIcontrolUpdates::baseline($temporary, $loader)['loader_sha256'] === hash('sha256', $new), 'recovery retains verified baseline');
    $diff = AIcontrolUpdates::changedFiles(['old.php' => 'a', 'changed.php' => 'b'], ['changed.php' => 'c', 'new.php' => 'd']);
    updateCheck($diff === ['added' => ['new.php'], 'changed' => ['changed.php'], 'removed' => ['old.php']], 'change summary matches exact file differences');
    updateCheck(AIcontrolUpdates::components(['a' => 'one'], ['a' => 'one'], 'hash', 'hash') === ['app_update' => false, 'loader_update' => false], 'identical deployed bytes need no update');
    updateCheck(AIcontrolUpdates::components(['a' => 'one'], ['a' => 'one'], 'old', 'new') === ['app_update' => false, 'loader_update' => true], 'loader-only update detected separately');
    updateCheck(AIcontrolUpdates::components(['a' => 'one'], ['a' => 'two'], 'hash', 'hash') === ['app_update' => true, 'loader_update' => false], 'workspace-only update detected separately');
    AIcontrolUpdates::save($temporary, 'update-status.json', ['event' => 'update.availability', 'state' => 'up_to_date', 'installed_sha' => $target['sha'], 'running_loader_hash' => hash_file('sha256', $loader), 'checked_at' => time(), 'expires_at' => time() + 900]);
    updateCheck(AIcontrolUpdates::availability($temporary, $loader)['state'] === 'up_to_date', 'signed cached status reused without remote requests');
    $cache = AIcontrolInstaller::readJson($temporary . '/update-status.json');
    $cache['payload'] = base64_encode('{"state":"forged"}');
    AIcontrolInstaller::jsonFile($temporary . '/update-status.json', $cache);
    updateRefuses(fn() => AIcontrolUpdates::availability($temporary, $loader), 'tampered availability cache refused');
    echo "PASS: $count automatic update, self-replacement, rollback and signature checks\n";
} finally {
    AIcontrolInstaller::$signer = null;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($temporary);
}
