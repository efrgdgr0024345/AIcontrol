<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Vault.php';
require_once __DIR__.'/../src/AgentRunner.php';
require_once __DIR__.'/../src/SystemsAgent.php';

use AIcontrol\Systems;
use AIcontrol\SystemsAgent;

$dir=sys_get_temp_dir().'/aicontrol-systems-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('AICONTROL_PRIVATE_DIR='.$dir);
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function rejects(callable $f):void{try{$f();}catch(Throwable){return;}throw new RuntimeException('Expected rejection.');}
$ssh=new class {
    public array $commands=[];public mixed $onExecute=null;
    public function probe(array $s):string{return 'ssh-ed25519 fixture';}
    public function execute(array $s,string $private,string $command,string $dir,callable $cancel):array{$this->commands[]=$command;if($this->onExecute)($this->onExecute)();return ['stdout'=>'Linux fixture','stderr'=>'','exit_code'=>0,'timed_out'=>false,'cancelled'=>$cancel(),'truncated'=>false,'elapsed_ms'=>1];}
};
try {
    $s=new Systems($dir);$id=$s->add('Linux fixture','192.0.2.10','22','aicontrol','inspect','');
    $key=$s->privateKey($id);check(!str_contains(file_get_contents($dir.'/vault.json'),'OPENSSH'),'Credential stored in plaintext');
    $script=$s->script($id);check(str_contains($script,'restrict ssh-ed25519')&&str_contains($script,"useradd --password '*'")&&!str_contains($script,$key),'Invalid setup script or secret leaked');
    rejects(fn()=>$s->add('Bad','-oProxyCommand=bad','22','root','inspect',''));rejects(fn()=>$s->add('Bad','example.com','0','root','inspect',''));rejects(fn()=>$s->pin($id,'SHA256:bad'));
    $s->pin($id,'SHA256:'.str_repeat('a',43));$s->verify($id,$ssh);check((bool)$s->system($id)['verified_at'],'Connection check not saved');
    rejects(fn()=>$s->pin($id,'SHA256:'.str_repeat('b',43)));
    $calls=0;$agent=new SystemsAgent($s,$ssh,function(array $body)use(&$calls,$key){check(!str_contains(json_encode($body),'BEGIN OPENSSH PRIVATE KEY'),'Key sent to model');$calls++;return $calls===1?['tool'=>'resources']:['tool'=>'finish','summary'=>'Observed fixture resources.'];});
    $run=$agent->start($id,'Inspect resources');rejects(fn()=>$agent->start($id,'Concurrent task'));$agent->step($run);check($s->run($run)['status']==='ready','Successful command did not advance');check(count($s->query('SELECT * FROM system_commands WHERE run_id=?',[$run]))===1,'Missing command result');$agent->step($run);check($s->run($run)['status']==='complete','Summary not saved');
    $bad=new SystemsAgent($s,$ssh,fn()=>['tool'=>'shell','command'=>'touch /tmp/forbidden']);$r=$bad->start($id,'Inspect');$before=count($ssh->commands);$bad->step($r);check(count($ssh->commands)===$before&&$s->run($r)['status']==='outcome_uncertain','Inspection bypassed by shell tool');
    $stopRun=0;$stop=new SystemsAgent($s,$ssh,function()use($s,&$stopRun){$s->stop($stopRun);return ['tool'=>'resources'];});$stopRun=$stop->start($id,'Stop race');$stop->step($stopRun);check(count($ssh->commands)===$before&&$s->run($stopRun)['status']==='stopped','Late provider decision executed after Stop');
    $during=new SystemsAgent($s,$ssh,fn()=>['tool'=>'resources']);$r=$during->start($id,'Stop during SSH');$ssh->onExecute=fn()=>$s->stop($r);$during->step($r);check($s->run($r)['status']==='stopped'&&$s->query('SELECT status FROM system_commands WHERE run_id=?',[$r])[0]['status']==='outcome_uncertain','Interrupted remote execution claimed success');$ssh->onExecute=null;
    $r=$agent->start($id,'Lost worker');$s->write("UPDATE system_runs SET status='working' WHERE id=?",[$r]);$before=count($ssh->commands);$agent->step($r);check($s->run($r)['status']==='outcome_uncertain'&&count($ssh->commands)===$before,'Lost work retried');
    $r=$during->start($id,'Bounded task');$s->write('UPDATE system_runs SET rounds=8 WHERE id=?',[$r]);$during->step($r);check($s->run($r)['status']==='limit_reached','Decision bound ignored');
    $snapshot=$s->snapshot($id,$run);check(!str_contains(json_encode($snapshot),'BEGIN OPENSSH PRIVATE KEY'),'Private key in display payload');
    rejects(fn()=>$s->purge($id,'DELETE SYSTEM '.$id));
    $s->revoke($id);check(!$s->system($id)['enabled'],'Disconnect failed');rejects(fn()=>$s->privateKey($id));rejects(fn()=>$s->verify($id,$ssh));
    rejects(fn()=>$s->purge($id,'wrong confirmation'));$s->purge($id,'DELETE SYSTEM '.$id);rejects(fn()=>$s->system($id));check(!$s->query('SELECT id FROM system_runs')&&!$s->query('SELECT id FROM system_commands')&&!$s->query('SELECT id FROM system_messages'),'Deletion left saved task data');
    echo "PASS: Systems validation, encrypted keys/scripts, pinning, scoped tools, command evidence, Stop races, lost-step refusal, bounds and revoke (synthetic transports)\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
