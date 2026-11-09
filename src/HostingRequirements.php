<?php
declare(strict_types=1);

namespace AIcontrol;

final class HostingRequirements
{
    public const EXTENSIONS = ['curl', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'sodium', 'zip'];

    /** @return list<string> Nonsecret machine-readable prerequisite failures. */
    public static function failures(int $version, array $extensions): array
    {
        $failures = [];
        if ($version < 80200 || $version >= 80600) {
            $failures[] = 'unsupported_php_version';
        }
        $extensions = array_map('strtolower', $extensions);
        foreach (self::EXTENSIONS as $extension) {
            if (!in_array($extension, $extensions, true)) {
                $failures[] = 'missing_extension:' . $extension;
            }
        }
        return $failures;
    }
}
