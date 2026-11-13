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
   $input=json_decode($body['input'],true);$value=['ideas'=>[['parent_id'=>$input['parents'][0]['id'],'title'=>'Association: shared repair training','idea'=>'Provisional model association','uncertainty'=>'Participation','test'=>'Measure a pilot']]];
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
