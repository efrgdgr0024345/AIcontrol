<?php
declare(strict_types=1);
/** Synthetic graph for browser JSON/map inspection tests; no provider calls. */
function seedProjectInspector(string $directory): void
{
    $db=new PDO('sqlite:'.$directory.'/research.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec("INSERT INTO research_runs(id,project_id,status,phase,started_at,model)VALUES(1,1,'complete','reconciliation',1,'gpt-4.1-mini'),(2,1,'complete','reconciliation',2,'gpt-4.1-mini'),(3,2,'complete','reconciliation',3,'gpt-4.1-mini')");
    $statement=$db->prepare('INSERT INTO research_nodes(id,run_id,parent_id,type,status,title,content,created_at,updated_at,data_json)VALUES(?,?,?,?,?,?,?,?,?,?)');
    foreach([[10,1,null,'phase','complete','Dream','Unchecked hypotheses',1,1,'{}'],[11,1,10,'dream_claim','complete','Inspector claim','</textarea><script>window.BAD=true</script>',1,1,'{}'],[12,1,11,'plan_step','complete','Verify claim','Check independent data',1,1,'{}'],[13,1,12,'search','complete','Search','Source summary',1,1,'{}'],[14,1,13,'source','complete','Inspector source','Citation only',1,1,'{}'],[20,2,null,'dream_claim','complete','Latest saved claim','Different investigation',1,1,'{}']] as $row)$statement->execute($row);
    $db->exec("INSERT INTO research_sources(id,run_id,node_id,url,title,extract,domain,retrieved_at,meta_json)VALUES(7,1,14,'https://example.com/inspector','Inspector source','A bounded summary','example.com',1,'{\"claim_ids\":[11]}')");
    $db->exec("INSERT INTO research_passages(id,source_id,run_id,exact_text,content_hash,created_at)VALUES(5,7,1,'Captured fixture passage','fixture-hash',1)");
    $db->exec("INSERT INTO research_edges(id,run_id,claim_id,passage_id,source_id,relation,rationale,created_at)VALUES(3,1,11,5,7,'supports','Scope caveat',1)");
    $db->exec("INSERT INTO research_claim_versions(claim_id,run_id,status,rationale,created_at,created_by)VALUES(11,1,'supported','Only within source scope',1,'verifier')");
}
