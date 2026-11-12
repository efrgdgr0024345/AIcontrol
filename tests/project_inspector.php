<?php
declare(strict_types=1);
require __DIR__.'/../src/PanelStore.php';require __DIR__.'/../src/Vault.php';require __DIR__.'/../src/AgentRunner.php';require __DIR__.'/../src/Research.php';require __DIR__.'/../src/ProjectInspector.php';
use AIcontrol\ProjectInspector;
umask(0077);$dir=sys_get_temp_dir().'/aicontrol-inspect-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('AICONTROL_PRIVATE_DIR='.$dir);putenv('AICONTROL_OPENAI_API_KEY=PRIVATE-INSPECT-KEY');$checks=0;
function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function denied(callable $work,string $message):void{$blocked=false;try{$work();}catch(Throwable){$blocked=true;}verify($blocked,$message);}
function state(PDO $db):string{$all=[];foreach($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table)$all[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);return json_encode($all,JSON_THROW_ON_ERROR);}
try {
 $research=new AIcontrol\Research($dir);$a=$research->create('Selected project','Selected question?');$b=$research->create('Other project','PRIVATE OTHER PROJECT CONTENT');$empty=$research->create('Empty project','No investigation yet?');
 (new AIcontrol\Vault($dir))->change('hidden','note','PRIVATE VAULT NOTE',false);
 $db=new PDO('sqlite:'.$dir.'/research.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
 $insert=$db->prepare("INSERT INTO research_runs(project_id,status,phase,started_at,model,busy,busy_since)VALUES(?,'running','evidence',1,'synthetic','PRIVATE-WORKER-TOKEN',1)");
 $insert->execute([$a]);$run=(int)$db->lastInsertId();$insert->execute([$b]);$foreign=(int)$db->lastInsertId();
 $db->exec("INSERT INTO research_nodes(run_id,type,status,title,content,created_at,updated_at,data_json)VALUES($run,'dream_claim','complete','Testable claim','Unchecked',1,1,'{}')");$claim=(int)$db->lastInsertId();
 $db->exec("INSERT INTO research_requests(run_id,node_id,kind,status,created_at)VALUES($run,$claim,'evidence','pending',1)");
 $before=state($db);$bundle=ProjectInspector::bundle($dir,$a,$run);verify(state($db)===$before,'Inspection changed stale worker or pending request state');
 verify($bundle['selected_run_id']===$run&&$bundle['snapshot']['run']['status']==='running','Wrong run/state');verify($bundle['map_payload_error']===null,'Valid payload failed');
 verify($bundle['snapshot']['nodes']===(new AIcontrol\Investigation($dir))->getRunSnapshot($run,false)['nodes'],'Different map payload builder');
 verify(!isset($bundle['snapshot']['run']['busy'])&&!isset($bundle['stored_state']['run']['busy']),'Worker token exported');
 $encoded=json_encode($bundle);foreach(['PRIVATE-INSPECT-KEY','PRIVATE OTHER PROJECT CONTENT','PRIVATE VAULT NOTE','PRIVATE-WORKER-TOKEN'] as $secret)verify(!str_contains($encoded,$secret),'Unrelated/private content exported');
 verify($bundle['checks']['warnings']===[],'Healthy references flagged');verify($bundle['stored_state']['nodes'][0]['id']===$claim&&isset($bundle['snapshot']['nodes'][0]['linked_claim_ids']),'Stored/enriched layers missing');
 denied(fn()=>ProjectInspector::bundle($dir,$a,$foreign),'Cross-project run accepted');denied(fn()=>ProjectInspector::bundle($dir,999999),'Unknown project accepted');
 foreach(['0','01','-1','1 OR 1=1',[],null] as $invalid)denied(fn()=>ProjectInspector::id($invalid),'Invalid ID accepted');verify(ProjectInspector::id('0',true)===0,'Latest-run sentinel rejected');
 $none=ProjectInspector::bundle($dir,$empty);verify($none['snapshot']===null&&$none['selected_run_id']===null&&$none['stored_state']===null,'Empty project invented map data');
 // More than the workbench's 20-run history: inspector must still select every saved run.
 for($i=0;$i<22;$i++)$insert->execute([$a]);$latest=(int)$db->lastInsertId();$many=ProjectInspector::bundle($dir,$a);verify($many['selected_run_id']===$latest&&count($many['available_runs'])===23,'Latest/all run selection truncated');verify(ProjectInspector::bundle($dir,$a,$run)['selected_run_id']===$run,'Old saved run unavailable');
 // A parent outside this run is a valid SQLite foreign key, but a broken map reference.
 $db->exec("INSERT INTO research_nodes(run_id,type,status,title,content,created_at,updated_at)VALUES($foreign,'phase','complete','Foreign parent','Other content',1,1)");$outside=(int)$db->lastInsertId();$db->exec("UPDATE research_nodes SET parent_id=$outside WHERE id=$claim");$bad=ProjectInspector::bundle($dir,$a,$run);verify(in_array('missing_parent',array_column($bad['checks']['warnings'],'kind'),true),'Dangling map parent not flagged');
 $db->exec("UPDATE research_nodes SET parent_id=$claim WHERE id=$claim");$bad=ProjectInspector::bundle($dir,$a,$run);verify(in_array('parent_cycle',array_column($bad['checks']['warnings'],'kind'),true),'Parent cycle not flagged');
 $db->exec("UPDATE research_nodes SET parent_id=NULL,data_json='{\"source_ids\":\"invalid\"}' WHERE id=$claim");$before=state($db);$bad=ProjectInspector::bundle($dir,$a,$run);verify($bad['snapshot']===null&&$bad['map_payload_error']!==null,'Payload failure hidden or invented');verify($bad['stored_state']['nodes'][0]['data_json']==='{"source_ids":"invalid"}','Broken raw data lost');verify(state($db)===$before,'Failed inspection changed stored state');
 $lock=fopen($dir.'/provider-request.lock','c');flock($lock,LOCK_EX);verify(ProjectInspector::bundle($dir,$empty)['snapshot']===null,'Read-only inspection blocked by provider admission');flock($lock,LOCK_UN);fclose($lock);
 echo "PASS: $checks project JSON scope, payload parity, read-only stale state, all-run selection, links/cycles, failed-payload fallback and privacy checks\n";
} finally {foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
