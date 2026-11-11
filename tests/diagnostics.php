<?php
declare(strict_types=1);
require __DIR__.'/../src/Vault.php';require __DIR__.'/../src/AgentRunner.php';require_once __DIR__.'/../src/Diagnostics.php';
use AIcontrol\Diagnostics;
$dir=sys_get_temp_dir().'/aicontrol-diagnostics-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('AICONTROL_PRIVATE_DIR='.$dir);putenv('AICONTROL_OPENAI_API_KEY=private-env-fixture');
try {
    (new AIcontrol\Vault($dir))->change('private','note','PRIVATE-VAULT-FIXTURE',false);
    (new AIcontrol\Vault($dir))->change('escaped','note',"PRIVATE/value\nquoted\"note",false);
    Diagnostics::record('test.escaped',['message'=>"PRIVATE/value\nquoted\"note"]);
    Diagnostics::record('test.error',['message'=>'private-env-fixture PRIVATE-VAULT-FIXTURE Bearer abc sk-secret-fixture'],new RuntimeException('PRIVATE-VAULT-FIXTURE'));
    $report=Diagnostics::report();
    foreach (['private-env-fixture','PRIVATE-VAULT-FIXTURE','Bearer abc','sk-secret-fixture','PRIVATE/value','PRIVATE\\/value',$dir] as $secret) if(str_contains($report,$secret))throw new RuntimeException('Redaction failed');
    if (!str_contains($report,'test.error')||!str_contains($report,'request_id')||!str_contains($report,'RuntimeException'))throw new RuntimeException('Missing context');
    foreach(file($dir.'/diagnostics.jsonl') as $line)json_decode($line,true,32,JSON_THROW_ON_ERROR);
    if((fileperms($dir.'/diagnostics.jsonl')&0777)!==0600)throw new RuntimeException('Log permissions');
    for($i=0;$i<120;$i++)Diagnostics::record('test.rotation',['message'=>str_repeat('x',10000)]);
    clearstatcache();if(filesize($dir.'/diagnostics.jsonl')>1061000)throw new RuntimeException('Log unbounded');
    foreach(file($dir.'/diagnostics.jsonl') as $line)json_decode($line,true,32,JSON_THROW_ON_ERROR);
    echo "PASS: diagnostic redaction, exception context, JSON lines, private permissions and bounded retention\n";
} finally {foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
