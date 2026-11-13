<?php
declare(strict_types=1);
namespace AIcontrol;
use AIcontrolWorkspaceUi as Ui;
use RuntimeException;
use Throwable;
final class ProjectInspector
{
    public static function id(mixed $value,bool $zero=false): int
    {
        if(!is_string($value)||!preg_match($zero?'/^(0|[1-9][0-9]{0,9})$/D':'/^[1-9][0-9]{0,9}$/D',$value))throw new RuntimeException('Choose a valid research project and investigation.');return (int)$value;
    }
    public static function bundle(string $directory,int $project,int $run=0): array
    {
        $bundle=(new Investigation($directory))->inspectProject($project,$run);$snapshot=$bundle['snapshot'];$nodes=$snapshot['nodes']??$bundle['stored_state']['nodes']??[];$sources=$snapshot['sources']??$bundle['stored_state']['sources']??[];$ledger=$snapshot['ledger']??$bundle['stored_state']['ledger']??[];
        $nodeIds=array_fill_keys(array_map('strval',array_column($nodes,'id')),true);$sourceIds=array_fill_keys(array_map('strval',array_column($sources,'id')),true);$passageIds=array_fill_keys(array_map('strval',array_column($ledger['passages']??[],'id')),true);
        $warnings=$bundle['map_payload_error']!==null?[['kind'=>'map_payload_error']+$bundle['map_payload_error']]:[];$parents=[];$types=[];$cycles=[];
        foreach($nodes as $node){$id=(string)$node['id'];$parents[$id]=$node['parent_id'];$types[$node['type']]=($types[$node['type']]??0)+1;
            if($node['parent_id']!==null&&!isset($nodeIds[(string)$node['parent_id']]))$warnings[]=['kind'=>'missing_parent','node_id'=>$node['id'],'parent_id'=>$node['parent_id']];
            foreach(['data_json'] as $field)if(is_string($node[$field]??null)){json_decode($node[$field],true);if(json_last_error()!==JSON_ERROR_NONE)$warnings[]=['kind'=>'invalid_json','node_id'=>$node['id'],'field'=>$field];}
        }
        foreach($parents as $id=>$parent){$seen=[];$at=(string)$id;while(isset($parents[$at])){if(isset($seen[$at])){$cycle=array_slice(array_keys($seen),$seen[$at]);sort($cycle);$cycles[implode(',',$cycle)]=$cycle;break;}$seen[$at]=count($seen);$next=$parents[$at];if($next===null)break;$at=(string)$next;}}
        foreach($cycles as $cycle)$warnings[]=['kind'=>'parent_cycle','node_ids'=>$cycle];
        foreach($sources as $source){if(!isset($nodeIds[(string)$source['node_id']]))$warnings[]=['kind'=>'missing_source_node','source_id'=>$source['id'],'node_id'=>$source['node_id']];$meta=json_decode($source['meta_json']??'{}',true);if(json_last_error()!==JSON_ERROR_NONE)$warnings[]=['kind'=>'invalid_source_metadata','source_id'=>$source['id']];
            if(is_array($meta)){$links=$meta['claim_ids']??(isset($meta['claim_id'])?[$meta['claim_id']]:[]);if(!is_array($links))$warnings[]=['kind'=>'invalid_source_claim_links','source_id'=>$source['id']];else foreach($links as $claim)if(!is_scalar($claim)||!isset($nodeIds[(string)$claim]))$warnings[]=['kind'=>'missing_source_claim','source_id'=>$source['id'],'claim_id'=>$claim];}
        }
        foreach($ledger['passages']??[] as $passage)if(!isset($sourceIds[(string)$passage['source_id']]))$warnings[]=['kind'=>'missing_passage_source','passage_id'=>$passage['id'],'source_id'=>$passage['source_id']];
        foreach($ledger['edges']??[] as $edge){foreach(['claim_id'=>$nodeIds,'source_id'=>$sourceIds,'passage_id'=>$passageIds] as $field=>$known)if(($edge[$field]??null)!==null&&!isset($known[(string)$edge[$field]]))$warnings[]=['kind'=>'missing_edge_reference','edge_id'=>$edge['id'],'field'=>$field,'id'=>$edge[$field]];}
        foreach($snapshot['requests']??$bundle['stored_state']['requests']??[] as $request)foreach(['node_id','claim_id','path_node_id'] as $field)if(($request[$field]??null)!==null&&!isset($nodeIds[(string)$request[$field]]))$warnings[]=['kind'=>'missing_request_node','request_id'=>$request['id'],'field'=>$field,'node_id'=>$request[$field]];
        $bundle['checks']=['node_count'=>count($nodes),'source_count'=>count($sources),'passage_count'=>count($ledger['passages']??[]),'evidence_edge_count'=>count($ledger['edges']??[]),'request_count'=>count($snapshot['requests']??$bundle['stored_state']['requests']??[]),'nodes_by_type'=>$types,'warnings'=>$warnings];
        $bundle['notes']=['snapshot'=>'Same payload builder as research polling; timeout reconciliation is disabled for read-only inspection.',
            'stored_state'=>'Selected-run database rows before payload enrichment; internal busy token excluded. Retained even if payload building fails.',
            'projection'=>'Browser uses AIcontrolMap.project(snapshot, filter). Filters, hidden wrappers and grouped evidence mean card counts need not equal database rows.',
            'view_state'=>'Map viewport, zoom, cached layout, layer visibility and collapsed branches belong to the research browser window and are not captured here.',
            'consistency'=>'This is one saved point in time; refresh explicitly to inspect new progress. No provider call is made.'];
        return $bundle;
    }
    private static function json(mixed $data): string{return json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);}
    public static function download(string $directory,int $project,int $run,string $kind): void
    {
        try {
            if(!in_array($kind,['payload','bundle'],true))throw new RuntimeException('Choose a valid JSON export.');
            $bundle=self::bundle($directory,$project,$run);$data=$kind==='payload'?$bundle['snapshot']:$bundle;$json=self::json($data);
            header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="research-project-'.$project.'-run-'.($bundle['selected_run_id']??0).'-'.$kind.'.json"');
            echo $json;
        } catch(Throwable $e){Diagnostics::record('admin.project_json.failed',['project_id'=>$project,'run_id'=>$run],$e);http_response_code(422);header('Content-Type: application/json; charset=utf-8');echo self::json(['error'=>'Project JSON is unavailable. Check that the selected investigation belongs to this project and refresh Admin.']);}
    }
    public static function render(string $directory,string $csrf,array $projects): void
    {
        echo '<section class="card mb-3" id="project-json-inspector"><div class="card-body"><h2>Research project JSON inspector</h2><p>Select a project and investigation to compare stored data with the JSON used by the map. Inspection is read-only and makes no AI request. Exports contain this project’s saved research content; vault, configuration and session files are not exported.</p>';
        if(!$projects){echo '<p>No saved research projects to inspect.</p></div></section>';return;}
        $bundle=null;$selected=0;
        try {if(isset($_GET['inspect_project'])){$selected=self::id($_GET['inspect_project']);$run=self::id($_GET['inspect_run']??'0',true);$bundle=self::bundle($directory,$selected,$run);}}
        catch(Throwable $e){echo '<p class="alert alert-warning" role="alert">The selected project or investigation is unavailable. Select the project again; an investigation must belong to that project.</p>';}
        echo '<form method="get" action="/loader.php" class="project-json-select"><input type="hidden" name="view" value="admin"><label class="form-label" for="inspect-project">Research project to inspect</label><select id="inspect-project" name="inspect_project" class="form-select" required>';
        foreach($projects as $project)echo '<option value="'.(int)$project['id'].'"'.((int)$project['id']===$selected?' selected':'').'>#'.(int)$project['id'].' · '.Ui::e($project['name']).'</option>';
        echo '</select><button class="btn btn-outline-primary">Load project JSON</button></form>';
        if(!$bundle){echo '</div></section>';return;}
        $projectId=(int)$bundle['project']['id'];$runId=(int)($bundle['selected_run_id']??0);
        echo '<div data-project-json-inspector data-project-id="'.$projectId.'" data-run-id="'.$runId.'"><h3>'.Ui::e($bundle['project']['name']).($runId?' · Investigation #'.$runId:' · No investigations saved').'</h3>';
        if($bundle['available_runs']){echo '<form method="get" action="/loader.php" class="project-json-select"><input type="hidden" name="view" value="admin"><input type="hidden" name="inspect_project" value="'.$projectId.'"><label class="form-label" for="inspect-run">Investigation to inspect</label><select id="inspect-run" name="inspect_run" class="form-select">';foreach($bundle['available_runs'] as $run)echo '<option value="'.(int)$run['id'].'"'.((int)$run['id']===$runId?' selected':'').'>#'.(int)$run['id'].' · '.Ui::e($run['status'].' · '.$run['phase']).'</option>';echo '</select><button class="btn btn-outline-primary">Load investigation JSON</button></form>';}
        echo '<p>'.(int)$bundle['checks']['node_count'].' payload nodes · '.(int)$bundle['checks']['source_count'].' source cards · '.(int)$bundle['checks']['passage_count'].' passages · '.(int)$bundle['checks']['evidence_edge_count'].' evidence relations. Captured '.Ui::e(Ui::time($bundle['generated_utc'])).'.</p>';
        if($runId)echo '<p><a href="/loader.php?view=research&amp;project='.$projectId.'&amp;run='.$runId.'">Open this investigation on the map</a></p>';
        if($bundle['map_payload_error']!==null)echo '<p class="alert alert-warning" role="alert">The map payload could not be built. The debug bundle retains the stored data and error for diagnosis.</p>';
        echo '<details class="project-json-checks"><summary>Saved ID and relationship checks · '.count($bundle['checks']['warnings']).' warnings</summary><pre class="admin-schema">'.Ui::e(self::json($bundle['checks'])).'</pre></details><p>Phase wrappers and grouped evidence do not always appear as separate map cards. Choose a map filter below to inspect the display projection. Browser-specific zoom, layout memory, layer visibility and collapsed branches are not included.</p>';
        self::textReport('project-map-payload','Map input JSON (polling payload)',self::json($bundle['snapshot']));
        echo '<div data-project-projection hidden><label class="form-label" for="inspect-map-filter">Map projection filter</label><select id="inspect-map-filter" class="form-select mb-2" data-project-projection-filter>';foreach(['all'=>'All concepts','dream_claim'=>'Claims','question'=>'Questions','source'=>'Sources','passage'=>'Passages','edge'=>'Evidence relations','dead_end'=>'Dead ends','open'=>'Open findings'] as $value=>$name)echo '<option value="'.$value.'">'.$name.'</option>';echo '</select><p data-project-projection-summary role="status" aria-live="polite"></p>';
        self::textReport('project-map-projection','Projected map cards and links (same browser projection engine)','');echo '<button class="btn btn-outline-secondary mb-3" type="button" data-download-map-projection>Download projected JSON</button></div>';
        self::textReport('project-debug-bundle','Debug bundle: project, stored rows, map payload and checks',self::json($bundle));
        echo '<div class="project-json-downloads">';foreach(['payload'=>'Download map input JSON','bundle'=>'Download debug bundle'] as $kind=>$label)echo '<form method="post" action="/loader.php?view=admin" data-inspection-download="'.$kind.'">'.$csrf.'<input type="hidden" name="action" value="admin_project_json"><input type="hidden" name="project_id" value="'.$projectId.'"><input type="hidden" name="run_id" value="'.$runId.'"><input type="hidden" name="kind" value="'.$kind.'"><button class="btn btn-outline-primary">'.$label.'</button></form>';echo '</div><noscript><p>Map input and debug downloads work without JavaScript. The display projection requires JavaScript, like the map.</p></noscript></div></div></section>';
    }
    private static function textReport(string $id,string $label,string $value): void
    {
        echo '<details class="project-json-report"'.($id==='project-map-payload'?' open':'').'><summary>'.Ui::e($label).'</summary><div><button type="button" class="btn btn-sm btn-outline-secondary my-2" data-copy-report="'.$id.'">Copy '.Ui::e($id==='project-map-payload'?'map input JSON':($id==='project-debug-bundle'?'debug bundle':'projected JSON')).'</button><span class="ms-2" role="status" data-copy-report-status></span><label class="form-label" for="'.$id.'">'.Ui::e($label).'</label><textarea id="'.$id.'" class="form-control project-json-text" rows="14" readonly spellcheck="false">'.Ui::e($value).'</textarea></div></details>';
    }
}
