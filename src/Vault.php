<?php
declare(strict_types=1);
namespace AIcontrol;
use RuntimeException;
require_once __DIR__.'/PrivateStorage.php';
require_once __DIR__.'/SystemGuard.php';
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
    public function sealSystemRecord(int $id,array $data): string {
        if($id<1)throw new RuntimeException('Invalid server record.');
        $master=SystemGuard::recordKey($this->directory);$key=sodium_crypto_generichash('AIcontrol system records v1',$master??$this->key(),32);$nonce=random_bytes(24);
        return ($master===null?'v1:':'v2:').base64_encode($nonce.sodium_crypto_secretbox(json_encode(['system_id'=>$id,'data'=>$data],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),$nonce,$key));
    }
    public function openSystemRecord(int $id,string $sealed): array {
        $master=str_starts_with($sealed,'v2:')?SystemGuard::recordKey($this->directory):null;
        if($master===null&&(!is_file($this->directory.'/vault.key')||!str_starts_with($sealed,'v1:')))throw new RuntimeException('Protected server record is unavailable.');
        $raw=base64_decode(substr($sealed,3),true);if($raw===false||strlen($raw)<40)throw new RuntimeException('Protected server record is damaged.');
        $key=sodium_crypto_generichash('AIcontrol system records v1',$master??$this->key(),32);$plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$key);
        if($plain===false)throw new RuntimeException('Protected server record could not be verified.');$v=json_decode($plain,true,64,JSON_THROW_ON_ERROR);
        if(($v['system_id']??null)!==$id||!is_array($v['data']??null))throw new RuntimeException('Protected record belongs to another server.');return $v['data'];
    }
    private function contextProfilePermitsSharing(): bool
    {
        try {
            // Use the actual validated installation profile, including older
            // stored public-testing configurations. Environment flags follow
            // the same precedence as Panel; a demo CSRF session is not an owner.
            if(!is_file($this->directory.'/config.json')||is_link($this->directory.'/config.json')||!is_dir($this->directory.'/sessions')||!is_file($this->directory.'/panel.sqlite'))return false;
            $config=PrivateStorage::config($this->directory);
            $mode=getenv('AICONTROL_PIN_VERIFIED')==='1'?'pin':(getenv('AICONTROL_PUBLIC_TESTING')==='1'?'testing':$config['access_mode']);
            return in_array($mode,['pin','password','host','local'],true);
        } catch (\Throwable) {
            // Missing, invalid or unreadable configuration must never expand
            // the context visible to a public or indeterminate caller.
            return false;
        }
    }
    public function context(): string {
        if(!$this->contextProfilePermitsSharing())return '';
        $text = '';
        foreach ($this->read() as $name => $item) {
            if($name!=='openai_api_key'&&!self::systemManaged($name)&&is_array($item)&&($item['type']??null)==='note'&&($item['share']??null)===true&&is_string($item['value']??null))$text.="\n".$name.': '.$item['value'];
        }
        return mb_strcut($text, 0, 12000, 'UTF-8');
    }
    public function change(string $name, string $type, string $value, bool $share, bool $delete = false): void {
        if(self::systemManaged($name))throw new RuntimeException('SSH credentials are managed in Systems.');
        $this->write($name,$type,$value,$share,$delete);
    }
    public static function systemManaged(string $name): bool { return str_starts_with($name,'ssh_system_'); }
    public function saveSystemKey(int $id,string $privateKey): void {
        if($id<1)throw new RuntimeException('Invalid system credential.');
        if(SystemGuard::rowAt($this->directory)!==null)throw new RuntimeException('Protected SSH credentials are saved by Systems.');
        $this->write('ssh_system_'.$id,'key',$privateKey,false,false);
    }
    public function deleteSystemKey(int $id): void {
        if($id<1)throw new RuntimeException('Invalid system credential.');
        $this->write('ssh_system_'.$id,'key','',false,true);
    }
    public function systemKeyCandidates(): array {
        $out=[];foreach($this->read() as $name=>$item)if(preg_match('/^ssh_system_([1-9][0-9]*)$/D',$name,$m)&&is_string($item['value']??null))$out[(int)$m[1]]=$item['value'];return $out;
    }
    public function removeMigratedSystemKeys(array $expected): void {
        if(!$expected)return;$lock=fopen($this->directory.'/vault.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Vault busy during protected key transfer.');
        try{$items=$this->read();foreach($expected as $id=>$hash){$name='ssh_system_'.$id;if(!isset($items[$name]))continue;if(!hash_equals($hash,hash('sha256',$items[$name]['value'])))throw new RuntimeException('An SSH credential changed during transfer. Unlock Systems again after inspecting it.');unset($items[$name]);}$this->persist($items);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function persist(array $items): void {
        $nonce=random_bytes(24);$data=base64_encode($nonce.sodium_crypto_secretbox(json_encode($items,JSON_THROW_ON_ERROR),$nonce,$this->key()));$temp=tempnam($this->directory,'.vault-');chmod($temp,0600);if(file_put_contents($temp,$data)!==strlen($data)||!rename($temp,$this->path)){@unlink($temp);throw new RuntimeException('Vault save failed.');}
    }
    private function write(string $name,string $type,string $value,bool $share,bool $delete): void {
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
