<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/src/PrivateStorage.php';
require_once dirname(__DIR__) . '/src/PanelStore.php';

umask(0077);
try {
    $options = getopt('', ['private-dir:', 'public-root:']);
    $directory = $options['private-dir'] ?? '';
    $roots = (array) ($options['public-root'] ?? []);
    if ($directory === '' || $roots === []) {
        throw new RuntimeException('Supply --private-dir and every --public-root. Read the login password from stdin.');
    }
    if (is_dir($directory) && count(scandir($directory)) > 2) {
        throw new RuntimeException('Refusing to overwrite existing private state.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException('Cannot create private directory.');
    }
    \AIcontrol\PrivateStorage::validate($directory, $roots);
    $password = rtrim(stream_get_contents(STDIN, 1024), "\r\n");
    if (strlen($password) < 14 || strlen($password) > 256) {
        throw new RuntimeException('Login password must contain 14–256 bytes.');
    }
    $config = ['version' => 1, 'public_roots' => $roots, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)];
    unset($password);
    new \AIcontrol\PanelStore($directory . '/panel.sqlite', true);
    mkdir($directory . '/sessions', 0700);
    // Config is the final initialization marker; never silently recreate lost state.
    $file = fopen($directory . '/config.json', 'x');
    if ($file === false) {
        throw new RuntimeException('Configuration already exists.');
    }
    fwrite($file, json_encode($config, JSON_THROW_ON_ERROR));
    fclose($file);
    \AIcontrol\PrivateStorage::config($directory);
    echo "Metadata panel initialized. No credentials, agents or privileged operations enabled.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Setup failed: " . $error->getMessage() . "\n");
    exit(1);
}
