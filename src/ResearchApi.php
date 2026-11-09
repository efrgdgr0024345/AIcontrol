<?php
declare(strict_types=1);
namespace AIcontrol;
use Throwable;
final class ResearchApi
{
    private static function input(string $key): string { return is_string($_POST[$key]??null)?$_POST[$key]:''; }
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
            elseif($action==='research_step') { $engine->step($id); }
            elseif($action==='research_stop') { $engine->stop($id); }
            elseif($action==='research_learn') { $engine->learn($id,self::input('learn_action'),self::input('question')); }
            elseif($action==='research_export') {
                $text=$engine->export($id);header('Content-Type: text/markdown; charset=utf-8');header('Content-Disposition: attachment; filename="research-'.$id.'.md"');echo $text;return;
            }
            $snapshot=$engine->getRunSnapshot($id);
            if($json) { self::json($snapshot); return; }
            header('Location: '.$entry.'?view=research&project='.$snapshot['run']['project_id'].'&run='.$id,true,303);
        } catch(Throwable $e) {
            http_response_code(422);
            $safe=['Connect OpenAI in Admin first.','Invalid model configuration.','Research limit reached: ten provider requests per hour.','Research is already active. Stop or resolve it before starting another request.','Research run not found.','Research project not found.','Collect source cards before using Learn.','Invalid Learn action or question.'];
            $message=in_array($e->getMessage(),$safe,true)?$e->getMessage():'Research request did not complete. Inspect its saved status before starting another request.';
            if($json) { self::json(['error'=>$message]);return; }
            header('Content-Type: text/html; charset=utf-8');
            \AIcontrolWorkspaceUi::begin('Research request stopped',$entry);
            echo '<h1>Research request stopped</h1><p>'.\AIcontrolWorkspaceUi::e($message).'</p><a class="btn btn-primary" href="'.\AIcontrolWorkspaceUi::e($entry.'?view=research').'">Return to research</a>';
            \AIcontrolWorkspaceUi::end();
        }
    }
}
