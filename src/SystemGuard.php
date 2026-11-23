<?php
declare(strict_types=1);
namespace AIcontrol;
use PDO;
use RuntimeException;
/** Separate factor and passphrase-wrapped key custody, including protection from old app code. */
final class SystemGuard
{
 public function __construct(private Systems $systems){}
 public static function identity(array $row):string{return hash('sha256',json_encode(['password_hash'=>$row['password_hash'],'generation'=>(int)$row['generation']],JSON_THROW_ON_ERROR));}
 private function row():?array{return $this->systems->query('SELECT * FROM system_guard WHERE id=1')[0]??null;}
 public function configured():bool{return $this->row()!==null;}
 private function validGrant():bool{$r=$this->row();return $r&&($_SESSION['systems_unlock']['until']??0)>time()&&hash_equals(self::identity($r),(string)($_SESSION['systems_unlock']['identity']??''))&&strlen(base64_decode((string)($_SESSION['systems_unlock']['record_key']??''),true)?:'')===32;}
 public function unlocked():bool{return $this->validGrant()&&(bool)$this->row()['migration_complete'];}
 public function requireUnlocked():void{if(!$this->unlocked())throw new RuntimeException('Unlock Systems with its separate password before controlling a server or opening protected records.');}
 public static function rowAt(string $directory):?array{
  $path=$directory.'/systems.sqlite';if(!is_file($path))return null;if(is_link($path))throw new RuntimeException('Systems protection is unavailable.');$db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=5000');if(!$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='system_guard'")->fetchColumn())return null;return $db->query('SELECT * FROM system_guard WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:null;
 }
 public static function recordKey(string $directory):?string{
  $r=self::rowAt($directory);if(!$r)return null;$grant=$_SESSION['systems_unlock']??[];
  if(($grant['until']??0)<=time()||!hash_equals(self::identity($r),(string)($grant['identity']??'')))throw new RuntimeException('Unlock Systems before using its encrypted records.');$key=base64_decode((string)($grant['record_key']??''),true);if($key===false||strlen($key)!==32)throw new RuntimeException('Systems unlock key is unavailable.');return $key;
 }
 private static function wrapped(string $key,int $id,string $secret):string{$nonce=random_bytes(24);return base64_encode($nonce.sodium_crypto_secretbox(json_encode(['system_id'=>$id,'purpose'=>'SSH private key','value'=>$secret],JSON_THROW_ON_ERROR),$nonce,sodium_crypto_generichash('AIcontrol SSH keys v1',$key,32)));}
 public static function saveConnectionKey(PDO $db,string $directory,int $id,string $secret):void{
  $key=self::recordKey($directory);if($key===null)throw new RuntimeException('Set Systems protection before saving a protected key.');$hash=hash('sha256',$secret);$q=$db->prepare('SELECT legacy_sha FROM system_guard_keys WHERE system_id=?');$q->execute([$id]);$old=$q->fetchColumn();if($old!==false&&!hash_equals($old,$hash))throw new RuntimeException('This server ID already has a different protected credential. Inspect it before replacement.');$db->prepare('INSERT INTO system_guard_keys(system_id,sealed,legacy_sha)VALUES(?,?,?) ON CONFLICT(system_id) DO NOTHING')->execute([$id,self::wrapped($key,$id,$secret),$hash]);
 }
 public function connectionKey(int $id):string{
  $this->requireUnlocked();$row=$this->systems->query('SELECT sealed FROM system_guard_keys WHERE system_id=?',[$id])[0]??throw new RuntimeException('Protected SSH credential unavailable.');$raw=base64_decode($row['sealed'],true);$key=self::recordKey($this->systems->directory);if($raw===false||strlen($raw)<40||$key===null)throw new RuntimeException('Protected SSH credential is damaged.');$plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),sodium_crypto_generichash('AIcontrol SSH keys v1',$key,32));if($plain===false)throw new RuntimeException('Protected SSH credential could not be verified.');$v=json_decode($plain,true,8,JSON_THROW_ON_ERROR);if(($v['system_id']??null)!==$id||($v['purpose']??null)!=='SSH private key'||!is_string($v['value']??null))throw new RuntimeException('SSH credential scope is invalid.');return $v['value'];
 }
 private static function masterEnvelope(string $password,string $key):array{$salt=random_bytes(16);$kek=sodium_crypto_pwhash(32,$password,$salt,SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);$nonce=random_bytes(24);return [base64_encode($salt),base64_encode($nonce.sodium_crypto_secretbox($key,$nonce,$kek))];}
 private function grant(string $master):void{if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);$_SESSION['systems_unlock']=['until'=>time()+900,'identity'=>self::identity($this->row()),'record_key'=>base64_encode($master)];}
 private function migrateLegacy():void{
  $vault=new Vault($this->systems->directory);$keys=$vault->systemKeyCandidates();$hashes=[];$this->systems->db->exec('BEGIN IMMEDIATE');
  try{foreach($keys as $id=>$value){self::saveConnectionKey($this->systems->db,$this->systems->directory,(int)$id,$value);$hashes[$id]=hash('sha256',$value);}$this->systems->db->exec('COMMIT');}catch(\Throwable $e){$this->systems->db->exec('ROLLBACK');throw $e;}
  // Durable encrypted copies and the passphrase envelope exist before legacy cleanup.
  $vault->removeMigratedSystemKeys($hashes);if($vault->systemKeyCandidates())throw new RuntimeException('A legacy credential appeared during transfer. Unlock Systems again to reconcile it.');foreach(['system_notebooks','system_playbooks'] as $table)foreach($this->systems->query('SELECT * FROM '.$table.' WHERE sealed LIKE ?', ['v1:%']) as $row){$id=(int)$row['system_id'];$data=$vault->openSystemRecord($id,$row['sealed']);$sealed=$vault->sealSystemRecord($id,$data);$key=$table==='system_notebooks'?'system_id':'id';$this->systems->write('UPDATE '.$table.' SET sealed=? WHERE '.$key.'=?',[$sealed,$row[$key]]);}$this->systems->write('UPDATE system_guard SET migration_complete=1 WHERE id=1');
 }
 public function setup(string $password,string $confirm):void{
  if($this->configured())throw new RuntimeException('A Systems password is already set. It cannot be replaced using the website PIN.');
  if(!mb_check_encoding($password,'UTF-8')||mb_strlen($password,'UTF-8')<12||strlen($password)>256||!preg_match('/\p{L}/u',$password)||!hash_equals($password,$confirm))throw new RuntimeException('Choose matching Systems passwords of 12–256 bytes including letters. Use a different password from the website PIN.');
  $hash=sodium_crypto_pwhash_str($password,SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);$master=random_bytes(32);[$salt,$wrapped]=self::masterEnvelope($password,$master);$this->systems->db->exec('BEGIN IMMEDIATE');try{if($this->configured())throw new RuntimeException('A Systems password is already set. It cannot be replaced using the website PIN.');$this->systems->write('INSERT INTO system_guard(id,password_hash,created_at,salt,wrapped_master)VALUES(1,?,?,?,?)',[$hash,time(),$salt,$wrapped]);$this->systems->db->exec('COMMIT');}catch(\Throwable $e){$this->systems->db->exec('ROLLBACK');throw $e;}$this->grant($master);$this->migrateLegacy();
 }
 public function unlock(string $password):void{
  $attempt=bin2hex(random_bytes(16));$this->systems->db->exec('BEGIN IMMEDIATE');try{$this->systems->write('DELETE FROM system_guard_attempts WHERE created_at<=?',[time()-600]);if(count($this->systems->query('SELECT id FROM system_guard_attempts'))>=5)throw new RuntimeException('Systems unlock is limited. Wait ten minutes before trying again.');$r=$this->row()??throw new RuntimeException('Set the separate Systems password first.');$this->systems->write('INSERT INTO system_guard_attempts(id,created_at)VALUES(?,?)',[$attempt,time()]);$this->systems->db->exec('COMMIT');}catch(\Throwable $e){$this->systems->db->exec('ROLLBACK');throw $e;}
  if(strlen($password)>256||!sodium_crypto_pwhash_str_verify($r['password_hash'],$password))throw new RuntimeException('Systems password was not accepted.');
  $salt=base64_decode((string)$r['salt'],true);$raw=base64_decode((string)$r['wrapped_master'],true);if($salt===false||strlen($salt)!==16||$raw===false||strlen($raw)!==72)throw new RuntimeException('Systems key envelope needs server recovery. It will not be replaced.');$kek=sodium_crypto_pwhash(32,$password,$salt,SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);$master=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$kek);if($master===false||strlen($master)!==32)throw new RuntimeException('Systems key envelope could not be verified.');$this->systems->write('DELETE FROM system_guard_attempts WHERE id=?',[$attempt]);$this->grant($master);$this->migrateLegacy();
 }
 public function change(string $current,string $password,string $confirm):void{
  $this->requireUnlocked();if(!mb_check_encoding($password,'UTF-8')||mb_strlen($password,'UTF-8')<12||strlen($password)>256||!preg_match('/\p{L}/u',$password)||!hash_equals($password,$confirm))throw new RuntimeException('Choose matching new Systems passphrases of at least 12 characters including letters.');
  $this->unlock($current);$before=$this->row();$master=self::recordKey($this->systems->directory);$hash=sodium_crypto_pwhash_str($password,SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);[$salt,$wrapped]=self::masterEnvelope($password,$master);
  $this->systems->db->exec('BEGIN IMMEDIATE');try{if(!hash_equals(self::identity($before),self::identity($this->row())))throw new RuntimeException('Systems protection changed. Unlock again before changing its password.');$this->systems->write('UPDATE system_guard SET password_hash=?,salt=?,wrapped_master=?,generation=generation+1 WHERE id=1',[$hash,$salt,$wrapped]);$this->systems->db->exec('COMMIT');}catch(\Throwable $e){$this->systems->db->exec('ROLLBACK');throw $e;}$this->grant($master);
 }
 public function lock():void{if($this->validGrant())$this->systems->write('UPDATE system_guard SET generation=generation+1 WHERE id=1');unset($_SESSION['systems_unlock']);}
 public function state():array{return ['configured'=>$this->configured(),'unlocked'=>$this->unlocked(),'until'=>$this->unlocked()?(int)$_SESSION['systems_unlock']['until']:0];}
}
