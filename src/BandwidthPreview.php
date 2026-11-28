<?php
declare(strict_types=1);

namespace AIcontrol;

use RuntimeException;

final class BandwidthPreview
{
    public const ENTRY = '/aiadmin/loader.php';
    public const PATH = '/aiadmin/bandwidth/';
    public const SOURCE = 'https://raw.githubusercontent.com/efrgdgr0024345/music/8fe9be949820a480b93eda1c96c69e90a72a172e/loader.php';
    private const SHA256 = '43aabe9fd87a215d6b5071ba51b1404e5a700c3f97c945c2349cec60b39daf22';
    private const LIMIT = 65536;

    public static function destination(string $entry, mixed $script): ?string
    {
        if ($entry !== self::ENTRY || !is_string($script) || $script === '') {
            return null;
        }
        $real = realpath($script);
        if ($real === false || basename($real) !== 'loader.php' || basename(dirname($real)) !== 'aiadmin') {
            return null;
        }
        return dirname($real) . '/bandwidth';
    }

    private static function fetch(): string
    {
        $curl = curl_init(self::SOURCE);
        if ($curl === false) {
            throw new RuntimeException('Could not connect to the Bandwidth repository.');
        }
        $bytes = '';
        curl_setopt_array($curl, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'AIcontrol-Bandwidth-bootstrap',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes): int {
                if (strlen($bytes) + strlen($chunk) > self::LIMIT) {
                    return 0;
                }
                $bytes .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (curl_exec($curl) !== true || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
            throw new RuntimeException('Could not read the pinned Bandwidth installer from GitHub.');
        }
        return $bytes;
    }

    public static function prepare(string $private, string $entry, mixed $script, ?callable $download = null): void
    {
        $target = self::destination($entry, $script);
        if ($target === null || is_link($target) || is_link("$target/loader.php")) {
            throw new RuntimeException('The Bandwidth installation directory is unavailable.');
        }
        if (is_file("$target/loader.php")) {
            return;
        }
        if (file_exists($target) && (!is_dir($target) || scandir($target) !== ['.', '..'])) {
            throw new RuntimeException('The Bandwidth directory already contains files. They were preserved.');
        }
        $bytes = $download === null ? self::fetch() : $download();
        if (strlen($bytes) > self::LIMIT || !str_starts_with($bytes, "<?php\n")
            || !hash_equals(self::SHA256, hash('sha256', $bytes))) {
            throw new RuntimeException('The Bandwidth installer fingerprint did not match the checked source.');
        }
        $stage = $private . '/bandwidth-stage-' . bin2hex(random_bytes(8));
        $file = "$stage/loader.php";
        if (!mkdir($stage, 0700)) {
            throw new RuntimeException('The host could not stage the Bandwidth installer.');
        }
        try {
            if (file_put_contents($file, $bytes) !== strlen($bytes)
                || !chmod($file, 0644) || !chmod($stage, 0755) || !@rename($stage, $target)) {
                throw new RuntimeException('The host could not publish the Bandwidth installer.');
            }
        } finally {
            if (is_dir($stage)) {
                if (is_file($file)) {
                    unlink($file);
                }
                rmdir($stage);
            }
        }
    }
}
