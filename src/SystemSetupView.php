<?php
declare(strict_types=1);
namespace AIcontrol;
use AIcontrolWorkspaceUi as Ui;

final class SystemSetupView
{
    private static function command(string $id,string $label,string $command,string $script): void
    {
        echo '<label class="form-label" for="'.$id.'">'.Ui::e($label).'</label><textarea id="'.$id.'" class="form-control systems-code system-one-command" rows="3" readonly spellcheck="false">'.Ui::e($command).'</textarea><button type="button" class="btn btn-outline-primary mt-2" data-copy-report="'.$id.'">Copy '.Ui::e(strtolower($label)).'</button><span class="ms-2" role="status" data-copy-report-status></span><details class="mt-2"><summary>Review what this command runs</summary><pre class="systems-code system-script-preview">'.Ui::e($script).'</pre></details>';
    }
    public static function render(?array $e,string $csrf,array $values=[]): void
    {
        $risk=$e['risk_level']??'readonly';
        echo '<section class="card mb-3 systems-section" id="server-setup"><div class="card-body"><h2>Connect a Linux server in two commands</h2><p>Run each command in your existing server shell, then paste its complete formatted reply. The commands use Python 3 and sudo or root access.</p><form method="post" action="/loader.php?view=systems" class="systems-form" data-system-risk-form>'.$csrf.'<input type="hidden" name="action" value="system_prepare"><label class="form-label" for="setup-risk">Server risk level</label><select class="form-select" id="setup-risk" name="risk_level" data-system-risk>';
        foreach(SystemRisk::LEVELS as $key=>$level)echo '<option value="'.$key.'"'.($key===$risk?' selected':'').'>'.Ui::e($level['label']).'</option>';
        echo '</select><p data-system-risk-description>'.Ui::e(SystemRisk::level($risk)['description']).'</p><button class="btn btn-primary">Prepare first command</button><noscript><p>Choose the risk level, then prepare the command.</p></noscript></form>';
        if(!$e){echo '<p>The first command creates an account for your chosen risk level and reports the server’s addresses, SSH port and public host fingerprint. It optionally looks up the public IP through ipify; the connection address remains editable for NAT or private networks.</p></div></section>';return;}
        if(isset($e['system'])&&!$e['system']['enabled']){echo '<p>This setup is disconnected. Prepare a new first command to issue a new account and key.</p></div></section>';return;}
        echo '<p><strong>'.Ui::e(SystemRisk::level($risk)['label']).'</strong> · Dedicated account <code>'.Ui::e($e['username']).'</code>. Changing the risk selection prepares a different account and setup.</p>';
        if($risk==='admin')echo '<p class="alert alert-warning">Administrator gives this dedicated account full passwordless sudo. Command 1 creates that grant on the server.</p>';
        echo '<details'.(!$e['system_id']?' open':'').'><summary>1. Create the account and discover the server</summary>';
        self::command('system-discovery-command','First command',$e['command1'],$e['script1']);
        echo '<form method="post" action="/loader.php?view=systems" class="systems-form">'.$csrf.'<input type="hidden" name="action" value="system_import_discovery"><input type="hidden" name="enrollment_id" value="'.$e['id'].'"><label class="form-label" for="system-discovery-reply">First reply — server details</label><textarea class="form-control systems-code" id="system-discovery-reply" name="discovery_reply" rows="8" required placeholder="Paste from BEGIN AICONTROL DISCOVERY V1 through END AICONTROL DISCOVERY V1">'.Ui::e($values['discovery_reply']??($e['discovery_json']?'---BEGIN AICONTROL DISCOVERY V1---'."\n".$e['discovery_json']."\n".'---END AICONTROL DISCOVERY V1---':'')).'</textarea><button class="btn btn-primary">Use discovery reply</button></form></details>';
        if(!$e['system_id']){echo '</div></section>';return;}
        $s=$e['system'];
        echo '<section class="mt-3"><h3>2. Install the SSH key and connect</h3><p>Command 2 checks the same account, machine and host key, installs this setup’s public key, and returns a matching receipt. The private key stays encrypted in AIcontrol.</p>';
        self::command('system-key-command','Second command',$e['command2'],$e['script2']);
        echo '<form method="post" action="/loader.php?view=systems" class="systems-form">'.$csrf.'<input type="hidden" name="action" value="system_connect"><input type="hidden" name="enrollment_id" value="'.$e['id'].'"><input type="hidden" name="system_id" value="'.(int)$s['id'].'"><label class="form-label" for="system-key-reply">Second reply — SSH setup receipt</label><textarea id="system-key-reply" class="form-control systems-code" name="setup_reply" rows="8" required placeholder="Paste from BEGIN AICONTROL RECEIPT V1 through END AICONTROL RECEIPT V1">'.Ui::e($values['setup_reply']??($e['receipt_json']?'---BEGIN AICONTROL RECEIPT V1---'."\n".$e['receipt_json']."\n".'---END AICONTROL RECEIPT V1---':'')).'</textarea><details><summary>Connection address and port</summary><p>These are detected from your server. Correct them here if your network uses a public address or port mapping.</p><label class="form-label" for="setup-host">Connection address</label><input class="form-control" id="setup-host" name="connection_host" value="'.Ui::e($values['connection_host']??$s['host']).'" required><label class="form-label" for="setup-port">Connection SSH port</label><input class="form-control" id="setup-port" name="connection_port" type="number" min="1" max="65535" value="'.Ui::e($values['connection_port']??(string)$s['port']).'" required></details><button class="btn btn-primary">Connect and open command line</button></form></section></div></section>';
    }
}
