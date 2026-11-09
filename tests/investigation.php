<?php
declare(strict_types=1);
namespace AIcontrol {
    final class InvestigationWire {
        public static array $bodies=[];
        public static array $options=[];
        public static mixed $handler=null;
        public static int $status=200;
        public static bool $ok=true;
    }
    function curl_init(string $url):object { if($url!=='https://api.openai.com/v1/responses')throw new \RuntimeException('Wrong provider');return new \stdClass(); }
    function curl_setopt_array(object $c,array $options):bool { InvestigationWire::$options=$options;return true; }
    function curl_exec(object $c):bool {
        $body=json_decode(InvestigationWire::$options[CURLOPT_POSTFIELDS],true);InvestigationWire::$bodies[]=$body;
        $reply=(InvestigationWire::$handler)($body);
        (InvestigationWire::$options[CURLOPT_WRITEFUNCTION])($c,json_encode($reply,JSON_THROW_ON_ERROR));return InvestigationWire::$ok;
    }
    function curl_getinfo(object $c,int $option):int{return InvestigationWire::$status;}
    function curl_close(object $c):void{}
}
namespace {
require dirname(__DIR__).'/src/Vault.php';require dirname(__DIR__).'/src/AgentRunner.php';require dirname(__DIR__).'/src/Research.php';
umask(0077);$checks=0;$dirs=[];
function verify(bool $condition,string $message):void{global $checks;if(!$condition)throw new \RuntimeException($message);$checks++;}
function denied(callable $work,string $label):void{$denied=false;try{$work();}catch(\RuntimeException){$denied=true;}verify($denied,$label);}
function fixture():array {
 global $dirs;$dir=sys_get_temp_dir().'/aicontrol-investigate-'.bin2hex(random_bytes(7));mkdir($dir,0700);$dirs[]=$dir;
 putenv('AICONTROL_PRIVATE_DIR='.$dir);putenv('AICONTROL_OPENAI_API_KEY=synthetic-test-only');
 $old=new \AIcontrol\Research($dir);$old->create('Research fixture','Check household solar claims');
 $vault=new \AIcontrol\Vault($dir);$vault->change('shared','note','OPTED-IN-CONTEXT',true);$vault->change('hidden','note','NEVER-SEND-THIS',false);
 return [$dir,new \AIcontrol\Investigation($dir),$old,new \PDO('sqlite:'.$dir.'/research.sqlite')];
}
function reply(string $text,array $extra=[]):array{return ['status'=>'completed','usage'=>['input_tokens'=>10,'output_tokens'=>20],'output'=>array_merge($extra,[['type'=>'message','content'=>[['type'=>'output_text','text'=>$text]]]])];}
try {
 [$dir,$engine,$old,$db]=fixture();
 verify(count($old->snapshot()['projects'])===1,'Legacy project lost');
 $db->exec("INSERT INTO research_messages(project_id,role,text,status,created_at)VALUES(1,'user','Old conversation','saved',1)");
 new \AIcontrol\Research($dir);verify(count($old->snapshot()['messages'])===1,'Migration lost conversation');
 putenv('AICONTROL_OPENAI_API_KEY=');denied(fn()=>$engine->createRun(1),'Missing key accepted');putenv('AICONTROL_OPENAI_API_KEY=synthetic-test-only');
 $run=$engine->createRun(1);denied(fn()=>$engine->createRun(1),'Concurrent run accepted');denied(fn()=>$old->chat(1,'Legacy must wait',false),'Legacy bypassed active run');
 \AIcontrol\InvestigationWire::$handler=function(array $body)use($engine,$run):array {
  verify($body['store']===false&&$body['max_output_tokens']===1600,'Request caps');verify(!str_contains(json_encode($body),'synthetic-test-only')&&!str_contains(json_encode($body),'NEVER-SEND-THIS'),'Secret leaked');
  $snapshot=$engine->getRunSnapshot($run);$phase=$snapshot['run']['phase'];
  if($phase==='dream') {verify(!isset($body['tools'])&&str_contains($body['instructions'],'unchecked'),'Dream tools/label');return reply(json_encode(['claims'=>[['claim'=>'Solar may save money','uncertainty'=>'Location matters','confidence'=>'medium','verify'=>'Compare tariffs'],['claim'=>'Payback varies','uncertainty'=>'Capital cost','confidence'=>'low','verify'=>'Find independent costs']]]));}
  $claims=array_values(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='dream_claim'));
  if($phase==='plan')return reply(json_encode(['steps'=>[['claim_id'=>(int)$claims[0]['id'],'query'=>'solar tariff studies'],['claim_id'=>(int)$claims[1]['id'],'query'=>'independent capital costs']]]));
  if($phase==='evidence'){
   verify($body['max_tool_calls']===1&&count($body['tools'])===1,'Search bounds');
   if((int)$snapshot['run']['evidence_index']===1)return reply('No useful cited result.',[['type'=>'web_search_call','status'=>'completed']]);
   $r=reply('Provider summary: measured solar results vary.',[['type'=>'web_search_call','status'=>'completed']]);
   $r['output'][1]['content'][0]['annotations']=[['type'=>'url_citation','url'=>'https://example.com/solar','title'=>'Solar study'],['type'=>'url_citation','url'=>'javascript:alert(1)','title'=>'Unsafe']];return $r;
  }
  verify(!isset($body['tools']),'Reconciliation can search');
  return reply(json_encode(['claims'=>[['claim_id'=>(int)$claims[0]['id'],'verdict'=>'supported','reason'=>'Within the cited study scope','source_ids'=>[(int)$snapshot['sources'][0]['id']]],['claim_id'=>(int)$claims[1]['id'],'verdict'=>'open','reason'=>'No useful source','source_ids'=>[]]],'open_questions'=>['Which local tariff applies?']]));
 };
 for($i=0;$i<5;$i++)$snapshot=$engine->step($run);
 verify($snapshot['run']['status']==='complete','Investigation not complete');
 verify(count($snapshot['sources'])===1&&$snapshot['sources'][0]['url']==='https://example.com/solar','Invalid or missing source');
 verify(count(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='dead_end'))===1,'Dead end disappeared');
 verify((int)$snapshot['run']['token_input']===50&&(int)$snapshot['run']['token_output']===100,'Token counts incorrect');
 verify(count(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='synthesis'))===3,'Reconciliation missing');
 verify(str_contains($engine->export($run),'https://example.com/solar')&&str_contains($engine->export($run),'dead_end'),'Export missing evidence/dead ends');
 \AIcontrol\InvestigationWire::$handler=function(array $body):array {verify(!isset($body['tools']),'Learn enabled search');verify(str_contains($body['input'],'https://example.com/solar')&&!str_contains($body['input'],'OPTED-IN-CONTEXT'),'Learn evidence scope');return reply('Explanation based on the collected study.');};
 $snapshot=$engine->learn($run,'compare','What differs?');verify(end($snapshot['nodes'])['type']==='learn','Learn not persisted');
 $requests=(int)$db->query('SELECT COUNT(*) FROM research_requests')->fetchColumn();
 for($i=$requests;$i<10;$i++)$db->exec("INSERT INTO research_requests(run_id,node_id,kind,status,created_at) VALUES($run,1,'learn','complete',".time().")");
 denied(fn()=>$engine->createRun(1),'Hourly provider cap bypassed');denied(fn()=>$old->chat(1,'Legacy cap',false),'Legacy bypassed shared cap');
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);
 \AIcontrol\InvestigationWire::$handler=function(array $body)use($dir,$run):array{(new \AIcontrol\Investigation($dir))->stop($run);return reply('{"claims":[{"claim":"late","uncertainty":"unknown","confidence":"low","verify":"search"}]}');};
 $snapshot=$engine->step($run);verify($snapshot['run']['status']==='interrupted','Stop lost to late result');verify(!array_filter($snapshot['nodes'],fn($n)=>$n['type']==='dream_claim'),'Late answer applied');verify($snapshot['requests'][0]['status']==='uncertain'&&(int)$snapshot['run']['usage_unknown']===1,'Stopped outcome not uncertain');
 $calls=count(\AIcontrol\InvestigationWire::$bodies);$engine->step($run);verify(count(\AIcontrol\InvestigationWire::$bodies)===$calls,'Stopped run retried');
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);$engine->stop($run);verify($engine->getRunSnapshot($run)['run']['status']==='interrupted','Idle Stop failed');
 denied(fn()=>$engine->learn($run,'explain',''),'Learn accepted missing evidence');
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);\AIcontrol\InvestigationWire::$ok=false;\AIcontrol\InvestigationWire::$handler=fn($body)=>reply('Unknown outcome');
 $snapshot=$engine->step($run);verify($snapshot['run']['status']==='uncertain','Transport uncertainty claimed success');$engine->step($run);verify(count($engine->getRunSnapshot($run)['requests'])===1,'Uncertain phase retried');\AIcontrol\InvestigationWire::$ok=true;
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);\AIcontrol\InvestigationWire::$handler=fn($body)=>reply('{"claims":[]}');verify($engine->step($run)['run']['status']==='incomplete','Malformed output complete');
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);$db->exec("UPDATE research_runs SET busy='orphan',busy_since=".(time()-130)." WHERE id=$run");verify($engine->getRunSnapshot($run)['run']['status']==='uncertain','Stale worker not surfaced');
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);\AIcontrol\InvestigationWire::$status=429;\AIcontrol\InvestigationWire::$handler=fn($body)=>reply('No response');verify($engine->step($run)['run']['status']==='failed','HTTP refusal claimed success');\AIcontrol\InvestigationWire::$status=200;
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);\AIcontrol\InvestigationWire::$handler=function($body){$r=reply('Partial output');$r['status']='incomplete';return $r;};verify($engine->step($run)['run']['status']==='incomplete','Truncated provider result claimed success');
 verify(!\AIcontrol\Investigation::sourceUrl('https://user:pass@example.com')&&!\AIcontrol\Investigation::sourceUrl('data:text/html,test'),'Unsafe source URL');
 echo "PASS: $checks investigation migration, phase, budget, evidence, Learn, Stop race and uncertainty checks (synthetic transport only)\n";
} finally {foreach($dirs as $dir){foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}}
}
