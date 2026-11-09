<?php
declare(strict_types=1);

namespace AIcontrol;

final class AccessPolicy
{
    public static function permits(string $mode, array $server, bool $localDevelopment): bool
    {
        if ($mode === 'pin') {
            return getenv('AICONTROL_PIN_VERIFIED') === '1';
        }
        if ($mode === 'testing') {
            return true;
        }
        if ($mode === 'host') {
            // REMOTE_USER is supplied by server authentication. Never trust
            // HTTP_REMOTE_USER, forwarded identity headers or PHP_AUTH_USER.
            return is_string($server['REMOTE_USER'] ?? null) && trim($server['REMOTE_USER']) !== '';
        }
        return $mode === 'local' && $localDevelopment
            && in_array($server['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    }
}
