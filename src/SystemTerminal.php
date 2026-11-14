<?php
declare(strict_types=1);
namespace AIcontrol;

use RuntimeException;

/** Authenticated command console. No daemon or provider call; cwd is explicitly confirmed. */
final class SystemTerminal
{
    public function __construct(private Systems $systems,private ?object $ssh=null){$this->ssh??=new SshTransport();}
    public function connect(int $system,string $owner): array
    {
        $s=$this->systems->system($system);
        if($s['enrollment_id']){
            $e=(new SystemEnrollment($this->systems))->get($s['enrollment_id']);
            if(!$e['receipt_json'])throw new RuntimeException('Paste the key setup reply before connecting.');
        }
        $this->systems->verify($system,$this->ssh);
        $id=bin2hex(random_bytes(16));
        $this->systems->write("INSERT INTO system_runs(system_id,task,status,created_at)VALUES(?,'Manual SSH command console','console',?)",[$system,time()]);$run=(int)$this->systems->db->lastInsertId();
        $this->systems->write('INSERT INTO system_terminals(id,system_id,run_id,owner_hash,cwd,expires_at,created_at)VALUES(?,?,?,?,?,?,?)',[$id,$system,$run,$owner,$s['home_dir'],time()+1800,time()]);
        if($s['enrollment_id'])$this->systems->write("UPDATE system_enrollments SET state='connected' WHERE id=?",[$s['enrollment_id']]);
        return $this->get($id,$owner);
    }
    public function get(string $id,string $owner): array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Connect to open a command console.');
        $terminal=$this->systems->query('SELECT * FROM system_terminals WHERE id=?',[$id])[0]??throw new RuntimeException('Console unavailable. Connect again.');
        if(!hash_equals($terminal['owner_hash'],$owner))throw new RuntimeException('This console belongs to another sign-in session. Connect again.');
        $system=$this->systems->system((int)$terminal['system_id']);
        if(!$terminal['enabled']||!$system['enabled']||!$system['verified_at']||$terminal['expires_at']<time())throw new RuntimeException('Console disconnected or expired. Connect again.');
        return $terminal;
    }
    public function view(string $id,string $owner): array
    {
        $t=$this->get($id,$owner);unset($t['owner_hash']);
        $t['commands']=array_reverse($this->systems->query('SELECT * FROM system_commands WHERE run_id=? ORDER BY id DESC LIMIT 20',[$t['run_id']]));
        return $t;
    }
    public function disconnect(string $id,string $owner): void
    {
        $t=$this->get($id,$owner);$this->systems->write('UPDATE system_terminals SET enabled=0 WHERE id=?',[$id]);
        $this->systems->write("UPDATE system_runs SET status='disconnected',finished_at=? WHERE id=?",[time(),$t['run_id']]);
    }
    public function command(string $id,string $owner,string $command): array
    {
        $command=trim($command);if($command===''||strlen($command)>4000||str_contains($command,"\0")||!mb_check_encoding($command,'UTF-8'))throw new RuntimeException('Enter a command using 1–4,000 UTF-8 bytes.');
        $t=$this->get($id,$owner);$s=$this->systems->system((int)$t['system_id']);
        $path=$this->systems->directory.'/systems-run.lock';if(is_link($path))throw new RuntimeException('Systems execution lock unavailable.');$lock=fopen($path,'c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new RuntimeException('Another SSH step is running. Wait before sending another command.');}
        $commandId=null;
        try {
            if($this->systems->query("SELECT id FROM system_runs WHERE status IN ('ready','working')"))throw new RuntimeException('Stop the active agent task before using the manual console.');
            $recent=$this->systems->query('SELECT COUNT(*) AS n FROM system_commands c JOIN system_runs r ON r.id=c.run_id WHERE r.system_id=? AND r.task=? AND c.created_at>?',[$s['id'],'Manual SSH command console',time()-3600])[0]['n'];
            if($recent>=60)throw new RuntimeException('Console limit reached: 60 commands per system per hour.');
            if($this->systems->query("SELECT id FROM system_commands WHERE run_id=? AND status='running'",[$t['run_id']]))throw new RuntimeException('A previous command has an unverified outcome. Disconnect and inspect the server before reconnecting.');
            $readonly=$s['access']==='inspect';$actual=$command;
            if($readonly){
                $aliases=SystemRisk::commands();$aliases['whoami']='id -un';$aliases['ls']='ls -la';
                if(isset($aliases[$command]))$actual=$aliases[$command];
                elseif(!in_array($command,$aliases,true))throw new RuntimeException('Read-only console: use a listed diagnostic command such as uptime, df -h, resources or services.');
            }
            $marker='AICTRL_CWD_'.bin2hex(random_bytes(16)).':';
            if(!$readonly){$cd=$t['cwd']===null?'"$HOME"':SystemRisk::quote($t['cwd']);$actual='cd -- '.$cd.' || exit 125; { '.$command."\n".'}; __aicontrol_status=$?; __aicontrol_cwd=$(printf %s "$PWD" | base64 -w 0); printf '.SystemRisk::quote("\n".$marker."%s\n").' "$__aicontrol_cwd"; exit "$__aicontrol_status"';}
            $this->systems->write("INSERT INTO system_commands(run_id,command,status,created_at)VALUES(?,?,'running',?)",[$t['run_id'],Diagnostics::clean($command),time()]);$commandId=(int)$this->systems->db->lastInsertId();
            $cancel=function()use($id,$owner):bool{try{$this->get($id,$owner);return false;}catch(\Throwable){return true;}};
            if($cancel())throw new RuntimeException('Console disconnected before dispatch.');
            $r=$this->ssh->execute($s,$this->systems->privateKey((int)$s['id']),$actual,$this->systems->directory,$cancel);
            $cwd=$t['cwd'];$cwdVerified=$readonly;
            if(!$readonly&&preg_match('~\n'.preg_quote($marker,'~').'([A-Za-z0-9+/=]*)\n~',$r['stdout'],$match)){
                $candidate=base64_decode($match[1],true);
                if(is_string($candidate)&&str_starts_with($candidate,'/')&&strlen($candidate)<=4096&&!str_contains($candidate,"\0")&&mb_check_encoding($candidate,'UTF-8')){$cwd=$candidate;$cwdVerified=true;}
                $r['stdout']=str_replace($match[0],'',$r['stdout']);
            }
            $uncertain=$r['timed_out']||$r['cancelled']||in_array($r['exit_code'],[-1,255,124,137],true);
            $this->systems->write('UPDATE system_commands SET status=?,stdout=?,stderr=?,exit_code=?,timed_out=?,truncated=?,elapsed_ms=?,finished_at=? WHERE id=?',[$uncertain?'outcome_uncertain':'complete',Diagnostics::clean($r['stdout']),Diagnostics::clean($r['stderr']),$r['exit_code'],(int)$r['timed_out'],(int)$r['truncated'],$r['elapsed_ms'],time(),$commandId]);
            if($uncertain){$this->systems->write('UPDATE system_terminals SET enabled=0 WHERE id=?',[$id]);$this->systems->write("UPDATE system_runs SET status='outcome_uncertain',error=?,finished_at=? WHERE id=?",['SSH command outcome uncertain. No command was retried; inspect the server before reconnecting.',time(),$t['run_id']]);}
            else $this->systems->write('UPDATE system_terminals SET cwd=?,expires_at=? WHERE id=? AND enabled=1',[$cwd,time()+1800,$id]);
            return ['command'=>$this->systems->query('SELECT * FROM system_commands WHERE id=?',[$commandId])[0],'cwd'=>$cwd,'cwd_verified'=>$cwdVerified,'connected'=>!$uncertain];
        }catch(\Throwable $e){
            if($commandId){$this->systems->write("UPDATE system_commands SET status='outcome_uncertain',stderr=?,finished_at=? WHERE id=? AND status='running'",[Diagnostics::clean($e->getMessage()),time(),$commandId]);$this->systems->write('UPDATE system_terminals SET enabled=0 WHERE id=?',[$id]);$this->systems->write("UPDATE system_runs SET status='outcome_uncertain',error=?,finished_at=? WHERE id=?",[Diagnostics::clean($e->getMessage()),time(),$t['run_id']]);}
            throw $e;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
