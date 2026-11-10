<?php
declare(strict_types=1);
require __DIR__.'/investigation_fixtures.php';
require dirname(__DIR__).'/src/ResearchApi.php';
require dirname(__DIR__).'/src/ResearchView.php';
require dirname(__DIR__).'/src/Ui.php';

function mapFixture(bool $legacy=false):array
{
    [$dir,$engine,$old,$db]=fixture();$run=$legacy?legacyRun($engine,$db):$engine->createRun(1);
    $claim=$engine->appendNode($run,null,'dream_claim','complete','Selected concept','Unchecked prior.');
    $excluded=$engine->appendNode($run,null,'dream_claim','complete','Excluded concept','Unchecked prior.');
    $question=$engine->appendNode($run,$claim,'question','complete','Testable question','What would falsify this concept?');
    $dead=$engine->appendNode($run,$question,'dead_end','dead_end','Unresolved question','No usable source.');
    $open=$engine->appendNode($run,$claim,'synthesis','complete','Open assessment','Missing evidence.');
    $closed=$engine->appendNode($run,$claim,'synthesis','complete','Open-looking label','Saved verdict controls eligibility.');
    $unlinked=$engine->appendNode($run,null,'question','complete','Unlinked question','No selected claim owner.');
    $hidden=$engine->appendNode($run,$excluded,'dead_end','dead_end','Excluded dead end','No usable source.');
    $plan=$engine->appendNode($run,$claim,'plan_step','pending','Verify concept','Original query must be replaced.');
    $db->exec("UPDATE research_runs SET phase='evidence',growth_remaining=0 WHERE id=$run");
    $db->exec("UPDATE research_nodes SET selected=0 WHERE id=$excluded");
    $write=$db->prepare('UPDATE research_nodes SET data_json=? WHERE id=?');
    $write->execute(['{"verdict":"open"}',$open]);$write->execute(['{"verdict":"supported"}',$closed]);
    return [$dir,$engine,$db,$run,compact('claim','excluded','question','dead','open','closed','unlinked','hidden','plan')];
}
function mapRejectsUnchanged($engine,int $run,callable $work,string $label):void
{
    $before=$engine->getRunSnapshot($run);$calls=count(\AIcontrol\InvestigationWire::$bodies);
    denied($work,$label);$after=$engine->getRunSnapshot($run);
    verify($after['nodes']===$before['nodes']&&$after['requests']===$before['requests']
        &&$after['run']===$before['run']&&count(\AIcontrol\InvestigationWire::$bodies)===$calls,$label.' mutated the run or sent a request');
}
try {
    // Every eligible real target retains the chosen branch and selected claim in the saved snapshot.
    foreach(['claim','question','dead','open'] as $kind){
        [$dir,$engine,$db,$run,$ids]=mapFixture();$before=$engine->getRunSnapshot($run);$target=$ids[$kind];
        \AIcontrol\InvestigationWire::$handler=function(array $body)use($engine,$run,$target):array{
            $pending=$engine->getRunSnapshot($run);$request=end($pending['requests']);
            verify($request['status']==='pending'&&(int)$request['path_node_id']===$target,'Pending focus attribution missing');
            verify($body['max_tool_calls']===1&&!str_contains($body['input'],'Original query must be replaced.'),'Focus did not replace its bounded planned query');
            return reply('No useful citation returned.',[['type'=>'web_search_call','status'=>'completed']]);
        };
        $after=$engine->step($run,$target,'Find a falsification test.');
        $searches=array_values(array_filter($after['nodes'],static fn(array $n):bool=>$n['type']==='search'));
        $search=$searches[0];$newDead=array_values(array_filter($after['nodes'],static fn(array $n):bool=>$n['type']==='dead_end'&&(int)$n['parent_id']===(int)$search['id']));
        verify((int)$search['parent_id']===$target&&$search['linked_claim_ids']===[$ids['claim']]&&count($newDead)===1,'Focused search/dead-end ownership missing for '.$kind);
        verify(json_decode($search['data_json'],true)['note']==='Find a falsification test.','Focused note lost');
        verify((int)$after['run']['evidence_index']===1&&count($after['requests'])===count($before['requests'])+1,'Focus failed to consume exactly one slot');
        verify(count(array_filter($after['nodes'],static fn(array $n):bool=>$n['type']==='plan_step'))===1,'Focus added a planned slot');
        ob_start();\AIcontrol\ResearchView::nodes($after['nodes']);$list=ob_get_clean();
        verify(str_contains($list,'id="research-node-'.$search['id'].'"')&&str_contains($list,'id="research-node-'.$newDead[0]['id'].'"'),'List lacks focus result anchors');
    }
    [$dir,$engine,$db,$run,$ids]=mapFixture();
    foreach(['excluded','hidden','closed','unlinked','plan'] as $kind)mapRejectsUnchanged($engine,$run,fn()=>$engine->step($run,$ids[$kind]),'Invalid '.$kind.' focus accepted');
    mapRejectsUnchanged($engine,$run,fn()=>$engine->step($run,999999),'Foreign focus accepted');
    mapRejectsUnchanged($engine,$run,fn()=>$engine->step($run,$ids['claim'],str_repeat('x',1001)),'Oversized note accepted');
    $db->exec('UPDATE research_nodes SET selected=0 WHERE id='.$ids['question']);
    mapRejectsUnchanged($engine,$run,fn()=>$engine->step($run,$ids['question']),'Excluded question accepted');
    foreach(['dream_review','verification_review','plan'] as $phase){
        $db->prepare('UPDATE research_runs SET phase=? WHERE id=?')->execute([$phase,$run]);
        mapRejectsUnchanged($engine,$run,fn()=>$engine->step($run,$ids['claim']),'Focus bypassed '.$phase);
    }
    $db->exec("UPDATE research_runs SET phase='evidence',busy='worker',busy_since=".time()." WHERE id=$run");
    mapRejectsUnchanged($engine,$run,fn()=>$engine->step($run,$ids['claim']),'Busy focus accepted');
    $db->exec("UPDATE research_runs SET busy=NULL,busy_since=NULL WHERE id=$run");
    $db->exec("INSERT INTO research_requests(run_id,node_id,kind,status,created_at) VALUES($run,".$ids['plan'].",'evidence','pending',".time().")");
    mapRejectsUnchanged($engine,$run,fn()=>$engine->step($run,$ids['claim']),'Pending provider focus accepted');
    $db->exec("DELETE FROM research_requests WHERE run_id=$run");
    $db->exec("INSERT INTO research_messages(project_id,role,text,status,created_at) VALUES(1,'assistant','Pending chat','pending',".time().")");
    mapRejectsUnchanged($engine,$run,fn()=>$engine->step($run,$ids['claim']),'Pending shared chat focus accepted');
    $db->exec("DELETE FROM research_messages WHERE status='pending'");
    $db->exec("UPDATE research_nodes SET status='complete' WHERE type='plan_step' AND run_id=$run");
    mapRejectsUnchanged($engine,$run,fn()=>$engine->step($run,$ids['claim']),'Flow without a pending planned slot accepted');
    $db->exec("UPDATE research_nodes SET status='pending' WHERE type='plan_step' AND run_id=$run");
    $db->exec("UPDATE research_runs SET evidence_index=1 WHERE id=$run");
    mapRejectsUnchanged($engine,$run,fn()=>$engine->step($run,$ids['claim']),'Spent planned slot accepted');
    $db->exec("UPDATE research_runs SET evidence_index=0 WHERE id=$run");

    // API synthetic display IDs are rejected before any worker or ledger mutation.
    foreach(['passage-1','edge-1','source-1','0','-1','1.0',['1']] as $synthetic){
        $_POST=['run_id'=>(string)$run,'focus_node_id'=>$synthetic,'focus_note'=>''];$calls=count(\AIcontrol\InvestigationWire::$bodies);
        ob_start();\AIcontrol\ResearchApi::action($dir,'research_step','/loader.php',true);$result=json_decode(ob_get_clean(),true);
        verify(http_response_code()===422&&isset($result['error'])&&count(\AIcontrol\InvestigationWire::$bodies)===$calls,'Synthetic or malformed focus ID accepted');
    }
    $_POST=[];http_response_code(200);

    // Old runs keep their indexed plan and title-only open assessment support.
    [$dir,$engine,$db,$run,$ids]=mapFixture(true);$db->exec("UPDATE research_nodes SET data_json='{}' WHERE id=".$ids['open']);
    \AIcontrol\InvestigationWire::$handler=static fn(array $body):array=>reply('No useful citation.',[['type'=>'web_search_call','status'=>'completed']]);
    $legacy=$engine->step($run,$ids['open']);verify((int)$legacy['run']['evidence_index']===1&&$legacy['ledger']['passages']===[],'Legacy focus compatibility lost');

    // Budget and spend reservations continue to prevent provider dispatch.
    foreach(['hourly','spend'] as $cap){
        [$dir,$engine,$db,$run,$ids]=mapFixture();
        if($cap==='hourly')for($i=0;$i<10;$i++)$db->exec("INSERT INTO research_requests(run_id,node_id,kind,status,created_at) VALUES($run,".$ids['plan'].",'learn','complete',".time().")");
        else $db->exec('UPDATE research_projects SET spend_cap_usd=0 WHERE id=1');
        $calls=count(\AIcontrol\InvestigationWire::$bodies);$snapshot=$engine->step($run,$ids['claim']);
        verify($snapshot['run']['status']==='incomplete'&&count(\AIcontrol\InvestigationWire::$bodies)===$calls,'Focus bypassed '.$cap.' budget');
    }
    echo "PASS: $checks map steering, pending-request, slot, API ID, attribution, legacy and budget checks (synthetic transport only)\n";
} finally {foreach($dirs as $dir){foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}}
