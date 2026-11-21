<?php
declare(strict_types=1);
require __DIR__.'/investigation_fixtures.php';
require_once __DIR__.'/../src/PanelStore.php';
require_once __DIR__.'/../src/NasaProvider.php';
use AIcontrol\NasaStore;

function atomicProposal(array $types): array
{
    $move=['process'=>'4.2','title'=>'Define the next useful promise','why'=>'The saved operating scenario needs a bounded, reviewable draft.','next_step'=>'Review a small related bundle against the recorded needs.'];
    return ['primary'=>$move,'alternatives'=>[array_replace($move,['process'=>'6.4','title'=>'Reduce the largest uncertainty']),array_replace($move,['process'=>'5.3','title'=>'Plan a discriminating check'])],'assumptions'=>['The saved scenario represents the owner.'],'unknowns'=>['Recovery has not been measured.'],'bundle'=>[
        'artifacts'=>array_map(static fn($type)=>['type'=>$type,'content'=>'Synthetic '.$type.' draft; an owner must review its assumptions.'],$types),'requirements'=>[],'risks'=>[],
    ]];
}
function atomicStage(NasaStore $store,int $id,array $types): array
{
    $project=$store->project($id);$hash=$store->collaborationContextHash($id);
    $store->execute("INSERT INTO nasa_ai_requests(project_id,source_kind,source_id,revision,action,status,model,reserved_usd,created_at)VALUES(?,?,?,?,'collaborate','pending','synthetic-atomicity-only',0,?)",[$id,$project['source_kind'],$project['source_id'],$project['revision'],time()]);
    $request=(int)$store->db->lastInsertId();
    verify($store->retainProposal($id,$request,(int)$project['revision'],$hash,$types,atomicProposal($types)),'Fresh synthetic proposal was not retained');
    $store->execute("UPDATE nasa_ai_requests SET status='complete' WHERE id=?",[$request]);
    return $store->proposal($id,'PROP-'.$request);
}

