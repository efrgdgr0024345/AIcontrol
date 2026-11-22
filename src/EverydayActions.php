<?php
declare(strict_types=1);
namespace AIcontrol;
use RuntimeException;
require_once __DIR__.'/NasaProvider.php';

/** Owner/session/CSRF protection is enforced by Panel before dispatch. */
final class EverydayActions
{
    private static function input(string $key,string $default=''): string
    {
        $value=$_POST[$key]??$default;if(!is_string($value)||strlen($value)>12000||!mb_check_encoding($value,'UTF-8'))throw new RuntimeException('Use valid text within the displayed limits.');return $value;
    }
    private static function id(string $key,bool $zero=false): int {return NasaStore::id(self::input($key,$zero?'0':''),$zero);}
    private static function object(string $key): array
    {
        $data=json_decode(self::input($key),true,32,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Use a valid structured proposal.');return $data;
    }
    public static function serve(string $directory,string $action,string $entry): void
    {
        $id=0;$promotionRun=$promotionNode=null;
        try{
            $s=new NasaStore($directory);
            if($action==='nasa_everyday_start'){
                if(self::input('promote_run')!==''||self::input('promote_node')!==''){$promotionRun=NasaStore::id(self::input('promote_run'));$promotionNode=NasaStore::id(self::input('promote_node'));}
                $id=$s->start(self::input('source_kind'),self::id('source_id'));$p=$s->project($id);
                if($s->engineeringProfile($id)['mode']==='rigorous'&&((int)$p['revision']>0||$p['phase']!=='pre'||$s->items($id)))throw new RuntimeException('Use the operating-mode decision to change an existing NASA Managed workspace.');
                $s->startEveryday($id,(int)$p['revision']);
            }else{
                $id=self::id('nasa_project');$s->project($id);
                switch($action){
                    case 'nasa_everyday_mode':$s->setEngineeringMode($id,self::input('mode'),self::input('reason'),self::input('exposure'),self::input('revisit'),self::id('revision',true));break;
                    case 'nasa_everyday_ai':(new NasaProvider($s))->run($id,self::input('operation'));break;
                    case 'nasa_everyday_proposal_edit':$s->editEverydayProposal($id,self::input('proposal_key'),self::id('version'),self::object('proposal_json'));break;
                    case 'nasa_everyday_proposal_accept':case 'nasa_everyday_proposal_reject':$s->decideEverydayProposal($id,self::input('proposal_key'),self::id('version'),$action==='nasa_everyday_proposal_accept'?'adopt':'reject');break;
                    case 'nasa_everyday_cycle':
                        $transition=self::input('transition');$dispositions=[];
                        if(!in_array($transition,['start','review','pause'],true)){
                            if(isset($_POST['dispositions'])){if(!is_array($_POST['dispositions'])||count($_POST['dispositions'])>100)throw new RuntimeException('Review the listed obligation dispositions.');$dispositions=$_POST['dispositions'];}
                            elseif(self::input('dispositions_json')!=='')$dispositions=self::object('dispositions_json');
                        }
                        $s->transitionCycle($id,self::input('cycle_key'),self::id('version'),$transition,self::input('reason'),$dispositions,self::id('revision',true),self::input('configuration_ref'));break;
                    case 'nasa_everyday_object':$s->saveEverydayObject($id,self::input('object_key'),self::id('version'),self::input('title'),self::input('content'),self::id('revision',true));break;
                    case 'nasa_everyday_observation':$s->reportCommitment($id,self::input('commitment_key'),self::id('version'),self::input('state'),self::input('source'),self::input('scope'),self::input('observation'),self::input('limitations'),self::id('revision',true),self::input('configuration_ref'));break;
                    default:throw new RuntimeException('Unknown everyday engineering action.');
                }
            }
            Diagnostics::record('engineering.owner_action',['action'=>$action,'project_id'=>$id]);
            $location=$entry.'?view=nasa&nasa_project='.$id;
            if($promotionRun!==null)$location.='&promote_run='.$promotionRun.'&promote_node='.$promotionNode;
            header('Location: '.$location,true,303);
        }catch(\Throwable $failure){
            Diagnostics::record('engineering.action.failed',['action'=>$action,'project_id'=>$id],$failure);http_response_code(422);header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error'=>Diagnostics::clean($failure instanceof RuntimeException?$failure->getMessage():'Engineering records could not be saved. Refresh and inspect Admin diagnostics.')],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }
}
