<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$package = json_decode(file_get_contents($root . '/assets/vendor/tabler-package.json'), true, 32, JSON_THROW_ON_ERROR);
if (!hash_equals($package['vendored_css_sha256'], hash_file('sha256', $root . '/assets/vendor/tabler-1.7.4.min.css'))
    || !hash_equals($package['license_sha256'], hash_file('sha256', $root . '/assets/vendor/TABLER-LICENSE.txt'))) {
    throw new RuntimeException('Vendored Tabler files do not match their recorded checksums.');
}
$license = file_get_contents($root . '/assets/vendor/TABLER-LICENSE.txt');
$vendor = file_get_contents($root . '/assets/vendor/tabler-1.7.4.min.css');
$vendor = str_replace('@charset "UTF-8";', '', $vendor);
$css = "/*\n" . $license . "\n*/\n" . $vendor . "\n" . file_get_contents($root . '/assets/app.css');
$ui = str_replace(['@@CSS@@', '@@JS@@'], [$css, file_get_contents($root . '/assets/map-engine.js')."\n".file_get_contents($root . '/assets/panel.js')], file_get_contents($root . '/ui/Ui.php.tpl'));
$loader = file_get_contents($root . '/loader.php');
$start = '// BEGIN GENERATED UI';
$end = '// END GENERATED UI';
$block = $start . "\n" . substr($ui, strlen("<?php\n")) . "\n" . $end;
if (str_contains($loader, $start)) {
    $left = strpos($loader, $start);
    $right = strpos($loader, $end, $left) + strlen($end);
    $loader = substr($loader, 0, $left) . $block . substr($loader, $right);
} else {
    $loader = str_replace('final class AIcontrolPin', $block . "\n\nfinal class AIcontrolPin", $loader);
}
$updates = file_get_contents($root . '/ui/Updates.php.inc');
$updateBlock = "// BEGIN GENERATED UPDATES\n" . substr($updates, strlen("<?php\n")) . "\n// END GENERATED UPDATES";
if (str_contains($loader, '// BEGIN GENERATED UPDATES')) {
    $left = strpos($loader, '// BEGIN GENERATED UPDATES');
    $right = strpos($loader, '// END GENERATED UPDATES', $left) + strlen('// END GENERATED UPDATES');
    $loader = substr($loader, 0, $left) . $updateBlock . substr($loader, $right);
} else {
    $loader = str_replace('final class AIcontrolPin', $updateBlock . "\n\nfinal class AIcontrolPin", $loader);
}
$workspaceUi = str_replace('AIcontrolUi', 'AIcontrolWorkspaceUi', $ui);
$files = ['assets/panel.css' => $css, 'src/Ui.php' => $workspaceUi, 'loader.php' => $loader];
$check = in_array('--check', $argv, true);
foreach ($files as $path => $contents) {
    if ($check) {
        if (!is_file($root . '/' . $path) || file_get_contents($root . '/' . $path) !== $contents) {
            fwrite(STDERR, "UI build is stale: $path. Run php bin/build-ui.php.\n");
            exit(1);
        }
    } else {
        file_put_contents($root . '/' . $path, $contents);
    }
}
echo $check ? "UI build verified.\n" : "UI assets and standalone theme generated.\n";
