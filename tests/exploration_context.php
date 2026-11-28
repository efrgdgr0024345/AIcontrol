<?php
declare(strict_types=1);
require __DIR__.'/investigation_fixtures.php';
use AIcontrol\InvestigationWire as Wire;
try{
 putenv('AICONTROL_RESEARCH_HOURLY_LIMIT=30');
 [$dir,$engine,$old,$db]=fixture(true);$run=$engine->createRun(1);
 // Include a parked sibling, a priority branch, an earlier run, and a separate project.
 $seed=['core_claims'=>[['claim'=>'Repair could reduce material use','uncertainty'=>'Throughput','confidence'=>'medium','verify'=>'Measure throughput','priority'=>'high','next_question'=>'Which part of the repair process changes material use?'],['claim'=>'Battery reuse may extend service life','uncertainty'=>'Cycling','confidence'=>'low','verify'=>'Measure cycles','priority'=>'normal']],'competing_angles'=>['Demand'],'least_confident'=>'Scale','background_assumptions'=>['Parts']];
 Wire::$handler=fn($body)=>reply(json_encode($seed));$s=$engine->step($run);$a=(int)$s['ledger']['claims'][0]['id'];$b=(int)$s['ledger']['claims'][1]['id'];$engine->changeLayer($run,[$b],'park','Later');
 $engine->stop($run);$other=$old->create('Unrelated project','PRIVATE OTHER PROJECT');$otherRun=$engine->createRun((int)$other);$foreign=$engine->appendNode($otherRun,null,'model_idea','complete','DO NOT INCLUDE','Unrelated content');$engine->stop($otherRun);
 // A real historical run, in the same project, remains coverage rather than a parent candidate.
 $run=$engine->createRun(1);Wire::$handler=fn($body)=>reply(json_encode($seed));$s=$engine->step($run);$anchor=(int)$s['ledger']['claims'][0]['id'];
 $idea=['parent_id'=>$anchor,'title'=>'Pool spare parts between hubs','idea'=>'Shared inventories could reduce delays caused by rare components at small repair hubs.','uncertainty'=>'Transport costs','test'=>'Compare waiting times across pooled and isolated inventories','connection'=>'Inventory tests whether delays reduce completed repairs.','next_question'=>'Which parts cause most of the delays?'];
 Wire::$handler=function($body)use($a,$b,$anchor,$foreign,$idea){
  $input=json_decode($body['input'],true);$covered=array_column($input['coverage']['ideas'],null,'id');
  verify(isset($covered[$a],$covered[$b],$covered[$anchor]),'History omitted a saved line or previous run');verify($covered[$b]['layer']===2,'Parked line omitted or reactivated');verify(!isset($covered[$foreign])&&!str_contains($body['input'],'PRIVATE OTHER PROJECT'),'Cross-project context leaked');
  verify(array_column($input['areas_of_interest'],'id')===[$anchor],'Selected interest missing');verify(array_column($input['parents'],'id')===[$anchor],'History broadened allowed parent IDs');
  verify(!isset($body['tools'])&&str_contains($body['instructions'],'Do not regenerate'),'Novelty or model-only guard missing');return reply(json_encode(['ideas'=>[$idea,$idea]]));
 };
 $s=$engine->explore($run,[$anchor],'explore',[$anchor]);$new=array_values(array_filter($s['nodes'],fn($n)=>$n['type']==='model_idea'));verify(count($new)===1,'Within-response duplicate saved');$child=(int)$new[0]['id'];
 $engine->savePathNote($run,$child,'RESEARCHER-NOTE-LOCAL');
 verify($engine->getRunSnapshot($run)['nodes'][array_search($child,array_column($engine->getRunSnapshot($run)['nodes'],'id'))]['path_note']==='RESEARCHER-NOTE-LOCAL','Pathway note was not persisted');
 denied(fn()=>$engine->savePathNote($run,$foreign,'Foreign note'),'Cross-run note admitted');
 // An explicit interest survives while parents move forward; the entire ancestry is supplied.
 Wire::$handler=function($body)use($anchor,$child,$idea){$input=json_decode($body['input'],true);verify(array_column($input['areas_of_interest'],'id')===[$anchor]&&array_column($input['parents'],'id')===[$child],'Interest drifted with the frontier');verify(array_column($input['selected_logic_paths'],'id')===[$child,$anchor],'Logical ancestry missing');verify(array_column($input['selected_logic_paths'],'next_question')===[$idea['next_question'],'Which part of the repair process changes material use?'],'Next questions lost from active path');verify(!str_contains($body['input'],'RESEARCHER-NOTE-LOCAL'),'Local note sent to provider');verify(in_array($child,array_column($input['coverage']['ideas'],'id'),true),'Last round absent from next request');$idea['parent_id']=$child;$idea['title']=strtoupper($idea['title']).'!';return reply(json_encode(['ideas'=>[$idea]]));};
 $before=count($s['nodes']);$s=$engine->explore($run,[$child],'explore',[$anchor]);verify(count($s['nodes'])===$before+1,'Repeated idea under a new parent saved');$phase=end($s['nodes']);$result=json_decode($phase['data_json'],true)['growth_result'];verify($result===['received'=>1,'saved'=>0,'duplicates_skipped'=>1]&&$s['run']['phase']==='exploring'&&!$s['sources'],'Duplicate-only response lost the idle/model-only contract');
 // New mechanisms sharing a topic must survive the conservative duplicate check.
 Wire::$handler=fn($body)=>reply(json_encode(['ideas'=>[['parent_id'=>$child,'title'=>$idea['title'],'idea'=>'A rotating courier route could make pooled inventory accessible to remote towns.','uncertainty'=>'Fuel costs','test'=>'Measure delivery distances per completed repair']]]));$s=$engine->explore($run,[$child],'explore',[$anchor]);verify(count(array_filter($s['nodes'],fn($n)=>$n['type']==='model_idea'))===2,'Distinct mechanism sharing a title was rejected');
 $newTest=$idea;$newTest['parent_id']=$child;$newTest['test']='Randomise regional hubs to component sharing and compare transport costs per completed repair';Wire::$handler=fn($body)=>reply(json_encode(['ideas'=>[$newTest]]));$s=$engine->explore($run,[$child]);verify(count(array_filter($s['nodes'],fn($n)=>$n['type']==='model_idea'))===3,'A new discriminating test for a known mechanism was rejected');
 // Zero useful extensions is a completed round, not an error or reason to retry.
 Wire::$handler=fn($body)=>reply('{"ideas":[]}');$s=$engine->explore($run,[$child]);verify($s['run']['phase']==='exploring'&&end($s['requests'])['status']==='complete','Exhausted path became an uncertain/retried request');
 // Hundreds of older branches stay represented, including non-ASCII labels.
 $bulk=[];for($i=0;$i<350;$i++)$bulk[]=$engine->appendNode($run,null,'association','complete','Older pathway '.$i.' — 地域','Distinct saved hypothesis '.$i.' about local repair constraints');
 Wire::$handler=function($body)use($bulk){$input=json_decode($body['input'],true,512,JSON_THROW_ON_ERROR);verify(!array_diff($bulk,array_column($input['coverage']['ideas'],'id')),'Large-map context silently dropped old lines');verify(strlen($body['input'])<=240000,'Context size guard failed');return reply('{"ideas":[]}');};$engine->explore($run,[$child],'explore',[$anchor]);
 $count=count(Wire::$bodies);denied(fn()=>$engine->explore($run,[$child],'explore',[$foreign]),'Cross-run interest admitted');verify(count(Wire::$bodies)===$count,'Invalid interest reached provider');
 for($i=0;$i<1000;$i++)$engine->appendNode($run,null,'association','complete','Additional historical pathway '.$i.' with a retained descriptive title','A detailed saved topic that must remain represented rather than silently omitted.');
 $count=count(Wire::$bodies);$s=$engine->explore($run,[$child]);verify(count(Wire::$bodies)===$count&&$s['run']['status']==='incomplete'&&end($s['requests'])['billing_json']!==null,'Oversized coverage dispatched a paid request or lost the no-dispatch ledger');
 echo "PASS: $checks coverage, project isolation, stable interests, ancestry, duplicate suppression and exhaustion checks (synthetic provider)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}finally{foreach($dirs as $dir){foreach(glob($dir.'/*') as $file)@unlink($file);@rmdir($dir);}}
