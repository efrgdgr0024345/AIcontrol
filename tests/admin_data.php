<?php
declare(strict_types=1);
require __DIR__.'/../src/PanelStore.php';require __DIR__.'/../src/Vault.php';require __DIR__.'/../src/AgentRunner.php';require __DIR__.'/../src/Research.php';require __DIR__.'/../src/AdminData.php';
use AIcontrol\AdminData;
umask(0077);$directory=sys_get_temp_dir().'/aicontrol-admin-data-'.bin2hex(random_bytes(6));mkdir($directory,0700);putenv('AICONTROL_PRIVATE_DIR='.$directory);putenv('AICONTROL_OPENAI_API_KEY=PRIVATE-ENV-FIXTURE');$checks=0;
function verify(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function denied(callable $work,string $message):void{$blocked=false;try{$work();}catch(RuntimeException){$blocked=true;}verify($blocked,$message);}
function change(AdminData $admin,string $operation,int $id=0):void{$admin->change($operation,$id,AdminData::token($operation,$id));}
function countRows(PDO $db,string $table):int{return (int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
function graph(PDO $db,int $project):int {
 $db->exec("INSERT INTO research_runs(project_id,status,phase,started_at,model)VALUES($project,'complete','reconciliation',1,'synthetic')");$run=(int)$db->lastInsertId();
 $db->exec("INSERT INTO research_nodes(run_id,type,status,title,content,created_at,updated_at)VALUES($run,'dream_claim','complete','Claim','Saved text',1,1)");$claim=(int)$db->lastInsertId();
 $db->exec("INSERT INTO research_nodes(run_id,parent_id,type,status,title,content,created_at,updated_at)VALUES($run,$claim,'source','complete','Source','Evidence',1,1)");$node=(int)$db->lastInsertId();
 $db->exec("INSERT INTO research_sources(run_id,node_id,url,title,extract,domain,retrieved_at,meta_json)VALUES($run,$node,'https://example.com/$run','Source','Extract','example.com',1,'{}')");$source=(int)$db->lastInsertId();
 $db->exec("INSERT INTO research_passages(source_id,run_id,exact_text,content_hash,created_at)VALUES($source,$run,'Exact passage','hash',1)");$passage=(int)$db->lastInsertId();
 $db->exec("INSERT INTO research_edges(run_id,claim_id,passage_id,source_id,relation,rationale,created_at)VALUES($run,$claim,$passage,$source,'supports','Scope',1)");
 $db->exec("INSERT INTO research_claim_versions(claim_id,run_id,status,created_at)VALUES($claim,$run,'open',1)");
 $db->exec("INSERT INTO research_path_rankings(run_id,node_id,weight,tier,reason,created_at)VALUES($run,$claim,50,'primary','Priority',1)");
 $db->exec("INSERT INTO research_requests(run_id,node_id,kind,status,created_at)VALUES($run,$claim,'evidence','complete',1)");return $run;
}
try {
 $store=new AIcontrol\PanelStore($directory.'/panel.sqlite',true);$p1=$store->createProject('Task project one','Keep');$p2=$store->createProject('Task project two','Keep');$t1=$store->createTask($p1,'Task one','Criteria');$t2=$store->createTask($p2,'Task two','Criteria');
 $agent=$store->startAgent($t1,'gpt-4.1-mini');$store->finishAgent($agent['id'],'in_review','Synthetic output');
 $research=new AIcontrol\Research($directory);$r1=$research->create('Research one','Question one?');$r2=$research->create('Research two','Question two?');
 $db=new PDO('sqlite:'.$directory.'/research.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA foreign_keys=ON');$panel=new PDO('sqlite:'.$directory.'/panel.sqlite');
 $run1=graph($db,$r1);$run2=graph($db,$r2);$db->exec("INSERT INTO research_messages(project_id,role,text,status,created_at)VALUES($r1,'user','PRIVATE PROJECT CONTENT','saved',1)");
 $vault=new AIcontrol\Vault($directory);$vault->change('secret','key','PRIVATE-VAULT-FIXTURE',false);file_put_contents($directory.'/config.json','PROTECTED CONFIG');mkdir($directory.'/sessions',0700);file_put_contents($directory.'/sessions/session-fixture','PROTECTED SESSION');
 $protected=[];foreach(['vault.key','vault.json','config.json','sessions/session-fixture'] as $file)$protected[$file]=hash_file('sha256',$directory.'/'.$file);
 $panel->exec("INSERT INTO login_failures(client,attempted_at)VALUES('security-fixture',1)");$admin=new AdminData($directory);
 $report=$admin->report();verify(count($report['databases'])===2,'Database inventory');verify($report['databases'][0]['driver']==='sqlite'&&$report['databases'][0]['path']===$directory.'/panel.sqlite','Driver/path not confirmed');verify($report['databases'][1]['quick_check']==='ok','Health not queried');verify($report['php']['version']===PHP_VERSION&&isset($report['php']['settings']['display_errors']),'PHP details missing');
 $encoded=json_encode($report);foreach(['PRIVATE-ENV-FIXTURE','PRIVATE-VAULT-FIXTURE','PRIVATE PROJECT CONTENT','PROTECTED CONFIG','PROTECTED SESSION'] as $private)verify(!str_contains($encoded,$private),'Technical report leaked private content');
 $tables=array_column($report['databases'][1]['tables'],'name');foreach(['research_passages','research_edges','research_claim_versions','research_path_rankings','research_requests'] as $table)verify(in_array($table,$tables,true),'Missing table '.$table);
 denied(fn()=>$admin->change('delete_research_project',$r1,'wrong'),'Missing confirmation accepted');verify(countRows($db,'research_projects')===2,'Confirmation failure changed data');
 denied(fn()=>change($admin,'not_a_table'),'Arbitrary operation allowed');
 $lock=fopen($directory.'/provider-request.lock','c');flock($lock,LOCK_EX);denied(fn()=>change($admin,'reset_workspace'),'Active provider did not block deletion');flock($lock,LOCK_UN);fclose($lock);verify(countRows($db,'research_runs')===2,'Busy rejection changed data');
 change($admin,'delete_research_run',$run1);verify(countRows($db,'research_runs')===1&&countRows($db,'research_edges')===1,'Run deletion affected another project or left edges');verify(!$db->query('PRAGMA foreign_key_check')->fetchAll(),'Run deletion orphaned data');verify(countRows($db,'research_messages')===1,'Run deletion removed chat');
 $run1=graph($db,$r1);change($admin,'reset_research_project',$r1);verify(countRows($db,'research_projects')===2&&countRows($db,'research_runs')===1&&countRows($db,'research_messages')===0,'Research reset scope');
 change($admin,'delete_research_project',$r1);verify(countRows($db,'research_projects')===1&&countRows($db,'research_runs')===1,'Project delete scope');
 denied(fn()=>change($admin,'delete_research_project',99999),'Missing record accepted');verify(countRows($db,'research_projects')===1,'Missing record altered database');
 change($admin,'reset_task',$t1);verify(countRows($panel,'agent_runs')===0&&countRows($panel,'tasks')===2,'Task history reset scope');verify($panel->query('SELECT status FROM tasks WHERE id='.$t1)->fetchColumn()==='draft','Task not reset to draft');
 change($admin,'reset_project',$p1);verify(countRows($panel,'tasks')===1&&countRows($panel,'projects')===2,'Task project reset scope');change($admin,'delete_project',$p1);verify(countRows($panel,'projects')===1,'Task project delete');
 file_put_contents($directory.'/brief-suggestions.json','[{"status":"complete"}]');change($admin,'clear_suggestions');verify(file_get_contents($directory.'/brief-suggestions.json')==='[]','Suggestion journal not cleared');
 AIcontrol\Diagnostics::record('old.diagnostic');change($admin,'clear_diagnostics');verify(!str_contains(file_get_contents($directory.'/diagnostics.jsonl'),'old.diagnostic'),'Diagnostic cleanup failed');verify(str_contains(file_get_contents($directory.'/diagnostics.jsonl'),'admin.data.changed'),'Cleanup not recorded');
 $db->exec("PRAGMA foreign_keys=OFF; INSERT INTO research_nodes(run_id,type,status,title,content,created_at,updated_at)VALUES(999999,'phase','interrupted','Orphan legacy row','Unknown prior state',1,1); PRAGMA foreign_keys=ON");verify((bool)$db->query('PRAGMA foreign_key_check')->fetchAll(),'Orphan fixture not created');
 change($admin,'reset_workspace');foreach(['research_runs','research_nodes','research_sources','research_passages','research_edges','research_claim_versions','research_path_rankings','research_requests','research_messages','research_projects'] as $table)verify(countRows($db,$table)===0,'Reset left '.$table);
 foreach(['projects','tasks','agent_runs','events'] as $table)verify(countRows($panel,$table)===0,'Reset left '.$table);verify(countRows($panel,'login_failures')===1,'Reset removed security throttling');
 foreach($protected as $file=>$hash)verify(hash_file('sha256',$directory.'/'.$file)===$hash,'Protected storage changed: '.$file);
 verify(!$db->query('PRAGMA foreign_key_check')->fetchAll(),'Research reset orphaned rows');verify(!$panel->query('PRAGMA foreign_key_check')->fetchAll(),'Task reset orphaned rows');
 // Force a failure after child deletion and verify the complete operation rolls back.
 $r3=$research->create('Rollback project','Preserve on failure?');graph($db,$r3);$db->exec("CREATE TRIGGER prevent_project_delete BEFORE DELETE ON research_projects BEGIN SELECT RAISE(ABORT,'Synthetic rollback'); END");denied(fn()=>change($admin,'delete_research_project',$r3),'Failure did not abort');verify(countRows($db,'research_projects')===1&&countRows($db,'research_edges')===1&&countRows($db,'research_passages')===1,'Rollback lost related rows');
 $db->exec('DROP TRIGGER prevent_project_delete');$p3=$store->createProject('Atomic rollback','Preserve');$store->createTask($p3,'Preserve task','Criteria');
 $panel->exec("CREATE TRIGGER prevent_task_project_delete BEFORE DELETE ON projects BEGIN SELECT RAISE(ABORT,'Synthetic attached rollback'); END");denied(fn()=>change($admin,'reset_workspace'),'Attached failure did not abort');verify(countRows($db,'research_projects')===1&&countRows($db,'research_edges')===1&&countRows($panel,'projects')===1&&countRows($panel,'tasks')===1,'Attached rollback lost either database');
 echo "PASS: $checks admin inventory, privacy, confirmation, admission, scoped deletion/reset, foreign keys, rollback and protected-state checks\n";
} finally {foreach(glob($directory.'/*') as $file){if(is_dir($file)){foreach(glob($file.'/*') as $child)unlink($child);rmdir($file);}else unlink($file);}rmdir($directory);}
