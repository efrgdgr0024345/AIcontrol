<?php
declare(strict_types=1);

namespace AIcontrol;

use RuntimeException;

final class PrivateStorage
{
    public static function validate(string $directory, array $publicRoots): string
    {
        $real = realpath($directory);
        if ($real === false || $real !== rtrim($directory, '/') || !is_dir($real) || is_link($directory)) {
            throw new RuntimeException('Private directory must be an existing canonical absolute path.');
        }
        foreach (array_merge([dirname(__DIR__)], $publicRoots) as $root) {
            $public = realpath($root);
            if ($public === false || $public === '/' || $real === $public || str_starts_with($real . '/', $public . '/')) {
                throw new RuntimeException('Private storage must be outside every public root.');
            }
        }
        self::permissions($real, 0700);
        return $real;
    }

    public static function permissions(string $path, int $expected): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || (fileperms($path) & 0777) !== $expected) {
            throw new RuntimeException('Unsafe private storage permissions.');
        }
        if (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid()) {
            throw new RuntimeException('Private storage owner mismatch.');
        }
    }

    public static function config(string $directory): array
    {
        self::validate($directory, []);
        $file = $directory . '/config.json';
        self::permissions($file, 0600);
        $config = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        $mode = $config['access_mode'] ?? 'password'; // Preserve existing installations until explicitly migrated.
        if (!in_array($config['version'] ?? null, [1, 2], true) || !is_array($config['public_roots'] ?? null)
            || ($config['public_roots'] ?? []) === [] || !in_array($mode, ['pin', 'host', 'local', 'testing', 'password'], true)
            || ($mode === 'password' && (!is_string($config['password_hash'] ?? null)
                || (password_get_info($config['password_hash'])['algo'] ?? null) === null))) {
            throw new RuntimeException('Invalid configuration.');
        }
        $config['access_mode'] = $mode;
        self::validate($directory, $config['public_roots']);
        self::permissions($directory . '/sessions', 0700);
        self::permissions($directory . '/panel.sqlite', 0600);
        return $config;
    }
}
