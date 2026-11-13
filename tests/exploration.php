<?php
declare(strict_types=1);
require __DIR__.'/investigation_fixtures.php';
use AIcontrol\InvestigationWire as Wire;
function seedPrior():array{return ['core_claims'=>[['claim'=>'Battery reuse may help local storage','uncertainty'=>'Lifetime','confidence'=>'low','verify'=>'Measure cycles','priority'=>'normal'],['claim'=>'Repair could reduce material use','uncertainty'=>'Scale','confidence'=>'medium','verify'=>'Measure throughput','priority'=>'high']],'competing_angles'=>['Demand reduction'],'least_confident'=>'Scale','background_assumptions'=>['Available batteries']];}
try{
 putenv('AICONTROL_RESEARCH_HOURLY_LIMIT=30');
 [$dir,$engine,$old,$db]=fixture(true);$run=$engine->createRun(1);
 verify($engine->getRunSnapshot($run)['run']['flow_version']===2,'New run did not default to exploration-first');
 denied(fn()=>$engine->lockEvidence($run,[]),'Fresh run accepted evidence');
 Wire::$handler=function($body){verify(!isset($body['tools']),'Seed invoked web tools');return reply(json_encode(seedPrior()));};
 $s=$engine->step($run);verify($s['run']['phase']==='exploring','Seed did not pause for model exploration');
 $a=(int)$s['ledger']['claims'][0]['id'];$b=(int)$s['ledger']['claims'][1]['id'];
 $count=count($s['requests']);$engine->step($run);verify(count($engine->getRunSnapshot($run)['requests'])===$count,'Generic step automatically advanced living map');
 denied(fn()=>$engine->continueRun($run,[$a]),'Legacy continue bypassed explicit lock-in');
 $engine->changeLayer($run,[$a],'demote');$engine->changeLayer($run,[$b],'park','Try after the pilot');$s=$engine->getRunSnapshot($run);
 verify(count($s['requests'])===$count&&count($s['nodes'])===3,'Metadata called provider or removed nodes');
 verify($s['nodes'][1]['layer']===1&&$s['nodes'][2]['park_reason']==='Try after the pilot','Layer/reason did not persist');
 denied(fn()=>$engine->lockEvidence($run,[$b]),'Tertiary branch allowed grounding');
 Wire::$handler=function($body)use($a,$engine,$run){denied(fn()=>$engine->explore($run,[]),'Overlapping model exploration admitted');denied(fn()=>$engine->lockEvidence($run,[$a]),'Lock-in admitted during provider call');verify(!isset($body['tools'])&&str_contains($body['instructions'],'provisional'),'Exploration gained tools or lost provisional instructions');return reply(json_encode(['ideas'=>[['parent_id'=>$a,'title'=>'Shared local repair hubs','idea'=>'A model association only','uncertainty'=>'Demand and logistics','test'=>'Compare measured material savings']]]));};
 $s=$engine->explore($run,[$a],'association');$idea=end($s['nodes']);$c=(int)$idea['id'];
 verify($idea['parent_id']===$a&&$idea['type']==='association'&&$idea['origin']==='model_latent'&&$idea['layer']===0&&$idea['provenance_tag']==='provisional_model','New idea lost parent/type/provenance');
 verify(!$s['sources']&&!$s['ledger']['passages']&&!$s['ledger']['edges'],'Model text became evidence');
 // Snapshot migration is repeatable and does not rewrite stored layers.
 new AIcontrol\Investigation($dir);new AIcontrol\Investigation($dir);verify($engine->getRunSnapshot($run)['nodes'][1]['layer']===1,'Repeated migration rewrote layer');
 denied(fn()=>$engine->changeLayer($run,[$a,9999],'park'),'Mixed foreign IDs accepted');verify($engine->getRunSnapshot($run)['nodes'][1]['layer']===1,'Failed metadata write was partial');
 $engine->lockEvidence($run,[$c]);$s=$engine->getRunSnapshot($run);$target=end($s['ledger']['claims']);$claim=(int)$target['id'];
 verify($s['run']['phase']==='plan'&&$s['run']['auto_search']===0&&$s['run']['growth_remaining']===0,'Lock-in enabled background search/growth');
 verify($target['parent_id']===$c&&json_decode($target['data_json'],true)['grounding_node_id']===$c,'Grounding target lost explicit selection');
 verify(count(array_filter($s['ledger']['claims'],fn($n)=>(int)$n['selected']===1))===1,'Unselected branches entered evidence');
 Wire::$handler=fn($body)=>reply(json_encode(['steps'=>[['claim_id'=>$a,'query'=>'Wrong unlocked branch']],'challenge'=>['claim_id'=>$claim,'query'=>'Challenge repair hubs']]));
 // A separate invalid-plan fixture tests rollback without losing the main run.
 [$badDir,$badEngine,$badOld,$badDb]=fixture(true);$badRun=$badEngine->createRun(1);Wire::$handler=fn($body)=>reply(json_encode(seedPrior()));$bad=$badEngine->step($badRun);$badA=(int)$bad['ledger']['claims'][0]['id'];$badEngine->lockEvidence($badRun,[$badA]);
 Wire::$handler=fn($body)=>reply(json_encode(['steps'=>[['claim_id'=>$badA,'query'=>'Unlocked original']],'challenge'=>['claim_id'=>$badA,'query'=>'Wrong'] ]));$bad=$badEngine->step($badRun);verify($bad['run']['status']==='incomplete'&&!array_filter($bad['nodes'],fn($n)=>$n['type']==='plan_step'),'Plan escaped selected grounding targets');
 putenv('AICONTROL_PRIVATE_DIR='.$dir);
 Wire::$handler=function($body)use($claim){verify(!isset($body['tools']),'Planner used web tools');return reply(json_encode(['steps'=>[['claim_id'=>$claim,'query'=>'Repair hub measured savings']],'challenge'=>['claim_id'=>$claim,'query'=>'Counter-evidence repair hubs']]));};
 $s=$engine->step($run);verify($s['run']['phase']==='evidence','Locked plan failed');
 denied(fn()=>$engine->step($run,$b),'Evidence redirected to an unlocked branch');
 Wire::$handler=function($body)use($claim){verify(isset($body['tools'])&&$body['max_tool_calls']===1,'Locked Evidence lost bounded web search');$r=reply('Provider summary, not a quote',[['type'=>'web_search_call','status'=>'completed','action'=>['sources'=>[['url'=>'https://example.com/repair','snippet'=>'Measured material savings in one pilot.','locator'=>'Results']]]]]);$r['output'][1]['content'][0]['annotations']=[['type'=>'url_citation','url'=>'https://example.com/repair','title'=>'Pilot study']];return $r;};
 $s=$engine->step($run);$s=$engine->step($run);verify($s['run']['phase']==='extraction'&&count($s['sources'])===1&&count($s['ledger']['passages'])>0,'Evidence did not reuse real source/passages ledger');
 $passage=(int)$s['ledger']['passages'][0]['id'];$source=(int)$s['sources'][0]['id'];
 Wire::$handler=fn($body)=>reply(json_encode(['edges'=>[['claim_id'=>$claim,'passage_id'=>$passage,'source_id'=>$source,'relation'=>'supports','rationale'=>'Limited pilot','strength'=>'moderate']],'assessments'=>[['claim_id'=>$claim,'status'=>'supported','rationale'=>'Limited pilot only','strength'=>'moderate']]]));$s=$engine->step($run);
 Wire::$handler=fn($body)=>reply(json_encode(['assessments'=>[['claim_id'=>$claim,'status'=>'supported','rationale'=>'Within pilot scope','strength'=>'moderate']],'defects'=>[],'calculations'=>[]]));$s=$engine->step($run);
 verify($s['run']['phase']==='verification_review','Verification review lost');$original=array_values(array_filter($s['nodes'],fn($n)=>$n['id']===$c))[0];verify($original['provenance_tag']==='grounded'&&$original['origin']==='model_latent','Grounding lost original provenance');
 // Continue exploring and lock another branch without deleting earlier records.
 Wire::$handler=fn($body)=>reply(json_encode(['ideas'=>[['parent_id'=>0,'title'=>'A fresh avenue','idea'=>'Unchecked','uncertainty'=>'Transfer','test'=>'Measure']]]));$s=$engine->explore($run,[],'reframing');verify($s['run']['phase']==='exploring'&&count($s['sources'])===1,'Exploring after verification lost prior evidence');
 $engine->changeLayer($run,[$b],'promote');$engine->lockEvidence($run,[$b]);verify($engine->getRunSnapshot($run)['run']['phase']==='plan','Second bounded lock-in failed');
 $s=$engine->getRunSnapshot($run);$second=(int)end($s['ledger']['claims'])['id'];
 Wire::$handler=fn($body)=>reply(json_encode(['steps'=>[['claim_id'=>$second,'query'=>'Only second branch']],'challenge'=>['claim_id'=>$second,'query'=>'Challenge second branch']]));$s=$engine->step($run);
 Wire::$handler=fn($body)=>reply('No completed search and no source.');$s=$engine->step($run);$s=$engine->step($run);verify($s['run']['phase']==='extraction','Repeated lock counted historical plan slots as new searches');
 Wire::$handler=function($body)use($second){$ledger=json_decode(explode('Evidence ledger: ',$body['input'])[1],true);verify(!$ledger['sources']&&!$ledger['passages']&&!$ledger['edges']&&count($ledger['claims'])===1,'Previous unselected evidence leaked into focused ledger');return reply(json_encode(['edges'=>[],'assessments'=>[['claim_id'=>$second,'status'=>'open','rationale'=>'No material','strength'=>null]]]));};$s=$engine->step($run);
 Wire::$handler=fn($body)=>reply(json_encode(['assessments'=>[['claim_id'=>$second,'status'=>'supported','rationale'=>'Unsupported model assertion','strength'=>'moderate']],'defects'=>[],'calculations'=>[]]));$s=$engine->step($run);$node=array_values(array_filter($s['nodes'],fn($n)=>$n['id']===$b))[0];
 verify($s['run']['phase']==='verification_review'&&$node['provenance_tag']==='locked','Unbacked verifier assertion incorrectly marked branch grounded');
 $engine->finishVerification($run);denied(fn()=>$engine->explore($run,[]),'Frozen run accepted growth');$engine->changeLayer($run,[$a],'promote');verify($engine->getRunSnapshot($run)['nodes'][1]['layer']===0,'Frozen metadata stewardship failed');
 // Stop races retain usage but reject all late ideas.
 $engine->stop($run);[$raceDir,$race,$raceOld,$raceDb]=fixture(true);$raceRun=$race->createRun(1);Wire::$handler=fn($body)=>reply(json_encode(seedPrior()));$s=$race->step($raceRun);$before=count($s['nodes']);
 Wire::$handler=function($body)use($race,$raceRun){$race->stop($raceRun);return reply(json_encode(['ideas'=>[['parent_id'=>0,'title'=>'Late idea','idea'=>'Must not save','uncertainty'=>'Unknown','test'=>'None']]]));};$s=$race->explore($raceRun,[]);verify($s['run']['status']==='interrupted'&&count($s['nodes'])===$before+1&&!array_filter($s['nodes'],fn($n)=>$n['title']==='Late idea'),'Stop allowed late growth');
 denied(fn()=>$race->explore($raceRun,[]),'Stopped run grew again');
 // Multi-selection produces one plan slot for every target plus Challenge.
 [$multiDir,$multi,$multiOld,$multiDb]=fixture(true);$multiRun=$multi->createRun(1);Wire::$handler=fn($body)=>reply(json_encode(seedPrior()));$multi->step($multiRun);
 Wire::$handler=fn($body)=>reply(json_encode(['ideas'=>array_map(fn($i)=>['parent_id'=>0,'title'=>'Alternative '.$i,'idea'=>'Unchecked model path','uncertainty'=>'Scope','test'=>'Measure'],range(1,5))]));$s=$multi->explore($multiRun,[]);$ids=array_map('intval',array_column(array_filter($s['nodes'],fn($n)=>$n['type']==='model_idea'),'id'));$multi->lockEvidence($multiRun,$ids);$s=$multi->getRunSnapshot($multiRun);$targets=array_values(array_filter($s['ledger']['claims'],fn($n)=>(int)$n['selected']===1));
 Wire::$handler=function($body)use($targets){$schema=$body['text']['format']['schema']['properties']['steps'];verify($schema['minItems']===5&&$schema['maxItems']===5,'Five selected branches not represented by plan schema');return reply(json_encode(['steps'=>array_map(fn($n)=>['claim_id'=>(int)$n['id'],'query'=>'Ground only '.$n['title']],$targets),'challenge'=>['claim_id'=>(int)$targets[0]['id'],'query'=>'Counter-evidence']]));};$s=$multi->step($multiRun);verify($s['run']['phase']==='evidence'&&count(array_filter($s['nodes'],fn($n)=>$n['type']==='plan_step'))===6,'Five selected branches did not get bounded slots');$multi->stop($multiRun);
 // Exploration shares hourly and project spend admission before dispatch.
 [$capDir,$capEngine,$capOld,$capDb]=fixture(true);$capRun=$capEngine->createRun(1);Wire::$handler=fn($body)=>reply(json_encode(seedPrior()));$s=$capEngine->step($capRun);$capEngine->configureProject(1,'gpt-4.1',0.05,false,0);$capDb->exec('UPDATE research_requests SET estimated_usd=0.049');$calls=0;Wire::$handler=function($body)use(&$calls){$calls++;return reply('Must not dispatch');};$s=$capEngine->explore($capRun,[]);verify($calls===0&&$s['run']['status']==='incomplete'&&str_contains($s['run']['stop_reason'],'cap'),'Exploration bypassed project spend reservation');
 [$hourDir,$hourEngine,$hourOld,$hourDb]=fixture(true);$hourRun=$hourEngine->createRun(1);Wire::$handler=fn($body)=>reply(json_encode(seedPrior()));$s=$hourEngine->step($hourRun);$phase=(int)$s['nodes'][0]['id'];for($i=0;$i<29;$i++)$hourDb->exec("INSERT INTO research_requests(run_id,node_id,kind,status,created_at) VALUES($hourRun,$phase,'model_explore','complete',".time().")");$calls=0;Wire::$handler=function($body)use(&$calls){$calls++;return reply('Must not dispatch');};$s=$hourEngine->explore($hourRun,[]);verify($calls===0&&$s['run']['status']==='incomplete'&&$s['budget']['remaining']===0,'Exploration bypassed hourly ceiling');
 echo "PASS: $checks exploration-first migration, layers, model-only growth, scoped lock-in, ledger, repeat rounds and Stop checks (synthetic transport)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}finally{foreach($dirs as $dir){foreach(glob($dir.'/*') as $file)@unlink($file);@rmdir($dir);}}
