<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/src/HostingRequirements.php';
$failures = \AIcontrol\HostingRequirements::failures(PHP_VERSION_ID, get_loaded_extensions());
echo json_encode(['php' => PHP_VERSION, 'requirements_met' => $failures === [], 'failures' => $failures], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($failures === [] ? 0 : 1);
