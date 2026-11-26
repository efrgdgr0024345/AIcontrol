<?php
declare(strict_types=1);
namespace AIcontrol;
use AIcontrolWorkspaceUi as Ui;

final class SystemTerminalView
{
    public static function render(array $system,?array $terminal,string $csrf,bool $blocked,string $guided='',?array $run=null,?array $activity=null): void
    {
        if(!$system['enabled']||!$system['verified_at'])return;
        echo '<section id="ssh-console" class="system-terminal"'.($terminal?' data-system-terminal="'.Ui::e($terminal['id']).'"':'').'><h3>SSH command line</h3>';
        echo '<p>'.($terminal?'Connected to ':'Verified server ').'<strong>'.Ui::e($system['username'].'@'.$system['host']).'</strong> · '.Ui::e($system['risk_level']==='legacy'?$system['access']:SystemRisk::level($system['risk_level'])['label']).'</p>';
        if($guided!==''){
            echo '<h4>Guided action transcript</h4><pre class="system-terminal-output" tabindex="0" role="log" aria-label="Guided action commands and output" data-guided-output>'.Ui::e($guided).'</pre>';
        }
        echo '<p role="status" data-guided-progress></p>';
        $agentActive=$activity&&in_array($activity['status'],['ready','working'],true);
        if($activity&&$run)self::agentActivity($run,$activity,$csrf,$blocked);
        if(!$terminal){
            if($blocked)echo '<p>Reconcile the active guided action before opening an SSH command line.</p>';
            else echo '<form method="post" action="/loader.php?view=systems">'.$csrf.'<input type="hidden" name="action" value="system_console_connect"><input type="hidden" name="system_id" value="'.(int)$system['id'].'"><button class="btn btn-primary">Open SSH command line</button></form>';
            echo '</section>';
            return;
        }
        echo '<p>Each manual command runs in a fresh shell. The last confirmed directory is retained. Manual output is limited to 16 KB per stream and commands to 15 seconds.</p>';
        if($system['access']==='inspect')echo '<p>Read-only commands: <code>uptime</code>, <code>df -h</code>, <code>free -m</code>, <code>pwd</code>, <code>ls</code>, <code>whoami</code>, <code>system_info</code>, <code>resources</code>, <code>services</code>, <code>web_diagnostics</code>, <code>network</code>, <code>processes</code>.</p>';
        $disabled=$blocked||$agentActive?' disabled':'';
        $clean=static fn(?string $value):string=>preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/','',$value??'')??'';
        $output='';
        foreach($terminal['commands'] as $c){
            $stdout=$clean($c['stdout']);$stderr=$clean($c['stderr']);
            $streams=implode("\n",array_filter([$stdout,$stderr!==''?"[stderr]\n".$stderr:''],static fn(string $v):bool=>$v!==''));
            $output.='$ '.$clean($c['command'])."\n".($streams!==''?$streams:'No output returned.')."\n[".$c['status'].' · exit '.($c['exit_code']??'unknown').($c['truncated']?' · output truncated':'')."]\n\n";
        }
        $entryStatus=$blocked?'Command entry is paused while a guided server action is active or uncertain.':($agentActive?'Command entry is paused while the agent task is active.':'');
        echo '<pre class="system-terminal-output" tabindex="0" role="log" aria-label="SSH command output" aria-live="polite" data-terminal-output'.($guided!==''&&$blocked?' hidden':'').'>'.Ui::e($output?:'SSH connection verified. Enter a command below.').'</pre><p data-terminal-directory>Directory: '.Ui::e($terminal['cwd']??'server home').'</p><form method="post" action="/loader.php?view=systems" class="systems-form" data-terminal-form>'.$csrf.'<input type="hidden" name="action" value="system_console_command"><input type="hidden" name="system_id" value="'.(int)$system['id'].'"><input type="hidden" name="terminal_id" value="'.$terminal['id'].'"><label class="form-label" for="terminal-command">Shell command</label><input class="form-control" id="terminal-command" name="command" autocomplete="off" spellcheck="false" maxlength="4000" required placeholder="'.($system['access']==='inspect'?'uptime':'pwd').'" data-terminal-input' . $disabled . '><button class="btn btn-primary"' . $disabled . '>Run command</button></form><form method="post" action="/loader.php?view=systems" data-terminal-disconnect>'.$csrf.'<input type="hidden" name="action" value="system_console_disconnect"><input type="hidden" name="system_id" value="'.(int)$system['id'].'"><input type="hidden" name="terminal_id" value="'.$terminal['id'].'"><button class="btn btn-outline-danger">Disconnect console / stop command</button></form><p role="status" data-terminal-status>'.Ui::e($entryStatus).'</p><noscript><p>Run command and Disconnect work without JavaScript; output refreshes after each request.</p></noscript></section>';
    }

    private static function agentActivity(array $run,array $activity,string $csrf,bool $blocked): void
    {
        $status=$activity['status'];
        $id=(int)$run['id'];
        echo '<section class="system-agent-activity" aria-labelledby="agent-activity-title" data-system-run="'.$id.'" data-system-status="'.Ui::e($status).'"><h4 id="agent-activity-title">Agent activity</h4><p>'.Ui::e($run['task']).'</p>';
        echo '<p role="status" data-agent-status>'.Ui::e($status).' · '.$activity['rounds'].' / 8 decisions</p>';
        echo '<div class="system-agent-updates"><strong>Agent updates</strong><ol data-agent-updates>';
        foreach($activity['updates'] as $note)echo '<li>'.Ui::e($note).'</li>';
        echo '</ol><p data-agent-message role="status">'.($status==='working'?'A step is in progress. Check its outcome before continuing.':'').'</p></div>';
        echo '<pre class="system-terminal-output system-agent-commands" tabindex="0" role="log" aria-label="Agent commands and VPS output" data-agent-output>'.Ui::e($activity['output']?:'Waiting for the first command.').'</pre>';
        if($activity['error']!=='')echo '<p role="alert">'.Ui::e($activity['error']).'</p>';
        if($activity['result']!=='')echo '<p class="system-agent-result">'.Ui::e($activity['result']).'</p>';
        if($status==='limit_reached')echo '<p>Eight decisions used. Review the transcript for confirmed results.</p>';
        if(in_array($status,['ready','working'],true)){
            foreach(['system_step'=>'Run next agent step','system_stop'=>'Stop system task'] as $action=>$label){
                $stop=$action==='system_stop';
                echo '<form method="post" action="/loader.php?view=systems#ssh-console" data-system-action="'.$action.'">'.$csrf.'<input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="system_id" value="'.(int)$run['system_id'].'"><input type="hidden" name="run_id" value="'.$id.'"><button class="btn '.($stop?'btn-outline-danger':'btn-primary').'"'.(!$stop&&($status!=='ready'||$blocked)?' disabled':'').'>'.$label.'</button></form>';
            }
            if($status==='ready'&&!$blocked)echo '<button type="button" class="btn btn-outline-secondary system-agent-auto" data-system-auto>Run automatic steps</button>';
        }
        echo '</section>';
    }
}
