<?php
declare(strict_types=1);
namespace AIcontrol;
require_once __DIR__.'/NasaProvider.php';

/** Called only behind Panel's authenticated, CSRF-protected owner action boundary. */
final class NasaActions
{
    private static function input(string $key,string $default=''): string {return NasaStore::text($_POST[$key]??$default,13000,true);}
    public static function serve(string $directory,string $action,string $entry): void
    {
        $json=($_GET['nasa_api']??'')==='1';$id=0;
        try{
            $s=new NasaStore($directory);
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
                        foreach(['source','method','evidence','owner','mitigation','requirement','artifact','units','direction','alternatives','revisit','phase','residual_risk'] as $key)$data[$key]=self::input($key);
                        if($kind==='tailoring'){$criteria=$_POST['criteria']??[];if(!is_array($criteria)||!array_is_list($criteria)||count($criteria)>8||array_filter($criteria,static fn($v)=>!is_string($v)))throw new \RuntimeException('Choose valid tailoring criteria.');$data['criteria']=$criteria;}
                        foreach(['likelihood','consequence','residual_likelihood','residual_consequence'] as $key)if(isset($_POST[$key]))$data[$key]=NasaStore::id(self::input($key));
                        foreach(['target','observed'] as $key)if(isset($_POST[$key])){$v=filter_var(self::input($key),FILTER_VALIDATE_FLOAT);if($v===false)throw new \RuntimeException('Use a finite numeric measurement.');$data[$key]=$v;}
                        $s->saveRecord($id,$kind,self::input('item_key'),self::input('title'),self::input('content'),self::input('status'),$data,$version);break;
                    case 'nasa_review':$s->review($id);break;
                    case 'nasa_ai_review':(new NasaProvider($s))->run($id,'review');break;
                    case 'nasa_generate':
                        $type=self::input('artifact');$types=$type==='missing'?array_values(array_diff(array_keys(NasaKnowledge::ARTIFACTS),array_column(array_filter($s->items($id),static fn($i)=>$i['kind']==='artifact'),'item_key'))):[$type];
                        if(!$types)throw new \RuntimeException('All artifact types already have a document. Open one to create a new AI draft.');(new NasaProvider($s))->run($id,'generate',$types);break;
                    case 'nasa_advance':$s->advance($id,NasaStore::id(self::input('revision'),true));break;
                    case 'nasa_link':$s->linkResearch($id,NasaStore::id(self::input('research_id','0'),true),NasaStore::id(self::input('revision'),true));break;
                    case 'nasa_task':$s->createTask($id,NasaStore::id(self::input('review_id')),NasaStore::id(self::input('finding_index'),true));break;
                    case 'nasa_map':
                        $review=NasaStore::id(self::input('review_id'));$index=NasaStore::id(self::input('finding_index'),true);$f=$s->finding($id,$review,$index);
                        if(!$p['linked_research_id'])throw new \RuntimeException('Link a research project first.');
                        $engine=new Investigation($directory);$run=NasaStore::id(self::input('run_id'));$snapshot=$engine->getRunSnapshot($run);if((int)$snapshot['run']['project_id']!==(int)$p['linked_research_id'])throw new \RuntimeException('Choose a run from the linked research project.');
                        $engine->addUserIdea($run,$f['title'],$f['detail'],'nasa:'.$id.':'.$review.':'.$index);break;
                    case 'nasa_baseline':$s->baseline($id,self::input('title'));break;
                    case 'nasa_restore_baseline':$s->restoreBaseline($id,NasaStore::id(self::input('baseline_id')),self::input('confirmation'),NasaStore::id(self::input('revision'),true));break;
                    case 'nasa_export':
                        $bundle=$s->export($id);$format=self::input('format');if(!in_array($format,['json','markdown'],true))throw new \RuntimeException('Choose JSON or Markdown.');
                        header('Content-Type: '.($format==='json'?'application/json':'text/markdown').'; charset=utf-8');header('Content-Disposition: attachment; filename="nasa-project-'.$id.'.'.($format==='json'?'json':'md').'"');
                        if($format==='json')echo json_encode($bundle,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                        else{echo '# '.$p['source']['name']." — engineering review package\n\nNASA/SP-2016-6105 Rev 2 adaptation. Planning records; not NASA approval.\n".NasaKnowledge::SOURCE."\n\nPhase: ".$p['phase']."\n\n";foreach($bundle['workspace']['items'] as $i)echo '## '.$i['item_key'].' · '.$i['title']."\n\nVersion ".$i['version'].' · '.$i['status'].' · '.$i['created_by']."\n\n".$i['content']."\n\n```json\n".json_encode($i['data'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n```\n\n";foreach($bundle['workspace']['reviews'] as $r)echo '## Review #'.$r['id']."\n\n```json\n".$r['report_json']."\n```\n\n";}
                        return;
                    default:throw new \RuntimeException('Unknown NASA Managed action.');
                }
            }
            Diagnostics::record('nasa.action.complete',['action'=>$action,'project_id'=>$id]);
            $location=$entry.'?view=nasa&nasa_project='.$id;
            if(in_array($action,['nasa_template','nasa_save_artifact','nasa_generate'],true)){$artifact=self::input('artifact');if(isset(NasaKnowledge::ARTIFACTS[$artifact]))$location.='&edit_artifact='.rawurlencode($artifact);}
            if($json){header('Content-Type: application/json');echo json_encode(['location'=>$location,'message'=>'Planning records updated.'],JSON_THROW_ON_ERROR);return;}header('Location: '.$location,true,303);
        }catch(\Throwable $e){Diagnostics::record('nasa.action.failed',['action'=>$action,'project_id'=>$id],$e);http_response_code(422);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>Diagnostics::clean($e instanceof \RuntimeException?$e->getMessage():'Planning could not be saved. Refresh the project and inspect Admin diagnostics.')],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);}
    }
}
