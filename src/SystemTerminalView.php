<?php
declare(strict_types=1);
namespace AIcontrol;

use AIcontrolWorkspaceUi as Ui;

final class SystemTerminalView
{
    private const SOURCES=['ssh'=>'SSH','manual'=>'You','agent'=>'Agent','guided'=>'Guided action'];
    public static function render(array $system,array $data,string $csrf,array $projects):void
    {
        if(!$system['enabled']||!$system['verified_at'])return;
        $terminal=$data['terminal']??null;
        $run=$data['run']??null;
        $activity=$data['agent_activity']??null;
        $blocked=$data['ssh_blocked']??false;
        $agentActive=$activity&&in_array($activity['status'],['ready','working'],true);
        $connection=$system['username'].'@'.$system['host'];
        $risk=$system['risk_level']==='legacy'?$system['access']:SystemRisk::level($system['risk_level'])['label'];

        echo '<section id="ssh-console" class="system-terminal"'.($terminal?' data-system-terminal="'.Ui::e($terminal['id']).'"':'').'>';
        echo '<div class="system-terminal-frame"><div class="terminal-bar"><h3>SSH terminal</h3><span class="terminal-host">'.Ui::e($connection).'</span>';
        echo '<span class="terminal-connection" data-terminal-connection>'.($terminal?'Connected':'Verified').' · '.Ui::e($risk).'</span></div>';
        echo '<div class="terminal-screen" data-terminal-screen><div class="system-terminal-output" tabindex="0" role="log" aria-label="SSH terminal commands and output" data-terminal-output>';
        foreach($data['transcript']??[] as $entry)self::entry($entry);
        echo '</div>';
        if($terminal)self::prompt($terminal,$system,$csrf,$blocked||$agentActive);
        echo '</div></div>';

        echo '<p role="status" data-guided-progress></p>';
        if($terminal){
            $entryStatus=$blocked?'Command entry is paused while a guided action owns the connection.':($agentActive?'Command entry is paused while the agent task is active.':'');
            echo '<div class="system-terminal-footer"><form method="post" action="/loader.php?view=systems" data-terminal-disconnect>'.$csrf;
            echo '<input type="hidden" name="action" value="system_console_disconnect"><input type="hidden" name="system_id" value="'.(int)$system['id'].'"><input type="hidden" name="terminal_id" value="'.Ui::e($terminal['id']).'">';
            echo '<button class="btn btn-outline-danger btn-sm">Disconnect console / stop command</button></form></div>';
            echo '<p role="status" data-terminal-status>'.Ui::e($entryStatus).'</p>';
        }elseif($blocked){
            echo '<p>Reconcile the active guided action before opening an SSH command line.</p>';
        }else{
            echo '<form method="post" action="/loader.php?view=systems" data-terminal-connect>'.$csrf.'<input type="hidden" name="action" value="system_console_connect"><input type="hidden" name="system_id" value="'.(int)$system['id'].'"><button class="btn btn-primary">Open SSH command line</button></form>';
        }
        if($system['access']==='inspect')echo '<details><summary>Read-only commands</summary><p><code>uptime</code>, <code>df -h</code>, <code>free -m</code>, <code>pwd</code>, <code>ls</code>, <code>whoami</code>, <code>system_info</code>, <code>resources</code>, <code>services</code>, <code>web_diagnostics</code>, <code>network</code>, <code>processes</code>.</p></details>';
        if($activity&&$run)self::agentActivity($run,$activity,$csrf,$blocked);
        self::direction((int)$system['id'],$csrf,$projects,$blocked||$agentActive);
        echo '</section>';
    }

    private static function entry(array $entry):void
    {
        echo '<div class="terminal-entry" data-terminal-entry="'.Ui::e($entry['key']).'" data-terminal-time="'.Ui::e((string)$entry['at']).'" data-source="'.Ui::e($entry['source']).'">';
        $source=self::SOURCES[$entry['source']];
        if(isset($entry['command'])){
            echo '<div class="terminal-line"><span class="terminal-text"><span class="terminal-symbol" aria-hidden="true">$</span> <span data-terminal-command>'.Ui::e($entry['command']).'</span></span>';
            echo '<span class="terminal-source'.($entry['failed']?' terminal-exit-error':'').'">'.Ui::e($source.' · '.$entry['status']).'</span></div>';
            if($entry['output']!=='')echo '<div class="terminal-text terminal-result">'.Ui::e($entry['output']).'</div>';
            if($entry['hint']!=='')echo '<p class="terminal-hint">'.Ui::e($entry['hint']).'</p>';
        }else{
            echo '<div class="terminal-line terminal-message"><span class="terminal-text">'.Ui::e($entry['text']).'</span><span class="terminal-source">'.Ui::e($source).'</span></div>';
        }
        echo '</div>';
    }

