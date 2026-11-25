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
  $wrong=$d;$wrong['challenge']=str_repeat('0',48);rejectSetup(fn()=>$setups->importDiscovery('',envelope($wrong,'discovery')));
  verifySetup(!$setups->get($e['id'])['system_id'],'Wrong challenge created a system');
  $saved=$setups->importDiscovery('',envelope($d,'discovery'));$s=$systems->system((int)$saved['system_id']);verifySetup($s['risk_level']===$risk,'Risk not saved');
  $systems->write('UPDATE system_enrollments SET created_at=? WHERE id=?',[time()-90000,$e['id']]);
  $again=$setups->importDiscovery('',envelope($d,'discovery'));
  verifySetup($saved['system_id']===$again['system_id']&&$setups->view($e['id'])['system']['public_key']===$s['public_key'],'Resuming saved discovery changed the system or key');
  $r=['format'=>'AIcontrol SSH receipt v1','enrollment_id'=>$e['id'],'risk_level'=>$risk,'username'=>$e['username'],'challenge'=>$e['receipt_challenge'],'machine_tag'=>$d['machine_tag'],'host_fingerprint'=>$fingerprint,'authorized_key_sha256'=>SystemEnrollment::fingerprint($s['public_key']),'uid'=>1001,'home_dir'=>$d['home_dir'],'sudo_granted'=>$risk==='admin','read_only_enforced'=>$risk==='readonly'];
  foreach(['challenge'=>'wrong','uid'=>0,'machine_tag'=>str_repeat('b',64),'authorized_key_sha256'=>$fingerprint] as $field=>$value){$bad=$r;$bad[$field]=$value;rejectSetup(fn()=>$setups->receipt($e['id'],envelope($bad,'receipt')));}
  $setups->receipt($e['id'],envelope($r,'receipt'));
  if($risk==='admin'){
   $denied=new class($host){
    public function __construct(private string $key){}
    public function probe(array $system):string{return $this->key;}
    public function execute(mixed ...$args):array{return ['exit_code'=>255,'elapsed_ms'=>123,'timed_out'=>false,'cancelled'=>false,'stdout'=>'SSH-PRIVATE-STDOUT','stderr'=>'Permission denied (publickey). SSH-PRIVATE-STDERR'];}
   };
   rejectSetup(fn()=>$systems->verify((int)$s['id'],$denied));
   $error=$systems->system((int)$s['id'])['error'];
   $log=AIcontrol\Diagnostics::report();
   verifySetup(str_contains($error,'rejected the connection key')&&str_contains($error,AIcontrol\Diagnostics::requestId()),'SSH rejection lacked actionable detail or reference');
   verifySetup(str_contains($log,'systems.ssh.verification_failed')&&str_contains($log,'public_key_rejected')&&str_contains($log,'"elapsed_ms":123')&&!str_contains($log,'SSH-PRIVATE-STD'),'SSH failure log lost typed details or retained raw output');
  }
  $console=new SystemTerminal($systems,$ssh);$t=$console->connect((int)$s['id'],'owner-one');rejectSetup(fn()=>$console->get($t['id'],'other-owner'));
  verifySetup($console->current((int)$s['id'],'owner-one')['id']===$t['id']&&$console->current((int)$s['id'],'other-owner')===null,'Console recovery escaped its owning sign-in');
  if($risk==='readonly'){rejectSetup(fn()=>$console->command($t['id'],'owner-one','touch /tmp/forbidden'));$result=$console->command($t['id'],'owner-one','whoami');}
  else{$result=$console->command($t['id'],'owner-one','cd /tmp && printf done');verifySetup($result['cwd']==='/tmp'&&$result['cwd_verified'],'Working directory was not confirmed');}
  verifySetup($result['command']['exit_code']===0&&!str_contains(json_encode($result),'PRIVATE KEY'),'Missing or unsafe console result');$console->disconnect($t['id'],'owner-one');rejectSetup(fn()=>$console->get($t['id'],'owner-one'));
  verifySetup($console->current((int)$s['id'],'owner-one')===null,'Disconnected console was recovered on navigation');
  $systems->revoke((int)$s['id']);$systems->purge((int)$s['id'],'DELETE SYSTEM '.$s['id']);
 }
 $expired=$setups->prepare('admin');$systems->write('UPDATE system_enrollments SET created_at=? WHERE id=?',[time()-90000,$expired['id']]);
 rejectSetup(fn()=>$setups->get($expired['id']));
 rejectSetup(fn()=>SystemEnrollment::parse('partial reply','receipt'));echo "PASS: risk-bound enrollment, saved setup recovery, key reuse, redacted SSH failure logs, console scope, read-only rejection and cleanup (synthetic SSH)\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
