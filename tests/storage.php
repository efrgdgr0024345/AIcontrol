<?php
declare(strict_types=1);
define('AICONTROL_LOADER_TEST', true);
require dirname(__DIR__) . '/loader.php';
umask(0077);
$temporary = sys_get_temp_dir() . '/aicontrol-storage-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
mkdir($temporary . '/account', 0700);
mkdir($temporary . '/account/public_html', 0700);
mkdir($temporary . '/account/public_html/control', 0700);
$loader = $temporary . '/account/public_html/control/loader.php';
file_put_contents($loader, '<?php');
try {
    $server = ['DOCUMENT_ROOT' => $temporary . '/account/public_html'];
    $choice = AIcontrolInstaller::autoStorage($server, $loader, $temporary . '/account');
    if ($choice['rule'] !== 'account-home' || dirname($choice['home']) !== $temporary . '/account' || !is_dir($choice['home']) || (fileperms($choice['home']) & 0777) !== 0700) {
        throw new RuntimeException('Automatic cPanel storage choice failed');
    }
    if (str_starts_with($choice['home'], $server['DOCUMENT_ROOT'] . '/')) {
        throw new RuntimeException('Private storage placed in public root');
    }
    $rejected = false;
    try { AIcontrolInstaller::autoStorage($server, $loader, $temporary . '/account'); }
    catch (RuntimeException) { $rejected = true; }
    if (!$rejected || !is_dir($choice['home'])) {
        throw new RuntimeException('Existing private state was not preserved');
    }
    mkdir($temporary . '/generic', 0700);
    mkdir($temporary . '/generic/site', 0700);
    $generic = AIcontrolInstaller::autoStorage(['DOCUMENT_ROOT' => $temporary . '/generic/site'], $temporary . '/generic/site/loader.php', '/nonexistent-account-home');
    if ($generic['rule'] !== 'document-root-parent' || dirname($generic['home']) !== $temporary . '/generic') {
        throw new RuntimeException('Generic PHP host fallback failed');
    }
    echo "PASS: automatic cPanel storage, generic fallback, permissions and existing-state preservation\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($temporary);
}
