<?php
declare(strict_types=1);

// Application entry, loaded only from a verified release by the standalone loader.
ini_set('display_errors', '0');
require_once __DIR__ . '/src/HostingRequirements.php';
require_once __DIR__ . '/src/Bootstrap.php';

if (getenv('AICONTROL_PRIVATE_DIR') !== false) {
    require_once __DIR__ . '/src/PrivateStorage.php';
    require_once __DIR__ . '/src/PanelStore.php';
    require_once __DIR__ . '/src/Panel.php';
    try {
        if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID >= 80600
            || !extension_loaded('pdo_sqlite') || !extension_loaded('mbstring') || !extension_loaded('session')) {
            throw new RuntimeException('Planning panel requirements not met.');
        }
        $requestedPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $script = $_SERVER['SCRIPT_NAME'] ?? '/loader.php';
        if (str_ends_with($script, '.php') && $requestedPath === $script) {
            $requestedPath = '/loader.php';
        }
        \AIcontrol\Panel::serve(
            getenv('AICONTROL_PRIVATE_DIR'),
            isset($_GET['health']) ? '/health' : $requestedPath,
            $_SERVER['REQUEST_METHOD'] ?? 'GET'
        );
    } catch (Throwable) {
        http_response_code(503);
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
        echo '{"error":"panel_unavailable"}';
    }
    return;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
[$status, $headers, $body] = \AIcontrol\Bootstrap::response(
    $method,
    is_string($path) ? $path : '',
    \AIcontrol\HostingRequirements::failures(PHP_VERSION_ID, get_loaded_extensions())
);
http_response_code($status);
foreach ($headers as $name => $value) {
    header($name . ': ' . $value);
}
if ($method !== 'HEAD') {
    echo $body;
}
