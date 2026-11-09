<?php
declare(strict_types=1);

define('AICONTROL_LOADER_TEST', true);
require dirname(__DIR__) . '/loader.php';

$count = 0;
function expect(bool $condition, string $label): void
{
    global $count;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $count++;
}
function refuses(callable $work, string $label): void
{
    try {
        $work();
    } catch (Throwable) {
        expect(true, $label);
        return;
    }
    expect(false, $label);
}
$temporary = sys_get_temp_dir() . '/aicontrol-loader-test-' . bin2hex(random_bytes(8));
umask(0077);
mkdir($temporary, 0700);
$sha = str_repeat('a', 40);
$raw = file_get_contents(dirname(__DIR__) . '/release-manifest.json');
$selection = ['sha' => $sha, 'manifest_hash' => hash('sha256', $raw), 'checked_at' => time()];
try {
    expect(AIcontrolInstaller::sha($sha) === $sha, 'full commit accepted');
    refuses(fn() => AIcontrolInstaller::sha('main'), 'branch is not a selected commit');
    refuses(fn() => AIcontrolInstaller::selection($selection, str_repeat('b', 40)), 'changed commit refused');
    refuses(fn() => AIcontrolInstaller::selection(array_replace($selection, ['checked_at' => time() - 600]), $sha), 'ten-minute expiry');
    refuses(fn() => AIcontrolInstaller::selection(array_replace($selection, ['checked_at' => time() + 10]), $sha), 'future selection refused');
    refuses(fn() => AIcontrolInstaller::manifest('{"version":1,"files":{"../escape":"x"}}'), 'unsafe manifest refused');
    refuses(fn() => AIcontrolInstaller::home($temporary, [dirname($temporary)]), 'public private-path refused');
    expect(AIcontrolInstaller::home($temporary, [dirname(__DIR__)]) === $temporary, 'private directory accepted');
    foreach (['normal', 'legacy', 'traversal', 'symlink', 'checksum', 'root'] as $scenario) {
        $zipPath = $temporary . '/' . $scenario . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $folder = $scenario === 'legacy' ? 'efrgdgr0024345-AIcontrol-' . substr($sha, 0, 7) : 'AIcontrol-' . ($scenario === 'root' ? str_repeat('b', 40) : $sha);
        $prefix = $folder . '/';
        $zip->addEmptyDir($folder);
        $zip->addFromString($prefix . 'release-manifest.json', $raw);
        foreach (AIcontrolInstaller::manifest($raw) as $path => $hash) {
            $data = file_get_contents(dirname(__DIR__) . '/' . $path);
            $zip->addFromString($prefix . $path, $scenario === 'checksum' && $path === 'app.php' ? $data . 'changed' : $data);
        }
        if ($scenario === 'traversal') {
            $zip->addFromString($prefix . '../outside', 'forbidden');
        }
        if ($scenario === 'symlink') {
            $zip->addFromString($prefix . 'link', '/etc/passwd');
            $zip->setExternalAttributesName($prefix . 'link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
        $zip->close();
        $stage = $temporary . '/stage-' . bin2hex(random_bytes(8));
        if (in_array($scenario, ['normal', 'legacy'], true)) {
            AIcontrolInstaller::stage($zipPath, $stage, $selection);
            AIcontrolInstaller::verify($stage, $selection['manifest_hash']);
            expect(is_file($stage . '/app.php'), $scenario . ' extracted');
            file_put_contents($stage . '/unmanaged.txt', 'local edit');
            refuses(fn() => AIcontrolInstaller::verify($stage, $selection['manifest_hash']), 'unexpected local file refused');
            unlink($stage . '/unmanaged.txt');
            file_put_contents($stage . '/app.php', 'modified');
            refuses(fn() => AIcontrolInstaller::verify($stage, $selection['manifest_hash']), 'modified release refused');
        } else {
            refuses(fn() => AIcontrolInstaller::stage($zipPath, $stage, $selection), $scenario . ' refused');
        }
        AIcontrolInstaller::removeStage($stage);
        expect(!is_dir($stage), 'staging cleanup');
    }
    file_put_contents($temporary . '/project-data', 'retain');
    $old = ['sha' => str_repeat('b', 40), 'manifest_hash' => str_repeat('c', 64)];
    AIcontrolInstaller::jsonFile($temporary . '/current.json', $old);
    refuses(fn() => AIcontrolInstaller::activate($temporary, $selection, static function (): void { throw new RuntimeException('bad health'); }), 'failed health refused');
    expect(AIcontrolInstaller::readJson($temporary . '/current.json') === $old, 'failed health restores prior code');
    expect(file_get_contents($temporary . '/project-data') === 'retain', 'rollback preserves data');
    AIcontrolInstaller::jsonFile($temporary . '/journal.json', ['phase' => 'activating', 'previous' => $old]);
    AIcontrolInstaller::jsonFile($temporary . '/current.json', $selection);
    AIcontrolInstaller::recover($temporary);
    expect(AIcontrolInstaller::readJson($temporary . '/current.json') === $old, 'interrupted activation recovered');
    AIcontrolInstaller::activate($temporary, $selection, static function (): void {});
    expect(AIcontrolInstaller::readJson($temporary . '/current.json')['sha'] === $sha, 'healthy activation retained');
    AIcontrolInstaller::jsonFile($temporary . '/journal.json', ['phase' => 'activating', 'previous' => null]);
    AIcontrolInstaller::recover($temporary);
    expect(!file_exists($temporary . '/current.json'), 'interrupted first activation clears code pointer');
    expect(file_get_contents($temporary . '/project-data') === 'retain', 'first activation recovery preserves data');
    echo "PASS: $count installer checks\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}
