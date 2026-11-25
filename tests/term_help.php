<?php
declare(strict_types=1);
// Synthetic Responses transport: no external provider requests.
namespace AIcontrol {
    final class TermWire {
        public static array $options=[];
        public static int $calls=0;
        public static int $status=200;
        public static bool $ok=true;
        public static mixed $during=null;
        public static string $text='A counterfactual asks what would happen if one condition were different. For example, compare repair costs with and without shared tools.';
    }
    function curl_init(string $url):object { if($url!=='https://api.openai.com/v1/responses')throw new \RuntimeException('Wrong URL');return new \stdClass(); }
    function curl_setopt_array(object $h,array $options):bool {TermWire::$options=$options;return true;}
    function curl_exec(object $h):bool {
        TermWire::$calls++;
        if(TermWire::$during)(TermWire::$during)();
        if(!TermWire::$ok)return false;
        $result=TermWire::$status===200
            ?['id'=>'resp-term-fixture','model'=>'gpt-5.4-nano','status'=>'completed','usage'=>['input_tokens'=>100,'output_tokens'=>50],'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>TermWire::$text]]]]]
            :['error'=>['code'=>'invalid_api_key','message'=>'Synthetic rejection']];
        (TermWire::$options[CURLOPT_WRITEFUNCTION])($h,json_encode($result));return true;
    }
    function curl_getinfo(object $h,int $option):int {return TermWire::$status;}
}
namespace {
require __DIR__.'/../src/PanelStore.php';require __DIR__.'/../src/Vault.php';require __DIR__.'/../src/AgentRunner.php';require __DIR__.'/../src/TermExplainer.php';require __DIR__.'/../src/AdminData.php';require __DIR__.'/../src/ResearchHandover.php';
use AIcontrol\{Research,Investigation,TermExplainer,TermHelpError,TermWire,ProviderGate,AdminData,ResearchHandover};
umask(0077);$dir=sys_get_temp_dir().'/aicontrol-term-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('AICONTROL_PRIVATE_DIR='.$dir);putenv('AICONTROL_OPENAI_API_KEY=synthetic-term-secret');$checks=0;
function check(bool $v,string $why):void {global $checks;$checks++;if(!$v)throw new RuntimeException($why);}
function blocked(callable $work,string $why,?int $status=null):void {try{$work();}catch(RuntimeException $e){check($status===null||($e instanceof TermHelpError&&$e->status===$status),$why.': '.$e->getMessage());return;}throw new RuntimeException($why);}
try {
    new AIcontrol\PanelStore($dir.'/panel.sqlite',true);
    $r=new Research($dir);$project=$r->create('Repair systems','Use counterfactual analysis and Bayesian inference to compare shared tools.');$other=$r->create('PRIVATE OTHER PROJECT','NEVER SEND OTHER BRIEF');
    $engine=new Investigation($dir);$run=$engine->createRun($project);$engine->stop($run);
    $db=new PDO('sqlite:'.$dir.'/research.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA foreign_keys=ON');
    $q=$db->prepare("INSERT INTO research_nodes(run_id,type,status,title,content,created_at,updated_at)VALUES(?,'model_idea','complete','Counterfactual pathways',?,1,1)");
    $content=str_repeat('Context before. ',100).'A counterfactual changes an assumption. Bayesian inference updates beliefs. Heuristics guide choices. Entropy measures uncertainty. '.str_repeat('TAIL-NOT-SENT ',100);$q->execute([$run,$content]);$node=(int)$db->lastInsertId();
    (new AIcontrol\Vault($dir))->change('private_test','note','DO-NOT-SEND-VAULT',true);
    $helper=new TermExplainer($dir);new Research($dir); // Repeated migration is idempotent.
    $original=$db->query("SELECT * FROM research_nodes WHERE id=$node")->fetch(PDO::FETCH_ASSOC);
    blocked(fn()=>$helper->explain($other,'node',$node,'counterfactual'),'Cross-project source accepted',404);
    blocked(fn()=>$helper->explain($project,'node',$node,'not-in-the-source'),'Absent term accepted',422);
    blocked(fn()=>$helper->explain($project,'node',$node,'counterfact'),'Partial word accepted',422);
    blocked(fn()=>$helper->explain($project,'node',$node,"bad\xff"),'Invalid UTF-8 accepted',422);
    blocked(fn()=>$helper->explain($project,'node',$node,'  '),'Whitespace term accepted',422);
    check(TermWire::$calls===0,'Validation dispatched a provider request');
    TermWire::$during=function()use($helper,$project,$node):void{blocked(fn()=>$helper->explain($project,'node',$node,'heuristics'),'Concurrent provider dispatch admitted');};
    $reply=$helper->explain($project,'node',$node,'counterfactual');TermWire::$during=null;
    $body=json_decode(TermWire::$options[CURLOPT_POSTFIELDS],true);$input=json_decode($body['input'],true);
    check($reply['cached']===false&&$body['model']==='gpt-5.4-nano'&&$body['max_output_tokens']===160&&$body['reasoning']['effort']==='none'&&!isset($body['tools'])&&$body['store']===false,'Model, privacy or token bound changed');
    check(mb_strlen($input['excerpt'])<=700&&!str_contains($input['excerpt'],'TAIL-NOT-SENT'),'Excerpt exceeds context bound');
    check(!str_contains(json_encode($body),'DO-NOT-SEND')&&!str_contains(json_encode($body),'synthetic-term-secret')&&!str_contains(json_encode($body),'NEVER SEND'),'Unrelated or secret context disclosed');
    check(TermWire::$calls===1&&abs($helper->usage()['estimated_usd']-.0000825)<1e-12&&$helper->usage()['reserved_usd']===0.0,'Usage or initial call count incorrect');
    putenv('AICONTROL_OPENAI_API_KEY=');
    ProviderGate::run($dir,function()use($helper,$project,$node):void{check($helper->explain($project,'node',$node,'COUNTERFACTUAL')['cached']===true,'Saved cache needs key or provider lock');});
    check(TermWire::$calls===1,'Saved cache made paid request');
    blocked(fn()=>$helper->explain($project,'node',$node,'heuristics'),'Missing key dispatched',503);putenv('AICONTROL_OPENAI_API_KEY=synthetic-term-secret');
    check($original===$db->query("SELECT * FROM research_nodes WHERE id=$node")->fetch(PDO::FETCH_ASSOC),'Explanation changed original output');
    $db->prepare('UPDATE research_nodes SET content=content||?,updated_at=2 WHERE id=?')->execute([' New source context.',$node]);$changed=$helper->explain($project,'node',$node,'counterfactual');check(!$changed['cached']&&$changed['source_sha']!==$reply['source_sha']&&TermWire::$calls===2,'Changed source reused stale definition');
    TermWire::$ok=false;blocked(fn()=>$helper->explain($project,'node',$node,'heuristics'),'Timeout succeeded',502);$calls=TermWire::$calls;
    blocked(fn()=>$helper->explain($project,'node',$node,'heuristics'),'Unknown request automatically repeated',409);check(TermWire::$calls===$calls&&$helper->usage()['reserved_usd']>0,'Uncertain request lost cost reservation');
    TermWire::$ok=true;$retry=$helper->explain($project,'node',$node,'heuristics',true);check(!$retry['cached']&&TermWire::$calls===$calls+1&&$helper->usage()['reserved_usd']>0,'Explicit retry hid earlier unknown costs');
    TermWire::$status=401;blocked(fn()=>$helper->explain($project,'node',$node,'entropy'),'Rejection succeeded',502);$last=$db->query('SELECT * FROM research_term_usage ORDER BY rowid DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);check($last['status']==='failed'&&(float)$last['reserved_usd']===0.0&&(float)$last['estimated_usd']===0.0,'Confirmed rejection retained paid reservation');TermWire::$status=200;
    TermWire::$during=fn()=>$db->exec("UPDATE research_nodes SET content=content||' changed during call' WHERE id=$node");$before=(int)$db->query('SELECT COUNT(*) FROM research_term_explanations')->fetchColumn();blocked(fn()=>$helper->explain($project,'node',$node,'Bayesian inference'),'Stale response was saved',409);check((int)$db->query('SELECT COUNT(*) FROM research_term_explanations')->fetchColumn()===$before,'Changed-context output entered cache');TermWire::$during=null;
    $brief=$helper->explain($project,'brief',0,'Bayesian inference');$data=ResearchHandover::data($dir,$project);check(count($data['saved_tables']['research_term_explanations'])===$before+1&&count($data['saved_tables']['research_term_usage'])===$helper->usage()['requests'],'Export omitted learning aids or costs');check(!str_contains(json_encode(ResearchHandover::data($dir,$other)),'counterfactual'),'Export leaked another project learning aids');
    // Exhaust quota using metadata, then prove a saved definition still needs no call.
    $saved=$db->query('SELECT * FROM research_term_usage')->fetchAll(PDO::FETCH_ASSOC);$q=$db->prepare("INSERT INTO research_term_usage(id,project_id,cache_key,status,model,created_at,estimated_usd)VALUES(?,?,?,'complete',?,?,0)");for($i=count($saved);$i<30;$i++)$q->execute(['cap-'.$i,$project,'cap-'.$i,TermExplainer::MODEL,time()]);
    blocked(fn()=>$helper->explain($project,'brief',0,'counterfactual'),'Request ceiling ignored',429);check($helper->explain($project,'brief',0,'Bayesian inference')['cached'],'Quota blocked saved definition');
    $db->exec("DELETE FROM research_term_usage WHERE id LIKE 'cap-%'; UPDATE research_term_usage SET reserved_usd=.01 WHERE status='uncertain'");blocked(fn()=>$helper->explain($project,'brief',0,'counterfactual'),'Cost ceiling ignored',429);
    $admin=new AdminData($dir);$admin->change('reset_research_project',$project,AdminData::token('reset_research_project',$project));check((int)$db->query('SELECT COUNT(*) FROM research_term_explanations')->fetchColumn()===0,'Reset retained learning text');check($helper->usage()['requests']===count($saved),'Reset erased usage allowance history');
    $admin->change('delete_research_project',$project,AdminData::token('delete_research_project',$project));check((int)$db->query('SELECT COUNT(*) FROM research_term_usage WHERE project_id IS NOT NULL')->fetchColumn()===0,'Delete did not detach retained cost metadata');check(!$db->query('PRAGMA foreign_key_check')->fetchAll(),'New tables have broken references');
    echo "PASS: $checks term-source scope, nano bounds, privacy, cache, shared admission, usage/reservations, retry, limits, source integrity, export and cleanup checks\n";
} finally {foreach(glob($dir.'/*') as $f)if(is_file($f))unlink($f);rmdir($dir);}
}
