<?php
declare(strict_types=1);

require __DIR__.'/investigation_fixtures.php';

use AIcontrol\IdeaVectorIndex;
use AIcontrol\InvestigationWire as Wire;
use AIcontrol\ResearchIdeas;

function syntheticVectors(array $body): array
{
    $items = [];
    foreach ($body['input'] as $offset => $text) {
        $vector = array_fill(0, IdeaVectorIndex::DIMENSIONS, 0);
        $vector[str_contains($text, 'volcanic glass') ? 1 : 0] = 3.2;
        $items[] = ['index' => $offset, 'embedding' => $vector];
    }
    return ['model' => $body['model'], 'usage' => ['prompt_tokens' => 320], 'data' => array_reverse($items)];
}

try {
    putenv('AICONTROL_RESEARCH_HOURLY_LIMIT=30');
    [$dir, $engine, $projects, $db] = fixture(true);
    $run = $engine->createRun(1);
    $ids = [];
    foreach (['Acoustic interruptions draw attention','Traffic peaks impair concentration','Birdsong may mask abrupt noise',
        'Steady background noise leaves concentration intact','Detecting volcanic glass dates ancient trade'] as $title) {
        $ids[] = $engine->appendNode($run, null, 'model_idea', 'complete', $title, $title);
    }
    [$anchor,$paired,,,$outlier] = $ids;
    $question = $engine->appendNode($run, $anchor, 'question', 'complete', 'PRIVATE QUESTION?', 'What next?');
    $phase = $engine->appendNode($run, null, 'phase', 'complete', 'PRIVATE PHASE DATA');
    $db->exec("UPDATE research_runs SET phase='exploring' WHERE id=$run");
    $data = ['idea'=>'Acoustic interruptions draw attention even at low average sound levels.',
        'next_question'=>'PRIVATE GUIDANCE?', 'test'=>'PRIVATE TEST','classification'=>[
            'role'=>'mechanism','mechanisms'=>['noise peaks', 'Noise peaks', 'attention capture'],'topics'=>['soundscape'],'outcomes'=>['attention'],'conditions'=>[]]];
    $db->prepare('UPDATE research_nodes SET data_json=? WHERE id=?')->execute([json_encode($data, JSON_THROW_ON_ERROR), $anchor]);
    $engine->savePathNote($run, $anchor, 'PRIVATE NOTE');
    $initial = $engine->getRunSnapshot($run);
    verify(!$initial['semantic']['enabled'] && $initial['semantic']['eligible']===5 && count(Wire::$bodies)===0, 'Reading a map indexed content or counted question/process records');
    verify($initial['branch_questions'][$anchor]['text']==='PRIVATE GUIDANCE?', 'Saved branch guidance was lost');
    verify(array_column($initial['nodes'],null,'id')[$anchor]['classification']['mechanisms']===['noise peaks','attention capture'],'Tag normalisation lost role or case-insensitive deduplication');

    Wire::$handler = function($body) {
        verify($body['model']===IdeaVectorIndex::MODEL && $body['dimensions']===256, 'Incorrect embeddings request');
        verify(count($body['input'])===5 && !str_contains(json_encode($body), 'PRIVATE'), 'Auxiliary research data left the workspace for embeddings');
        return syntheticVectors($body);
    };
    $engine->indexIdeas($run);
    $indexed = $engine->getRunSnapshot($run);
    verify($indexed['semantic']['enabled'] && $indexed['semantic']['indexed']===5,'Index did not persist');
    verify($indexed['semantic']['outliers']===[$outlier],'Distant idea was not labelled against the map’s nearest-neighbour spread');
    verify(in_array([$anchor,$paired],array_map(static fn(array $e):array=>[$e['from'],$e['to']],$indexed['semantic']['edges']),true), 'Related statements have no link');
    verify((int)$db->query("SELECT count(*) FROM research_idea_vectors WHERE typeof(embedding)='blob' AND length(embedding)=1024")->fetchColumn()===5, 'Derivations did not persist as fixed-length vectors');
    verify(!str_contains(json_encode($indexed), '"embedding":') && $indexed['usage']['project']['estimated_usd']===0.0000064,'Raw vectors leaked or charged usage did not reach the project meter');
    verify($indexed['run']['phase']==='exploring' && !$indexed['sources'],'Indexing touched Evidence or moved the run');
    $engine->indexIdeas($run);
    verify(count(Wire::$bodies)===1, 'A fully indexed map charged another provider request');

    $child = ['parent_id'=>$anchor,'title'=>'Short bursts may matter independently of mean volume',
        'idea'=>'Repeated unexpected sound onsets might interrupt attention more than a steady background.',
        'connection'=>'Distinguishes acoustic change from average volume.','uncertainty'=>'Predictability',
        'test'=>'Match mean volume and vary the onset timing','next_question'=>'Does predictability change the effect?',
        'parent_question'=>'What happens if only the timing of sound bursts changes?',
        'classification'=>['role'=>'counterexample','topics'=>['soundscape'],'mechanisms'=>['noise peaks'],'outcomes'=>['attention'],'conditions'=>['matched volume']]];
    Wire::$handler = function($body) use ($child) {
        if ($body['model']===IdeaVectorIndex::MODEL) {
            verify(count($body['input'])===1 && str_contains($body['input'][0], 'Short bursts') && !str_contains($body['input'][0], 'predictability'), 'Growth re-indexed old ideas or sent follow-up questions');
            return syntheticVectors($body);
        }
        $input=json_decode($body['input'],true,32,JSON_THROW_ON_ERROR);
        verify(array_column($input['parents'],'id')===[$child['parent_id']] && isset($input['brief']), 'Semantic map changed original or branch steering');
        return reply(json_encode(['ideas'=>[$child]],JSON_THROW_ON_ERROR));
    };
    $grown = $engine->expandPathway($run, $anchor);
    verify($grown['semantic']['indexed']===6 && $grown['semantic']['outliers']===[$outlier], 'New idea did not join the existing embedding index');
    verify($grown['branch_questions'][$anchor]['text']===$child['parent_question'], 'Parent question did not incorporate the new idea');
    verify($grown['branch_questions'][$paired]===$indexed['branch_questions'][$paired], 'Unrelated branch suggestion changed');
    $last = $grown['branch_questions'][$anchor]['node_id'];

    Wire::$handler = function($body) use ($last,$paired) {
        verify($body['model']!==IdeaVectorIndex::MODEL && !isset($body['tools']), 'Reading informed chat unexpectedly indexed or searched');
        $context=json_decode(explode("\nCurrent question:\n",substr($body['input'],strlen("Saved concept context:\n")),2)[0],true,32,JSON_THROW_ON_ERROR);
        verify($context['possible_question']['node_id']===$last && count($context['related_network_ideas'])<=2, 'Chat lost branch guidance or retrieved too much of the network');
        verify(!str_contains($body['input'], 'PRIVATE NOTE') && !str_contains($body['input'], 'volcanic glass'), 'Local note or distant irrelevant branch sent to chat');
        return reply('Changing the timing tests attention capture at constant average volume.');
    };
    $engine->discussConcept($run, $anchor, 'What matters about this branch?');
    $before = count(Wire::$bodies);
    $engine->pauseIdeaIndex($run);
    $paused = $engine->getRunSnapshot($run);
    verify(!$paused['semantic']['enabled'] && !$paused['semantic']['edges'] && !$paused['semantic']['outliers'] && count(Wire::$bodies)===$before,'Pausing sent data or exposed old derived links');

    $engine->stop($run);
    $otherProject = $projects->create('Other research','Separate workspace subject');
    $other = $engine->createRun($otherProject);
    $otherIdea = $engine->appendNode($other, null, 'model_idea', 'complete', 'Unrelated idea from another project');
    $db->exec("UPDATE research_runs SET phase='exploring' WHERE id=$other");
    verify(!$engine->getRunSnapshot($other)['semantic']['enabled'], 'Project permission crossed project boundaries');

    Wire::$handler = function($body) use ($engine,$other) {
        verify(count($body['input'])===1 && str_contains($body['input'][0], 'Unrelated idea'), 'Foreign-run content sent for indexing');
        $engine->stop($other);
        return syntheticVectors($body);
    };
    $engine->indexIdeas($other);
    $stopped = $engine->getRunSnapshot($other);
    verify($stopped['semantic']['indexed']===0 && $stopped['semantic']['status']==='uncertain' && $stopped['nodes'][0]['status']==='complete', 'Late embedding result revived a stopped request or damaged an idea');

    $db->prepare("INSERT INTO research_requests(run_id,node_id,kind,status,created_at) VALUES(?,?,'idea_index','pending',?)")
        ->execute([$other,$otherIdea,time()-121]);
    $stale=$engine->getRunSnapshot($other);
    verify($stale['semantic']['status']==='uncertain' && $stale['nodes'][0]['title']==='Unrelated idea from another project'
        && $stale['nodes'][0]['status']==='complete', 'Index reconciliation overwrote saved branch content');

    $db->prepare('UPDATE research_projects SET spend_cap_usd=0 WHERE id=?')->execute([$otherProject]);
    $calls=count(Wire::$bodies);
    denied(fn()=>$engine->indexIdeas($other), 'Embeddings bypassed the project cap');
    verify(count(Wire::$bodies)===$calls, 'A capped embedding request reached OpenAI');

    $nodes = [
        ['id'=>1,'type'=>'model_idea','status'=>'complete','title'=>'Material recovery','parent_id'=>null,'updated_at'=>1],
        ['id'=>2,'type'=>'model_idea','status'=>'complete','title'=>'Sorting intensity','parent_id'=>1,'updated_at'=>2],
        ['id'=>3,'type'=>'source','status'=>'complete','title'=>'Trial record','parent_id'=>2,'updated_at'=>3],
    ];
    $suggestions = ResearchIdeas::suggestions($nodes,[['node_id'=>3,'title'=>'Field trial of sorting','retrieved_at'=>4]]);
    verify($suggestions[1]['node_id']===3 && str_contains($suggestions[1]['text'],'Field trial of sorting'), 'Branch question did not react to newly available data');
    fwrite(STDOUT, "PASS: $checks idea-only embeddings, consent, exact neighbours, outliers, branch refresh, bounded chat, usage and Stop checks (synthetic provider)\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n".$error->getTraceAsString()."\n");
    exit(1);
} finally {
    foreach ($dirs as $dir) {
        foreach (glob($dir.'/*') as $file) @unlink($file);
        @rmdir($dir);
    }
}
