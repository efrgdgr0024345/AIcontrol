<?php
declare(strict_types=1);
namespace AIcontrol;
use RuntimeException;
require_once __DIR__.'/SystemJob.php';
require_once __DIR__.'/SystemNotebook.php';

/** Deterministic reviewed recipes. No model-generated root commands. */
final class SystemPlaybooks
{
 public const RECIPES=[
  'inspect'=>['title'=>'Check system setup','description'=>'Check existing software, services and setup before choosing an action. Read-only.','access'=>'inspect'],
  'firewall'=>['title'=>'Lock down system','description'=>'Review required ports, preserve SSH and enable an inbound firewall with timed rollback. Fresh Debian/Ubuntu firewall only.','access'=>'admin'],
  'update'=>['title'=>'Update system','description'=>'Refresh package indexes and upgrade installed packages without removals, configuration replacement or automatic reboot.','access'=>'admin'],
  'vpn'=>['title'=>'Set up VPN','description'=>'Configure private-access WireGuard for your client public key. Existing routes and cloud firewall stay under your control.','access'=>'admin'],
  'codex'=>['title'=>'Install Codex','description'=>'Install the verified official Codex binary for the selected account. Separate Codex authentication is required afterwards.','access'=>'account'],
  'security_updates'=>['title'=>'Automatic security updates','description'=>'Enable distribution security updates and disable automatic reboot on Debian/Ubuntu.','access'=>'admin'],
  'health'=>['title'=>'Health check','description'=>'Inspect disk, memory, load and failed services. No repairs are made automatically.','access'=>'inspect'],
  'backup'=>['title'=>'Backup readiness','description'=>'Check backup tools and local backup metadata. A provider snapshot or a successful restore is not implied.','access'=>'account','read_only'=>true],
 ];
 private const CODEX=[
  'x86_64'=>'01baa2e47809f8597be5b2f8abd4e4dfe3fb5bcb6e504c2fbaaad97056c15e8d',
  'aarch64'=>'bcf50b836385e51bd2976ab2bbe92b1b08a1cbe37712eb8dbe378aa78d24871c',
 ];
 public function __construct(private Systems $systems,private ?object $ssh=null){$this->ssh??=new SshTransport();}
 private function binding(array $s):string{return hash('sha256',json_encode(array_intersect_key($s,array_flip(['id','host','port','username','fingerprint','risk_level','access'])),JSON_THROW_ON_ERROR));}
 private function remote(array $s,array $config):array{
  $config['username']=$s['username'];$source=SystemJob::program($config);$python='/usr/bin/python3 -I -c '.SystemRisk::quote($source);
  $command=($config['root']??false)?'sudo -n /usr/bin/env AICONTROL_SSH_CONNECTION="${SSH_CONNECTION-}" '.$python:$python;
  return $this->ssh->execute($s,$this->systems->privateKey((int)$s['id']),$command,$this->systems->directory,fn()=>!$this->systems->controlStillAllowed()||!(bool)$this->systems->system((int)$s['id'])['enabled']);
 }
 private function decode(array $r):array{if($r['timed_out']||$r['cancelled']||$r['exit_code']!==0||$r['truncated'])throw new RuntimeException('SSH result could not be verified. No operation is retried automatically.');try{$v=json_decode(trim($r['stdout']),true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new RuntimeException('The server did not return a valid bounded operation record.');}if(!is_array($v))throw new RuntimeException('The server record is invalid.');return $v;}
 private function lock():mixed{$path=$this->systems->directory.'/systems-run.lock';if(is_link($path))throw new RuntimeException('Systems execution lock unavailable.');$f=fopen($path,'c');if(!$f||!flock($f,LOCK_EX|LOCK_NB)){if($f)fclose($f);throw new RuntimeException('Another SSH step is running.');}return $f;}
 private function store(string $id,int $system,string $status,array $p,bool $blocking=false):void{$sealed=(new Vault($this->systems->directory))->sealSystemRecord($system,$p);$this->systems->write('UPDATE system_playbooks SET status=?,blocking=?,sealed=?,updated_at=? WHERE id=? AND system_id=?',[$status,(int)$blocking,$sealed,time(),$id,$system]);}
 public function get(string $id,int $system):array{
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Choose a saved server action.');$r=$this->systems->query('SELECT * FROM system_playbooks WHERE id=? AND system_id=?',[$id,$system])[0]??throw new RuntimeException('Server action does not belong to this server.');$r['data']=(new Vault($this->systems->directory))->openSystemRecord($system,$r['sealed']);unset($r['sealed']);return $r;
 }
 public static function alreadyConfigured(string $recipe,mixed $state):bool
 {
  return match($recipe){
   'vpn','security_updates'=>in_array($state,['active','configured'],true),
   'codex'=>in_array($state,['installed','configured'],true),
   'firewall'=>$state==='configured',
   default=>false,
  };
 }
 public function prepareWithAi(int $system,string $goal,SystemsAgent $agent):string
 {
  $this->systems->requireNoPlaybook();
  $server=$this->systems->system($system);
  if(!$server['enabled']||!$server['verified_at'])throw new RuntimeException('Verify the SSH connection before asking AI to prepare an action.');
  $latest=$this->latestOutcomes($server,$this->history($system));
  $checks=$latest['inspect']['data']['inventory']['setup_checks']??[];
  $choices=[];
  foreach(self::RECIPES as $key=>$recipe){
   if(($recipe['access']==='admin'&&$server['risk_level']!=='admin')||($recipe['access']==='account'&&$server['access']!=='maintain')||self::alreadyConfigured($key,$checks[$key]??null))continue;
   $choices[$key]=$recipe['description'];
  }
  $selection=$agent->chooseAction($system,$goal,$choices);
  if($selection['recipe']==='none')throw new RuntimeException('AI did not choose an action. '.$selection['reason']);
  if(!$this->systems->controlStillAllowed()||!hash_equals($this->binding($server),$this->binding($this->systems->system($system)))){
   throw new RuntimeException('The Systems unlock or server connection changed while AI was choosing. Review the server before continuing.');
  }
  return $this->prepare($system,$selection['recipe'],$selection['reason']);
 }
 public function prepare(int $system,string $recipe,?string $aiReason=null):string{
  $s=$this->systems->system($system);$definition=self::RECIPES[$recipe]??throw new RuntimeException('Choose a known server action.');if(!$s['enabled']||!$s['verified_at'])throw new RuntimeException('Verify the SSH connection before preparing a server action.');$this->systems->requireNoPlaybook();
  if($definition['access']==='admin'&&$s['risk_level']!=='admin')throw new RuntimeException('This action requires an Administrator enrollment. Existing account permissions will not be raised automatically.');
  if($definition['access']==='account'&&$s['access']!=='maintain')throw new RuntimeException('This action needs Account maintenance or Administrator access.');
  $lock=$this->lock();try{
   $this->systems->requireNoPlaybook();if($this->systems->query("SELECT id FROM system_runs WHERE status IN ('ready','working')"))throw new RuntimeException('Stop the active agent task before preparing a guided action.');
   if(count($this->systems->query('SELECT id FROM system_playbooks WHERE created_at>?',[time()-3600]))>=20)throw new RuntimeException('Guided actions are limited to 20 preparations per hour.');
   $id=bin2hex(random_bytes(16));$p=['binding'=>$this->binding($s),'prepared_at'=>microtime(true),'inventory'=>null,'parameters'=>[],'results'=>[],'inspection_partial'=>false,'root'=>$definition['access']==='admin'||($recipe==='inspect'&&$s['risk_level']==='admin'),'recipe'=>$recipe,'program_sha'=>hash('sha256',SystemJob::source())];
   if($aiReason!==null)$p['ai_reason']=$aiReason;
   if($s['access']==='inspect'){
    foreach(($recipe==='health'?['resources','services']:['system_info','network']) as $tool){$r=$this->ssh->execute($s,$this->systems->privateKey($system),SystemsAgent::INSPECT[$tool],$this->systems->directory,fn()=>!$this->systems->controlStillAllowed()||!$this->systems->system($system)['enabled']);$p['results'][]=['tool'=>$tool,'result'=>$r];if($r['exit_code']!==0||$r['truncated'])$p['inspection_partial']=true;if($r['timed_out']||$r['cancelled']||in_array($r['exit_code'],[-1,255],true))throw new RuntimeException('Read-only inspection is uncertain. No retry was made.');}
   }else{$p['inventory']=$this->decode($this->remote($s,['mode'=>'inventory','root'=>$p['root'],'token'=>$id,'recipe'=>$recipe]));if(($p['inventory']['format']??'')!=='AIcontrol VPS inventory v1')throw new RuntimeException('Unexpected server inventory.');}
   if($definition['access']==='admin'&&(!($p['inventory']['root']??false)||!in_array($p['inventory']['distribution']??'',['debian','ubuntu'],true)||!($p['inventory']['apt_available']??false)))throw new RuntimeException('This reviewed recipe supports Administrator access on Debian and Ubuntu with apt. Inspection remains available for other Linux servers.');
   if(in_array($recipe,['vpn','security_updates'],true)&&!($p['inventory']['systemd']??false))throw new RuntimeException('This recipe requires a systemd-based Debian or Ubuntu VPS.');
   if($recipe==='firewall'&&(($p['inventory']['existing_firewall']??true)||empty($p['inventory']['ssh_port'])))throw new RuntimeException('An existing firewall, container rules or unknown local SSH port needs manual review. No rules were changed.');
   if($recipe==='codex'){$arch=$p['inventory']['architecture']==='arm64'?'aarch64':$p['inventory']['architecture'];if(!isset(self::CODEX[$arch]))throw new RuntimeException('The verified Codex package supports x86_64 and aarch64 Linux.');$p['parameters']['asset']=['version'=>'0.171.0','url'=>'https://github.com/openai/codex/releases/download/rust-v0.171.0/codex-'.$arch.'-unknown-linux-musl.tar.gz','sha256'=>self::CODEX[$arch]];$p['parameters']['username']=$s['username'];}
   $read=$definition['read_only']??($definition['access']==='inspect');$sealed=(new Vault($this->systems->directory))->sealSystemRecord($system,$p);$this->systems->write('INSERT INTO system_playbooks(id,system_id,recipe,status,sealed,created_at,updated_at)VALUES(?,?,?,?,?,?,?)',[$id,$system,$recipe,$read?($p['inspection_partial']?'partial':'complete'):'review',$sealed,$p['prepared_at'],time()]);
   (new SystemNotebook($this->systems))->observe($system,$definition['title'],$read?($p['inspection_partial']?'partial observations':'observed'):'plan prepared',['inventory'=>$p['inventory'],'results'=>$p['results'],'limits'=>$recipe==='backup'?'Backup presence does not verify recoverability; confirm an off-server snapshot and restoration.':null]);return $id;
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public function start(string $id,int $system,string $confirmation,array $inputs):void{
  $lock=$this->lock();try{
   $this->systems->requireNoPlaybook();if($this->systems->query("SELECT id FROM system_runs WHERE status IN ('ready','working')"))throw new RuntimeException('Stop the active agent task first.');$r=$this->get($id,$system);$p=$r['data'];$s=$this->systems->system($system);if(!$s['enabled']||!$s['verified_at'])throw new RuntimeException('Verify an enabled SSH connection before applying this plan.');
   if($r['status']!=='review'||!hash_equals($p['program_sha']??'',hash('sha256',SystemJob::source()))||$p['prepared_at']<time()-600||!hash_equals($p['binding'],$this->binding($s)))throw new RuntimeException('This plan was used, expired or its connection changed. Prepare a fresh reviewed plan.');
   if(!hash_equals('APPLY '.$system,trim($confirmation))||($inputs['recovery_confirmed']??'')!=='1')throw new RuntimeException('Confirm access to your VPS provider recovery console and type APPLY '.$system.' before changing the server.');
   if($r['recipe']==='firewall'){
    $allow=$inputs['allow_ports']??[];if(!is_array($allow)||count($allow)>32)throw new RuntimeException('Choose up to 32 required service ports.');$allowed=[];foreach($p['inventory']['ports'] as $v)$allowed[$v['port'].'/'.$v['protocol']]=$v;$ports=[];foreach($allow as $key){if(!is_string($key)||!isset($allowed[$key]))throw new RuntimeException('Choose ports from the reviewed inventory.');$ports[]=$allowed[$key];}$p['parameters']['allow_ports']=$ports;
   }
   if($r['recipe']==='vpn'){
    $network=$inputs['vpn_network']??'';$key=$inputs['peer_public_key']??'';$port=$inputs['vpn_port']??'';
    if(!is_string($network)||!preg_match('~^(10\.(?:\d{1,3})\.(?:\d{1,3})\.0|192\.168\.\d{1,3}\.0|172\.(?:1[6-9]|2\d|3[01])\.\d{1,3}\.0)/24$~D',$network)||!filter_var(explode('/',$network)[0],FILTER_VALIDATE_IP)||!is_string($key)||strlen($key)!==44||strlen(base64_decode($key,true)?:'')!==32||!is_string($port)||!ctype_digit($port)||(int)$port<1024||(int)$port>65535)throw new RuntimeException('Provide a private /24 subnet, a valid WireGuard client public key, and an unused UDP port from 1024–65535.');$p['parameters']=['network'=>$network,'peer_public_key'=>$key,'listen_port'=>(int)$port,'allow_ufw'=>($inputs['allow_ufw']??'')==='1'];
   }
   $p['approved_at']=microtime(true);$this->store($id,$system,'dispatching',$p,true);
   $config=['token'=>$id,'recipe'=>$r['recipe'],'parameters'=>$p['parameters'],'inventory'=>$p['inventory'],'root'=>$p['root'],'username'=>$s['username']];$worker=SystemJob::program($config+['mode'=>'worker']);$config['program']=base64_encode($worker);$config['mode']='launch';
   try{$v=$this->decode($this->remote($s,$config));if(($v['token']??'')!==$id)throw new RuntimeException('Operation identity mismatch.');$p['result']=$v;$this->store($id,$system,'running',$p,true);(new SystemNotebook($this->systems))->observe($system,self::RECIPES[$r['recipe']]['title'],'started',['operation_id'=>$id]);}
   catch(\Throwable $e){$p['dispatch_uncertain']=true;$this->store($id,$system,'uncertain',$p,true);throw new RuntimeException('The start result is uncertain. Reconcile this same operation; it will never be launched again automatically.');}
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public function reconcile(string $id,int $system,bool $stop=false):void{
  $lock=$this->lock();try{$r=$this->get($id,$system);if(!$r['blocking'])return;$p=$r['data'];$s=$this->systems->system($system);$config=['mode'=>$stop?'stop':'status','token'=>$id,'recipe'=>$r['recipe'],'root'=>$p['root'],'parameters'=>$p['parameters']];
   try{$v=$this->decode($this->remote($s,$config));if(($v['token']??'')!==$id||($v['recipe']??'')!==$r['recipe'])throw new RuntimeException('Operation identity mismatch.');
    if(($v['status']??'')==='awaiting_confirmation'&&!$stop){$probe=$this->ssh->execute($s,$this->systems->privateKey($system),SystemsAgent::INSPECT['system_info'],$this->systems->directory,fn()=>!$this->systems->controlStillAllowed()||!$this->systems->system($system)['enabled']);if($probe['exit_code']!==0||$probe['timed_out']||$probe['cancelled'])throw new RuntimeException('Fresh SSH authentication was not confirmed. Firewall rollback remains armed.');$config['mode']='commit';$v=$this->decode($this->remote($s,$config));}
    if($stop&&($v['status']??'')==='awaiting_confirmation')$v['status']='running';
    $state=$v['status']??'';if(!in_array($state,['running','complete','failed','uncertain','stopped','rolled_back'],true))throw new RuntimeException('Unknown operation state.');$p['result']=$v;$blocking=in_array($state,['running','uncertain'],true);$this->store($id,$system,$state,$p,$blocking);
    if(!$blocking&&$r['status']!==$state)(new SystemNotebook($this->systems))->observe($system,self::RECIPES[$r['recipe']]['title'],$state,['operation_id'=>$id,'result'=>$v]);
   }catch(\Throwable $e){$p['reconcile_error']='Remote state remains unverified; no command was retried.';$this->store($id,$system,'uncertain',$p,true);throw new RuntimeException('Remote state is uncertain. Reconcile again after the current command or firewall rollback; no installation was repeated.');}
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public function release(string $id,int $system,string $confirmation):void{$lock=$this->lock();try{$r=$this->get($id,$system);if($r['status']!=='uncertain'||trim($confirmation)!=='INSPECTED '.$system)throw new RuntimeException('Inspect the server through recovery access, then type INSPECTED '.$system.'.');$p=$r['data'];$p['owner_closed_uncertain_at']=time();$this->store($id,$system,'uncertain',$p,false);(new SystemNotebook($this->systems))->observe($system,self::RECIPES[$r['recipe']]['title'],'uncertain; owner released lock',['operation_id'=>$id]);}finally{flock($lock,LOCK_UN);fclose($lock);}}
 public function history(int $system,?string $recipe=null):array{
  return $this->systems->query('SELECT id,recipe,status,blocking,created_at,updated_at FROM system_playbooks WHERE system_id=?'.($recipe===null?'':' AND recipe=?').' ORDER BY created_at DESC,rowid DESC LIMIT 30',$recipe===null?[$system]:[$system,$recipe]);
 }
 public function latestOutcomes(array $system,array $history):array
 {
  $outcomes=[];
  $binding=$this->binding($system);
  foreach($history as $row){
   if(isset($outcomes[$row['recipe']])||$row['status']==='review')continue;
   try{$operation=$this->get($row['id'],(int)$system['id']);}
   catch(\Throwable $e){Diagnostics::record('systems.action_history.unavailable',['system_id'=>(int)$system['id'],'operation_id'=>$row['id']],$e);continue;}
   if(($operation['data']['binding']??null)===$binding)$outcomes[$row['recipe']]=$operation;
  }
  return $outcomes;
 }
}
