<?php
declare(strict_types=1);
require __DIR__.'/investigation_fixtures.php';
use AIcontrol\InvestigationWire as Wire;
function prior():array{return ['core_claims'=>[['claim'=>'First fixture claim','confidence'=>'medium','uncertainty'=>'Local context','verify'=>'Find measurements','priority'=>'high'],['claim'=>'Second fixture claim','confidence'=>'low','uncertainty'=>'Population','verify'=>'Compare primary sources','priority'=>'normal']],'competing_angles'=>['Alternative explanation'],'least_confident'=>'Transfer to this setting','background_assumptions'=>['Comparable populations']];}
function rigourFixture(int $normalSearches=1):array{
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);$db->exec("UPDATE research_runs SET flow_version=0,auto_search=0,growth_remaining=0 WHERE id=$run");Wire::$handler=fn($body)=>reply(json_encode(prior()));$snapshot=$engine->step($run);$engine->continueRun($run);$claims=array_values(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='dream_claim'));$claim=(int)$claims[0]['id'];
 Wire::$handler=fn($body)=>reply(json_encode(['steps'=>array_fill(0,$normalSearches,['claim_id'=>$claim,'query'=>'Find supporting context']),'challenge'=>['claim_id'=>$claim,'query'=>'Seek counter-evidence'] ]));$snapshot=$engine->step($run);
 return [$dir,$engine,$old,$db,$run,$claim,$claims];
}
function searchReply(bool $snippet=true,bool $searched=true):array{
 $extra=$searched?[['type'=>'web_search_call','status'=>'completed','action'=>['sources'=>[['url'=>'https://example.com/study','snippet'=>'Exact provider source text: the measured effect depends on the population.','locator'=>'Results section']]]]]:[];
 if(!$snippet&&$extra)$extra[0]['action']['sources'][0]=['url'=>'https://example.com/study'];
 $r=reply('Generated answer summary is not an exact source passage.',$extra);$index=count($extra);$r['output'][$index]['content'][0]['annotations']=[['type'=>'url_citation','url'=>'https://example.com/study','title'=>'Study fixture']];return $r;
}
function extraction(array $ledger,int $claim):array{
 $passage=$ledger['passages'][0]['id']??null;$source=$ledger['sources'][0]['id'];
 return ['edges'=>[['claim_id'=>$claim,'passage_id'=>$passage===null?null:(int)$passage,'source_id'=>(int)$source,'relation'=>'supports','rationale'=>'Within the recorded population only','strength'=>$passage===null?'low':'moderate']],
 'assessments'=>array_map(fn($c)=>['claim_id'=>(int)$c['id'],'status'=>(int)$c['id']===$claim?'supported':'open','rationale'=>'Scope remains limited','strength'=>(int)$c['id']===$claim?($passage===null?'low':'moderate'):null],array_filter($ledger['claims'],fn($c)=>(int)$c['selected']===1))];
}
function verification(array $ledger,int $claim,array $defects=[],array $calculations=[]):array{return ['assessments'=>extraction($ledger,$claim)['assessments'],'defects'=>$defects,'calculations'=>$calculations];}
try {
 [$dir,$engine,$old,$db,$run,$claim,$claims]=rigourFixture();
 verify((int)$engine->getRunSnapshot($run)['run']['rigour_version']===1,'New run not on rigour workflow');
 $plans=array_values(array_filter($engine->getRunSnapshot($run)['nodes'],fn($n)=>$n['type']==='plan_step'));verify(count($plans)===2&&json_decode(end($plans)['data_json'],true)['challenge'],'Challenge not reserved');
 Wire::$handler=fn($body)=>searchReply();$engine->step($run);Wire::$handler=function($body){verify(str_contains($body['instructions'],'reserved challenge search')&&str_contains($body['instructions'],'even when redirected'),'Focused challenge lost counter-evidence requirement');return searchReply();};$snapshot=$engine->step($run,$claim,'Find counter-evidence');
 $challengePlans=array_values(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='plan_step'));verify(json_decode(end($challengePlans)['data_json'],true)['challenge']===true,'Redirect erased challenge metadata');
 verify($snapshot['run']['phase']==='extraction','Extraction not scheduled');$ledger=$snapshot['ledger'];
 verify(count($ledger['sources'])===1&&count($ledger['passages'])===1,'Source/passage deduplication failed');$passage=$ledger['passages'][0];$source=(int)$ledger['sources'][0]['id'];
 verify($passage['exact_text']==='Exact provider source text: the measured effect depends on the population.'&&$passage['content_hash']===hash('sha256',$passage['exact_text']),'Passage changed or hash invalid');
 verify(!str_contains($passage['exact_text'],'Generated answer'),'Model summary promoted into passage');
 denied(fn()=>$engine->addPassage($source,$run,'Invented exact quotation'),'Invented passage accepted');
 denied(fn()=>$engine->addPassage($source,$run,$passage['exact_text'],'Invented page 12'),'Invented locator accepted');
 denied(fn()=>$engine->addEdge($run,$claim,null,9999,'supports','Unknown source'),'Unknown source edge accepted');
 denied(fn()=>$engine->addEdge($run,$claim,(int)$passage['id'],9999,'supports','Mismatch'),'Passage/source mismatch accepted');
 denied(fn()=>$engine->addEdge($run,$claim,null,$source,'supports','Citation-only confidence','high'),'Citation-only inflated strength');
 denied(fn()=>$engine->recordClaimVersion($claim,$run,'supported','No edge yet'),'Unsupported promotion accepted');
 $before=$engine->getEvidenceLedger($run);new AIcontrol\Investigation($dir);new AIcontrol\Investigation($dir);verify($before===$engine->getEvidenceLedger($run),'Idempotent migration modified ledger');
 Wire::$handler=fn($body)=>reply(json_encode(extraction($engine->getEvidenceLedger($run),$claim)));$engine->step($run);
 verify(count($engine->getEvidenceLedger($run)['edges'])===1,'Typed relation not stored');
 $defects=[['claim_id'=>$claim,'severity'=>'high','kind'=>'scope','description'=>'Population scope needs a qualification.']];
 Wire::$handler=function($body)use($engine,$run,$claim,$defects){verify(!isset($body['tools'])&&$body['max_output_tokens']===1600,'Verifier bounds changed');return reply(json_encode(verification($engine->getEvidenceLedger($run),$claim,$defects)));};
 $snapshot=$engine->step($run);verify($snapshot['run']['phase']==='verification_review'&&$snapshot['run']['status']==='running','Verification review pause missing');
 $versions=array_values(array_filter($snapshot['ledger']['claim_versions'],fn($v)=>(int)$v['claim_id']===$claim));verify(end($versions)['status']==='open','High-impact defect not left open');
 $calls=count(Wire::$bodies);$engine->step($run);verify(count(Wire::$bodies)===$calls,'Verification pause spent request');
 Wire::$handler=function($body)use($engine,$run,$claim){verify(str_contains($body['input'],'verification_findings')&&str_contains($body['input'],'Population scope needs a qualification.'),'Repair lost original findings');return reply(json_encode(verification($engine->getEvidenceLedger($run),$claim)));};
 $snapshot=$engine->requestRepair($run);verify((int)$snapshot['run']['repair_count']===1,'Repair not bounded');denied(fn()=>$engine->requestRepair($run),'Second repair accepted');
 verify(count(array_filter($snapshot['nodes'],fn($n)=>$n['title']==='Verification'))===1,'Earlier verification findings deleted');
 $engine->finishVerification($run);$snapshot=$engine->getRunSnapshot($run);verify((int)$snapshot['run']['evidence_frozen']===1&&(int)$snapshot['run']['evidence_version']===2&&$snapshot['run']['status']==='complete','Ledger not frozen');
 denied(fn()=>$engine->addEdge($run,$claim,null,$source,'context','After freeze'),'Frozen edge write accepted');
 denied(fn()=>$engine->addPassage($source,$run,$passage['exact_text']),'Frozen passage write accepted');
 denied(fn()=>$engine->recordClaimVersion($claim,$run,'open','After freeze'),'Frozen assessment write accepted');
 Wire::$handler=function($body){verify(!isset($body['tools'])&&!str_contains($body['input'],'First fixture claim')&&!str_contains($body['input'],'OPTED-IN-CONTEXT')&&str_contains($body['input'],'Exact provider source text'),'Learn context not frozen evidence-only');return reply('Evidence-only explanation');};
 $snapshot=$engine->learn($run,'explain','',[$source]);verify(end($snapshot['nodes'])['status']==='complete','Frozen Learn failed');
 verify(count($snapshot['requests'])===8,'Request accounting omitted a call');
 $report=$engine->export($run);verify(str_contains($report,'Exact provider source text')&&str_contains($report,'Verification findings')&&str_contains($report,'https://example.com/study'),'Ledger export missing evidence');
 // Three searches can retain six distinct capped snippets for a deduplicated source.
 [$dir,$engine,$old,$db,$run,$claim]=rigourFixture(2);$round=0;
 Wire::$handler=function($body)use(&$round){$round++;$r=searchReply();$r['output'][0]['action']['sources']=[['url'=>'https://example.com/study','snippet'=>'Round '.$round.' first source text.'],['url'=>'https://example.com/study','snippet'=>$round===3?str_repeat('😀',1600):'Round '.$round.' second source text.']];return $r;};
 for($i=0;$i<3;$i++)$snapshot=$engine->step($run);
 verify($snapshot['run']['phase']==='extraction'&&count($snapshot['ledger']['sources'])===1&&count($snapshot['ledger']['passages'])===6,'Three-search source/snippet deduplication lost material');
 foreach($snapshot['ledger']['passages'] as $p)verify(strlen($p['exact_text'])<=6000&&mb_strlen($p['exact_text'],'UTF-8')<=2000,'Captured Unicode passage exceeded bounds');
 verify(str_contains($engine->export($run),'Round 1 first source text.'),'Export omitted an unlinked passage');$engine->stop($run);
 // Cross-run IDs exist in SQLite but must never bind into another run's ledger.
 [$dir,$engine,$old,$db,$run,$claim]=rigourFixture();Wire::$handler=fn($body)=>searchReply();$engine->step($run);$engine->step($run);$ledger=$engine->getEvidenceLedger($run);$source=(int)$ledger['sources'][0]['id'];$passage=$ledger['passages'][0];$engine->stop($run);
 $foreignRun=$engine->createRun(1);$foreignClaim=$engine->appendNode($foreignRun,null,'dream_claim','complete','Foreign claim');
 denied(fn()=>$engine->addEdge($foreignRun,$foreignClaim,(int)$passage['id'],$source,'supports','Cross-run passage'),'Cross-run passage accepted');
 denied(fn()=>$engine->addEdge($foreignRun,$foreignClaim,null,$source,'supports','Cross-run source'),'Cross-run source accepted');
 denied(fn()=>$engine->addEdge($foreignRun,$claim,null,$source,'context','Cross-run claim'),'Cross-run claim accepted');
 denied(fn()=>$engine->addPassage($source,$foreignRun,$passage['exact_text']),'Cross-run passage source accepted');$engine->stop($foreignRun);
 // An invalid batch rolls back its valid prefix; a counted failed extraction is not retried.
 [$dir,$engine,$old,$db,$run,$claim]=rigourFixture();Wire::$handler=fn($body)=>searchReply();$engine->step($run);$engine->step($run);
 Wire::$handler=function($body)use($engine,$run,$claim){$data=extraction($engine->getEvidenceLedger($run),$claim);$data['edges'][]=array_merge($data['edges'][0],['source_id'=>999999]);return reply(json_encode($data));};$snapshot=$engine->step($run);
 verify($snapshot['run']['status']==='incomplete'&&!$snapshot['ledger']['edges']&&!$snapshot['ledger']['claim_versions']&&count($snapshot['ledger']['passages'])===1,'Invalid batch left partial assessments or lost evidence');
 $calls=count(Wire::$bodies);$engine->step($run);verify(count(Wire::$bodies)===$calls,'Incomplete extraction automatically retried');
 // The minimum six-call budget reserves a challenge even when normal search is reduced.
 [$dir,$engine,$old,$db]=fixture();$db->exec("INSERT INTO research_messages(project_id,role,text,status,created_at) VALUES(1,'assistant','Spent fixture','complete',".time()."),(1,'assistant','Spent fixture','complete',".time()."),(1,'assistant','Spent fixture','complete',".time()."),(1,'assistant','Spent fixture','complete',".time().")");$run=$engine->createRun(1);verify((int)$engine->getRunSnapshot($run)['run']['normal_search_limit']===1,'Six-call budget did not reduce normal searches');$engine->stop($run);
 $db->exec("INSERT INTO research_messages(project_id,role,text,status,created_at) VALUES(1,'assistant','Spent fixture','complete',".time().")");denied(fn()=>$engine->createRun(1),'Started verification without reserved capacity');
 // Citation-only responses remain inspectable, but cannot produce final passage-backed support.
 [$dir,$engine,$old,$db,$run,$claim]=rigourFixture();Wire::$handler=fn($body)=>searchReply(false);$engine->step($run);$engine->step($run);
 verify(!$engine->getEvidenceLedger($run)['passages'],'Missing snippet was invented');Wire::$handler=fn($body)=>reply(json_encode(extraction($engine->getEvidenceLedger($run),$claim)));$engine->step($run);
 Wire::$handler=fn($body)=>reply(json_encode(verification($engine->getEvidenceLedger($run),$claim)));$snapshot=$engine->step($run);
 $claimVersions=array_values(array_filter($snapshot['ledger']['claim_versions'],fn($v)=>(int)$v['claim_id']===$claim));verify(end($claimVersions)['status']==='open','Citation-only final support accepted');$engine->finishVerification($run);
 // No completed search means no citation source or passage, even with annotations/snippets.
 [$dir,$engine,$old,$db,$run,$claim]=rigourFixture();Wire::$handler=fn($body)=>searchReply(true,false);$snapshot=$engine->step($run);verify(!$snapshot['sources']&&!$snapshot['ledger']['passages'],'Uncompleted tool created evidence');$engine->stop($run);
 // Stop during Evidence cannot apply a late passage or edge.
 [$dir,$engine,$old,$db,$run,$claim]=rigourFixture();Wire::$handler=function($body)use($engine,$run){$engine->stop($run);return searchReply();};$snapshot=$engine->step($run);verify($snapshot['run']['status']==='interrupted'&&!$snapshot['ledger']['passages']&&!$snapshot['ledger']['edges'],'Late Evidence corrupted ledger');$skipped=array_values(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='plan_step'&&(json_decode($n['data_json'],true)['challenge']??false)))[0];verify(isset(json_decode($skipped['data_json'],true)['skipped_reason']),'Unattempted challenge has no skipped reason');
 // Verification Stop retains provisional history but rejects late verdicts.
 [$dir,$engine,$old,$db,$run,$claim]=rigourFixture();Wire::$handler=fn($body)=>searchReply();$engine->step($run);$engine->step($run);Wire::$handler=fn($body)=>reply(json_encode(extraction($engine->getEvidenceLedger($run),$claim)));$engine->step($run);$saved=$engine->getEvidenceLedger($run);
 Wire::$handler=function($body)use($engine,$run,$claim){$result=reply(json_encode(verification($engine->getEvidenceLedger($run),$claim)));$engine->stop($run);return $result;};$snapshot=$engine->step($run);verify($snapshot['run']['status']==='interrupted'&&$saved===$snapshot['ledger'],'Late verifier changed saved ledger');
 // An abandoned verifier becomes uncertain and freezes existing evidence without new ledger writes.
 [$dir,$engine,$old,$db,$run,$claim]=rigourFixture();Wire::$handler=fn($body)=>searchReply();$engine->step($run);$engine->step($run);Wire::$handler=fn($body)=>reply(json_encode(extraction($engine->getEvidenceLedger($run),$claim)));$engine->step($run);$saved=$engine->getEvidenceLedger($run);
 $db->exec("UPDATE research_runs SET busy='abandoned',busy_since=".(time()-121)." WHERE id=$run");$snapshot=$engine->getRunSnapshot($run);verify($snapshot['run']['status']==='uncertain'&&(int)$snapshot['run']['evidence_frozen']===1&&$snapshot['ledger']===$saved,'Stale verifier did not preserve/freeze evidence');denied(fn()=>$engine->recordClaimVersion($claim,$run,'open','Stale write'),'Stale verifier ledger remained writable');
 // No request is reserved or dispatched beyond the combined hourly ceiling, even before verification.
 [$dir,$engine,$old,$db,$run,$claim]=rigourFixture();Wire::$handler=fn($body)=>searchReply();$engine->step($run);$engine->step($run);Wire::$handler=fn($body)=>reply(json_encode(extraction($engine->getEvidenceLedger($run),$claim)));$engine->step($run);
 $spent=(int)$db->query('SELECT COUNT(*) FROM research_requests')->fetchColumn();for($i=$spent;$i<10;$i++)$db->exec("INSERT INTO research_requests(run_id,node_id,kind,status,created_at) VALUES($run,$claim,'learn','complete',".time().")");$calls=count(Wire::$bodies);$snapshot=$engine->step($run);verify(count(Wire::$bodies)===$calls&&$snapshot['run']['status']==='incomplete'&&$snapshot['run']['verification_status']==='skipped','Verification bypassed shared budget or misreported status');
 // Deterministic arithmetic does not delegate correctness to model prose.
 [$dir,$engine,$old,$db,$run,$claim]=rigourFixture();Wire::$handler=fn($body)=>searchReply();$engine->step($run);$engine->step($run);Wire::$handler=fn($body)=>reply(json_encode(extraction($engine->getEvidenceLedger($run),$claim)));$engine->step($run);
 Wire::$handler=fn($body)=>reply(json_encode(verification($engine->getEvidenceLedger($run),$claim,[],[['claim_id'=>$claim,'operation'=>'multiply','a'=>4,'b'=>5,'reported'=>200]])));$snapshot=$engine->step($run);$checksNode=array_values(array_filter($snapshot['nodes'],fn($n)=>$n['title']==='Verification'))[0];verify(json_decode($checksNode['data_json'],true)['calculations'][0]['arithmetic_correct']===false,'Wrong arithmetic passed');$engine->stop($run);
 if($failures)throw new RuntimeException(implode('; ',$failures));
 echo "PASS: $checks rigour ledger, passages, verification, freeze and Stop checks (synthetic transport)\n";
} finally {foreach($dirs as $dir){foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}}
