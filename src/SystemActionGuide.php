<?php
declare(strict_types=1);
namespace AIcontrol;
use AIcontrolWorkspaceUi as Ui;

final class SystemActionGuide
{
    private const OUTLINES=[
        'inspect'=>[
            'Read Linux and SSH inventory'=>'Identify the distribution, resources and listening ports so later actions can use the right tools and preserve access.',
            'Check existing setup'=>'Look for the WireGuard interface, firewall rules, this account’s Codex executable and the automatic-update policy. A local interface check cannot confirm a VPN client handshake.',
        ],
        'vpn'=>[
            'Check for conflicts'=>'Compare the proposed subnet with existing routes and check the selected UDP port before making a change.',
            'Install WireGuard and write its configuration'=>'Create the server identity on the VPS and register the client public key. Each peer keeps its own private key.',
            'Enable the interface'=>'Start wg-quick and read the server public key for the client configuration. Test the client handshake separately.',
        ],
        'firewall'=>[
            'Inspect existing rules and listening ports'=>'Identify services that need to remain reachable, including the SSH port used by the current connection.',
            'Apply the reviewed rules and arm rollback'=>'Allow the reviewed services before enabling UFW. A fresh SSH connection must succeed before the change becomes permanent.',
        ],
        'update'=>[
            'Refresh package lists'=>'Ask APT for the current list of packages from the configured signed repositories.',
            'Upgrade and audit'=>'Install available upgrades while preserving local configuration and packages. Check for unfinished package work and a pending reboot.',
        ],
        'codex'=>[
            'Check architecture and verify the archive'=>'Select the official build for this account’s CPU and verify its checksum before extracting the executable.',
            'Check the version and install for the Linux account'=>'Use the account’s local bin directory. Codex sign-in is a separate step.',
        ],
        'security_updates'=>[
            'Install unattended-upgrades'=>'Use the distribution’s package for scheduled security updates.',
            'Write the policy and check the timers'=>'Enable daily package checks and upgrades. Keep automatic reboot disabled.',
        ],
        'health'=>[
            'Collect resource and service observations'=>'Read memory, disk use, uptime and available service status to help diagnose capacity or failures.',
        ],
        'backup'=>[
            'Look for local backup evidence'=>'Check common tools and available metadata. Verify recoverability separately with an actual restore test.',
        ],
    ];

    public static function render(string $recipe,?array $operation):void
    {
        echo '<details class="system-action-guide"><summary aria-label="Learn more about '.Ui::e(SystemPlaybooks::RECIPES[$recipe]['title']).'">Learn more</summary><ol>';
        foreach(self::OUTLINES[$recipe] as $step=>$purpose){
            echo '<li><strong>'.Ui::e($step).'</strong><p>'.Ui::e($purpose).'</p></li>';
        }
        echo '</ol>';
        if($operation)self::execution($operation);
        echo '</details>';
    }

    public static function execution(array $operation,bool $showStatus=true):void
    {
        $data=$operation['data'];
        $steps=$data['result']['steps']??$data['inventory']['setup_steps']??[];
        if(!is_array($steps))$steps=[];
        foreach($data['results']??[] as $entry){
            $command=SystemsAgent::INSPECT[$entry['tool']??'']??null;
            if($command)$steps[]=['command'=>[$command],'exit_code'=>$entry['result']['exit_code']??null];
        }
        if($showStatus)echo '<p><strong>Recorded run</strong> · '.Ui::e($operation['status']).' · '.Ui::e(Ui::time(gmdate('c',(int)$operation['updated_at']))).'</p>';
        if(!$steps){
            echo '<p>This run has no recorded command lines.</p>';
            return;
        }
        echo '<ol class="system-command-steps">';
        foreach(array_slice($steps,0,30) as $step){
            $argv=$step['command']??null;
            if(!is_array($argv)||!$argv||count($argv)>40||array_filter($argv,static fn($value)=>!is_string($value)))continue;
            $argv=array_values($argv);
            $command=SystemNotebook::mask(mb_strcut(implode(' ',$argv),0,2000,'UTF-8'));
            echo '<li><code>'.Ui::e($command).'</code><p>'.Ui::e(self::purpose($argv)).'</p><small>Exit '.Ui::e(is_int($step['exit_code']??null)?(string)$step['exit_code']:'unknown').'</small></li>';
        }
        echo '</ol>';
    }

    private static function purpose(array $argv):string
    {
        return match($argv[0]){
            'apt-get'=>in_array('update',$argv,true)?'Refresh APT’s package lists before choosing package versions.':
                (in_array('upgrade',$argv,true)?'Apply available upgrades while preserving packages and local configuration.':'Install the package needed for this reviewed action.'),
            'ufw'=>'Apply the reviewed firewall rule or set the firewall policy while retaining the selected access.',
            'systemctl'=>match($argv[1]??''){
                'is-active'=>'Check that the service or timer is active.',
                'is-enabled'=>'Check whether the timer is scheduled to start again after a reboot.',
                default=>'Enable and start the selected service or timer.',
            },
            'ip'=>'Read the interface name, type and flags to see whether the expected WireGuard interface is up.',
            'wg'=>'Read the WireGuard interface’s public key for the client configuration.',
            'dpkg'=>'Check for partially configured packages after the upgrade.',
            default=>in_array($argv[0],SystemsAgent::INSPECT,true)?'Collect read-only diagnostic observations from the selected server.':'Run a step recorded by the reviewed action. The exit status shows the command result.',
        };
    }
}
