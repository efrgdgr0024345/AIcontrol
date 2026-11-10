<?php
declare(strict_types=1);

namespace AIcontrol;

final class Bootstrap
{
    /** No provisioning, authentication or privileged execution is enabled yet. */
    public static function response(string $method, string $path, array $failures): array
    {
        $headers = [
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];
        if ($method !== 'GET' && $method !== 'HEAD') {
            return [405, $headers + ['Allow' => 'GET, HEAD', 'Content-Type' => 'application/json'], '{"error":"method_not_allowed"}'];
        }
        if ($path === '/health') {
            // Do not disclose host details, filesystem paths, configuration or credentials.
            return [503, $headers + ['Content-Type' => 'application/json'], json_encode([
                'status' => 'not_ready',
                'reason' => $failures === [] ? 'implementation_incomplete' : 'hosting_requirements_not_met',
            ], JSON_THROW_ON_ERROR)];
        }
        if ($path !== '/' && $path !== '/loader.php') {
            return [404, $headers + ['Content-Type' => 'application/json'], '{"error":"not_found"}'];
        }
        $body = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>AIcontrol — setup required</title><main><h1>AIcontrol</h1><p>Control panel under construction.</p>'
            . '<p>Credential storage, owner approval, agents and installation are not enabled.</p>'
            . '<p>Do not enter service credentials. No project or system is connected.</p></main></html>';
        return [503, $headers + ['Content-Type' => 'text/html; charset=utf-8'], $body];
    }
}
