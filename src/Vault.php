<?php
declare(strict_types=1);
namespace AIcontrol;
use RuntimeException;
final class Vault
{
    private string $path;
    public function __construct(private string $directory) { $this->path = $directory . '/vault.json'; }
    private function key(): string {
        $path = $this->directory . '/vault.key';
        if (is_link($path) || is_link($this->path)) { throw new RuntimeException('Vault unavailable.'); }
        if (!is_file($path)) {
            if (is_file($this->path)) { throw new RuntimeException('Vault key missing.'); }
            $handle = @fopen($path, 'x');
            if ($handle) { chmod($path, 0600); fwrite($handle, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); fclose($handle); }
        }
        $key = file_get_contents($path);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) { throw new RuntimeException('Vault key invalid.'); }
        return $key;
    }
    private function read(): array {
        if (!is_file($this->path)) { if (is_link($this->path)) { throw new RuntimeException('Vault unavailable.'); } return []; }
        $key = $this->key();
        $raw = base64_decode(file_get_contents($this->path), true);
        if ($raw === false || strlen($raw) < 40) { throw new RuntimeException('Vault damaged.'); }
        $plain = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), $key);
        if ($plain === false) { throw new RuntimeException('Vault verification failed.'); }
        return json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
    }
    public function entries(): array {
        return array_map(static fn(array $v): array => array_diff_key($v, ['value' => true]), $this->read());
    }
    public function value(string $name): string { return $this->read()[$name]['value'] ?? ''; }
    public function context(): string {
        $text = '';
        foreach ($this->read() as $name => $item) { if ($item['type'] === 'note' && $item['share']) { $text .= "\n" . $name . ': ' . $item['value']; } }
        return mb_strcut($text, 0, 12000, 'UTF-8');
    }
    public function change(string $name, string $type, string $value, bool $share, bool $delete = false): void {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) || !in_array($type, ['key', 'note'], true) || strlen($value) > 8000 || !mb_check_encoding($value, 'UTF-8')) { throw new RuntimeException('Use a valid name, type and value up to 8000 bytes.'); }
        if (!$delete && $name === 'openai_api_key' && ($type !== 'key' || !preg_match('/^[!-~]{1,1024}$/D', $value))) { throw new RuntimeException('Invalid provider key.'); }
        $lock = fopen($this->directory . '/vault.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Vault busy.'); }
        try {
            $items = $this->read();
            if ($delete) { unset($items[$name]); }
            else {
                if (trim($value) === '' || (!isset($items[$name]) && count($items) >= 100)) { throw new RuntimeException('Enter a value; the vault supports 100 entries.'); }
                $items[$name] = ['type' => $type, 'value' => $value, 'share' => $type === 'note' && $share, 'updated_at' => gmdate('c')];
            }
            $nonce = random_bytes(24);
            $data = base64_encode($nonce . sodium_crypto_secretbox(json_encode($items, JSON_THROW_ON_ERROR), $nonce, $this->key()));
            $temp = tempnam($this->directory, '.vault-'); chmod($temp, 0600);
            if (file_put_contents($temp, $data) !== strlen($data) || !rename($temp, $this->path)) { @unlink($temp); throw new RuntimeException('Vault save failed.'); }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
