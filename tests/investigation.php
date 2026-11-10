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
umask(0077);$checks=0;$dirs=[];$failures=[];
function verify(bool $condition,string $message):void{global $checks,$failures;if(!$condition){$failures[]=$message;throw new \RuntimeException($message);}$checks++;}
function denied(callable $work,string $label):void{$denied=false;try{$work();}catch(\RuntimeException){$denied=true;}verify($denied,$label);}
function evidenceCards(array $body):array{return json_decode(explode("\nUser question/understanding: ",explode('Collected evidence: ',$body['input'])[1])[0],true,32,JSON_THROW_ON_ERROR);}
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
  if($phase==='dream') {verify(!isset($body['tools'])&&str_contains($body['instructions'],'unchecked'),'Dream tools/label');return reply(json_encode(['core_claims'=>[['priority'=>'high','claim'=>'Solar may save money','uncertainty'=>'Location matters','confidence'=>'medium','verify'=>'Compare tariffs'],['priority'=>'normal','claim'=>'Payback varies','uncertainty'=>'Capital cost','confidence'=>'low','verify'=>'Find independent costs']],'competing_angles'=>['Export tariffs may offset savings'],'least_confident'=>'Local installation cost','background_assumptions'=>['Owner can install panels']]));}
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
 $snapshot=$engine->step($run);
 verify($snapshot['run']['phase']==='dream_review','Dream did not pause for review');
 $before=count(\AIcontrol\InvestigationWire::$bodies);$engine->step($run);verify(count(\AIcontrol\InvestigationWire::$bodies)===$before,'Review spent provider budget');
 $engine->continueRun($run);
 for($i=0;$i<4;$i++)$snapshot=$engine->step($run);
 verify($snapshot['run']['status']==='complete','Investigation not complete');
 verify(count($snapshot['sources'])===1&&$snapshot['sources'][0]['url']==='https://example.com/solar','Invalid or missing source');
 verify(count(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='dead_end'))===1,'Dead end disappeared');
 verify((int)$snapshot['run']['token_input']===50&&(int)$snapshot['run']['token_output']===100,'Token counts incorrect');
 verify(count(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='synthesis'))===3,'Reconciliation missing');
 verify(str_contains($engine->export($run),'https://example.com/solar')&&str_contains($engine->export($run),'dead_end'),'Export missing evidence/dead ends');
 \AIcontrol\InvestigationWire::$handler=function(array $body):array {verify(!isset($body['tools']),'Learn enabled search');verify(in_array('https://example.com/solar',array_column(evidenceCards($body),'url'),true)&&!str_contains($body['input'],'OPTED-IN-CONTEXT'),'Learn evidence scope');return reply('Explanation based on the collected study.');};
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
 // Strict rich prior validation must not leave partially accepted claim nodes.
 $valid=['core_claims'=>[['claim'=>'Concrete first claim','confidence'=>'medium','uncertainty'=>'Scope','verify'=>'Find measured evidence','priority'=>'high'],['claim'=>'Competing second claim','confidence'=>'low','uncertainty'=>'Cost','verify'=>'Compare independent costs','priority'=>'normal']],'competing_angles'=>['Alternative explanation'],'least_confident'=>'Local applicability','background_assumptions'=>['Comparable setting']];
 $invalid=[];$bad=$valid;$bad['core_claims'][1]['confidence']='certain';$invalid[]=$bad;
 $bad=$valid;$bad['core_claims'][1]['claim']=' ';$invalid[]=$bad;
 $bad=$valid;$bad['core_claims']=array_fill(0,6,$valid['core_claims'][0]);$invalid[]=$bad;
 $bad=$valid;$bad['core_claims']=[$valid['core_claims'][0]];$invalid[]=$bad;
 $bad=$valid;$bad['competing_angles']=array_fill(0,4,'angle');$invalid[]=$bad;
 $bad=$valid;$bad['least_confident']=str_repeat('x',3001);$invalid[]=$bad;
 foreach($invalid as $bad){
  [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);
  \AIcontrol\InvestigationWire::$handler=fn($body)=>reply(json_encode($bad));
  $snapshot=$engine->step($run);verify($snapshot['run']['status']==='incomplete','Invalid rich dream accepted');
  verify(!array_filter($snapshot['nodes'],fn($n)=>$n['type']==='dream_claim'),'Partial invalid prior persisted');
 }
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);
 \AIcontrol\InvestigationWire::$handler=fn($body)=>reply(json_encode($valid));
 $snapshot=$engine->step($run);$claims=array_values(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='dream_claim'));
 verify(count($claims)===2&&\AIcontrol\Investigation::claimData($claims[0])['priority']==='high','Rich claim metadata lost');
 denied(fn()=>$engine->continueRun($run,[]),'Empty selection accepted');
 denied(fn()=>$engine->continueRun($run,[(int)$claims[0]['id'],(int)$claims[0]['id']]),'Duplicate selection accepted');
 denied(fn()=>$engine->continueRun($run,[99999]),'Foreign selection accepted');
 denied(fn()=>$engine->continueRun($run,[(string)$claims[0]['id']]),'Noninteger selection accepted');
 $selected=(int)$claims[1]['id'];$excluded=(int)$claims[0]['id'];$engine->continueRun($run,[$selected]);
 denied(fn()=>$engine->continueRun($run,[$selected]),'Repeated Continue accepted');
 \AIcontrol\InvestigationWire::$handler=function($body)use($selected,$excluded){
  $nodes=json_decode(explode("Dream nodes: ",$body['input'])[1],true);
  verify(count($nodes)===1&&(int)$nodes[0]['id']===$selected,'Plan received excluded claim');
  return reply(json_encode(['steps'=>[['claim_id'=>$excluded,'query'=>'Must reject excluded claim']]]));
 };
 verify($engine->step($run)['run']['status']==='incomplete','Plan accepted excluded claim');
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);\AIcontrol\InvestigationWire::$handler=fn($body)=>reply(json_encode($valid));$engine->step($run);$engine->stop($run);
 denied(fn()=>$engine->continueRun($run),'Continue revived stopped review');
 verify($engine->getRunSnapshot($run)['run']['status']==='interrupted','Review Stop lost');
 // Selected claims retain their ancestry through a complete no-source investigation.
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);\AIcontrol\InvestigationWire::$handler=fn($body)=>reply(json_encode($valid));
 $snapshot=$engine->step($run);$claims=array_values(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='dream_claim'));$chosen=(int)$claims[1]['id'];
 $engine->continueRun($run,[$chosen]);
 \AIcontrol\InvestigationWire::$handler=function($body)use($chosen){
  if(str_contains($body['instructions'],'Plan phase'))return reply(json_encode(['steps'=>[['claim_id'=>$chosen,'query'=>'Check chosen claim']]]));
  if(isset($body['tools']))return reply('No useful citation found.',[['type'=>'web_search_call','status'=>'completed']]);
  return reply(json_encode(['claims'=>[['claim_id'=>$chosen,'verdict'=>'open','reason'=>'No useful source supports the claim.','source_ids'=>[]]],'open_questions'=>[]]));
 };
 for($i=0;$i<3;$i++)$snapshot=$engine->step($run);
 verify($snapshot['run']['status']==='complete','Selected-only run did not finish');
 $outcomes=array_values(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='synthesis'));
 verify(count($outcomes)===1&&(int)$outcomes[0]['parent_id']===$chosen,'Outcome attached to excluded claim');
 verify(count(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='dead_end'))===1,'Selected dead end lost');
 // A citation shared by two searches remains attached to both claims without duplicating source records.
 $engine->addSource($run,$chosen,'https://example.com/shared','Shared study','Synthetic summary',['claim_id'=>$chosen]);
 $engine->addSource($run,(int)$claims[0]['id'],'https://example.com/shared','Shared study','Synthetic summary',['claim_id'=>(int)$claims[0]['id']]);
 $snapshot=$engine->getRunSnapshot($run);$meta=json_decode($snapshot['sources'][0]['meta_json'],true);
 verify(count($snapshot['sources'])===1&&count($meta['claim_ids'])===2,'Shared citation lost claim relevance');
 // Old plain-text claims remain readable after the additive migration.
 $legacy=\AIcontrol\Investigation::claimData(['title'=>'Legacy prior','content'=>"Confidence: low\nUncertainty: Scope\nVerify: Check sources"]);
 verify($legacy['confidence']==='low'&&$legacy['verify']==='Check sources','Legacy claim rendering lost');
 [$dir,$engine,$old,$db]=fixture();
 verify($engine->requestBudget()['remaining']===10,'Fresh budget incorrect');
 $db->exec("INSERT INTO research_messages(project_id,role,text,status,created_at)VALUES(1,'assistant','Fixture','complete',".(time()-30).")");
 verify($engine->requestBudget()['remaining']===9,'Legacy chat absent from visible budget');
 $run=$engine->createRun(1);$node=$engine->appendNode($run,null,'phase','complete','Dream');
 for($i=0;$i<9;$i++)$db->exec("INSERT INTO research_requests(run_id,node_id,kind,status,created_at) VALUES($run,$node,'dream','complete',".time().")");
 $budget=$engine->requestBudget();verify($budget['remaining']===0&&$budget['retry_after_seconds']>3500,'Exhausted budget/recovery time incorrect');
 $db->exec('UPDATE research_requests SET created_at='.(time()-3601));
 verify($engine->requestBudget()['remaining']===9,'Expired requests still consume visible budget');
 $report=$engine->export($run);verify(str_contains($report,'Claims, evidence and outcomes')&&str_contains($report,'Open questions and learning notes'),'Notebook report headings missing');
 // Focus consumes an existing Evidence slot and preserves attribution, bounds and Stop.
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);\AIcontrol\InvestigationWire::$handler=fn($body)=>reply(json_encode($valid));
 $snapshot=$engine->step($run);$claims=array_values(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='dream_claim'));
 foreach(['confidence','linked_claim_ids','source_ids','dead_end_reason','sort_order'] as $key)verify(array_key_exists($key,$claims[0]),'Map contract missing '.$key);
 $first=(int)$claims[0]['id'];$second=(int)$claims[1]['id'];
 denied(fn()=>$engine->step($run,$first),'Focus bypassed review');$engine->continueRun($run);
 \AIcontrol\InvestigationWire::$handler=fn($body)=>reply(json_encode(['steps'=>[['claim_id'=>$first,'query'=>'Original angle one'],['claim_id'=>$first,'query'=>'Original angle two']]]));$engine->step($run);
 denied(fn()=>$engine->step($run,99999),'Foreign focus accepted');denied(fn()=>$engine->step($run,$second,str_repeat('x',1001)),'Oversized note accepted');
 \AIcontrol\InvestigationWire::$handler=function($body)use($second){
  verify($body['max_tool_calls']===1&&$body['max_output_tokens']===1600,'Focused search expanded bounds');
  verify(str_contains($body['input'],'counter-evidence')&&!str_contains($body['input'],'Original angle one'),'Focus did not replace original query');
  $r=reply('Synthetic focused summary',[['type'=>'web_search_call','status'=>'completed']]);$r['output'][1]['content'][0]['annotations']=[['type'=>'url_citation','url'=>'https://example.com/focused','title'=>'Focused study']];return $r;
 };
 $snapshot=$engine->step($run,$second,'counter-evidence');$searches=array_values(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='search'));
 verify(count($searches)===1&&(int)$searches[0]['parent_id']===$second,'Focused branch parent incorrect');
 verify(json_decode($searches[0]['data_json'],true)['note']==='counter-evidence','Focus note not recorded');
 verify((int)$snapshot['run']['evidence_index']===1&&count(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='plan_step'))===2,'Focus added search slots');
 verify($snapshot['sources'][0]['url']==='https://example.com/focused'&&$searches[0]['linked_claim_ids']===[$second],'Focused source/claim contract incorrect');
 $plan=array_values(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='plan_step'))[0];verify($plan['status']==='incomplete'&&str_contains($plan['content'],'not executed'),'Redirected plan falsely marked verified');
 \AIcontrol\InvestigationWire::$handler=function($body)use($engine,$run){$engine->stop($run);return reply('Late focused answer',[['type'=>'web_search_call','status'=>'completed']]);};
 $snapshot=$engine->step($run,$first,'primary sources');
 verify($snapshot['run']['status']==='interrupted'&&count(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='search'))===1,'Late focused reply overwrote Stop');
 denied(fn()=>$engine->step($run,$first),'Focus revived stopped run');
 $source=(int)$snapshot['sources'][0]['id'];$engine->addSource($run,$second,'https://example.com/not-selected','Unselected card','OTHER-CARD-CONTEXT',['claim_id'=>$second]);
 denied(fn()=>$engine->learn($run,'explain','',[]),'Empty source selection accepted');
 denied(fn()=>$engine->learn($run,'explain','',[9999]),'Foreign source accepted');
 denied(fn()=>$engine->learn($run,'explain','',[$source,$source]),'Duplicate source accepted');
 \AIcontrol\InvestigationWire::$handler=function($body){verify(!isset($body['tools'])&&!str_contains($body['input'],'OTHER-CARD-CONTEXT')&&!str_contains($body['input'],'Unchecked prior')&&!str_contains($body['input'],'OPTED-IN-CONTEXT')&&(array_column(evidenceCards($body),'url')===['https://example.com/focused']),'Selected Learn scope leaked');return reply('Selected evidence explanation');};
 $snapshot=$engine->learn($run,'explain','',[$source]);verify(end($snapshot['nodes'])['status']==='complete','Selected Learn not complete');verify(end($snapshot['nodes'])['source_ids']===[$source],'Selected Learn attribution lost');
 verify(str_contains($engine->export($run),'Map outline (node → parent)'),'Map outline missing from export');
 // No focus may bypass a locked worker, excluded claim, spent slot or hourly cap.
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);\AIcontrol\InvestigationWire::$handler=fn($body)=>reply(json_encode($valid));
 $snapshot=$engine->step($run);$claims=array_values(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='dream_claim'));$first=(int)$claims[0]['id'];$second=(int)$claims[1]['id'];$engine->continueRun($run,[$first]);
 \AIcontrol\InvestigationWire::$handler=fn($body)=>reply(json_encode(['steps'=>[['claim_id'=>$first,'query'=>'One bounded query']]]));$engine->step($run);
 denied(fn()=>$engine->step($run,$second),'Excluded focus accepted');
 $db->exec("UPDATE research_runs SET busy='locked',busy_since=".time()." WHERE id=$run");denied(fn()=>$engine->step($run,$first),'Busy focus accepted');$db->exec("UPDATE research_runs SET busy=NULL,busy_since=NULL,evidence_index=2 WHERE id=$run");
 denied(fn()=>$engine->step($run,$first),'Spent slot accepted');$db->exec("UPDATE research_runs SET evidence_index=0 WHERE id=$run");
 $node=(int)$snapshot['nodes'][0]['id'];for($i=2;$i<10;$i++)$db->exec("INSERT INTO research_requests(run_id,node_id,kind,status,created_at) VALUES($run,$node,'learn','complete',".time().")");
 $calls=count(\AIcontrol\InvestigationWire::$bodies);$snapshot=$engine->step($run,$first);
 verify($snapshot['run']['status']==='incomplete'&&count(\AIcontrol\InvestigationWire::$bodies)===$calls,'Focus bypassed hourly budget');
 if($failures)throw new \RuntimeException("Fixture assertion failures: ".implode("; ",$failures));
 echo "PASS: $checks investigation migration, phase, budget, evidence, Learn, Stop race and uncertainty checks (synthetic transport only)\n";
} finally {foreach($dirs as $dir){foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}}
}
