<?php
declare(strict_types=1);
namespace AIcontrol {
    final class ResearchTransport { public static array $options=[]; public static int $status=200; public static bool $ok=true; public static string $reply=''; }
    function curl_init(string $url): object { if($url!=='https://api.openai.com/v1/responses')throw new \RuntimeException('Wrong endpoint');return new \stdClass(); }
    function curl_setopt_array(object $c,array $options):bool { ResearchTransport::$options=$options;return true; }
    function curl_exec(object $c):bool { (ResearchTransport::$options[CURLOPT_WRITEFUNCTION])($c,ResearchTransport::$reply);return ResearchTransport::$ok; }
    function curl_getinfo(object $c,int $option):int {return ResearchTransport::$status;}
    function curl_close(object $c):void {}
}
namespace {
require dirname(__DIR__).'/src/Vault.php';require dirname(__DIR__).'/src/AgentRunner.php';require dirname(__DIR__).'/src/Research.php';
umask(0077);$dir=sys_get_temp_dir().'/aicontrol-workspace-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('AICONTROL_PRIVATE_DIR='.$dir);putenv('AICONTROL_OPENAI_API_KEY=');
function check(bool $ok,string $message):void{if(!$ok)throw new \RuntimeException($message);}
try {
 $vault=new \AIcontrol\Vault($dir);$vault->change('openai_api_key','key','synthetic-only-secret',true);$vault->change('shared_note','note','Useful project context',true);$vault->change('private_note','note','Never share this text',false);
 check(!str_contains(file_get_contents($dir.'/vault.json'),'synthetic-only-secret'),'Plaintext storage');
 check(!str_contains(json_encode($vault->entries()),'synthetic-only-secret'),'Secret metadata leak');
 check(\AIcontrol\AgentRunner::apiKey()==='synthetic-only-secret','Stored key not used');
 check(str_contains($vault->context(),'Useful project context')&&!str_contains($vault->context(),'Never share')&&!str_contains($vault->context(),'synthetic-only-secret'),'Context permissions');
 check((fileperms($dir.'/vault.key')&0777)===0600,'Key permissions');
 $before=file_get_contents($dir.'/vault.json');file_put_contents($dir.'/vault.json','damaged');$blocked=false;try{$vault->change('test','note','replacement',false);}catch(\Throwable){$blocked=true;}check($blocked&&file_get_contents($dir.'/vault.json')==='damaged','Corruption overwritten');file_put_contents($dir.'/vault.json',$before);
 rename($dir.'/vault.key',$dir.'/saved.key');$blocked=false;try{$vault->value('openai_api_key');}catch(\Throwable){$blocked=true;}check($blocked&&!file_exists($dir.'/vault.key'),'Missing key regenerated');rename($dir.'/saved.key',$dir.'/vault.key');
 $research=new \AIcontrol\Research($dir);$research->create('Solar research','Compare household options');$research->create('Separate project','Keep context apart');
 \AIcontrol\ResearchTransport::$reply='{"status":"completed","output":[{"content":[{"type":"output_text","text":"Fixture research answer","annotations":[{"type":"url_citation","url":"https://example.com/source"}]}]}]}';
 $research->chat(1,'Compare options',true);$data=$research->snapshot();check(count($data['messages'])===2&&$data['messages'][1]['status']==='complete','Conversation not saved');check(str_contains($data['messages'][1]['text'],'https://example.com/source'),'Citation lost');
 $request=json_decode(\AIcontrol\ResearchTransport::$options[CURLOPT_POSTFIELDS],true);check($request['store']===false&&$request['max_output_tokens']===1600&&$request['max_tool_calls']===1,'Research bounds');check(str_contains($request['instructions'],'Useful project context')&&!str_contains(json_encode($request),'synthetic-only-secret')&&!str_contains(json_encode($request),'Never share'),'Prompt disclosure');
 $research->chat(2,'Different topic',false);$request=json_decode(\AIcontrol\ResearchTransport::$options[CURLOPT_POSTFIELDS],true);check(count($request['input'])===1&&!isset($request['tools']),'Project isolation or web opt-out');
 \AIcontrol\ResearchTransport::$ok=false;$research->chat(1,'Timeout test',false);check($research->snapshot()['messages'][5]['status']==='uncertain','Uncertain outcome mislabeled');
 $vault->change('openai_api_key','key','',false,true);check(!\AIcontrol\AgentRunner::configured(),'Key removal failed');
 echo "PASS: encrypted vault, tamper/key-loss refusal, scoped notes, research isolation, web request, citations and uncertain outcomes (fixture transport only)\n";
} finally { foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir); }
}
