<?php
declare(strict_types=1);
namespace AIcontrol {
    final class InvestigationWire {
        public static array $bodies=[];
        public static array $options=[];
        public static mixed $handler=null;
        public static int $status=200;
        public static bool $ok=true;
        public static mixed $observer=null;
    }
    function curl_init(string $url):object { if($url!=='https://api.openai.com/v1/responses')throw new \RuntimeException('Wrong provider');return new \stdClass(); }
    function curl_setopt_array(object $c,array $options):bool { InvestigationWire::$options=$options;return true; }
    function curl_exec(object $c):bool {
        $body=json_decode(InvestigationWire::$options[CURLOPT_POSTFIELDS],true);InvestigationWire::$bodies[]=$body;
        $reply=(InvestigationWire::$handler)($body);
        if(isset($reply['__chunks'])){
            foreach($reply['__chunks'] as $chunk){(InvestigationWire::$options[CURLOPT_WRITEFUNCTION])($c,$chunk);if(InvestigationWire::$observer!==null)(InvestigationWire::$observer)();}
            return InvestigationWire::$ok;
        }
        (InvestigationWire::$options[CURLOPT_WRITEFUNCTION])($c,json_encode($reply,JSON_THROW_ON_ERROR));return InvestigationWire::$ok;
    }
    function curl_getinfo(object $c,int $option):int{return InvestigationWire::$status;}
    function curl_close(object $c):void{}
}
namespace {
require dirname(__DIR__).'/src/Vault.php';require dirname(__DIR__).'/src/AgentRunner.php';require dirname(__DIR__).'/src/Research.php';
putenv('AICONTROL_RESEARCH_HOURLY_LIMIT=10');
umask(0077);$checks=0;$dirs=[];$failures=[];
function verify(bool $condition,string $message):void{global $checks,$failures;if(!$condition){$failures[]=$message;throw new \RuntimeException($message);}$checks++;}
function denied(callable $work,string $label):void{$denied=false;try{$work();}catch(\RuntimeException){$denied=true;}verify($denied,$label);}
function evidenceCards(array $body):array{return json_decode(explode("\nUser question/understanding: ",explode('Collected evidence: ',$body['input'])[1])[0],true,32,JSON_THROW_ON_ERROR);}
function legacyRun($engine,$db):int{$id=$engine->createRun(1);$db->exec("UPDATE research_runs SET rigour_version=0,flow_version=0,auto_search=0,growth_remaining=0 WHERE id=$id");return $id;}
function fixture(bool $living=false):array {
 global $dirs;$dir=sys_get_temp_dir().'/aicontrol-investigate-'.bin2hex(random_bytes(7));mkdir($dir,0700);$dirs[]=$dir;
 putenv('AICONTROL_PRIVATE_DIR='.$dir);putenv('AICONTROL_OPENAI_API_KEY=synthetic-test-only');
 $old=new \AIcontrol\Research($dir);$old->create('Research fixture','Check household solar claims');
 $vault=new \AIcontrol\Vault($dir);$vault->change('shared','note','OPTED-IN-CONTEXT',true);$vault->change('hidden','note','NEVER-SEND-THIS',false);
 $engine=new \AIcontrol\Investigation($dir);$db=new \PDO('sqlite:'.$dir.'/research.sqlite');if(!$living)$db->exec('UPDATE research_projects SET exploration_first=0');return [$dir,$engine,$old,$db];
}
function reply(string $text,array $extra=[]):array{return ['status'=>'completed','usage'=>['input_tokens'=>10,'output_tokens'=>20],'output'=>array_merge($extra,[['type'=>'message','content'=>[['type'=>'output_text','text'=>$text]]]])];}

}
