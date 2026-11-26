<?php
declare(strict_types=1);
namespace AIcontrol;
use AIcontrolWorkspaceUi as Ui;

final class SystemTerminalView
{
    public static function render(array $system,?array $terminal,string $csrf,bool $blocked): void
    {
        if(!$system['enabled']||!$system['verified_at'])return;
        if(!$terminal){
            if($blocked){
                echo '<p>Reconcile the active guided action before opening an SSH command line.</p>';
                return;
            }
            if(!$system['enrollment_id'])echo '<form method="post" action="/loader.php?view=systems">'.$csrf.'<input type="hidden" name="action" value="system_console_connect"><input type="hidden" name="system_id" value="'.(int)$system['id'].'"><button class="btn btn-outline-primary">Connect and open command line</button></form>';
            return;
        }
        echo '<section id="ssh-console" class="system-terminal mt-3" data-system-terminal="'.$terminal['id'].'" data-terminal-cwd="'.Ui::e($terminal['cwd']??'~').'"><h3>SSH command line</h3><p>Connected to <strong>'.Ui::e($system['username'].'@'.$system['host']).'</strong> · '.Ui::e($system['risk_level']==='legacy'?$system['access']:SystemRisk::level($system['risk_level'])['label']).'</p><p>Each command runs in a fresh shell; the last confirmed directory is retained. Output is limited to 16 KB per stream and commands to 15 seconds. Interactive editors and password prompts require an SSH client.</p>';
        if($system['access']==='inspect')echo '<p>Read-only commands: <code>uptime</code>, <code>df -h</code>, <code>free -m</code>, <code>pwd</code>, <code>ls</code>, <code>whoami</code>, <code>system_info</code>, <code>resources</code>, <code>services</code>, <code>web_diagnostics</code>, <code>network</code>, <code>processes</code>.</p>';
        $disabled=$blocked?' disabled':'';
        $clean=static fn(?string $value):string=>preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/','',$value??'')??'';
        $output='';
        foreach($terminal['commands'] as $c){
            $stdout=$clean($c['stdout']);$stderr=$clean($c['stderr']);
            $streams=implode("\n",array_filter([$stdout,$stderr!==''?"[stderr]\n".$stderr:''],static fn(string $v):bool=>$v!==''));
            $output.='$ '.$clean($c['command'])."\n".($streams!==''?$streams:'No output returned.')."\n[".$c['status'].' · exit '.($c['exit_code']??'unknown').($c['truncated']?' · output truncated':'')."]\n\n";
        }
        echo '<pre class="system-terminal-output" tabindex="0" role="log" aria-label="SSH command output" aria-live="polite" data-terminal-output>'.Ui::e($output?:'SSH connection verified. Enter a command below.').'</pre><p data-terminal-directory>Directory: '.Ui::e($terminal['cwd']??'server home').'</p><form method="post" action="/loader.php?view=systems" class="systems-form" data-terminal-form>'.$csrf.'<input type="hidden" name="action" value="system_console_command"><input type="hidden" name="system_id" value="'.(int)$system['id'].'"><input type="hidden" name="terminal_id" value="'.$terminal['id'].'"><label class="form-label" for="terminal-command">Shell command</label><input class="form-control" id="terminal-command" name="command" autocomplete="off" spellcheck="false" maxlength="4000" required placeholder="'.($system['access']==='inspect'?'uptime':'pwd').'" data-terminal-input' . $disabled . '><button class="btn btn-primary"' . $disabled . '>Run command</button></form><form method="post" action="/loader.php?view=systems" data-terminal-disconnect>'.$csrf.'<input type="hidden" name="action" value="system_console_disconnect"><input type="hidden" name="system_id" value="'.(int)$system['id'].'"><input type="hidden" name="terminal_id" value="'.$terminal['id'].'"><button class="btn btn-outline-danger">Disconnect console / stop command</button></form><p role="status" data-terminal-status>'.($blocked?'Command entry is paused while a guided server action is active or uncertain.':'').'</p><noscript><p>Run command and Disconnect work without JavaScript; output refreshes after each request.</p></noscript></section>';
    }
}
