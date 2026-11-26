<?php
declare(strict_types=1);
namespace AIcontrol;

final class SystemActionTranscript
{
    public static function entries(array $operation,SystemNotebook $notebook):array
    {
        $data=$operation['data'];
        $result=$data['result']??[];
        $status=$operation['status'];
        $title=SystemPlaybooks::RECIPES[$operation['recipe']]['title'];
        $approved=$data['approved_at']??null;
        $lines=[$title.' · '.($approved?'preflight':$status)];
        if(is_string($data['ai_reason']??null))$lines[]='AI selected this action: '.$data['ai_reason'];
        if($status==='review')$lines[]='Ready for review. Applying the action requires approval.';

        $inventory=$data['inventory']??[];
        if($inventory){
            $facts=[];
            foreach(['os'=>'OS','architecture'=>'Architecture','ssh_port'=>'SSH port','disk_free_bytes'=>'Free disk bytes','failed_service_count'=>'Failed services','backup_file_count'=>'Local backup records'] as $field=>$label){
                $value=$inventory[$field]??null;
                if(is_string($value)||is_int($value))$facts[]=$label.': '.$value;
            }
            foreach(['vpn'=>'WireGuard','firewall'=>'Firewall','codex'=>'Codex','security_updates'=>'Automatic updates'] as $field=>$label){
                $value=$inventory['setup_checks'][$field]??null;
                if(in_array($value,['active','configured','installed','absent','unknown'],true))$facts[]=$label.': '.$value;
            }
            if($facts)$lines[]="VPS inventory\n".implode("\n",$facts);
        }
        $steps=[];
        foreach(['inventory_steps','setup_steps'] as $field){
            if(is_array($inventory[$field]??null))$steps=array_merge($steps,$inventory[$field]);
        }
        foreach($data['results']??[] as $entry){
            $tool=$entry['tool']??'';
            if(is_string($tool)&&isset(SystemsAgent::INSPECT[$tool])&&is_array($entry['result']??null)){
                $steps[]=['command'=>[SystemsAgent::INSPECT[$tool]]]+$entry['result'];
            }
        }
        $prefix='action-'.$operation['id'].'-';
        $prepared=(float)($data['prepared_at']??$operation['created_at']);
        $system=(int)$operation['system_id'];
        $entries=[SystemConsoleTranscript::entry($prefix.'review',$prepared,'guided',
            self::text($system,$notebook,implode("\n",$lines)))];
        $entries=array_merge($entries,self::commands($prefix.'review-',$prepared,$steps,$system,$notebook,false));
        if($approved){
            $entries[]=SystemConsoleTranscript::entry($prefix.'run',(float)$approved,'guided',$title.' · '.$status);
            if(is_array($result['steps']??null)){
                $entries=array_merge($entries,self::commands($prefix.'run-',(float)$approved,$result['steps'],$system,$notebook,$status==='running'));
            }
            $summary=$result['summary']??null;
            if(is_string($summary)&&$summary!==''){
                $entries[]=SystemConsoleTranscript::entry($prefix.'run-summary',(float)$approved,'guided',self::text($system,$notebook,$summary));
            }
        }
        return $entries;
    }

    private static function commands(string $prefix,float $at,array $steps,int $system,SystemNotebook $notebook,bool $running):array
    {
        $entries=[];
        $steps=array_slice(array_values($steps),-30,null,true);
        $redacted=$notebook->redact($system,$steps);
        foreach($steps as $i=>$step){
            $argv=$redacted[$i]['command']??null;
            if(!is_array($argv)||!$argv||count($argv)>40||array_filter($argv,static fn($value)=>!is_string($value)))continue;
            $stdout=$redacted[$i]['stdout']??null;
            $stderr=$redacted[$i]['stderr']??null;
            $exit=$step['exit_code']??null;
            $outcome=is_int($exit)?'exit '.$exit:($running?'awaiting output':'exit unconfirmed');
            if(!empty($step['truncated']))$outcome.=' · output truncated';
            $entries[]=SystemConsoleTranscript::invocation($prefix.'step-'.$i,$at,'guided',
                SystemConsoleTranscript::text(implode(' ',$argv),2000),
                SystemConsoleTranscript::text(is_string($stdout)?$stdout:null,2048),
                SystemConsoleTranscript::text(is_string($stderr)?$stderr:null,2048),
                $outcome,($exit===null&&!$running)||(is_int($exit)&&$exit!==0));
        }
        return $entries;
    }

    private static function text(int $system,SystemNotebook $notebook,string $text):string
    {
        return SystemConsoleTranscript::text($notebook->redact($system,$text),4096);
    }
}
