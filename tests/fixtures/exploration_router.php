<?php
// Disposable synthetic transport, never a deployed application entry point.
namespace AIcontrol {
 function curl_init($url){return new \stdClass();}
 function curl_setopt_array($h,$options){$GLOBALS['exploration_options']=$options;return true;}
 function curl_exec($h){
  $body=json_decode($GLOBALS['exploration_options'][CURLOPT_POSTFIELDS],true);$schema=$body['text']['format']['schema']['properties']??[];$extra=[];
  if(isset($schema['core_claims']))$value=['core_claims'=>[['claim'=>'Local repair hubs could reduce material demand','uncertainty'=>'Throughput','confidence'=>'medium','verify'=>'Measure materials','priority'=>'high'],['claim'=>'Shared storage could extend battery life','uncertainty'=>'Cycling','confidence'=>'low','verify'=>'Measure lifetime','priority'=>'normal']],'competing_angles'=>['Demand reduction'],'least_confident'=>'Transferability','background_assumptions'=>['Available parts']];
  elseif(isset($schema['ideas'])){
   if(isset($body['tools']))throw new \RuntimeException('Model exploration acquired tools');
   $input=json_decode($body['input'],true);
   $paths=[['Shared repair training','Train local volunteers to diagnose appliance faults','Compare diagnosis accuracy before and after workshops'],['Spare part bottlenecks','Pool rare components between neighbouring repair hubs','Measure waiting time under pooled inventory'],['Transport emissions','Schedule shared collection routes for bulky appliances','Compare fuel use per recovered appliance'],['Warranty incentives','Offer a limited warranty on successfully repaired devices','Measure repeat failures and warranty uptake'],['Tool calibration','Calibrate diagnostic equipment against known reference faults','Blind test fault detection on reference devices'],['Apprentice retention','Pair new volunteers with experienced mentors for six weeks','Compare retention under mentoring and solo onboarding'],['Demand forecasting','Forecast seasonal repair demand using booking histories','Evaluate out of sample booking predictions'],['Access barriers','Offer evening repair sessions for shift workers','Compare attendance across session times']];
   $paths=array_merge($paths,[['Safety screening','Quarantine appliances with damaged mains insulation before repair','Measure insulation resistance before volunteer handling'],['Repair triage','Sort incoming appliances by fault severity before assigning work','Compare queue completion time with severity triage'],['Reuse market','Offer refurbished devices through a community resale cooperative','Measure resale revenue against refurbishment costs'],['Supplier concentration','Qualify alternative suppliers for frequently failing connectors','Compare supply interruptions across supplier portfolios'],['Documentation gaps','Create illustrated repair notes for common appliance models','Measure novice completion rates using illustrated guides'],['Water exposure','Track corrosion failures after seasonal flooding','Compare corrosion rates by flood exposure'],['Insurance costs','Pool liability cover across small volunteer workshops','Compare premiums per volunteer hour'],['Software obsolescence','Separate firmware support limits from physical hardware faults','Measure serviceable devices blocked by missing firmware']]);
   $value=['ideas'=>[]];$coveredTitles=array_column($input['coverage']['ideas'],'title');foreach($paths as [$title,$idea,$test]){if(!in_array('Association: '.$title,$coveredTitles,true)){$value=['ideas'=>[['parent_id'=>$input['parents'][0]['id'],'title'=>'Association: '.$title,'idea'=>$idea,'uncertainty'=>'Pilot transferability','test'=>$test]]];break;}}
   if(is_file(getenv('AICONTROL_PRIVATE_DIR').'/repeat-idea-fixture')){foreach(array_reverse($input['coverage']['ideas']) as $covered){if(str_starts_with($covered['title'],'Association:')){$value['ideas']=[['parent_id'=>$input['parents'][0]['id'],'title'=>$covered['title'],'idea'=>$covered['idea'],'uncertainty'=>$covered['uncertainty'],'test'=>$covered['test']]];break;}}}
  }elseif(isset($schema['steps'])){
   $nodes=json_decode(explode('Dream nodes: ',$body['input'])[1],true);$value=['steps'=>array_map(fn($n)=>['claim_id'=>(int)$n['id'],'query'=>'Ground only '.$n['title']],$nodes),'challenge'=>['claim_id'=>(int)$nodes[0]['id'],'query'=>'Counter-evidence']];
  }elseif(isset($body['tools'])){
   $value='Provider summary, not a quotation';$extra=[['type'=>'web_search_call','status'=>'completed','action'=>['sources'=>[['url'=>'https://example.com/pilot','snippet'=>'A measured pilot saved some materials.','locator'=>'Results']]]]];
  }else{
   $ledger=json_decode(explode('Evidence ledger: ',$body['input'])[1],true);$assessments=array_map(fn($n)=>['claim_id'=>(int)$n['id'],'status'=>'open','rationale'=>'Unresolved pilot scope','strength'=>null],$ledger['claims']);
   $value=isset($schema['edges'])?['edges'=>[],'assessments'=>array_values($assessments)]:['assessments'=>array_values($assessments),'defects'=>[],'calculations'=>[]];
  }
  $content=['type'=>'output_text','text'=>is_string($value)?$value:json_encode($value)];if($extra)$content['annotations']=[['type'=>'url_citation','url'=>'https://example.com/pilot','title'=>'Pilot study']];
  $response=['status'=>'completed','usage'=>['input_tokens'=>20,'output_tokens'=>30],'output'=>array_merge($extra,[['type'=>'message','content'=>[$content]]])];
  ($GLOBALS['exploration_options'][CURLOPT_WRITEFUNCTION])($h,"event: response.completed\ndata: ".json_encode(['type'=>'response.completed','response'=>$response])."\n\n");return true;
 }
 function curl_getinfo($h,$option){return 200;}function curl_close($h){}
}
namespace {$_SERVER['SCRIPT_NAME']='/loader.php';require dirname(__DIR__,2).'/app.php';}
