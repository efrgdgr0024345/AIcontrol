<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Vault.php';require_once __DIR__.'/../src/AgentRunner.php';require_once __DIR__.'/../src/SystemsAgent.php';require_once __DIR__.'/../src/SystemEnrollment.php';require_once __DIR__.'/../src/SystemTerminal.php';
use AIcontrol\Systems;use AIcontrol\SystemEnrollment;use AIcontrol\SystemBootstrap;use AIcontrol\SystemTerminal;
$dir=sys_get_temp_dir().'/aicontrol-setup-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('AICONTROL_PRIVATE_DIR='.$dir);
function verifySetup(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function rejectSetup(callable $f):void{try{$f();}catch(Throwable){return;}throw new RuntimeException('Expected setup rejection');}
function envelope(array $data,string $kind):string{return '---BEGIN AICONTROL '.strtoupper($kind).' V1---'."\n".json_encode($data)."\n".'---END AICONTROL '.strtoupper($kind).' V1---';}
try{
 $systems=new Systems($dir);$setups=new SystemEnrollment($systems);$host=Systems::keypair()['public'];$fingerprint=SystemEnrollment::fingerprint($host);rejectSetup(fn()=>$setups->prepare('unknown'));
 $ssh=new class($host){public function __construct(private string $key){}public function probe(array $s):string{return $this->key;}public function execute(array $s,string $private,string $command,string $dir,callable $cancel):array{$stdout='Linux fixture';if(preg_match('/AICTRL_CWD_[a-f0-9]+:/',$command,$m))$stdout="done\n".$m[0].base64_encode('/tmp')."\n";return ['stdout'=>$stdout,'stderr'=>'','exit_code'=>0,'timed_out'=>false,'cancelled'=>false,'truncated'=>false,'elapsed_ms'=>1];}};
 foreach(['readonly','account','admin'] as $risk){
  $e=$setups->prepare($risk);$command=SystemBootstrap::command(SystemBootstrap::script($e,'discovery'));verifySetup(!str_contains($command,"\n")&&!str_contains($command,'PRIVATE KEY'),'Command is not a public-only single line');
  $d=['format'=>'AIcontrol SSH discovery v1','enrollment_id'=>$e['id'],'risk_level'=>$risk,'username'=>$e['username'],'challenge'=>$e['challenge'],'machine_tag'=>str_repeat('a',64),'hostname'=>'Fixture Linux','connection_host'=>'192.0.2.10','ssh_port'=>2222,'host_key'=>$host,'host_fingerprint'=>$fingerprint,'uid'=>1001,'home_dir'=>'/var/lib/aicontrol/homes/'.$e['username'],'sudo_granted'=>$risk==='admin','addresses'=>['192.0.2.10']];
  $bad=$d;$bad['risk_level']=$risk==='admin'?'readonly':'admin';rejectSetup(fn()=>$setups->importDiscovery($e['id'],envelope($bad,'discovery')));
  $saved=$setups->importDiscovery($e['id'],envelope($d,'discovery'));$again=$setups->importDiscovery($e['id'],envelope($d,'discovery'));verifySetup($saved['system_id']===$again['system_id'],'Duplicate paste generated another key');$s=$systems->system((int)$saved['system_id']);verifySetup($s['risk_level']===$risk,'Risk not saved');
  $r=['format'=>'AIcontrol SSH receipt v1','enrollment_id'=>$e['id'],'risk_level'=>$risk,'username'=>$e['username'],'challenge'=>$e['receipt_challenge'],'machine_tag'=>$d['machine_tag'],'host_fingerprint'=>$fingerprint,'authorized_key_sha256'=>SystemEnrollment::fingerprint($s['public_key']),'uid'=>1001,'home_dir'=>$d['home_dir'],'sudo_granted'=>$risk==='admin','read_only_enforced'=>$risk==='readonly'];
  foreach(['challenge'=>'wrong','uid'=>0,'machine_tag'=>str_repeat('b',64),'authorized_key_sha256'=>$fingerprint] as $field=>$value){$bad=$r;$bad[$field]=$value;rejectSetup(fn()=>$setups->receipt($e['id'],envelope($bad,'receipt')));}
  $setups->receipt($e['id'],envelope($r,'receipt'));
  $console=new SystemTerminal($systems,$ssh);$t=$console->connect((int)$s['id'],'owner-one');rejectSetup(fn()=>$console->get($t['id'],'other-owner'));
  if($risk==='readonly'){rejectSetup(fn()=>$console->command($t['id'],'owner-one','touch /tmp/forbidden'));$result=$console->command($t['id'],'owner-one','whoami');}
  else{$result=$console->command($t['id'],'owner-one','cd /tmp && printf done');verifySetup($result['cwd']==='/tmp'&&$result['cwd_verified'],'Working directory was not confirmed');}
  verifySetup($result['command']['exit_code']===0&&!str_contains(json_encode($result),'PRIVATE KEY'),'Missing or unsafe console result');$console->disconnect($t['id'],'owner-one');rejectSetup(fn()=>$console->get($t['id'],'owner-one'));
  $systems->revoke((int)$s['id']);$systems->purge((int)$s['id'],'DELETE SYSTEM '.$s['id']);
 }
 rejectSetup(fn()=>SystemEnrollment::parse('partial reply','receipt'));echo "PASS: risk-bound two-paste enrollment, replay/key identity, all account levels, console session scope, read-only rejection, cwd confirmation and cleanup (synthetic SSH)\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
