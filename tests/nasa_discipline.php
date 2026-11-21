<?php
declare(strict_types=1);
require __DIR__.'/investigation_fixtures.php';
require_once __DIR__.'/../src/PanelStore.php';
require_once __DIR__.'/../src/NasaStore.php';
use AIcontrol\NasaStore;

try{
    [$dir,$engine,$research,$db]=fixture(true);
    $panel=new AIcontrol\PanelStore($dir.'/panel.sqlite',true);
    $panel->createProject('Configuration fixture','Keep reviewed planning snapshots and explicit interface contracts.');
    $panel->createProject('Other source','PRIVATE-OTHER-NASA-DISCIPLINE');
    $n=new NasaStore($dir);$id=$n->start('project',1);$other=$n->start('project',2);
    $n->linkResearch($id,1,0);
    $run=$engine->createRun(1);$node=$engine->appendNode($run,null,'model_idea','complete','Recorded research idea','An unchecked design choice to investigate.');
    $n->saveArtifact($id,'conops','Owner journey with recoverable state.','reviewed',0);
    $requirement=$n->saveRecord($id,'requirement','','Recover saved work','The system shall retain 3 previous planning versions.','unverified',['source'=>'Owner recovery need','method'=>'test','evidence'=>'Manual test plan, not executed.'],0);
    denied(fn()=>$n->disciplinedBaseline($id,'No review yet',$n->project($id)['revision']),'Baseline admitted without a current local review');
    $n->review($id,'ai_draft',['summary'=>'Synthetic model advice','strengths'=>[],'gaps'=>[],'next_actions'=>[]]);
    denied(fn()=>$n->disciplinedBaseline($id,'AI cannot authorise',$n->project($id)['revision']),'AI advisory review authorised a baseline');
    $review=$n->review($id);$revision=(int)$n->project($id)['revision'];
    denied(fn()=>$n->disciplinedBaseline($id,'Stale screen',$revision-1),'Stale baseline form bypassed revision guard');
    verify(!$n->rows('SELECT id FROM nasa_baselines'),'Rejected baseline left a snapshot behind');
    putenv('AICONTROL_INSTALLED_SHA='.str_repeat('b',40));
    $baseline=$n->disciplinedBaseline($id,'Reviewed fixture snapshot',$revision);
    $row=$n->rows('SELECT * FROM nasa_baselines WHERE id=?',[$baseline])[0];$snapshot=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
    verify($snapshot['local_review_id']===$review&&$snapshot['revision']===$revision&&$snapshot['code_sha']===str_repeat('b',40),'Baseline omitted review/revision/installed code provenance');
    verify($snapshot['research']['available']&&strlen($snapshot['research']['sha256'])===64&&$snapshot['research']['project_id']===1,'Baseline omitted linked research hash');
    verify(!str_contains($row['snapshot_json'],'PRIVATE-OTHER')&&!str_contains($row['snapshot_json'],'NEVER-SEND-THIS')&&!str_contains($row['snapshot_json'],'OPTED-IN-CONTEXT'),'Baseline captured unselected context or Vault notes');
    verify(hash_equals($row['sha256'],hash('sha256',$row['snapshot_json'])),'Baseline content checksum incorrect');
    $initial=$n->researchState($id)['sha256'];
    $db->exec("UPDATE research_nodes SET last_focused_at=1234,size_factor=0.3,brightness_factor=0.4,last_layer_change=999,updated_at=9999 WHERE id=$node");
    $db->exec("UPDATE research_runs SET busy='PRIVATE-WORKER-TOKEN',busy_since=1,token_input=900,token_output=700,usage_unknown=1 WHERE id=$run");
    $db->exec('UPDATE research_projects SET spend_cap_usd=9 WHERE id=1');
    verify($n->researchState($id)['sha256']===$initial,'Camera, focus, spend or heartbeat changed the research baseline hash');
    verify(!str_contains(json_encode($n->researchState($id)),'PRIVATE-WORKER-TOKEN'),'Research snapshot exposed worker identity');
    $otherResearch=$research->create('Unselected research','PRIVATE-OTHER-RESEARCH-DISCIPLINE');
    verify($n->researchState($id)['sha256']===$initial,'Unselected project changed a scoped research hash');
    $db->prepare('UPDATE research_nodes SET content=? WHERE id=?')->execute(['A substantively changed design option.',$node]);
    verify($n->researchState($id)['sha256']!==$initial,'Changed research content did not change the baseline hash');
    $n->saveArtifact($id,'conops','A newer recovery assumption.','draft',1);
    $contractData=['side_a'=>'Browser','side_b'=>'Authenticated PHP endpoint','payload'=>'JSON with bounded schema and version','errors'=>'409 for stale input; no automatic retry','auth'=>'Owner session plus CSRF; no key in browser','owner'=>'Project owner','change_rule'=>'Both sides reviewed before schema changes'];
    $contract=$n->saveRecord($id,'interface','','Planning save API','Agree the browser/PHP save boundary.','draft',$contractData,0);
    verify($contract==='IF-001','Interface stable key missing');
    $n->saveRecord($id,'interface',$contract,'Planning save API','Agree the browser/PHP save boundary.','reviewed',$contractData,1);
    verify((int)$n->current($id,'interface',$contract)['version']===2,'Interface edit did not append a version');
    denied(fn()=>$n->saveRecord($id,'interface',$contract,'Lost change','Must not overwrite.','draft',$contractData,1),'Stale interface edit accepted');
    $bad=$contractData;$bad['auth']='';denied(fn()=>$n->saveRecord($id,'interface','','Incomplete API','Authentication agreement missing.','draft',$bad,0),'Incomplete interface contract accepted');
    denied(fn()=>$n->saveRecord($id,'interface','','Forged API','No approval status allowed.','approved',$contractData,0),'Unknown interface approval state accepted');
    $n->archiveRecord($id,'interface',$contract,2);$n->archiveRecord($id,'interface',$contract,3,true);
    verify($n->current($id,'interface',$contract)['status']==='draft'&&(int)$n->current($id,'interface',$contract)['version']===4,'Restored interface skipped history or asserted review');
    denied(fn()=>$n->disciplinedBaseline($id,'Review is now stale',$revision),'Edited planning retained prior baseline authority');
    $delta=$n->baselineDelta($id,$baseline);
    verify($delta['research']['changed']===true&&count($delta['planning']['changed_since_baseline'])===1&&count($delta['planning']['added_since_baseline'])===1,'Baseline comparison omitted research or planning changes');
    denied(fn()=>$n->baselineDelta($other,$baseline),'Cross-project baseline comparison admitted');
    $researchBefore=$n->researchState($id)['sha256'];$count=count($n->rows('SELECT id FROM nasa_items WHERE project_id=?',[$id]));
    denied(fn()=>$n->disciplinedRestoreBaseline($id,$baseline,'RESTORE BASELINE 999',$n->project($id)['revision']),'Wrong baseline restore confirmation accepted');
    denied(fn()=>$n->disciplinedRestoreBaseline($id,$baseline,'RESTORE BASELINE '.$baseline,$revision),'Stale baseline restore accepted');
    $restored=$n->disciplinedRestoreBaseline($id,$baseline,'RESTORE BASELINE '.$baseline,(int)$n->project($id)['revision']);
    verify($restored['research']['changed']===true&&$n->researchState($id)['sha256']===$researchBefore,'Planning restore mutated research or lost its before-restore comparison');
    verify($n->current($id,'artifact','conops')['content']==='Owner journey with recoverable state.'&&count($n->rows('SELECT id FROM nasa_items WHERE project_id=?',[$id]))>$count,'Baseline restore failed to append planning history');
    verify($n->current($id,'interface',$contract)['status']==='removed','Post-baseline interface did not recede via a new tombstone');
    verify(count($n->baselineHistory($id))===1&&$n->baselineHistory($id)[0]['delta']['research']['changed']===true,'Restore comparison was not retained for the owner');
    // Legacy snapshots remain restorable, but a previously verified requirement cannot reappear verified.
    $legacy=['phase'=>'pre','revision'=>1,'knowledge_version'=>'legacy','items'=>$n->items($id)];
    foreach($legacy['items'] as &$item)if($item['kind']==='requirement')$item['status']='verified';unset($item);
    $json=json_encode($legacy,JSON_THROW_ON_ERROR);$n->execute('INSERT INTO nasa_baselines(project_id,title,snapshot_json,sha256,created_at)VALUES(?,?,?,?,?)',[$id,'Legacy fixture',$json,hash('sha256',$json),time()]);$old=(int)$n->db->lastInsertId();
    verify($n->baselineDelta($id,$old)['research']['baseline_recorded']===false,'Older baseline fabricated a research hash');
    $n->disciplinedRestoreBaseline($id,$old,'RESTORE BASELINE '.$old,(int)$n->project($id)['revision']);
    verify($n->current($id,'requirement',$requirement)['status']==='unverified','Legacy baseline restored unreviewed verification authority');
    $n->execute("UPDATE nasa_baselines SET snapshot_json='{}' WHERE id=?",[$old]);denied(fn()=>$n->baselineDelta($id,$old),'Tampered baseline comparison bypassed checksum');
    putenv('AICONTROL_INSTALLED_SHA=invalid');$n->review($id);$unknown=$n->disciplinedBaseline($id,'Unknown code reference',(int)$n->project($id)['revision']);
    $state=json_decode($n->rows('SELECT snapshot_json FROM nasa_baselines WHERE id=?',[$unknown])[0]['snapshot_json'],true,64,JSON_THROW_ON_ERROR);verify($state['code_sha']===null,'Unknown installed code SHA was invented');
    // Hash focused projects only while their immutable identity still matches the owner-created origin.
    $beforeFocus=$n->researchState($id)['sha256'];$focused=$n->focusedResearch($id,'gap','risks',0,(int)$n->project($id)['revision']);$focusId=$focused['research_project_id'];
    $focusState=$n->researchState($id);verify($focusState['sha256']!==$beforeFocus&&count($focusState['snapshot']['focused_projects'])===1,'Owner-created focused research was absent from the configuration snapshot');
    $db->prepare('UPDATE research_projects SET brief=? WHERE id=?')->execute(['A changed focused research question.',$focusId]);verify($n->researchState($id)['sha256']!==$focusState['sha256'],'Focused research content changes did not alter the baseline hash');
    $db->prepare('DELETE FROM research_projects WHERE id=?')->execute([$focusId]);$replacement=$research->create('Unrelated replacement','PRIVATE-UNRELATED-REUSED-RESEARCH');
    verify($replacement===$focusId&&!str_contains(json_encode($n->researchState($id)),'PRIVATE-UNRELATED-REUSED-RESEARCH'),'Reused research project ID contaminated a planning baseline');
    $makeBaseline=function(array $state)use($n,$id):int{$json=json_encode($state,JSON_THROW_ON_ERROR);$n->execute('INSERT INTO nasa_baselines(project_id,title,snapshot_json,sha256,created_at)VALUES(?,?,?,?,?)',[$id,'Malformed snapshot fixture',$json,hash('sha256',$json),time()]);return (int)$n->db->lastInsertId();};
    $valid=['phase'=>'pre','revision'=>(int)$n->project($id)['revision'],'items'=>$n->items($id)];
    foreach(['foreign_scope','wrong_key','duplicate','wrong_state','missing_method','array_phase'] as $case){
        $bad=$valid;
        if($case==='foreign_scope')$bad['items'][0]['project_id']=$other;
        elseif($case==='wrong_key')$bad['items'][0]['item_key']='not-a-known-artifact';
        elseif($case==='duplicate')$bad['items'][]=$bad['items'][0];
        elseif($case==='wrong_state')$bad['items'][0]['status']='approved';
        elseif($case==='array_phase')$bad['phase']=[];
        else foreach($bad['items'] as &$item)if($item['kind']==='requirement')unset($item['data']['method']);unset($item);
        $badId=$makeBaseline($bad);$beforeRows=count($n->rows('SELECT id FROM nasa_items WHERE project_id=?',[$id]));$beforeRevision=(int)$n->project($id)['revision'];
        denied(fn()=>$n->baselineDelta($id,$badId),'Malformed baseline comparison accepted: '.$case);
        denied(fn()=>$n->disciplinedRestoreBaseline($id,$badId,'RESTORE BASELINE '.$badId,$beforeRevision),'Malformed baseline restored: '.$case);
        verify(count($n->rows('SELECT id FROM nasa_items WHERE project_id=?',[$id]))===$beforeRows&&(int)$n->project($id)['revision']===$beforeRevision,'Rejected baseline partially changed records: '.$case);
    }
    // Force a failure after one valid item append and prove the whole restore is rolled back.
    $rollback=$makeBaseline($valid);$rowsBefore=count($n->rows('SELECT id FROM nasa_items WHERE project_id=?',[$id]));$revisionBefore=(int)$n->project($id)['revision'];$historyBefore=count($n->baselineHistory($id));
    $n->db->exec("CREATE TEMP TRIGGER nasa_restore_reject BEFORE INSERT ON nasa_items WHEN NEW.created_by='owner_baseline_restore' AND NEW.kind='requirement' BEGIN SELECT RAISE(ABORT,'Synthetic mid-restore failure'); END;");
    $failed=false;try{$n->disciplinedRestoreBaseline($id,$rollback,'RESTORE BASELINE '.$rollback,$revisionBefore);}catch(Throwable){$failed=true;}finally{$n->db->exec('DROP TRIGGER nasa_restore_reject');}
    verify($failed&&count($n->rows('SELECT id FROM nasa_items WHERE project_id=?',[$id]))===$rowsBefore&&(int)$n->project($id)['revision']===$revisionBefore&&count($n->baselineHistory($id))===$historyBefore,'Mid-restore failure escaped transaction rollback');
    for($i=0;$i<22;$i++)$makeBaseline($valid);
    $workspace=$n->workspace($id);
    verify(count($workspace['baselines'])>20&&in_array($baseline,array_column($workspace['baselines'],'id'),true)&&isset($workspace['baseline_deltas'][$baseline]),'Older baselines or their restore comparisons disappeared after twenty snapshots');
    verify(isset($workspace['baseline_deltas'][$badId]['error']),'Malformed baseline prevented safe workspace inspection');
    verify(!AIcontrol\InvestigationWire::$bodies,'Configuration discipline triggered a provider request');
    echo "PASS: $checks NASA baseline review/CAS, scoped research hashes, pure comparisons, immutable restore, legacy verification reset and interface contract checks\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);}finally{putenv('AICONTROL_INSTALLED_SHA');foreach($dirs as $directory){foreach(glob($directory.'/*') as $file)@unlink($file);@rmdir($directory);}}
