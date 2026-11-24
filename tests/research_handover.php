<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Research.php';require_once __DIR__.'/../src/ResearchHandover.php';
use AIcontrol\{Research,ResearchHandover};
$dir=sys_get_temp_dir().'/aicontrol-review-'.bin2hex(random_bytes(6));mkdir($dir,0700);$checks=0;
function check(bool $v,string $why):void{global $checks;$checks++;if(!$v)throw new RuntimeException($why);}
try{
 $r=new Research($dir);$id=$r->create('Complete review fixture','Compare two approaches and preserve unfinished work.');$other=$r->create('PRIVATE OTHER PROJECT','unrelated-private-brief');$db=new PDO('sqlite:'.$dir.'/research.sqlite');
 $q=$db->prepare('INSERT INTO research_runs(project_id,status,phase,started_at,model,busy,flow_version)VALUES(?,?,?,?,?,?,?)');foreach([[$id,'interrupted','exploring',1,'fixture-model','private-lease-one',2],[$id,'complete','complete',2,'fixture-model','private-lease-two',1],[$other,'failed','dream',3,'fixture-model','private-other',0]] as $row)$q->execute($row);
 $node=$db->prepare('INSERT INTO research_nodes(run_id,type,status,title,content,created_at,updated_at)VALUES(?,?,?,?,?,0,0)');$node->execute([1,'model_idea','complete','Idea one','Full body of the first idea.']);$node->execute([2,'learn','complete','Concept conversation','Question and full saved answer.']);$node->execute([3,'model_idea','complete','PRIVATE OTHER NODE','private-other-content']);
 $db->exec("INSERT INTO research_messages(project_id,role,text,status,created_at)VALUES(1,'user','Earlier project conversation','complete',0)");$before=file_get_contents($dir.'/research.sqlite');$d=ResearchHandover::data($dir,$id);$md=ResearchHandover::markdown($d);
 check($d['method_word_count']<=250,'Method overview exceeds compact budget');check(count($d['runs'])===2&&count($d['saved_tables']['research_nodes'])===2,'Older run content omitted');check(count($d['project_conversations'])===1,'Project chat omitted');check(str_contains($md,'Full body of the first idea.')&&str_contains($md,'Question and full saved answer.'),'Full saved bodies omitted');check(!str_contains($md,'PRIVATE OTHER')&&!str_contains($md,'private-other'),'Cross-project content leaked');check(!str_contains($md,'private-lease'),'Worker lease exported');check(str_contains($md,'not a reconstruction of private model chain-of-thought'),'Method misrepresents reasoning visibility');check(str_ends_with(trim($md),'?'),'Handover does not end with a review question');check(str_contains($md,'Web Evidence')&&str_contains($md,'provisional'),'Method omitted exploration/evidence distinction');check(file_get_contents($dir.'/research.sqlite')===$before,'Export mutated research data');
 $empty=$r->create('Empty project','Nothing run yet.');$e=ResearchHandover::data($dir,$empty);check(!$e['runs']&&!$e['saved_tables']['research_nodes'],'Empty project not supported');
 $parts=ResearchHandover::copyParts($d);$output=json_decode($parts['data']['text'],true,64,JSON_THROW_ON_ERROR);
 check($output['project']==$d['project']&&$output['saved_tables']==$d['saved_tables']&&$output['runs']==$d['runs']&&$output['project_conversations']==$d['project_conversations'],'Data-only copy lost saved research');
 check(!isset($output['method_overview'])&&!isset($output['method_word_count'])&&!str_contains($parts['data']['text'],ResearchHandover::REVIEW),'Data-only copy includes explanatory material');
 check(str_contains($parts['explanation']['text'],ResearchHandover::METHOD)&&str_contains($parts['explanation']['text'],ResearchHandover::REVIEW)&&!str_contains($parts['explanation']['text'],'Full body of the first idea.'),'Explanation-only copy lost context or included saved output');
 check($parts['complete']['text']===$md&&str_ends_with(trim($parts['explanation']['text']),'?'),'Complete handover or reviewer question changed');
 foreach($parts as $part)check($part['approximate_tokens']>0&&$part['approximate_tokens']===(int)ceil(strlen($part['text'])/4),'Token estimate does not describe its exact copied payload');
 check(ResearchHandover::approximateTokens('')===0&&ResearchHandover::approximateTokens('Café 中文 🚀')===(int)ceil(strlen('Café 中文 🚀')/4),'UTF-8 or empty token estimate failed');
 echo "PASS: $checks compact-method, all-run/chat content, project isolation, lease exclusion, read-only and useful-review-question checks\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
