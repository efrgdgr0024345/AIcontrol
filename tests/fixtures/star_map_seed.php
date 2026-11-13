<?php
// Disposable synthetic graph; no model or network calls.
require dirname(__DIR__,2).'/src/Vault.php';
require dirname(__DIR__,2).'/src/AgentRunner.php';
require dirname(__DIR__,2).'/src/Research.php';
$home=getenv('AICONTROL_PRIVATE_DIR');$research=new AIcontrol\Research($home);$research->create('Living star system fixture','Explore provisional families of ideas.');$engine=new AIcontrol\Investigation($home);$id=$engine->createRun(1);
$db=new PDO('sqlite:'.$home.'/research.sqlite');$db->exec("UPDATE research_runs SET phase='exploring' WHERE id=$id");
for($family=0;$family<12;$family++){
    $parent=null;
    for($i=0;$i<20;$i++){
        $type=['model_idea','association','counter','edge_case','reframing'][$i%5];$node=$engine->appendNode($id,$parent,$type,'complete',"Family $family · provisional idea $i",'Unchecked synthetic idea; not evidence.');
        if($parent===null)$parent=$node;
        $q=$db->prepare("UPDATE research_nodes SET layer=?,origin='model_latent',provenance_tag='provisional_model',knowledge_line_id=?,weight=?,path_weight=? WHERE id=?");$q->execute([$i%3,'constellation-'.$family,1+($i%4)/4,30+($i%6)*10,$node]);
    }
}
