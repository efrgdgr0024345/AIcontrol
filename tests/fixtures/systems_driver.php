<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../../src/Vault.php';require __DIR__.'/../../src/AgentRunner.php';require __DIR__.'/../../src/SystemsAgent.php';
use AIcontrol\Systems;use AIcontrol\SystemsAgent;
$s=new Systems(getenv('AICONTROL_PRIVATE_DIR'));$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
try {
 switch($input['action']){
 case 'add':$id=$s->add('SSH integration','127.0.0.1',(string)$input['port'],'aicontrol',$input['access']??'inspect',$input['fingerprint']);$result=$s->system($id);break;
 case 'verify':$s->verify($input['id']);$result=$s->system($input['id']);break;
 case 'start':$agent=new SystemsAgent($s,null,fn()=>['tool'=>'finish','summary'=>'Fixture']);$result=['id'=>$agent->start($input['id'],$input['task']??'Inspect selected SSH fixture')];break;
 case 'step':$agent=new SystemsAgent($s,null,fn(array $body)=>$input['decision']);$agent->step($input['run']);$result=$s->snapshot($input['id'],$input['run']);break;
 case 'stop':$s->stop($input['run']);$result=$s->run($input['run']);break;
 case 'revoke':$s->revoke($input['id']);$result=$s->system($input['id']);break;
 default:throw new RuntimeException('Fixture action unavailable.');
 }
 echo json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(1);}
