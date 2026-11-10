<?php
declare(strict_types=1);
require __DIR__.'/investigation_fixtures.php';
use AIcontrol\InvestigationWire as Wire;
use AIcontrol\UsageMeter as Meter;
putenv('AICONTROL_RESEARCH_HOURLY_LIMIT=30');
function flowPrior(): array {return ['core_claims'=>[['claim'=>'High impact fixture claim','confidence'=>'medium','uncertainty'=>'Population','verify'=>'Measure effect','priority'=>'high'],['claim'=>'Secondary fixture claim','confidence'=>'low','uncertainty'=>'Transfer','verify'=>'Find independent measurements','priority'=>'normal']],'competing_angles'=>['Alternative mechanism'],'least_confident'=>'Local transfer','background_assumptions'=>['Comparable population']];}
function flowSearch(): array {
    $response=reply('Source summary, not an exact quotation.',[['type'=>'web_search_call','status'=>'completed','action'=>['sources'=>[['url'=>'https://example.com/flow','snippet'=>'Literal provider snippet: a measured, population-specific effect.','locator'=>'Results']]]]]);
    $response['output'][1]['content'][0]['annotations']=[['type'=>'url_citation','url'=>'https://example.com/flow','title'=>'Flow fixture source']];return $response;
}
function streamChunks(array $response): array {
    return ['__chunks'=>["event: response.created\ndata: {\"type\":\"response.created\"}\n\n",
        "event: response.output_text.delta\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"Visible response\"}\n\n",
        'event: response.completed'."\ndata: ".json_encode(['type'=>'response.completed','response'=>$response],JSON_THROW_ON_ERROR)."\n\n"]];
}
try {
    $measured=Meter::measure(['usage'=>['input_tokens'=>1000,'input_tokens_details'=>['cached_tokens'=>400],'output_tokens'=>200,'output_tokens_details'=>['reasoning_tokens'=>50]],'output'=>[['type'=>'web_search_call','status'=>'completed']]],'gpt-4.1-mini');
    verify(abs($measured['estimated_usd']-0.0256)<1e-9,'Cached tokens, reasoning or preview tool fee double-counted');
    verify($measured['rates']['checked_at']===Meter::CHECKED&&$measured['invoice_verified']===false,'Pricing provenance missing');
    verify(Meter::measure([],'gpt-4.1')['estimated_usd']===null,'Missing usage became zero cost');
    verify(Meter::measure(['usage'=>['input_tokens'=>1,'output_tokens'=>2]],'gpt-4.1-mini-invented')['estimated_usd']===null,'Unpriced model borrowed another price');
    verify(Meter::measure(['usage'=>['input_tokens'=>1,'output_tokens'=>2],'service_tier'=>'priority'],'gpt-4.1')['estimated_usd']===null,'Unknown service tier silently priced as standard');
    verify(Meter::measure(['usage'=>['input_tokens'=>1,'output_tokens'=>2,'input_tokens_details'=>['cached_tokens'=>3]]],'gpt-4.1')['estimated_usd']===null,'Impossible cached counts accepted');
    verify(Meter::measure(['usage'=>['input_tokens'=>0,'output_tokens'=>0]],'gpt-4.1')['estimated_usd']===0.0,'Confirmed zero usage mishandled');
    $events=[];$stream=new AIcontrol\ResponseStream(function($activity,$chars)use(&$events){$events[]=[$activity,$chars];});
    $raw=implode('',streamChunks(reply('Final streamed text'))['__chunks']);foreach(str_split($raw,7) as $chunk)if($stream->push($chunk)!==strlen($chunk))throw new RuntimeException('Chunk reader rejected valid stream');
    verify($stream->result()['usage']['output_tokens']===20&&end($events)[1]===16,'Chunked SSE lost final usage or visible characters');
    $incomplete=new AIcontrol\ResponseStream(fn($a,$n)=>null);$incomplete->push("event: response.created\ndata: {\"type\":\"response.created\"}\n\n");denied(fn()=>$incomplete->result(),'Truncated stream treated as completed');

    [$dir,$engine,$old,$db]=fixture();verify($engine->requestBudget()['limit']===30,'Owner-selected 30-call ceiling not applied');
    $engine->configureProject(1,'gpt-4.1',1.0,true,3);$run=$engine->createRun(1);
    Wire::$handler=fn($body)=>reply(json_encode(flowPrior()));$snapshot=$engine->step($run);
    verify($snapshot['run']['phase']==='dream_review'&&$snapshot['run']['auto_search']===1,'New autoplay skipped human review or failed to persist');
    $claims=$snapshot['ledger']['claims'];$high=(int)$claims[0]['id'];$secondary=(int)$claims[1]['id'];$engine->continueRun($run);
    Wire::$handler=function($body)use($engine,$run,$high,$secondary):array{
        $snapshot=$engine->getRunSnapshot($run);$phase=$snapshot['run']['phase'];
        verify($body['max_output_tokens']===3200&&$body['store']===false&&$body['stream']===true,'Expanded worker bounds or streaming missing');
        if($phase==='plan')return reply(json_encode(['steps'=>[['claim_id'=>$high,'query'=>'Verify high impact'],['claim_id'=>$secondary,'query'=>'Verify secondary']],'challenge'=>['claim_id'=>$high,'query'=>'Seek counter-evidence']]));
        if($phase==='evidence')return flowSearch();
        if($phase==='exploration'){
            verify(!isset($body['tools'])&&$body['model']==='gpt-4.1-nano'&&str_contains($body['input'],'current_findings')&&str_contains($body['input'],'search observation'),'Divergence did not use cheap, evidence-context-only role');
            return reply(json_encode(['questions'=>array_map(fn($i)=>['claim_id'=>$high,'question'=>'Unusual falsifiable question '.$snapshot['run']['explore_count'].'-'.$i,'why_different'=>'Alternative mechanism; may be wrong','falsification_test'=>'Compare independent measured counterexamples'],range(1,6))]));
        }
        $assessments=[['claim_id'=>$high,'status'=>'supported','rationale'=>'Within the captured population only','strength'=>'moderate'],['claim_id'=>$secondary,'status'=>'open','rationale'=>'Needs independent evidence','strength'=>null]];
        if($phase==='extraction')return reply(json_encode(['edges'=>[['claim_id'=>$high,'passage_id'=>(int)$snapshot['ledger']['passages'][0]['id'],'source_id'=>(int)$snapshot['sources'][0]['id'],'relation'=>'supports','rationale'=>'Limited population support','strength'=>'moderate']],'assessments'=>$assessments]));
        verify($body['model']==='gpt-4.1'&&!isset($body['tools']),'Verification was downgraded or gained search tools');
        return reply(json_encode(['assessments'=>$assessments,'defects'=>[],'calculations'=>[]]));
    };
    for($i=0;$i<18&&$engine->getRunSnapshot($run)['run']['phase']!=='verification_review';$i++)$snapshot=$engine->step($run);
    verify($snapshot['run']['phase']==='verification_review'&&count($snapshot['requests'])===12,'Growth loop failed to stop within twelve calls');
    verify(count(array_filter($snapshot['nodes'],fn($n)=>$n['type']==='question'))===12,'Batched question growth was not bounded at twelve');
    verify(count(array_filter($snapshot['requests'],fn($r)=>$r['kind']==='evidence'))===6,'Search growth not bounded at six');
    verify((int)$snapshot['run']['explore_count']===2,'Periodic divergent passes missing or unbounded');
    $searches=array_values(array_filter($snapshot['requests'],fn($r)=>$r['kind']==='evidence'));
    verify($searches[0]['model']==='gpt-4.1'&&$searches[1]['model']==='gpt-4.1-mini'&&$searches[3]['model']==='gpt-4.1-mini','Rank-based model routing incorrect');
    verify($snapshot['usage']['project']['total_tokens']===360&&$snapshot['usage']['project']['unknown_requests']===0,'Project token accounting omitted a role');
    $areas=array_sum(array_map(fn($area)=>$area['usage']['total_tokens'],$snapshot['usage']['areas']));verify($areas+$snapshot['usage']['shared']['total_tokens']===$snapshot['usage']['run']['total_tokens'],'Path totals double-counted shared calls');
    verify(count($snapshot['ledger']['claim_versions'])===4,'Untested ideas were promoted into claim assessments');
    verify($snapshot['nodes'][0]['path_weight']===95||count(array_filter($snapshot['nodes'],fn($n)=>$n['id']===$high&&$n['path_weight']===95))===1,'Final assessment did not update priority');
    $saved=$snapshot['requests'][0]['billing_json'];new AIcontrol\Investigation($dir);verify($saved===$engine->getRunSnapshot($run)['requests'][0]['billing_json'],'Migration repriced past usage');
    $engine->finishVerification($run);$before=$engine->projectUsage(1);
    Wire::$handler=fn($body)=>reply('Frozen source-only explanation');$snapshot=$engine->learn($run,'explain','',[(int)$snapshot['sources'][0]['id']]);
    verify($engine->projectUsage(1)['total_tokens']===$before['total_tokens']+30,'Learn missing from project total');
    $beforeChat=$engine->projectUsage(1)['total_tokens'];$old->chat(1,'Explain the captured evidence',false);verify($engine->projectUsage(1)['total_tokens']===$beforeChat+30,'Legacy chat absent from project usage total');
    verify(str_contains($engine->export($run),'Token and estimated spend ledger')&&str_contains($engine->export($run),'gpt-4.1-nano'),'Export omitted usage by role/model');
    denied(fn()=>$engine->rankPath($run,$high,20,'economy'),'Frozen path ranking accepted');
    $otherProject=$old->create('Other project','Keep accounting isolated');verify($engine->projectUsage(2)['requests']===0,'Usage leaked into another project');

    // Historical rows remain explicitly unpriced; they do not silently become free calls.
    [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);$node=$engine->appendNode($run,null,'phase','complete','Historical fixture');
    $db->exec("INSERT INTO research_requests(run_id,node_id,kind,status,created_at,token_input,token_output) VALUES($run,$node,'evidence','complete',1,42,24)");$historical=$engine->projectUsage(1);
    verify($historical['unknown_requests']===1&&$historical['total_tokens']===66&&str_contains(Meter::label($historical),'unpriced/unknown'),'Historical accounting presented unknown spend as free');$engine->stop($run);

    // A cap blocks dispatch while preserving prior evidence and a known zero-charge attempt.
    [$dir,$engine,$old,$db]=fixture();$engine->configureProject(1,'gpt-4.1',0.05,true,0);$run=$engine->createRun(1);Wire::$handler=fn($body)=>reply(json_encode(flowPrior()));$snapshot=$engine->step($run);$high=(int)$snapshot['ledger']['claims'][0]['id'];$engine->continueRun($run);
    Wire::$handler=fn($body)=>reply(json_encode(['steps'=>[['claim_id'=>$high,'query'=>'Verify']],'challenge'=>['claim_id'=>$high,'query'=>'Challenge']]));$engine->step($run);$calls=count(Wire::$bodies);$snapshot=$engine->step($run);
    verify(count(Wire::$bodies)===$calls&&$snapshot['run']['status']==='incomplete'&&str_contains($snapshot['run']['stop_reason'],'spend cap'),'Spend cap failed to stop dispatch');verify(end($snapshot['requests'])['estimated_usd']===0.0,'An undispatched capped request was marked billable');

    // Live stream callbacks expose real activity; Stop blocks subsequent progress/ledger mutations.
    [$dir,$engine,$old,$db]=fixture();$run=$engine->createRun(1);$seen=[];
    Wire::$handler=fn($body)=>streamChunks(reply(json_encode(flowPrior())));
    Wire::$observer=function()use($engine,$run,&$seen){$snapshot=$engine->getRunSnapshot($run);$current=end($snapshot['requests']);$seen[]=$current['activity'];if($current['output_chars']>0)$engine->stop($run);};
    $snapshot=$engine->step($run);Wire::$observer=null;
    verify(in_array('Provider accepted request',$seen,true)&&in_array('Receiving response text',$seen,true),'Streaming activity was not observable during the request');
    verify($snapshot['run']['status']==='interrupted'&&!$snapshot['ledger']['claims']&&$snapshot['usage']['run']['total_tokens']===30,'Late streamed result revived stopped work or lost confirmed billing');
    echo "PASS: $checks pricing, streaming, project/path usage, cap, model routing and bounded question growth checks (synthetic transport)\n";
}finally{Wire::$observer=null;foreach($dirs as $dir){foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}}
