<?php
declare(strict_types=1);
namespace AIcontrol;

/** Shared-host admission lock. Never covers Stop or status polling. */
final class ProviderGate
{
    public static function run(string $directory, callable $work): mixed
    {
        if($directory==='')return $work(); // Standalone adapter fixtures without a workspace.
        $path=$directory.'/provider-request.lock';
        if(is_link($path))throw new \RuntimeException('Provider admission is unavailable.');
        $handle=fopen($path,'c');
        if(!$handle)throw new \RuntimeException('Provider admission is unavailable.');
        chmod($path,0600);
        try {
            if(!flock($handle,LOCK_EX|LOCK_NB))throw new \RuntimeException('Another AI request is finishing. Wait before asking for suggestions or starting research.');
            return $work();
        } finally {flock($handle,LOCK_UN);fclose($handle);}
    }
}
