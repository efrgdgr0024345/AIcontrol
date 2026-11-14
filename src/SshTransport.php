<?php
declare(strict_types=1);
namespace AIcontrol;

use RuntimeException;

/** No shell on the web host. Only the remote, selected account executes commands. */
final class SshTransport
{
    public static function available(): bool
    {
        return function_exists('proc_open') && is_executable('/usr/bin/ssh') && is_executable('/usr/bin/ssh-keyscan');
    }

    public static function process(array $argv, int $seconds, ?callable $cancel=null): array
    {
        if(!self::available())throw new RuntimeException('SSH runner unavailable: this PHP host needs proc_open, /usr/bin/ssh and /usr/bin/ssh-keyscan.');
        $p=proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($p))throw new RuntimeException('SSH process could not start.');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $output=[1=>'',2=>''];$truncated=false;$timeout=false;$stopped=false;$start=microtime(true);$exit=-1;
        try {
            do {
                foreach([1,2] as $n){$chunk=stream_get_contents($pipes[$n],32768);if($chunk!==false){if(strlen($output[$n])+strlen($chunk)>16384)$truncated=true;$output[$n].=substr($chunk,0,max(0,16384-strlen($output[$n])));}}
                $status=proc_get_status($p);
                if(!$status['running']){$exit=$status['exitcode'];break;}
                $stopped=$cancel&&$cancel();$timeout=microtime(true)-$start>$seconds;
                if($stopped||$timeout){proc_terminate($p);usleep(100000);$status=proc_get_status($p);if($status['running'])proc_terminate($p,9);break;}
                usleep(25000);
            }while(true);
            foreach([1,2] as $n){$chunk=stream_get_contents($pipes[$n],32768);if(is_string($chunk)){if(strlen($output[$n])+strlen($chunk)>16384)$truncated=true;$output[$n].=substr($chunk,0,max(0,16384-strlen($output[$n])));}}
        } finally {fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($p);if($exit<0&&$closed>=0)$exit=$closed;}
        return ['stdout'=>$output[1],'stderr'=>$output[2],'exit_code'=>$exit,'timed_out'=>$timeout,'cancelled'=>$stopped,'truncated'=>$truncated,'elapsed_ms'=>(int)round((microtime(true)-$start)*1000)];
    }

    public function probe(array $system): string
    {
        $r=self::process(['/usr/bin/ssh-keyscan','-T','5','-t','ed25519','-p',(string)$system['port'],$system['host']],8);
        foreach(explode("\n",$r['stdout']) as $line){$parts=preg_split('/\s+/',trim($line));if(count($parts)!==3||$parts[1]!=='ssh-ed25519')continue;$raw=base64_decode($parts[2],true);if($raw===false)continue;$fingerprint='SHA256:'.rtrim(base64_encode(hash('sha256',$raw,true)),'=');if(hash_equals($system['fingerprint'],$fingerprint))return $parts[1].' '.$parts[2];}
        throw new RuntimeException('Server identity could not be verified. Check the address, SSH port and fingerprint printed on your server; a changed key is never accepted automatically.');
    }

    public function execute(array $system,string $private,string $command,string $directory,callable $cancel): array
    {
        if(empty($system['host_key']))throw new RuntimeException('Verify the SSH host key before executing work.');
        $key=tempnam($directory,'.ssh-key-');$hosts=tempnam($directory,'.ssh-host-');
        try {
            chmod($key,0600);chmod($hosts,0600);file_put_contents($key,$private);
            file_put_contents($hosts,'['.$system['host'].']:'.$system['port'].' '.$system['host_key']."\n".$system['host'].' '.$system['host_key']."\n");
            $args=['/usr/bin/ssh','-F','/dev/null','-i',$key,'-p',(string)$system['port'],'-l',$system['username']];
            foreach(['HostKeyAlgorithms=ssh-ed25519','BatchMode=yes','IdentitiesOnly=yes','StrictHostKeyChecking=yes','UserKnownHostsFile='.$hosts,'GlobalKnownHostsFile=/dev/null','ConnectTimeout=8','PasswordAuthentication=no','KbdInteractiveAuthentication=no','ForwardAgent=no','ClearAllForwardings=yes','LogLevel=ERROR','ServerAliveInterval=5','ServerAliveCountMax=1'] as $option){$args[]='-o';$args[]=$option;}
            $args[]='--';$args[]=$system['host'];$args[]='LC_ALL=C timeout -k 2 15 sh -c '.escapeshellarg($command);
            return self::process($args,20,$cancel);
        } finally {if(is_string($key))@unlink($key);if(is_string($hosts))@unlink($hosts);}
    }
}
