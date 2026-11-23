<?php
declare(strict_types=1);
namespace AIcontrol;
use RuntimeException;
require_once __DIR__.'/Vault.php';
/** Public notes are masked; full observations and protected values are authenticated ciphertext. */
final class SystemNotebook
{
 public function __construct(private Systems $systems){}
 public static function mask(string $text,array $known=[]):string{
  usort($known,static fn($a,$b)=>strlen((string)$b)<=>strlen((string)$a));foreach($known as $value)if(is_string($value)&&$value!=='')$text=str_replace($value,'********',$text);
  $text=preg_replace('~-----BEGIN [^-]*PRIVATE KEY-----.*?-----END [^-]*PRIVATE KEY-----~s','********',$text);
  $text=preg_replace('~\b(?:sk-[A-Za-z0-9_-]{12,}|gh[pousr]_[A-Za-z0-9_]{12,})\b~','********',$text);
  $text=preg_replace('~(?i)(\b(?:password|passwd|secret|token|api[ _-]?key|user(?:name)?|login)\s*(?:is\s+|[:=]\s*))(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)~','$1********',$text);
  $text=preg_replace('~(?i)(Bearer\s+)\S+~','$1********',$text);return preg_replace('~(https?://)[^\s/@]+(?::[^\s/@]*)?@~','$1********@',$text);
 }
 private function privateData(int $id):array{$row=$this->systems->query('SELECT sealed FROM system_notebooks WHERE system_id=?',[$id])[0]??null;return $row?(new Vault($this->systems->directory))->openSystemRecord($id,$row['sealed']):['secrets'=>[],'observations'=>[]];}
 private function save(int $id,string $notes,array $private):void{$sealed=(new Vault($this->systems->directory))->sealSystemRecord($id,$private);$this->systems->write('INSERT INTO system_notebooks(system_id,public_notes,sealed,updated_at)VALUES(?,?,?,?) ON CONFLICT(system_id) DO UPDATE SET public_notes=excluded.public_notes,sealed=excluded.sealed,updated_at=excluded.updated_at',[$id,$notes,$sealed,time()]);$public=$this->view($id);$this->systems->write('UPDATE system_notebooks SET public_json=? WHERE system_id=?',[json_encode($public,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),$id]);}
 public function edit(int $id,string $notes,string $label='',string $value=''):void{
  $this->systems->db->exec('BEGIN IMMEDIATE');try{$s=$this->systems->system($id);if(strlen($notes)>4000||!mb_check_encoding($notes,'UTF-8')||strlen($value)>4000||!mb_check_encoding($value,'UTF-8'))throw new RuntimeException('Notes and each protected value support up to 4,000 UTF-8 bytes.');
  $p=$this->privateData($id);if($label!==''||$value!==''){if(!preg_match('/^[a-z][a-z0-9_-]{0,47}$/D',$label)||$value==='')throw new RuntimeException('Use a lowercase protected label and a nonempty value.');if(!isset($p['secrets'][$label])&&count($p['secrets'])>=30)throw new RuntimeException('This server supports 30 protected values.');$p['secrets'][$label]=$value;}
  $this->save($id,self::mask($notes,array_merge(array_values($p['secrets']),[$s['username'],$s['host']])),$p);$this->systems->db->exec('COMMIT');}catch(\Throwable $e){$this->systems->db->exec('ROLLBACK');throw $e;}
 }
 public function observe(int $id,string $title,string $status,array $details):void{
  $this->systems->db->exec('BEGIN IMMEDIATE');try{$this->systems->system($id);$p=$this->privateData($id);$p['observations'][]=['utc'=>gmdate('c'),'title'=>$title,'status'=>$status,'details'=>$details];if(count($p['observations'])>100)$p['observations']=array_slice($p['observations'],-100);$row=$this->systems->query('SELECT public_notes FROM system_notebooks WHERE system_id=?',[$id])[0]??[];$this->save($id,$row['public_notes']??'',$p);$this->systems->db->exec('COMMIT');}catch(\Throwable $e){$this->systems->db->exec('ROLLBACK');throw $e;}
 }
 public function redactForModel(int $id,mixed $data):mixed{$s=$this->systems->system($id);$p=$this->privateData($id);$known=array_merge(array_values($p['secrets']),[$s['username'],$s['host']]);$walk=function($value)use(&$walk,$known){if(is_array($value)){foreach($value as $key=>$v)$value[$key]=$walk($v);return $value;}return is_string($value)?self::mask($value,$known):$value;};return $walk($data);}
 public function view(int $id,bool $reveal=false):array{
  $s=$this->systems->system($id);$row=$this->systems->query('SELECT public_notes,updated_at,public_json FROM system_notebooks WHERE system_id=?',[$id])[0]??[];
  if((new SystemGuard($this->systems))->configured()&&!(new SystemGuard($this->systems))->unlocked()){
   if($reveal)throw new RuntimeException('Unlock Systems to reveal protected values.');$public=array_intersect_key(json_decode($row['public_json']??'{}',true)?:[],array_flip(['notes','inventory','updated_at','protected','observations']));$public['notes']=self::mask($row['public_notes']??'',[$s['username'],$s['host']]);return $public+['inventory'=>[],'updated_at'=>$row['updated_at']??null,'protected'=>[],'observations'=>[],'revealed'=>null];
  }
  $p=$this->privateData($id);$events=array_map(static fn($e)=>array_intersect_key($e,array_flip(['utc','title','status'])),$p['observations']);
  $inventory=[];foreach(array_reverse($p['observations']) as $event){$raw=$event['details']['inventory']??$event['details']['result']['inventory']??null;if(!is_array($raw))continue;foreach(['os','distribution','version','architecture','kernel','cpu_count','load_average','failed_service_count','memory_kib','disk_free_bytes','reboot_required','wireguard_configured','backup_tools','backup_file_count','observed_utc'] as $key)if(isset($raw[$key]))$inventory[$key]=$raw[$key];break;}
  if(!$inventory)foreach(array_reverse($p['observations']) as $event)foreach($event['details']['results']??[] as $result){$output=$result['result']['stdout']??'';if(preg_match('/^PRETTY_NAME="([^"\n]+)"/m',$output,$match)){$inventory=['os'=>$match[1],'observed_utc'=>$event['utc']];break 2;}}
  foreach($inventory as $key=>$value)if(is_string($value))$inventory[$key]=self::mask(substr($value,0,160),array_merge(array_values($p['secrets']),[$s['username'],$s['host']]));
  return ['inventory'=>$inventory,'notes'=>self::mask($row['public_notes']??'',array_merge(array_values($p['secrets']),[$s['username'],$s['host']])),'updated_at'=>$row['updated_at']??null,'protected'=>array_fill_keys(array_keys($p['secrets']),'********'),'observations'=>array_reverse($events),'revealed'=>$reveal?$p:null];
 }
}
