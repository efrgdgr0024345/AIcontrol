<?php
declare(strict_types=1);
require __DIR__.'/investigation_fixtures.php';
try{
 [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);$node=$engine->appendNode($run,null,'dream_claim','complete','Saved version-one idea','Original unchecked text');
 $db->exec("UPDATE research_nodes SET layer=2,park_reason='Keep legacy reasoning' WHERE id=$node");
 foreach(['weight','knowledge_line_id','last_focused_at','size_factor','brightness_factor'] as $column)$db->exec('ALTER TABLE research_nodes DROP COLUMN '.$column);
 new AIcontrol\Investigation($dir);$saved=$db->query("SELECT * FROM research_nodes WHERE id=$node")->fetch(PDO::FETCH_ASSOC);
 verify($saved['title']==='Saved version-one idea'&&$saved['content']==='Original unchecked text'&&$saved['layer']===2&&$saved['park_reason']==='Keep legacy reasoning','Additive migration changed legacy content or layer');
 verify((float)$saved['weight']===1.0&&$saved['knowledge_line_id']===null&&$saved['last_focused_at']===null&&$saved['size_factor']===null&&$saved['brightness_factor']===null,'Legacy migration fabricated camera/provenance state');
 $db->exec("UPDATE research_nodes SET weight=2.25,knowledge_line_id='saved-family',last_focused_at=123,size_factor=0.55,brightness_factor=0.4 WHERE id=$node");new AIcontrol\Investigation($dir);new AIcontrol\Investigation($dir);$again=$db->query("SELECT * FROM research_nodes WHERE id=$node")->fetch(PDO::FETCH_ASSOC);
 verify((float)$again['weight']===2.25&&$again['knowledge_line_id']==='saved-family'&&(int)$again['last_focused_at']===123&&(float)$again['size_factor']===.55&&(float)$again['brightness_factor']===.4,'Repeat migration overwrote cached/optional state');
 $before=$engine->getRunSnapshot($run);$engine->recordMapFocus($run,$node);$after=$engine->getRunSnapshot($run);
 verify(count($after['nodes'])===count($before['nodes'])&&$after['requests']===$before['requests']&&$after['budget']===$before['budget']&&$after['run']===$before['run'],'Focus metadata changed the run or spent provider budget');
 verify((int)$after['nodes'][0]['last_focused_at']>=time()-2&&$after['nodes'][0]['knowledge_line_id']==='saved-family','Focus timestamp missing or rewrote family');
 denied(fn()=>$engine->recordMapFocus($run,999999),'Unknown focus ID accepted');denied(fn()=>$engine->recordMapFocus($run,0),'Synthetic root accepted for persisted focus');
 $engine->stop($run);$db->exec('UPDATE research_projects SET exploration_first=1');$new=$engine->createRun(1);$parent=$engine->appendNode($new,null,'model_idea','complete','New family','Provisional model idea');$child=$engine->appendNode($new,$parent,'association','complete','Related idea','Provisional association');$snapshot=$engine->getRunSnapshot($new);$rows=$snapshot['nodes'];
 verify($rows[0]['knowledge_line_id']!==null&&$rows[0]['knowledge_line_id']===$rows[1]['knowledge_line_id'],'New idea did not inherit its knowledge family');
 verify($rows[0]['origin']==='model_latent'&&$rows[1]['provenance_tag']==='provisional_model'&&!$snapshot['sources']&&!$snapshot['ledger']['passages'],'Map metadata promoted model ideas into evidence');
 denied(fn()=>$engine->recordMapFocus($run,$child),'Cross-run focus ID accepted');
 $engine->recordMapFocus($new,$child);$engine->stop($new);$engine->recordMapFocus($new,$child);verify($engine->getRunSnapshot($new)['run']['status']==='interrupted','Focus metadata resumed a stopped investigation');
 echo "PASS: $checks star-map additive migration, legacy readability, family inheritance and optional focus scope/budget checks\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}finally{foreach($dirs as $dir){foreach(glob($dir.'/*') as $file)@unlink($file);@rmdir($dir);}}
