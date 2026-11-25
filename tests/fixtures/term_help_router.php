<?php
// Disposable provider fixture. All term definitions here are synthetic.
namespace AIcontrol {
    function curl_init($url){if($url!=='https://api.openai.com/v1/responses')throw new \RuntimeException('Wrong URL');return new \stdClass();}
    function curl_setopt_array($h,$options){$GLOBALS['term_options']=$options;return true;}
    function curl_exec($h){
        $body=json_decode($GLOBALS['term_options'][CURLOPT_POSTFIELDS],true);
        if($body['model']!=='gpt-5.4-nano'||isset($body['tools'])||$body['max_output_tokens']!==160)throw new \RuntimeException('Unbounded term request');
        file_put_contents(getenv('AICONTROL_PRIVATE_DIR').'/term-fixture-calls',"1\n",FILE_APPEND);
        $response=['status'=>'completed','model'=>'gpt-5.4-nano','usage'=>['input_tokens'=>100,'output_tokens'=>50],'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'A counterfactual describes a different possible situation. For example, compare a repair with and without shared tools. <img src=x onerror=alert(1)>']]]]];
        ($GLOBALS['term_options'][CURLOPT_WRITEFUNCTION])($h,json_encode($response));return true;
    }
    function curl_getinfo($h,$option){return 200;}
}
namespace {$_SERVER['SCRIPT_NAME']='/loader.php';require dirname(__DIR__,2).'/app.php';}
