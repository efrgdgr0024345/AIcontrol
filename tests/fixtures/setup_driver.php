<?php
declare(strict_types=1);if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../../src/Vault.php';require __DIR__.'/../../src/AgentRunner.php';require __DIR__.'/../../src/SystemsAgent.php';require __DIR__.'/../../src/SystemEnrollment.php';require __DIR__.'/../../src/SystemTerminal.php';
$s=new AIcontrol\Systems(getenv('AICONTROL_PRIVATE_DIR'));$_SESSION=[];$guard=new AIcontrol\SystemGuard($s);if($guard->configured())$guard->unlock('synthetic separate systems passphrase');$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$setups=new AIcontrol\SystemEnrollment($s);
$ssh=new class {
 public function probe(array $system):string{$db=new AIcontrol\Systems(getenv('AICONTROL_PRIVATE_DIR'));$e=(new AIcontrol\SystemEnrollment($db))->get($system['enrollment_id']);return json_decode($e['discovery_json'],true)['host_key'];}
 public function execute(array $s,string $key,string $cmd,string $dir,callable $cancel):array{$out='Synthetic Linux UI fixture';if(preg_match('/AICTRL_CWD_[a-f0-9]+:/',$cmd,$m))$out='<script>window.CONSOLE_PWNED=1</script>'."\n".$m[0].base64_encode('/tmp')."\n";return ['stdout'=>$out,'stderr'=>'','exit_code'=>0,'timed_out'=>false,'cancelled'=>false,'truncated'=>false,'elapsed_ms'=>1];}
};
if($input['action']==='metadata'){$e=$setups->get($input['enrollment']);echo json_encode(['enrollment'=>$e,'system'=>$e['system_id']?$s->system($e['system_id']):null,'host_key'=>AIcontrol\Systems::keypair()['public']]);}
elseif($input['action']==='connect'){$system=$setups->receipt($input['enrollment'],$input['receipt']);echo json_encode((new AIcontrol\SystemTerminal($s,$ssh))->connect($system['id'],$input['owner']));}
elseif($input['action']==='command')echo json_encode((new AIcontrol\SystemTerminal($s,$ssh))->command($input['terminal'],$input['owner'],$input['command']));
