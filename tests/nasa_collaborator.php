<?php
declare(strict_types=1);
require __DIR__.'/investigation_fixtures.php';
require_once __DIR__.'/../src/PanelStore.php';
require_once __DIR__.'/../src/NasaProvider.php';
require_once __DIR__.'/../src/NasaCollaborationView.php';
require_once __DIR__.'/../src/AdminData.php';
use AIcontrol\NasaStore;
use AIcontrol\NasaProvider;

function collaboratorFixture(): array
{
    [$dir,$engine,$old,$db]=fixture(true);$panel=new AIcontrol\PanelStore($dir.'/panel.sqlite',true);$panel->createProject('Collaborator project','Make one useful, recoverable product.');$panel->createProject('Unselected project','PRIVATE-UNSELECTED-COLLABORATOR');
    $store=new NasaStore($dir);return [$dir,$store,$store->start('project',1),$engine,$db];
}
function collaboratorResult(array $types=[]): array
{
    $move=static fn(string $process,string $title)=>['process'=>$process,'title'=>$title,'why'=>'The current plan lacks reviewed evidence for its next commitment.','next_step'=>'Record the missing promise and a bounded check before proceeding.'];
    return ['primary'=>$move('4.2','Clarify the next promise'),'alternatives'=>[$move('5.3','Plan a meaningful check'),$move('6.4','Reduce the biggest uncertainty')],'assumptions'=>['The current operating story still represents the owner.'],'unknowns'=>['Recovery behavior has not been measured.'],'bundle'=>[
        'artifacts'=>array_map(static fn($type)=>['type'=>$type,'content'=>'Proposed '.$type.' draft. Assumption: the owner will review the recorded operating scenario.'],$types),
        'requirements'=>in_array('requirements',$types,true)?[['title'=>'Reject expired sessions','statement'=>'The system shall reject expired sessions before writing saved data.','source'=>'ConOps: protected owner operation','method'=>'test']]:[],
        'risks'=>in_array('risks',$types,true)?[['title'=>'Recovery may fail','condition'=>'An untested recovery path could strand the owner after an interruption.','owner'=>'Project owner','mitigation'=>'Run a bounded recovery exercise with disposable data.','likelihood'=>3,'consequence'=>4]]:[],
    ]];
}
function collaboratorReply(array $result): array
{
    return ['status'=>'completed','model'=>'gpt-4.1-mini','usage'=>['input_tokens'=>100,'output_tokens'=>200],'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($result,JSON_THROW_ON_ERROR)]]]]];
}
function stageCollaboration(NasaStore $store,int $id,array $types,array $result): array
{
    $p=$store->project($id);$store->execute("INSERT INTO nasa_ai_requests(project_id,source_kind,source_id,revision,action,status,model,reserved_usd,created_at)VALUES(?,?,?,?,'collaborate','pending','synthetic-test-only',0,?)",[$id,$p['source_kind'],$p['source_id'],$p['revision'],time()]);$request=(int)$store->db->lastInsertId();
    verify($store->retainProposal($id,$request,(int)$p['revision'],$store->collaborationContextHash($id),$types,$result),'Fresh proposal was not retained');
    $store->execute("UPDATE nasa_ai_requests SET status='complete' WHERE id=?",[$request]);return $store->proposal($id,'PROP-'.$request);
}
try{
    [$dir,$store,$id,$engine,$researchDb]=collaboratorFixture();$types=['requirements','risks','verification'];$result=collaboratorResult($types);
    $body=(new NasaProvider($store))->body($id,'collaborate',$types);$encoded=json_encode($body,JSON_THROW_ON_ERROR);
    verify(!isset($body['tools'])&&$body['store']===false&&str_contains($body['instructions'],'readiness')&&str_contains($body['instructions'],'pending proposals'),'Collaborator prompt lost scope, posture or proposal-only boundary');
    verify(!str_contains($encoded,'PRIVATE-UNSELECTED-COLLABORATOR')&&!str_contains($encoded,'NEVER-SEND-THIS')&&!str_contains($encoded,'OPTED-IN-CONTEXT'),'Collaborator included unrelated project or Vault context');
    $schema=$body['text']['format']['schema'];verify($schema['additionalProperties']===false&&$schema['properties']['alternatives']['minItems']===2&&$schema['properties']['alternatives']['maxItems']===3,'Strict schema or alternative limits missing');
    denied(fn()=>(new NasaProvider($store))->body($id,'collaborate',array_keys(AIcontrol\NasaKnowledge::ARTIFACTS)),'More than three artifact types admitted');
    denied(fn()=>NasaStore::validateProposal($result,[]),'Advice-only scope admitted a bundle');
    $bad=$result;$bad['alternatives']=[$bad['alternatives'][0]];denied(fn()=>NasaStore::validateProposal($bad,$types),'Single alternative admitted');
    $bad=$result;$bad['bundle']['artifacts'][1]['type']='requirements';denied(fn()=>NasaStore::validateProposal($bad,$types),'Duplicate bundle artifact admitted');
    $bad=$result;$bad['bundle']['requirements'][0]['status']='verified';denied(fn()=>NasaStore::validateProposal($bad,$types),'Forged verified state admitted');
    $bad=$result;$bad['phase']='E';denied(fn()=>NasaStore::validateProposal($bad,$types),'Forged phase action admitted');
    $bad=$result;$bad['primary']['process']='9.9';denied(fn()=>NasaStore::validateProposal($bad,$types),'Unknown technical process admitted');
    $bad=$result;$bad['bundle']['risks'][0]['likelihood']=0;denied(fn()=>NasaStore::validateProposal($bad,$types),'Invalid risk rating admitted');
    $form=$result;$form['bundle']['risks'][0]['likelihood']='3';$form['bundle']['risks'][0]['consequence']='4';verify(NasaStore::proposalFromForm($form)===$result,'Ordinary POST risk values were not normalised strictly');
    $form['bundle']['risks'][0]['likelihood']='3.0';denied(fn()=>NasaStore::proposalFromForm($form),'Non-whole POST rating admitted');

    $before=$store->project($id);$request=(new NasaProvider($store,fn()=>collaboratorReply($result)))->run($id,'collaborate',$types);$key='PROP-'.$request;
    verify(!$store->items($id)&&!$store->rows('SELECT id FROM nasa_reviews WHERE project_id=?',[$id])&&!$store->rows('SELECT id FROM nasa_baselines WHERE project_id=?',[$id])&&$store->project($id)['revision']===$before['revision']&&$store->project($id)['phase']===$before['phase'],'AI proposal changed items, reviews, baselines, revision or phase');
    verify(count($store->proposals($id))===1&&$store->proposal($id,$key)['status']==='pending','AI did not create exactly one pending proposal');
    verify($store->rows('SELECT status,reserved_usd FROM nasa_ai_requests WHERE id=?',[$request])[0]===['status'=>'complete','reserved_usd'=>0.0],'Completed collaborator lost billing release');
    $edited=$result;$edited['bundle']['artifacts'][0]['content']='Owner edited draft </textarea><script>window.NASA_PROPOSAL_XSS=true</script>';$store->editProposal($id,$key,1,$edited);
    verify(!$store->items($id)&&count($store->proposalHistory($id))===2&&$store->proposal($id,$key)['version']===2&&$store->proposals($id)[0]['fresh'],'Editing proposal applied records, lost history or superseded itself');
    denied(fn()=>$store->editProposal($id,$key,1,$result),'Stale proposal edit admitted');
    denied(fn()=>$store->decideProposal($id,$key,1,'accept'),'Stale proposal acceptance admitted');
    $other=$store->start('project',2);denied(fn()=>$store->decideProposal($other,$key,2,'accept'),'Cross-project proposal acceptance admitted');
    ob_start();AIcontrol\NasaCollaborationView::render(['project'=>$store->project($id),'proposals'=>$store->proposals($id)],'<input type="hidden" name="csrf" value="fixture">');$html=ob_get_clean();
    verify(str_contains($html,'name="proposal[bundle][artifacts][0][content]"')&&str_contains($html,'name="action" value="nasa_proposal_accept"')&&str_contains($html,'method="post"'),'Editable no-JavaScript proposal forms missing');
    verify(!str_contains($html,'</textarea><script>window.NASA_PROPOSAL_XSS')&&str_contains($html,'&lt;script&gt;window.NASA_PROPOSAL_XSS'),'Untrusted proposal content was not escaped');
    $saved=$store->decideProposal($id,$key,2,'accept');$items=$store->items($id);
    verify(count($saved)===5&&count($items)===5&&$store->project($id)['revision']===$before['revision']+1,'Bundle was not committed together with one revision increment');
    foreach($items as $item){verify($item['status']===['artifact'=>'draft','requirement'=>'unverified','risk'=>'open'][$item['kind']],'Proposal escalated a record status');verify($item['created_by']==='owner_accepted_ai'&&$item['data']['proposal']['key']===$key&&$item['data']['proposal']['version']===2,'Accepted item lost request/proposal provenance');}
    $req=$store->current($id,'requirement','REQ-001');$reqData=json_decode($req['data_json'],true);verify($reqData['evidence']==='','AI proposal invented requirement evidence');$risk=json_decode($store->current($id,'risk','RISK-001')['data_json'],true);verify($risk['residual_likelihood']===$risk['likelihood']&&$risk['residual_consequence']===$risk['consequence'],'Proposed mitigation was treated as already implemented');
    verify($store->proposal($id,$key)['status']==='accepted'&&count($store->proposalHistory($id))===3,'Accepted proposal disposition overwrote its history');
    denied(fn()=>$store->decideProposal($id,$key,3,'accept'),'Double acceptance admitted');verify(count($store->items($id))===5,'Double acceptance duplicated saved records');

    $proposal=stageCollaboration($store,$id,['interfaces'],collaboratorResult(['interfaces']));$context=$store->collaborationContextHash($id);$store->decideProposal($id,$proposal['proposal_key'],1,'reject');verify($store->collaborationContextHash($id)===$context&&$store->proposal($id,$proposal['proposal_key'])['status']==='rejected','Rejection changed planning context or lost disposition');
    $advice=stageCollaboration($store,$id,[],collaboratorResult());denied(fn()=>$store->decideProposal($id,$advice['proposal_key'],1,'accept'),'Advice-only proposal accepted records');$store->decideProposal($id,$advice['proposal_key'],1,'reject');
    $stale=stageCollaboration($store,$id,['interfaces'],collaboratorResult(['interfaces']));$revision=$store->project($id)['revision'];$store->execute('UPDATE projects SET brief=? WHERE id=1',['Changed source brief without a NASA revision bump.']);verify($store->project($id)['revision']===$revision,'Fixture unexpectedly bumped NASA revision');
    denied(fn()=>$store->editProposal($id,$stale['proposal_key'],1,$stale['payload']),'Changed source context admitted a proposal edit');denied(fn()=>$store->decideProposal($id,$stale['proposal_key'],1,'accept'),'Changed source context admitted proposal acceptance');verify(!$store->current($id,'artifact','interfaces'),'Stale acceptance partially materialised an artifact');$store->decideProposal($id,$stale['proposal_key'],1,'reject');
    $store->linkResearch($id,1,$store->project($id)['revision']);$run=$engine->createRun(1);$researchDb->exec("UPDATE research_runs SET phase='exploring' WHERE id=$run");$stale=stageCollaboration($store,$id,['interfaces'],collaboratorResult(['interfaces']));$engine->addUserIdea($run,'New uncertainty','A new consideration changes the saved research context.','nasa:'.$id.':100:0');denied(fn()=>$store->decideProposal($id,$stale['proposal_key'],1,'accept'),'Changed research context admitted proposal acceptance');$store->decideProposal($id,$stale['proposal_key'],1,'reject');
    $rollback=stageCollaboration($store,$id,['conops','interfaces'],collaboratorResult(['conops','interfaces']));$count=(int)$store->rows('SELECT COUNT(*) AS n FROM nasa_items WHERE project_id=?',[$id])[0]['n'];$revision=$store->project($id)['revision'];
    $store->db->exec("CREATE TEMP TRIGGER fail_collaborator_member BEFORE INSERT ON nasa_items WHEN NEW.item_key='interfaces' BEGIN SELECT RAISE(ABORT,'Synthetic second member failure'); END");denied(fn()=>$store->decideProposal($id,$rollback['proposal_key'],1,'accept'),'Forced mid-bundle storage failure was accepted');$store->db->exec('DROP TRIGGER fail_collaborator_member');
    verify((int)$store->rows('SELECT COUNT(*) AS n FROM nasa_items WHERE project_id=?',[$id])[0]['n']===$count&&$store->project($id)['revision']===$revision&&$store->proposal($id,$rollback['proposal_key'])['version']===1,'Mid-bundle failure did not roll back records, revision and disposition');
    $store->decideProposal($id,$rollback['proposal_key'],1,'accept');verify($store->current($id,'artifact','conops')&&$store->current($id,'artifact','interfaces'),'Rolled-back proposal could not be accepted after fixing the cause');
    $export=$store->export($id);verify(isset($export['workspace']['proposal_history'])&&count($export['workspace']['proposal_history'])===count($store->proposalHistory($id)),'Export omitted append-only proposal history');

    [$providerDir,$providerStore,$providerId]=collaboratorFixture();$calls=0;$invalid=$result;$invalid['bundle']['requirements'][0]['evidence']='Invented evidence';
    $invalidProvider=new NasaProvider($providerStore,function()use(&$calls,$invalid){$calls++;return collaboratorReply($invalid);});denied(fn()=>$invalidProvider->run($providerId,'collaborate',$types),'Provider approval fields bypassed strict validation');verify($calls===1&&!$providerStore->proposals($providerId)&&!$providerStore->items($providerId),'Rejected provider output partially applied or retried');
    $staleProvider=new NasaProvider($providerStore,function()use($providerStore,$providerId,$result){$providerStore->execute('UPDATE projects SET brief=? WHERE id=1',['Owner changed context while response was in flight.']);return collaboratorReply($result);});$staleRequest=$staleProvider->run($providerId,'collaborate',$types);verify($providerStore->rows('SELECT status FROM nasa_ai_requests WHERE id=?',[$staleRequest])[0]['status']==='superseded'&&!$providerStore->proposals($providerId),'Late collaborator response was staged against changed context');
    $lock=fopen($providerDir.'/provider-request.lock','c');flock($lock,LOCK_EX);denied(fn()=>(new NasaProvider($providerStore,fn()=>collaboratorReply($result)))->run($providerId,'collaborate',$types),'Concurrent collaborator provider request admitted');flock($lock,LOCK_UN);fclose($lock);
    $providerStore->execute('UPDATE nasa_ai_requests SET estimated_usd=1,reserved_usd=0 WHERE id=?',[$staleRequest]);denied(fn()=>(new NasaProvider($providerStore,fn()=>collaboratorReply($result)))->run($providerId,'collaborate',$types),'Collaborator bypassed existing source-project daily allowance');
    [$limitDir,$limitStore,$limitId]=collaboratorFixture();$calls=0;$unknown=new NasaProvider($limitStore,function()use(&$calls){$calls++;throw new RuntimeException('Synthetic uncertain transport');});for($i=0;$i<8;$i++)denied(fn()=>$unknown->run($limitId,'collaborate'),'Synthetic failed call did not retain uncertain outcome');denied(fn()=>$unknown->run($limitId,'review'),'Collaborator allowance was not shared with review');verify($calls===8&&count($limitStore->rows("SELECT id FROM nasa_ai_requests WHERE status='uncertain' AND reserved_usd>0"))===8,'Collaborator retried or lost unknown-outcome reservations');
    $store->execute('DELETE FROM nasa_projects WHERE id=?',[$id]);verify(!$store->rows('SELECT id FROM nasa_proposals WHERE project_id=?',[$id]),'Project deletion did not cascade proposal output');
    echo "PASS: $checks collaborator schema, pending drafts, owner edits, atomic accept/reject, freshness, provenance, shared limits, no-JS forms and export checks\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}finally{foreach($dirs as $dir){foreach(glob($dir.'/*') as $file)@unlink($file);@rmdir($dir);}}
