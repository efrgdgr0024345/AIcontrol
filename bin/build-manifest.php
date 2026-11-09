<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$paths = array_merge(['app.php', 'assets/panel.css'], array_map(static fn(string $path): string => substr($path, strlen($root) + 1), glob($root . '/src/*.php')));
sort($paths);
$files = [];
foreach ($paths as $path) {
    $files[$path] = hash_file('sha256', $root . '/' . $path);
}
$json = json_encode(['version' => 2, 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (in_array('--check', $argv, true)) {
    if (!is_file($root . '/release-manifest.json') || file_get_contents($root . '/release-manifest.json') !== $json) {
        fwrite(STDERR, "Release manifest is stale. Run php bin/build-manifest.php.\n");
        exit(1);
    }
    echo "Release manifest verified.\n";
} else {
    file_put_contents($root . '/release-manifest.json', $json);
    echo "Release manifest generated.\n";
}
