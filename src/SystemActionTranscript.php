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
        $entries=[SystemConsoleTranscript::entry($prefix.'review',(float)($data['prepared_at']??$operation['created_at']),'guided',
            self::text($operation,$notebook,array_merge($lines,self::commands($steps,$status))))];
        if($approved){
            $execution=[$title.' · '.$status];
            if(is_array($result['steps']??null))$execution=array_merge($execution,self::commands($result['steps'],$status));
            $summary=$result['summary']??null;
            if(is_string($summary)&&$summary!=='')$execution[]=$summary;
            $entries[]=SystemConsoleTranscript::entry($prefix.'run',(float)$approved,'guided',self::text($operation,$notebook,$execution));
        }
        return $entries;
    }

    private static function commands(array $steps,string $status):array
    {
        $lines=[];
        foreach(array_slice($steps,-30) as $step){
            $argv=$step['command']??null;
            if(!is_array($argv)||!$argv||count($argv)>40||array_filter($argv,static fn($value)=>!is_string($value)))continue;
            $line='$ '.implode(' ',$argv);
            $stdout=$step['stdout']??null;
            $stderr=$step['stderr']??null;
            if(is_string($stdout)&&$stdout!=='')$line.="\n".$stdout;
            if(is_string($stderr)&&$stderr!=='')$line.="\n[stderr]\n".$stderr;
            $exit=$step['exit_code']??null;
            $outcome=is_int($exit)?'exit '.$exit:($status==='running'?'awaiting output':'exit unconfirmed');
            $lines[]=$line."\n[".$outcome.(!empty($step['truncated'])?' · output truncated':'').']';
        }
        return $lines;
    }

    private static function text(array $operation,SystemNotebook $notebook,array $lines):string
    {
        return SystemConsoleTranscript::text($notebook->redact((int)$operation['system_id'],implode("\n\n",$lines)),24000);
    }
}
