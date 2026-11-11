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
    $summary=Diagnostics::apiReturn(['id'=>'resp-fixture','status'=>'incomplete','model'=>'gpt-4.1-mini',
        'usage'=>['input_tokens'=>120,'output_tokens'=>45,'output_tokens_details'=>['reasoning_tokens'=>5]],
        'error'=>['code'=>'rate_limit_exceeded','message'=>'Please wait'],'incomplete_details'=>['reason'=>'max_output_tokens'],
        'instructions'=>'PRIVATE PROMPT','metadata'=>['private'=>'hidden'],
        'output'=>[['type'=>'web_search_call','status'=>'completed','action'=>['query'=>'Perth solar','sources'=>[['url'=>'https://example.com/source']]]],
                   ['type'=>'message','content'=>[['type'=>'output_text','text'=>str_repeat('NOISY-PROSE',2000),'annotations'=>[['type'=>'url_citation','url'=>'https://example.com/source']]]]]]]);
    $encoded=json_encode($summary,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    foreach(['resp-fixture','rate_limit_exceeded','reasoning_tokens','max_output_tokens','web_search_call','https://example.com/source','sha256'] as $fact)if(!str_contains($encoded,$fact))throw new RuntimeException('Missing API fact '.$fact);
    foreach(['PRIVATE PROMPT','NOISY-PROSE'] as $noise)if(str_contains($encoded,$noise))throw new RuntimeException('Private/noisy API block retained');
    if(strlen($encoded)>4000)throw new RuntimeException('API summary too noisy');
    Diagnostics::record('test.api',['api_return'=>$summary]);
    require_once __DIR__.'/../src/ResponseStream.php';
    $stream=new AIcontrol\ResponseStream(static function():void{});
    $stream->push("data: ".json_encode(['type'=>'error','code'=>'server_error','message'=>'Stream failed'])."\n\n");
    $streamSummary=$stream->diagnostics();
    if(($streamSummary['event_counts']['error']??0)!==1||!str_contains(json_encode($streamSummary),'server_error'))throw new RuntimeException('Stream failure facts missing');
    echo "PASS: diagnostic redaction, exception context, JSON lines, private permissions, bounded retention and compact API returns\n";
} finally {foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
