<?php
// Disposable provider fixture; never deployed as an application entry point.
namespace AIcontrol {
 function curl_init($url){return new \stdClass();}
 function curl_setopt_array($c,$options){$GLOBALS['nasa_options']=$options;return true;}
 function curl_exec($c){
  $home=getenv('AICONTROL_PRIVATE_DIR');if(is_file($home.'/nasa-fixture-fail'))return false;
  $body=json_decode($GLOBALS['nasa_options'][CURLOPT_POSTFIELDS],true,64,JSON_THROW_ON_ERROR);$input=json_decode($body['input'],true,64,JSON_THROW_ON_ERROR);
  if(isset($body['tools'])||str_contains(json_encode($body),'PRIVATE-UNSELECTED'))throw new \RuntimeException('Fixture detected incorrect NASA context.');
  file_put_contents($home.'/nasa-fixture-requests.jsonl',json_encode(['model'=>$body['model'],'input'=>$input,'max_output_tokens'=>$body['max_output_tokens']])."\n",FILE_APPEND);
  if(str_ends_with($body['text']['format']['name'],'generate')){
   $types=$input['requested_artifacts'];$result=['artifacts'=>array_map(fn($type)=>['type'=>$type,'content'=>'AI draft: '.$input['context']['project']['name']."\n\n".NasaKnowledge::template($type)."\n\nAssumption: the owner will check this draft. Verification has not been executed."],$types),'requirements'=>[],'risks'=>[]];
   if(in_array('requirements',$types,true))$result['requirements'][]=['title'=>'Reject expired sessions','statement'=>'The system shall reject expired sessions before writing project data.','source'=>'ConOps: owner recovery journey','method'=>'test'];
   if(in_array('risks',$types,true))$result['risks'][]=['title'=>'Model output may be wrong','condition'=>'An unchecked model proposal could lead to incorrect work.','owner'=>'Project owner','mitigation'=>'Review proposals and record actual verification before approval.','likelihood'=>3,'consequence'=>4];
  }else $result=['summary'=>'Advisory review of saved project records, not a repository audit.','strengths'=>['A user need is recorded.'],'gaps'=>[['severity'=>'medium','title'=>'Record real verification evidence','detail'=>'Run a bounded test and record the observed result. <script>window.NASA_XSS=true</script>','artifact'=>'verification']],'next_actions'=>['Review the requirements matrix.']];
  $response=['status'=>'completed','model'=>$body['model'],'usage'=>['input_tokens'=>100,'output_tokens'=>200],'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($result,JSON_THROW_ON_ERROR)]]]]];
  ($GLOBALS['nasa_options'][CURLOPT_WRITEFUNCTION])($c,json_encode($response,JSON_THROW_ON_ERROR));return true;
 }
 function curl_getinfo($c,$option){return is_file(getenv('AICONTROL_PRIVATE_DIR').'/nasa-fixture-fail')?502:200;}
 function curl_close($c){}
}
namespace {$_SERVER['SCRIPT_NAME']='/loader.php';require dirname(__DIR__,2).'/app.php';}