try{
    [$dir,$engine,$research,$db]=fixture(true);
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA busy_timeout=0');
    $panel=new AIcontrol\PanelStore($dir.'/panel.sqlite',true);$panel->createProject('Atomic planning fixture','Preserve the exact reviewed context when committing drafts.');
    $store=new NasaStore($dir);$id=$store->start('project',1);$store->linkResearch($id,1,0);
    $run=$engine->createRun(1);$db->exec("UPDATE research_runs SET phase='exploring' WHERE id=$run");
    $nodes=[];for($i=0;$i<55;$i++)$nodes[]=$engine->appendNode($run,null,'model_idea','complete','Synthetic idea '.$i,'Original synthetic content '.$i);
    $oldest=$nodes[0];$latest=$nodes[count($nodes)-1];$revision=(int)$store->project($id)['revision'];
    $context=$store->context($id);verify(count($context['research']['ideas'])===50&&!in_array($oldest,array_column($context['research']['ideas'],'id'),true),'Old-node freshness fixture did not exceed the summary window');
    $proposal=atomicStage($store,$id,['conops']);$oldHash=$store->collaborationContextHash($id);$oldState=$store->researchState($id)['sha256'];
    $db->prepare('UPDATE research_nodes SET content=? WHERE id=?')->execute(['The oldest saved idea materially changed outside the summary window.',$oldest]);
    verify((int)$store->project($id)['revision']===$revision,'Research fixture unexpectedly changed the NASA revision');
    verify($store->researchState($id)['sha256']!==$oldState&&$store->collaborationContextHash($id)!==$oldHash,'A substantive change outside the summary window did not change the freshness fingerprint');
    denied(fn()=>$store->decideProposal($id,$proposal['proposal_key'],1,'accept'),'An older linked-research change did not invalidate a pending proposal');
    verify(!$store->items($id)&&$store->proposal($id,$proposal['proposal_key'])['status']==='pending'&&count($store->proposalHistory($id))===1,'Stale acceptance left records or a false accepted disposition');

    // Legacy drafting and review must compare their original full context again
    // after entering the transaction, even if NASA's planning revision is equal.
    $legacy=['artifacts'=>[['type'=>'interfaces','content'=>'Synthetic browser/PHP contract draft.']],'requirements'=>[],'risks'=>[]];
    $hash=$store->collaborationContextHash($id);$store->execute('UPDATE projects SET brief=? WHERE id=1',['Source brief changed without editing a NASA item.']);
    verify((int)$store->project($id)['revision']===$revision,'Source fixture unexpectedly changed the NASA revision');
    verify(!$store->applyAi($id,$revision,'generate',$legacy,$hash),'A legacy draft bypassed the inside-transaction full context check');
    $review=['summary'=>'Synthetic advisory result only.','strengths'=>[],'gaps'=>[],'next_actions'=>[]];
    verify(!$store->applyAi($id,$revision,'review',$review,$hash),'A legacy review bypassed the inside-transaction full context check');
    verify(!$store->items($id)&&!$store->rows('SELECT id FROM nasa_reviews WHERE project_id=?',[$id]),'Superseded legacy AI results partially applied');

    // A deterministic race hook represents a concurrent research writer after
    // the acceptance freshness check and after the first bundle append.
    $fresh=atomicStage($store,$id,['conops','interfaces']);$beforeItems=$store->rows('SELECT * FROM nasa_items WHERE project_id=? ORDER BY id',[$id]);$beforeHistory=count($store->proposalHistory($id));$beforeHash=$store->collaborationContextHash($id);
    $attempts=0;$blocked=0;
    $store->db->sqliteCreateFunction('nasa_synthetic_concurrent_writer',function()use($db,$latest,&$attempts,&$blocked):int{
        $attempts++;
        try{$db->prepare('UPDATE research_nodes SET content=? WHERE id=?')->execute(['A concurrent writer must not commit during acceptance.',$latest]);}
        catch(PDOException $error){if(!str_contains(strtolower($error->getMessage()),'locked')&&!str_contains(strtolower($error->getMessage()),'busy'))throw $error;$blocked++;throw new RuntimeException('Synthetic concurrent research write was blocked as expected.',0,$error);}
        return 1;
    });
    $store->execute("CREATE TRIGGER nasa_synthetic_accept_race BEFORE INSERT ON nasa_items WHEN NEW.kind='artifact' AND NEW.item_key='interfaces' BEGIN SELECT nasa_synthetic_concurrent_writer(); END");
    denied(fn()=>$store->decideProposal($id,$fresh['proposal_key'],1,'accept'),'A concurrent research writer was allowed through a planning acceptance');
    $store->execute('DROP TRIGGER nasa_synthetic_accept_race');
    verify($attempts===1&&$blocked===1,'The acceptance race did not reach the external writer or failed for an unrelated reason');
    verify($store->rows('SELECT * FROM nasa_items WHERE project_id=? ORDER BY id',[$id])===$beforeItems,'A failed second bundle append left its first artifact saved');
    verify($store->proposal($id,$fresh['proposal_key'])['status']==='pending'&&count($store->proposalHistory($id))===$beforeHistory&&(int)$store->project($id)['revision']===$revision,'A blocked acceptance changed disposition, history or revision');
    verify($store->collaborationContextHash($id)===$beforeHash,'The blocked external writer mutated saved research');
    verify(!in_array('nasa_atomic_research',array_column($store->db->query('PRAGMA database_list')->fetchAll(),'name'),true),'The transaction leaked its temporary attached research alias');
    $db->prepare('UPDATE research_nodes SET content=? WHERE id=?')->execute(['The writer can commit again after rollback.',$latest]);
    verify($store->collaborationContextHash($id)!==$beforeHash,'The research write reservation remained held after rollback');

    // A pre-attached database belongs to its caller; the shared transaction must
    // reuse its reservation rather than attach the same file twice or detach it.
    $store->db->exec('ATTACH DATABASE '.$store->db->quote($dir.'/research.sqlite').' AS nasa_caller_research');
    $count=$store->transaction(fn()=>(int)$store->rows('SELECT COUNT(*) AS n FROM nasa_caller_research.research_projects')[0]['n']);
    verify($count===1&&in_array('nasa_caller_research',array_column($store->db->query('PRAGMA database_list')->fetchAll(),'name'),true),'The shared transaction duplicated or removed a caller-owned attachment');
    $store->db->exec('DETACH DATABASE nasa_caller_research');

    $accepted=atomicStage($store,$id,['conops','interfaces']);$saved=$store->decideProposal($id,$accepted['proposal_key'],1,'accept');
    verify(count($saved)===2&&count($store->items($id))===2&&$store->proposal($id,$accepted['proposal_key'])['status']==='accepted','A fresh uncontended bundle no longer commits successfully');
    verify(!array_filter($store->items($id),static fn($item)=>$item['status']!=='draft'),'Atomic acceptance escalated draft artifacts');
    verify(!AIcontrol\InvestigationWire::$bodies,'Atomicity fixtures dispatched a provider request');
    echo "PASS: $checks NASA full-state freshness, old-node summaries, legacy AI rechecks, attached research write exclusion, rollback, attachment ownership and draft acceptance checks\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);}
finally{foreach($dirs as $fixtureDirectory){foreach(glob($fixtureDirectory.'/*') as $file)@unlink($file);@rmdir($fixtureDirectory);}}
