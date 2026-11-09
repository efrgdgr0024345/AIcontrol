<?php
declare(strict_types=1);

// Only for the PHP loopback development server; never a general file server.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/assets/panel.css') {
    header('Content-Type: text/css; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    readfile(dirname(__DIR__) . '/assets/panel.css');
    return;
}
require dirname(__DIR__) . '/loader.php';
