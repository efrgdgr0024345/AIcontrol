<?php
declare(strict_types=1);if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../../src/Vault.php';require __DIR__.'/../../src/AgentRunner.php';require __DIR__.'/../../src/SystemsAgent.php';require __DIR__.'/../../src/SystemEnrollment.php';
$risk=$argv[1]??'readonly';$level=AIcontrol\SystemRisk::level($risk);$id=bin2hex(random_bytes(16));$e=['id'=>$id,'challenge'=>str_repeat('a',48),'receipt_challenge'=>str_repeat('b',48),'risk_level'=>$risk,'username'=>$level['prefix'].substr($id,0,12),'discovery_json'=>json_encode(['machine_tag'=>hash('sha256','fixture-machine'.$id)])];
$host=AIcontrol\Systems::keypair()['public'];$s=['id'=>1,'public_key'=>AIcontrol\Systems::keypair()['public'],'fingerprint'=>AIcontrol\SystemEnrollment::fingerprint($host)];
$first=AIcontrol\SystemBootstrap::script($e,'discovery');$second=AIcontrol\SystemBootstrap::script($e,'receipt',$s);
echo json_encode(['enrollment'=>$e,'host_key'=>$host,'first'=>$first,'second'=>$second,'command1'=>AIcontrol\SystemBootstrap::command($first),'command2'=>AIcontrol\SystemBootstrap::command($second)],JSON_THROW_ON_ERROR);
