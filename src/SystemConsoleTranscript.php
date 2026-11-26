<?php
declare(strict_types=1);
namespace AIcontrol;

/** Display records shared by the initial page and subsequent console updates. */
final class SystemConsoleTranscript
{
    public static function history(Systems $systems,array $page):array
    {
        $system=(int)$page['selected']['id'];
        $terminal=$page['terminal']??null;
        $notebook=new SystemNotebook($systems);
        $entries=[];
        if($terminal){
            $welcome=$terminal['welcome']?:$page['selected']['facts'];
            $intro="Host key verified. Signed in with the saved SSH key.\n".self::text($notebook->redact($system,$welcome),8192);
            $entries[]=self::entry('welcome',(float)$terminal['created_at'],'ssh',trim($intro));
            $entries=array_merge($entries,self::commands($terminal['commands'],true,$notebook,$system));
            $agent=$systems->query("SELECT c.* FROM system_commands c JOIN system_runs r ON r.id=c.run_id
                WHERE r.system_id=? AND r.id>? AND r.task NOT IN ('Manual SSH command console','AI-guided action selection')
                ORDER BY c.id DESC LIMIT 24",[$system,$terminal['run_id']]);
            $entries=array_merge($entries,self::commands($agent,false,$notebook,$system));
        }
        foreach($page['agent_activity']['entries']??[] as $entry)$entries[]=$entry;

        $playbooks=new SystemPlaybooks($systems);
        $selected=$page['playbook']??null;
        if($selected)$entries=array_merge($entries,SystemActionTranscript::entries($selected,$notebook));
        if($terminal){
            foreach(array_slice($page['playbooks']??[],0,10) as $action){
                if($action['id']===($selected['id']??null)||$action['created_at']<$terminal['created_at'])continue;
                try{
                    $entries=array_merge($entries,SystemActionTranscript::entries($playbooks->get($action['id'],$system),$notebook));
                }catch(\Throwable $error){
                    Diagnostics::record('systems.transcript.action_unavailable',['operation_id'=>$action['id']],$error);
                }
            }
        }
        $unique=array_column($entries,null,'key');
        uasort($unique,static fn(array $a,array $b):int=>$a['at']<=>$b['at']?:strnatcmp($a['key'],$b['key']));
        return array_values($unique);
    }

    public static function text(?string $value,int $limit):string
    {
        $text=Diagnostics::clean($value??'');
        $text=preg_replace('/\x1b(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07\x1b]*(?:\x07|\x1b\\\\))|[\x00-\x08\x0b-\x1f\x7f]/','',$text)??'';
        if(!mb_check_encoding($text,'UTF-8'))$text=mb_scrub($text,'UTF-8');
        return strlen($text)>$limit?mb_strcut($text,0,$limit,'UTF-8')."\n[Excerpt truncated]":$text;
    }

    public static function entry(string $key,float $at,string $source,string $text):array
    {
        return ['key'=>$key,'at'=>$at,'source'=>$source,'text'=>$text];
    }

    public static function invocation(string $key,float $at,string $source,string $command,string $stdout,string $stderr,string $status,bool $failed,string $hint=''):array
    {
        $streams=$stderr===''?$stdout:($stdout===''?'':rtrim($stdout,"\n")."\n")."[stderr]\n".$stderr;
        $body=rtrim($streams,"\n");
        $text='$ '.$command.($body===''?'':"\n".$body).($hint===''?'':"\n".$hint)."\n[".$status.']';
        return self::entry($key,$at,$source,$text)+['command'=>$command,'output'=>$body,'status'=>$status,'failed'=>$failed,'hint'=>$hint];
    }

    public static function commands(array $rows,bool $manual,SystemNotebook $notebook,int $system):array
    {
        $streams=$notebook->redact($system,array_map(static fn(array $row):array=>[$row['command'],$row['stdout'],$row['stderr']],$rows));
        $entries=[];
        foreach($rows as $i=>$row){
            [$command,$stdout,$stderr]=$streams[$i];
            $stdout=self::text($stdout,$manual?16384:4096);
            $stderr=self::text($stderr,$manual?16384:4096);
            $complete=$row['status']==='complete';
            $status=$complete&&$row['exit_code']!==null?'exit '.$row['exit_code']:
                ($row['status']==='running'?'waiting for VPS output':str_replace('_',' ',$row['status']));
            if($row['truncated'])$status.=' · output truncated';
            $emptyListing=$manual&&$row['command']==='ls'&&$complete&&$row['exit_code']===0
                &&$row['stdout']===''&&$row['stderr']===''&&!$row['truncated'];
            $hint=$emptyListing?'No visible entries. Run ls -la to include hidden files.':'';
            $entries[]=self::invocation('command-'.$row['id'],(float)$row['created_at'],$manual?'manual':'agent',
                self::text($command,4000),$stdout,$stderr,$status,
                $row['status']==='outcome_uncertain'||($row['exit_code']!==null&&$row['exit_code']!==0),$hint);
        }
        return $entries;
    }
}
