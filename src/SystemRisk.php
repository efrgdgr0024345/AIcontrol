<?php
declare(strict_types=1);
namespace AIcontrol;

final class SystemRisk
{
    public const LEVELS=[
        'readonly'=>['label'=>'Low — Read-only diagnostics','prefix'=>'aicontrol_ro_','access'=>'inspect','description'=>'Dedicated account, no sudo, and an SSH key restricted on the server to diagnostic commands.'],
        'account'=>['label'=>'Medium — Account maintenance','prefix'=>'aicontrol_ops_','access'=>'maintain','description'=>'Dedicated account with a writable home and a shell. No sudo; changes are limited by Linux account permissions.'],
        'admin'=>['label'=>'High — Administrator','prefix'=>'aicontrol_adm_','access'=>'maintain','description'=>'Dedicated account with full passwordless sudo. Commands and agents can administer the whole server.'],
    ];
    public static function level(string $risk): array {return self::LEVELS[$risk]??throw new \RuntimeException('Choose a valid risk level.');}
    public static function quote(string $text): string {return "'".str_replace("'","'\\''",$text)."'";}
    public static function commands(): array
    {
        return SystemsAgent::INSPECT+['console_pwd'=>'pwd','console_ls'=>'ls -la','console_whoami'=>'id -un','console_df'=>'df -h','console_free'=>'free -m','console_uptime'=>'uptime','console_uname'=>'uname -a'];
    }
}
