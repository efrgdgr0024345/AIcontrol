<?php
declare(strict_types=1);
namespace AIcontrol {
    final class BriefWire {public static array $options=[];public static int $status=200;public static bool $ok=true;public static int $calls=0;public static mixed $during=null;public static bool $partial=false;}
    function curl_init(string $url):object{if($url!=='https://api.openai.com/v1/responses')throw new \RuntimeException('Wrong URL');return new \stdClass();}
    function curl_setopt_array(object $c,array $options):bool{BriefWire::$options=$options;return true;}
    function curl_exec(object $c):bool{
        BriefWire::$calls++;$write=BriefWire::$options[CURLOPT_WRITEFUNCTION];
        $write($c,"data: ".json_encode(['type'=>'response.output_text.delta','delta'=>"Does local evidence support this claim?\n"])."\n\n");
        if(BriefWire::$during)(BriefWire::$during)();
        if(BriefWire::$partial)return false;
        $result=['id'=>'resp-brief','model'=>'gpt-5.4-nano','status'=>'completed','usage'=>['input_tokens'=>100,'output_tokens'=>50],
            'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>"Does local evidence support this claim?\nWhat independent data could disprove it?\nWhich assumptions matter for a Perth household?"]]]]];
        $write($c,"data: ".json_encode(['type'=>'response.output_text.delta','delta'=>"What independent data could disprove it?\nWhich assumptions matter for a Perth household?"])."\n\n");
        $write($c,"data: ".json_encode(['type'=>'response.completed','response'=>$result])."\n\n");return BriefWire::$ok;
    }
    function curl_getinfo(object $c,int $option):int{return BriefWire::$status;}
    function curl_close(object $c):void{}
}
namespace {
require __DIR__.'/../src/PanelStore.php';require __DIR__.'/../src/Vault.php';require __DIR__.'/../src/AgentRunner.php';require __DIR__.'/../src/Research.php';require __DIR__.'/../src/BriefSuggestions.php';
umask(0077);$dir=sys_get_temp_dir().'/aicontrol-brief-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('AICONTROL_PRIVATE_DIR='.$dir);putenv('AICONTROL_OPENAI_API_KEY=synthetic-brief-secret');putenv('AICONTROL_OPENAI_MODEL=gpt-4.1');
$checks=0;function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function blocked(callable $work,string $label):void{$denied=false;try{$work();}catch(RuntimeException){$denied=true;}check($denied,$label);}
try {
 $research=new AIcontrol\Research($dir);$project=$research->create('Existing research','Preserve this question');$engine=new AIcontrol\Investigation($dir);$helper=new AIcontrol\BriefSuggestions($dir);$store=new AIcontrol\PanelStore($dir.'/panel.sqlite',true);$taskProject=$store->createProject('Task fixture','Synthetic');$task=$store->createTask($taskProject,'Task','Review only');$db=new PDO('sqlite:'.$dir.'/research.sqlite');$tables=$db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
 (new AIcontrol\Vault($dir))->change('shared','note','DO-NOT-SEND-SHARED-NOTES',true);
 blocked(fn()=>$helper->suggest('tiny','',fn()=>null),'Tiny draft accepted');blocked(fn()=>$helper->suggest(str_repeat('é',1100),'',fn()=>null),'Oversize accepted');blocked(fn()=>$helper->suggest("invalid\xfftext draft here",'',fn()=>null),'Invalid UTF-8 accepted');
 putenv('AICONTROL_OPENAI_API_KEY=');blocked(fn()=>$helper->suggest('A valid research question?','',fn()=>null),'Missing credential accepted');putenv('AICONTROL_OPENAI_API_KEY=synthetic-brief-secret');
 AIcontrol\BriefWire::$during=function()use($helper,$engine,$research,$project,$store,$task):void {blocked(fn()=>$helper->suggest('Another valid question?','',fn()=>null),'Overlapping suggestions accepted');blocked(fn()=>$engine->createRun($project),'Investigation overlapped suggestion');blocked(fn()=>$research->chat($project,'Chat must wait',false),'Chat overlapped suggestion');blocked(fn()=>AIcontrol\AgentRunner::run($store,$task),'Task overlapped suggestion');};
 $events=[];$done=$helper->suggest('Does rooftop solar save Perth households money?','Solar',function($event,$data)use(&$events):void{$events[]=[$event,$data];});
 $body=json_decode(AIcontrol\BriefWire::$options[CURLOPT_POSTFIELDS],true);
 check($body['model']==='gpt-5.4-nano'&&$body['max_output_tokens']===240&&$body['reasoning']['effort']==='none'&&$body['stream']===true&&!isset($body['tools']),'Model or bounds');check(!str_contains(json_encode($body),'synthetic-brief-secret')&&!str_contains(json_encode($body),'DO-NOT-SEND'),'Prompt disclosure');
 check(abs($done['usage']['estimated_usd']-0.0000825)<1e-12,'Nano pricing incorrect');check(AIcontrol\UsageMeter::rates('gpt-5.4-nano-2026-03-17')['output_per_million']===1.25,'Snapshot not priced');check(AIcontrol\UsageMeter::rates('gpt-5.4-nano-2025-04-14')===null,'Unknown nano snapshot priced');
 check(array_column($events,0)===['start','delta','delta','done'],'Not incremental streaming');check(count($done['suggestions'])===3&&$done['usage']['input_tokens']===100&&$done['usage']['estimated_usd']>0,'Output or metering lost');
 check((int)$db->query('SELECT COUNT(*) FROM research_runs')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM research_messages')->fetchColumn()===0,'Suggestion created research records');check($tables===$db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN),'Suggestion added DB table');
 $journal=json_decode(file_get_contents($dir.'/brief-suggestions.json'),true);check($journal[0]['status']==='complete'&&(fileperms($dir.'/brief-suggestions.json')&0777)===0600,'Journal privacy/state');check(!str_contains(file_get_contents($dir.'/brief-suggestions.json'),'Does rooftop solar'),'Draft persisted');
 AIcontrol\BriefWire::$during=null;$taskRun=$store->startAgent($task,'gpt-4.1-mini');blocked(fn()=>$helper->suggest('Task is active so suggestions must wait?','',fn()=>null),'Active task ignored');$store->finishAgent($taskRun['id'],'failed');$run=$engine->createRun($project);blocked(fn()=>$helper->suggest('A different valid question?','',fn()=>null),'Running investigation ignored');$engine->stop($run);
 $db->exec("INSERT INTO research_messages(project_id,role,text,status,created_at)VALUES($project,'assistant','','pending',".time().")");blocked(fn()=>$helper->suggest('Chat is pending research?','',fn()=>null),'Pending chat ignored');$db->exec('DELETE FROM research_messages');
 AIcontrol\BriefWire::$partial=true;blocked(fn()=>$helper->suggest('Will this timeout remain uncertain?','',fn()=>null),'Transport error accepted');$journal=json_decode(file_get_contents($dir.'/brief-suggestions.json'),true);check(end($journal)['status']==='uncertain'&&end($journal)['reserved_usd']>0,'Unknown usage discarded');$calls=AIcontrol\BriefWire::$calls;blocked(fn()=>$helper->suggest('Will this timeout remain uncertain?','',fn()=>null),'Unknown request repeated');check($calls===AIcontrol\BriefWire::$calls,'Unknown request sent twice');AIcontrol\BriefWire::$partial=false;
 $saved=file_get_contents($dir.'/brief-suggestions.json');$rows=array_fill(0,20,['created_at'=>time(),'status'=>'complete','estimated_usd'=>0,'reserved_usd'=>0,'draft_hash'=>'x']);file_put_contents($dir.'/brief-suggestions.json',json_encode($rows));blocked(fn()=>$helper->suggest('Rate limit should prevent this call?','',fn()=>null),'Hourly ceiling ignored');
 file_put_contents($dir.'/brief-suggestions.json',json_encode([['created_at'=>time(),'status'=>'uncertain','estimated_usd'=>null,'reserved_usd'=>.01,'draft_hash'=>'x']]));blocked(fn()=>$helper->suggest('Cost cap should prevent this call?','',fn()=>null),'Spend cap ignored');
 file_put_contents($dir.'/brief-suggestions.json',$saved);check($engine->requestBudget()['used']===0,'Writing help used investigation request budget');
 echo "PASS: $checks brief validation, forced nano, streaming, shared admission, usage, privacy, hourly/cost caps and uncertainty checks\n";
} finally {foreach(glob($dir.'/*') as $f)if(is_file($f))unlink($f);rmdir($dir);}
}
