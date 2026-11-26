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

        echo '<section id="ssh-console" class="system-terminal"'.($terminal?' data-system-terminal="'.Ui::e($terminal['id']).'"':'').'><h3>SSH command line</h3>';
        echo '<p>'.($terminal?'Connected to ':'Verified server ').'<strong>'.Ui::e($connection).'</strong> · '.Ui::e($risk).'</p>';
        echo '<div class="system-terminal-frame"><div class="system-terminal-output" tabindex="0" role="log" aria-label="SSH terminal commands and output" data-terminal-output>';
        foreach($data['transcript']??[] as $entry)self::entry($entry);
        echo '</div>';
        if($terminal)self::prompt($terminal,(int)$system['id'],$csrf,$blocked||$agentActive);
        echo '</div>';

        echo '<p role="status" data-guided-progress></p>';
        if($terminal){
            $entryStatus=$blocked?'Command entry is paused while a guided action owns the connection.':($agentActive?'Command entry is paused while the agent task is active.':'');
            echo '<div class="system-terminal-footer"><p data-terminal-directory>Directory: '.Ui::e($terminal['cwd']??'server home').'</p><form method="post" action="/loader.php?view=systems" data-terminal-disconnect>'.$csrf;
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
        echo '<span class="terminal-source">'.Ui::e(self::SOURCES[$entry['source']]).'</span>';
        echo '<div class="terminal-text">'.Ui::e($entry['text']).'</div></div>';
    }

    private static function prompt(array $terminal,int $system,string $csrf,bool $blocked):void
    {
        $disabled=$blocked?' disabled':'';
        echo '<form method="post" action="/loader.php?view=systems" class="terminal-prompt" data-terminal-form>'.$csrf;
        echo '<input type="hidden" name="action" value="system_console_command"><input type="hidden" name="system_id" value="'.$system.'"><input type="hidden" name="terminal_id" value="'.Ui::e($terminal['id']).'">';
        echo '<span aria-hidden="true">$</span><label class="visually-hidden" for="terminal-command">Shell command</label>';
        echo '<input class="form-control" id="terminal-command" name="command" autocomplete="off" spellcheck="false" maxlength="4000" required data-terminal-input'.$disabled.'>';
        echo '<button class="btn btn-sm terminal-run"'.$disabled.'>Run command</button></form>';
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
