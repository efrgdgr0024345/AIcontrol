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
        catch(Throwable) { http_response_code(404); self::json(['error'=>'Research run is unavailable.']); }
    }
    public static function action(string $directory,string $action,string $entry,bool $json):void
    {
        $id=(int)self::input('run_id');
        try {
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
            http_response_code(422);
            $safe=['At least six requests must remain to start a verified investigation.','Invalid focus note.','Focus requires an idle Evidence phase with a remaining search.','Choose a selected claim, open question or dead end from this run.','Select valid source cards from this run.','Select at least one valid claim.','This run is not awaiting claim review.','Connect OpenAI in Admin first.','Invalid model configuration.','Research limit reached: ten provider requests per hour.','Research is already active. Stop or resolve it before starting another request.','Research run not found.','Research project not found.','Collect source cards before using Learn.','Invalid Learn action or question.'];
            $message=in_array($e->getMessage(),$safe,true)?$e->getMessage():'Research request did not complete. Inspect its saved status before starting another request.';
            if($json) { self::json(['error'=>$message,'budget'=>isset($engine)?$engine->requestBudget():null]);return; }
            header('Content-Type: text/html; charset=utf-8');
            \AIcontrolWorkspaceUi::begin('Research request stopped',$entry);
            echo '<h1>Research request stopped</h1><p>'.\AIcontrolWorkspaceUi::e($message).'</p><a class="btn btn-primary" href="'.\AIcontrolWorkspaceUi::e($entry.'?view=research').'">Return to research</a>';
            \AIcontrolWorkspaceUi::end();
        }
    }
}
