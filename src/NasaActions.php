<?php
declare(strict_types=1);
namespace AIcontrol;
require_once __DIR__.'/NasaProvider.php';
require_once __DIR__.'/EverydayActions.php';

/** Called only behind Panel's authenticated, CSRF-protected owner action boundary. */
final class NasaActions
{
    private static function input(string $key,string $default=''): string {return NasaStore::text($_POST[$key]??$default,13000,true);}
    private static function selectedSource(): array
    {
        $token=self::input('research_source');
        if(!preg_match('/^(research_node|research_source|research_passage|research_edge|research_claim_version|manual_evidence):(0|[1-9][0-9]{0,9}):(0|[1-9][0-9]{0,9})$/D',$token,$m))throw new \RuntimeException('Choose a saved research or manual evidence record.');
        return [$m[1],(int)$m[3],(int)$m[2]];
    }
    public static function serve(string $directory,string $action,string $entry): void
    {
        if(str_starts_with($action,'nasa_everyday_')){EverydayActions::serve($directory,$action,$entry);return;}
        $json=($_GET['nasa_api']??'')==='1';$id=0;$researchDestination=null;$researchRunDestination=null;$promotionRun=$promotionNode=null;
        try{
            $s=new NasaStore($directory);
            if($action==='nasa_start'&&(self::input('promote_run')!==''||self::input('promote_node')!=='')){$promotionRun=NasaStore::id(self::input('promote_run'));$promotionNode=NasaStore::id(self::input('promote_node'));}
            if($action==='nasa_start'){$id=$s->start(self::input('source_kind'),NasaStore::id(self::input('source_id')),self::input('phase','pre'),self::input('reason'));}
            else{
                $id=NasaStore::id(self::input('nasa_project'));$p=$s->project($id);$version=NasaStore::id(self::input('version','0'),true);
                switch($action){
                    case 'nasa_template':$type=self::input('artifact');$s->saveArtifact($id,$type,NasaKnowledge::template($type),'draft',0);break;
                    case 'nasa_save_artifact':$s->saveArtifact($id,self::input('artifact'),self::input('content'),self::input('status'),$version);break;
                    case 'nasa_restore_artifact':$s->restoreArtifact($id,NasaStore::id(self::input('record_id')),$version);break;
                    case 'nasa_archive_record':case 'nasa_restore_record':$s->archiveRecord($id,self::input('kind'),self::input('item_key'),$version,$action==='nasa_restore_record');break;
                    case 'nasa_save_record':
                        $kind=self::input('kind');$data=[];
                        foreach(['source','method','evidence','owner','mitigation','requirement','artifact','units','direction','alternatives','revisit','phase','residual_risk','verification_confirmed','side_a','side_b','payload','errors','auth','change_rule'] as $key)$data[$key]=self::input($key);
                        foreach(['required_scope','configuration_required','required_configuration_ref','rating_status'] as $key)if(array_key_exists($key,$_POST))$data[$key]=self::input($key);
                        if($kind==='tailoring'){$criteria=$_POST['criteria']??[];if(!is_array($criteria)||!array_is_list($criteria)||count($criteria)>8||array_filter($criteria,static fn($v)=>!is_string($v)))throw new \RuntimeException('Choose valid tailoring criteria.');$data['criteria']=$criteria;}
                        foreach(['likelihood','consequence','residual_likelihood','residual_consequence'] as $key)if(isset($_POST[$key]))$data[$key]=NasaStore::id(self::input($key));
                        foreach(['target','observed'] as $key)if(isset($_POST[$key])){$v=filter_var(self::input($key),FILTER_VALIDATE_FLOAT);if($v===false)throw new \RuntimeException('Use a finite numeric measurement.');$data[$key]=$v;}
                        $s->saveRecord($id,$kind,self::input('item_key'),self::input('title'),self::input('content'),self::input('status'),$data,$version);break;
                    case 'nasa_attach_provenance':
                        [$sourceKind,$sourceId,$runId]=self::selectedSource();$manual=[];foreach(['title','content','reference','method','scope','configuration_ref','limitations'] as $field)$manual[$field]=self::input('manual_'.$field);
                        $s->attachProvenance($id,self::input('kind'),self::input('item_key'),$version,$sourceKind,$sourceId,$runId,self::input('note'),$manual);break;
                    case 'nasa_review_provenance':$s->reviewProvenance($id,NasaStore::id(self::input('link_id')),NasaStore::id(self::input('link_version')),$version,self::input('review_note'));break;
                    case 'nasa_promote_research':
                        [$sourceKind,$sourceId,$runId]=self::selectedSource();$source=$s->researchSource($id,$sourceKind,$sourceId,$runId);$target=self::input('target_type');$kind=str_starts_with($target,'artifact:')?'artifact':$target;$key=$kind==='artifact'?substr($target,9):self::input('target_key');
                        $title=self::input('title')?:mb_strcut($source['title'],0,160,'UTF-8');$content=self::input('content')?:mb_strcut($source['content'],0,3000,'UTF-8');$data=match($kind){'requirement'=>['source'=>'Selected research '.$sourceKind.' #'.$sourceId,'method'=>self::input('method'),'evidence'=>''],'risk'=>['owner'=>'Project owner — confirm assignment','mitigation'=>'Investigate the selected uncertainty and record an owner-reviewed response.','likelihood'=>3,'consequence'=>3,'residual_likelihood'=>3,'residual_consequence'=>3,'requirement'=>'','artifact'=>'risks'],'decision'=>['alternatives'=>'Compare the selected idea with the current approach. Alternatives need owner review.','revisit'=>'Before committing implementation or approving the related artifact.'],default=>[]};
                        $data['provenance_note']=self::input('note');$s->promoteResearch($id,$sourceKind,$sourceId,$runId,$kind,$key,$title,$content,$data,NasaStore::id(self::input('target_version','0'),true));break;
                    case 'nasa_focused_research':
                        $focus=self::input('focus_key');$isRisk=str_starts_with($focus,'risk:');$key=$isRisk?substr($focus,5):$focus;$out=$s->focusedResearch($id,$isRisk?'risk':'gap',$key,NasaStore::id(self::input('focus_version','0'),true),NasaStore::id(self::input('revision'),true),NasaStore::id(self::input('run_id','0'),true));$researchDestination=(int)$out['research_project_id'];$researchRunDestination=$out['run_id'];
                        if($out['requires_start']&&$out['created']&&AgentRunner::configured()){try{$researchRunDestination=(new Investigation($directory))->createRun($researchDestination);}catch(\RuntimeException $failure){Diagnostics::record('nasa.focused_research.start_not_admitted',['project_id'=>$id,'research_project_id'=>$researchDestination],$failure);}}break;
                    case 'nasa_review':$s->review($id);break;
                    case 'nasa_collaborate':
                        $types=$_POST['artifacts']??[];if(!is_array($types)||!array_is_list($types)||count($types)>3||array_filter($types,static fn($v)=>!is_string($v)))throw new \RuntimeException('Choose up to three artifact types.');(new NasaProvider($s))->run($id,'collaborate',$types);break;
                    case 'nasa_proposal_edit':
                        $proposal=$_POST['proposal']??[];if(!is_array($proposal))throw new \RuntimeException('Use the proposal form.');$s->editProposal($id,self::input('proposal_key'),$version,NasaStore::proposalFromForm($proposal));break;
                    case 'nasa_proposal_accept':case 'nasa_proposal_reject':$s->decideProposal($id,self::input('proposal_key'),$version,$action==='nasa_proposal_accept'?'accept':'reject');break;
                    case 'nasa_ai_review':(new NasaProvider($s))->run($id,'review');break;
                    case 'nasa_generate':
                        $type=self::input('artifact');$types=$type==='missing'?array_values(array_diff(array_keys(NasaKnowledge::ARTIFACTS),array_column(array_filter($s->items($id),static fn($i)=>$i['kind']==='artifact'),'item_key'))):[$type];
                        if(!$types)throw new \RuntimeException('All artifact types already have a document. Open one to create a new AI draft.');(new NasaProvider($s))->run($id,'collaborate',array_slice($types,0,3));break;
                    case 'nasa_advance':$s->advance($id,NasaStore::id(self::input('revision'),true));break;
                    case 'nasa_link':$s->linkResearch($id,NasaStore::id(self::input('research_id','0'),true),NasaStore::id(self::input('revision'),true));break;
                    case 'nasa_task':$s->createTask($id,NasaStore::id(self::input('review_id')),NasaStore::id(self::input('finding_index'),true));break;
                    case 'nasa_map':
                        $review=NasaStore::id(self::input('review_id'));$index=NasaStore::id(self::input('finding_index'),true);$f=$s->finding($id,$review,$index);
                        if(!$p['linked_research_id'])throw new \RuntimeException('Link a research project first.');
                        $engine=new Investigation($directory);$run=NasaStore::id(self::input('run_id'));$snapshot=$engine->getRunSnapshot($run);if((int)$snapshot['run']['project_id']!==(int)$p['linked_research_id'])throw new \RuntimeException('Choose a run from the linked research project.');
                        $engine->addUserIdea($run,$f['title'],$f['detail'],'nasa:'.$id.':'.$review.':'.$index);break;
                    case 'nasa_baseline':$s->baseline($id,self::input('title'),isset($_POST['revision'])?NasaStore::id(self::input('revision'),true):null);break;
                    case 'nasa_restore_baseline':$s->restoreBaseline($id,NasaStore::id(self::input('baseline_id')),self::input('confirmation'),NasaStore::id(self::input('revision'),true));break;
                    case 'nasa_export':
                        $format=self::input('format');if(!in_array($format,['json','markdown','context_json','context_markdown'],true))throw new \RuntimeException('Choose a JSON or Markdown export.');$isPack=str_starts_with($format,'context_');$bundle=$isPack?$s->contextPack($id):$s->export($id);$jsonFormat=in_array($format,['json','context_json'],true);
                        header('Content-Type: '.($jsonFormat?'application/json':'text/markdown').'; charset=utf-8');header('Content-Disposition: attachment; filename="nasa-project-'.$id.'.'.($isPack?'context.':'').($jsonFormat?'json':'md').'"');
                        if($format==='context_markdown')echo $s->contextPackMarkdown($id);elseif($jsonFormat)echo json_encode($bundle,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                        else{echo '# '.$p['source']['name']." — engineering review package\n\nNASA/SP-2016-6105 Rev 2 adaptation. Planning records; not NASA approval.\n".NasaKnowledge::SOURCE."\n\nPhase: ".$p['phase']."\n\n";foreach($bundle['workspace']['items'] as $i)echo '## '.$i['item_key'].' · '.$i['title']."\n\nVersion ".$i['version'].' · '.$i['status'].' · '.$i['created_by']."\n\n".$i['content']."\n\n```json\n".json_encode($i['data'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n```\n\n";foreach($bundle['workspace']['reviews'] as $r)echo '## Review #'.$r['id']."\n\n```json\n".$r['report_json']."\n```\n\n";}
                        return;
                    default:throw new \RuntimeException('Unknown NASA Managed action.');
                }
            }
            Diagnostics::record('nasa.action.complete',['action'=>$action,'project_id'=>$id]);
            $location=$researchDestination!==null?$entry.'?view=research&project='.$researchDestination.($researchRunDestination?'&run='.(int)$researchRunDestination:''):$entry.'?view=nasa&nasa_project='.$id;
            if($action==='nasa_start'&&$promotionRun!==null)$location.='&promote_run='.$promotionRun.'&promote_node='.$promotionNode;
            if($researchDestination===null&&in_array($action,['nasa_save_record','nasa_attach_provenance','nasa_review_provenance','nasa_promote_research'],true)&&$s->engineeringProfile($id)['mode']==='everyday')$location.='#everyday-results';
            if(in_array($action,['nasa_template','nasa_save_artifact','nasa_generate'],true)){$artifact=self::input('artifact');if(isset(NasaKnowledge::ARTIFACTS[$artifact]))$location.='&edit_artifact='.rawurlencode($artifact);}
            if($json){header('Content-Type: application/json');echo json_encode(['location'=>$location,'message'=>'Planning records updated.'],JSON_THROW_ON_ERROR);return;}header('Location: '.$location,true,303);
        }catch(\Throwable $e){Diagnostics::record('nasa.action.failed',['action'=>$action,'project_id'=>$id],$e);http_response_code(422);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>Diagnostics::clean($e instanceof \RuntimeException?$e->getMessage():'Planning could not be saved. Refresh the project and inspect Admin diagnostics.')],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);}
    }
}