    private static function prompt(array $terminal,array $system,string $csrf,bool $blocked):void
    {
        $disabled=$blocked?' disabled':'';
        $home=$system['home_dir']??'';
        $cwd=$terminal['cwd']??'';
        $short=$cwd===''||$cwd===$home?'~':($home!==''&&str_starts_with($cwd,$home.'/')?'~'.substr($cwd,strlen($home)):$cwd);
        $description='Working directory: '.($cwd===''?'server home':$cwd);
        echo '<form method="post" action="/loader.php?view=systems" class="terminal-prompt" data-terminal-form data-terminal-home="'.Ui::e($home).'">'.$csrf;
        echo '<input type="hidden" name="action" value="system_console_command"><input type="hidden" name="system_id" value="'.(int)$system['id'].'"><input type="hidden" name="terminal_id" value="'.Ui::e($terminal['id']).'">';
        echo '<span id="terminal-cwd" class="terminal-location" title="'.Ui::e($description).'" data-terminal-directory>'.Ui::e($short).'</span>';
        echo '<span class="terminal-symbol" aria-hidden="true">$</span><label class="visually-hidden" for="terminal-command">Shell command</label>';
        echo '<input id="terminal-command" name="command" aria-describedby="terminal-cwd" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="4000" required data-terminal-input'.$disabled.'>';
        echo '<button class="terminal-run" aria-label="Run command" title="Run command (Enter)"'.$disabled.'>Enter ↵</button></form>';
    }

    private static function agentActivity(array $run,array $activity,string $csrf,bool $blocked):void
    {
        $status=$activity['status'];
        $id=(int)$run['id'];
        echo '<section class="system-agent-activity" aria-labelledby="agent-activity-title" data-system-run="'.$id.'" data-system-status="'.Ui::e($status).'"><h4 id="agent-activity-title">Agent conversation</h4><p><strong>You</strong> '.Ui::e($run['task']).'</p>';
        echo '<p role="status" data-agent-status>'.Ui::e($status).' · '.$activity['rounds'].' / 8 decisions</p>';
        echo '<div class="system-agent-updates"><strong>Agent updates</strong><ol aria-label="Plan and follow-up checks" data-agent-updates>';
        foreach($activity['updates'] as $note)echo '<li>'.Ui::e($note).'</li>';
        echo '</ol><p data-agent-message role="status">'.($status==='working'?'A step is in progress. Check its outcome before continuing.':'').'</p></div>';
        if($activity['error']!=='')echo '<p role="alert">'.Ui::e($activity['error']).'</p>';
        if($activity['result']!=='')echo '<p class="system-agent-result"><strong>Agent</strong> <span>'.Ui::e($activity['result']).'</span></p>';
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

    private static function direction(int $system,string $csrf,array $projects,bool $blocked):void
    {
        $disabled=$blocked?' disabled':'';
        echo '<form method="post" action="/loader.php?view=systems" class="systems-form terminal-agent-form" data-terminal-agent-form>'.$csrf;
        echo '<input type="hidden" name="action" value="system_start"><input type="hidden" name="system_id" value="'.$system.'">';
        echo '<label class="form-label" for="system-task">Task for the Linux agent</label><textarea class="form-control" id="system-task" name="task" rows="2" maxlength="4000" placeholder="Check why VPN traffic is not getting through." required'.$disabled.'></textarea>';
        if($projects){
            echo '<details><summary>Related task project</summary><label class="form-label" for="system-project">Related task project (optional)</label><select class="form-select" id="system-project" name="project_id"><option value="">System task only</option>';
            foreach($projects as $project)echo '<option value="'.(int)$project['id'].'">'.Ui::e($project['name']).'</option>';
            echo '</select></details>';
        }
        echo '<div class="terminal-agent-buttons"><button name="agent_submit" value="auto" class="btn btn-primary"'.$disabled.'>Send to agent</button>';
        echo '<button name="agent_submit" value="step" class="btn btn-outline-secondary system-agent-auto"'.$disabled.'>Start system task</button></div>';
        echo '<p role="status" data-agent-submit-status></p></form>';
    }
}
