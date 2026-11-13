<?php
declare(strict_types=1);
namespace AIcontrol;
use Throwable;
final class ResearchApi
{
    private static function input(string $key): string { return is_string($_POST[$key]??null)?$_POST[$key]:''; }
    private static function ids(string $key): ?array
    {
        if(!array_key_exists($key,$_POST))return null;
        $raw=$_POST[$key];if(!is_array($raw)||count($raw)>12)throw new \RuntimeException('Select valid source cards from this run.');
        $ids=[];foreach($raw as $value){if(!is_string($value)||!preg_match('/^[1-9][0-9]{0,9}$/D',$value))throw new \RuntimeException('Select valid source cards from this run.');$ids[]=(int)$value;}return $ids;
    }
    private static function json(array $data): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE); }
    public static function status(string $directory,int $id):void
    {
        try { self::json((new Investigation($directory))->getRunSnapshot($id)); }
        catch(Throwable $e) { Diagnostics::record('research.status.failed',['run_id'=>$id],$e); http_response_code(404); self::json(['error'=>'Research run is unavailable.']); }
    }
    public static function action(string $directory,string $action,string $entry,bool $json):void
    {
        $id=(int)self::input('run_id');
        try {
            Diagnostics::record('research.action',['action'=>$action,'run_id'=>$id]);
            $engine=new Investigation($directory);
            if($action==='research_start') { $id=$engine->createRun((int)self::input('project_id')); }
            elseif($action==='research_continue') {
                $selected=null;
                if (self::input('selection_explicit')==='1') {
                    $raw=$_POST['claim_ids']??[];
                    if (!is_array($raw) || count($raw)>5) throw new \RuntimeException('Select at least one valid claim.');
                    $selected=[];
                    foreach ($raw as $value) {
                        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D',$value)) throw new \RuntimeException('Select at least one valid claim.');
                        $selected[]=(int)$value;
                    }
                }
                $engine->continueRun($id,$selected);
            }
            elseif($action==='research_settings'){
                $cap=filter_var(self::input('spend_cap_usd'),FILTER_VALIDATE_FLOAT);$growth=filter_var(self::input('growth_searches'),FILTER_VALIDATE_INT);
                if($cap===false||$growth===false)throw new \RuntimeException('Invalid research settings.');
                $project=(int)self::input('project_id');$engine->configureProject($project,self::input('primary_model'),(float)$cap,self::input('auto_search')==='1',(int)$growth);
                if($json){self::json(['saved'=>true]);return;}header('Location: '.$entry.'?view=research&project='.$project,true,303);return;
            }
            elseif($action==='research_explore'){$engine->explore($id,self::ids('node_ids')??[],self::input('explore_mode')?:'explore');}
            elseif($action==='research_lock_evidence'){$engine->lockEvidence($id,self::ids('node_ids')??[]);}
            elseif(in_array($action,['research_promote_layer','research_demote_layer','research_park'],true)){$engine->changeLayer($id,self::ids('node_ids')??[],match($action){'research_promote_layer'=>'promote','research_demote_layer'=>'demote',default=>'park'},self::input('park_reason'));}
            elseif($action==='research_rank'){$engine->rankPath($id,(int)self::input('node_id'),(int)self::input('path_weight'),self::input('model_tier'));}
            elseif($action==='research_step') { if((isset($_POST['focus_node_id'])&&!is_string($_POST['focus_node_id']))||(isset($_POST['focus_note'])&&!is_string($_POST['focus_note'])))throw new \RuntimeException('Invalid focus note.');
                $focus=self::input('focus_node_id');
                if($focus!==''&&!preg_match('/^[1-9][0-9]{0,9}$/D',$focus))throw new \RuntimeException('Invalid focus note.');
                $engine->step($id,$focus===''?null:(int)$focus,self::input('focus_note')); }
            elseif($action==='research_finish') { $engine->finishVerification($id); }
            elseif($action==='research_repair') { $engine->requestRepair($id); }
            elseif($action==='research_stop') { $engine->stop($id); }
            elseif($action==='research_learn') { $ids=self::ids('source_ids');if(self::input('source_selection_explicit')==='1'&&$ids===null)$ids=[];$engine->learn($id,self::input('learn_action'),self::input('question'),$ids); }
            elseif($action==='research_export') {
                $text=$engine->export($id);header('Content-Type: text/markdown; charset=utf-8');header('Content-Disposition: attachment; filename="research-'.$id.'.md"');echo $text;return;
            }
            $snapshot=$engine->getRunSnapshot($id);
            if($json) { self::json($snapshot); return; }
            header('Location: '.$entry.'?view=research&project='.$snapshot['run']['project_id'].'&run='.$id,true,303);
        } catch(Throwable $e) {
            Diagnostics::record('research.action.failed',['action'=>$action,'run_id'=>$id],$e);
            http_response_code(422);
            $safe=['Evidence is restricted to the explicitly locked branches.','Wait for an idle living map before exploring or locking branches.','Select one to five valid branches from this investigation.','Choose a layer action and a reason up to 1,000 bytes.','Choose a valid model exploration action.','Not enough hourly requests remain for the selected Evidence plan.','Select unlocked Active or Secondary model branches to ground.','Explore the map before locking Evidence.','Use model exploration or explicitly Lock in with Evidence on selected branches.','Another AI request is finishing. Wait before asking for suggestions or starting research.','Project spend cap reached. Saved findings are retained; adjust the cap in Project menu to continue.','Current pricing is unavailable for this model. Choose a priced research model.','Choose a priced model, a USD cap from 0.05 to 1000, and zero to three growth searches.','Choose an idle path, weight 10–100 and a valid model tier.','At least six requests must remain to start a verified investigation.','Invalid focus note.','Focus requires an idle Evidence phase with a remaining search.','Choose a selected claim, open question or dead end from this run.','Select valid source cards from this run.','Select at least one valid claim.','This run is not awaiting claim review.','Connect OpenAI in Admin first.','Invalid model configuration.','Research hourly request limit reached.','Research is already active. Stop or resolve it before starting another request.','Research run not found.','Research project not found.','Collect source cards before using Learn.','Invalid Learn action or question.'];
            $message=in_array($e->getMessage(),$safe,true)?$e->getMessage():'Research request did not complete. Inspect its saved status before starting another request.';
            if($json) { self::json(['error'=>$message,'budget'=>isset($engine)?$engine->requestBudget():null]);return; }
            header('Content-Type: text/html; charset=utf-8');
            \AIcontrolWorkspaceUi::begin('Research request stopped',$entry);
            echo '<h1>Research request stopped</h1><p>'.\AIcontrolWorkspaceUi::e($message).'</p><a class="btn btn-primary" href="'.\AIcontrolWorkspaceUi::e($entry.'?view=research').'">Return to research</a>';
            \AIcontrolWorkspaceUi::end();
        }
    }
}
