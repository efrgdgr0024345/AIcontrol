<?php
declare(strict_types=1);
namespace AIcontrol;
use RuntimeException;
require_once __DIR__.'/AdminTestReports.php';

/** Invoked only after Panel's existing owner authentication and CSRF checks. */
final class AdminTestActions
{
    private static function input(string $name,string $default=''): string
    {
        $value=$_POST[$name]??$default;
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||strlen($value)>12000)throw new RuntimeException('Use valid report text within the displayed limits.');
        return $value;
    }
    private static function number(string $name,bool $zero=false): int
    {
        $value=self::input($name);
        if(!preg_match($zero?'/^(0|[1-9][0-9]{0,9})$/D':'/^[1-9][0-9]{0,9}$/D',$value))throw new RuntimeException('Choose a saved report and its current sequence.');
        return (int)$value;
    }
    private static function json(string $field): array
    {
        $value=json_decode(self::input($field),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($value))throw new RuntimeException('Use a valid structured report.');
        return $value;
    }
    public static function serve(string $directory,string $action,string $entry): void
    {
        $id=0;$json=($_GET['admin_test_api']??'')==='1';
        try{
            $store=new AdminTestReports($directory);
            if($action==='admin_test_start'){
                $plan=self::input('plan_json')===''?AdminTestReports::defaultPlan():self::json('plan_json');
                $id=$store->start(self::input('title'),self::input('expected_sha')?:null,$plan);
            }elseif($action==='admin_test_import'){$id=$store->import(self::json('packet_json'));}
            else{
                $id=self::number('report_id');
                switch($action){
                    case 'admin_test_check':$store->runLocalCheck($id,self::number('sequence',true),self::input('check_id'));break;
                    case 'admin_test_append':
                        $fields=[];foreach(['check_id','outcome','summary','output','claimed_tested_sha','client_utc','path','error_kind'] as $key){$value=self::input($key);if($value!=='')$fields[$key]=$value;}
                        foreach(['duration_ms','http_status','event_sequence'] as $key)if(self::input($key)!=='')$fields[$key]=self::number($key,true);
                        if(self::input('details_json')!=='')$fields['details']=self::json('details_json');
                        $store->append($id,self::number('sequence',true),self::input('event_kind','check.result'),$fields);break;
                    case 'admin_test_finish':$store->finish($id,self::number('sequence',true),self::input('owner_summary'));break;
                    case 'admin_test_read':
                        header('Content-Type: application/json; charset=utf-8');echo json_encode(['report'=>$store->report($id)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);return;
                    case 'admin_test_export':
                        $format=self::input('format');if(!in_array($format,['json','markdown'],true))throw new RuntimeException('Choose JSON or Markdown.');
                        $body=$store->export($id,$format);header('Content-Type: '.($format==='json'?'application/json':'text/markdown').'; charset=utf-8');header('Content-Disposition: attachment; filename="release-test-'.$id.'.'.($format==='json'?'json':'md').'"');echo $body;return;
                    default:throw new RuntimeException('Unknown test-report action.');
                }
            }
            $report=$store->report($id);$location=$entry.'?view=admin&test_report='.$id.'#release-test-record';
            Diagnostics::record('admin.test_report.saved',['action'=>$action,'report_id'=>$id]);
            if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['report'=>$report,'location'=>$location],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);return;}
            header('Location: '.$location,true,303);
        }catch(\Throwable $failure){
            Diagnostics::record('admin.test_report.failed',['action'=>$action,'report_id'=>$id],$failure);
            http_response_code(422);header('Content-Type: application/json; charset=utf-8');
            $message=$failure instanceof RuntimeException?$failure->getMessage():'Report could not be saved. Use the displayed form and inspect Admin diagnostics.';
            echo json_encode(['error'=>Diagnostics::clean($message)],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }
}
