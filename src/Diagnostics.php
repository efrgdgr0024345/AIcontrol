<?php
declare(strict_types=1);
namespace AIcontrol;

/** Private, bounded diagnostic journal. Never records request bodies or credentials. */
final class Diagnostics
{
    private static ?string $request = null;
    public static function install(): void
    {
        set_error_handler(static function(int $severity,string $message,string $file,int $line): bool {
            if (error_reporting() & $severity) self::record('php.warning',['severity'=>$severity,'message'=>$message,'file'=>basename($file),'line'=>$line]);
            return false;
        });
        register_shutdown_function(static function(): void {
            $last=error_get_last();
            if ($last && in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)) self::record('php.fatal',['severity'=>$last['type'],'message'=>$last['message'],'file'=>basename($last['file']),'line'=>$last['line']]);
        });
    }
    public static function clean(string $text): string
    {
        foreach (['AICONTROL_OPENAI_API_KEY','openai_api_key'] as $name) {
            $value=getenv($name); if (is_string($value) && $value!=='') $text=str_replace([$value,substr(json_encode($value,JSON_INVALID_UTF8_SUBSTITUTE),1,-1)],'[redacted]',$text);
        }
        $directory=getenv('AICONTROL_PRIVATE_DIR');
        try {
            if (is_string($directory) && class_exists(Vault::class)) {
                $vault=new Vault($directory);
                foreach ($vault->entries() as $name=>$entry) {
                    $value=$vault->value($name); if ($value!=='') $text=str_replace([$value,substr(json_encode($value,JSON_INVALID_UTF8_SUBSTITUTE),1,-1)],'[redacted]',$text);
                }
            }
        } catch (\Throwable) { return '[diagnostic text withheld: vault redaction unavailable]'; }
        $text=preg_replace('~Bearer\s+[^\s"<>]+|sk-[A-Za-z0-9_-]+~i','[redacted]',$text)??'';
        if (is_string($directory) && $directory!=='') $text=str_replace($directory,'[private]',$text);
        return $text;
    }
    public static function record(string $event,array $context=[],?\Throwable $error=null): void
    {
        try {
            $directory=getenv('AICONTROL_PRIVATE_DIR'); if (!is_string($directory)||$directory==='') return;
            $path=$directory.'/diagnostics.jsonl'; if (is_link($path)) return;
            self::$request??=bin2hex(random_bytes(8));
            if ($error) {
                $context['exception']=['class'=>get_class($error),'message'=>$error->getMessage(),'code'=>$error->getCode(),
                    'file'=>basename($error->getFile()),'line'=>$error->getLine(),
                    'trace'=>array_map(static fn(array $f):array=>array_intersect_key($f,array_flip(['function','class','type','line'])),array_slice($error->getTrace(),0,15))];
            }
            $row=['utc'=>gmdate('c'),'request_id'=>self::$request,'event'=>$event,'context'=>$context];
            $line=self::clean(json_encode($row,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
            if (strlen($line)>16000) $line=json_encode(['utc'=>$row['utc'],'request_id'=>self::$request,'event'=>$event,'truncated'=>true,'detail'=>substr($line,0,12000)],JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
            $line.="\n";
            $h=@fopen($path,'c+'); if (!$h) return;
            @chmod($path,0600);
            try { if (!flock($h,LOCK_EX)) return;
                $size=fstat($h)['size'];
                if ($size>1048576) { fseek($h,-524288,SEEK_END);$tail=stream_get_contents($h);$tail=substr($tail,strpos($tail,"\n")+1);rewind($h);ftruncate($h,0);fwrite($h,$tail); }
                fseek($h,0,SEEK_END);fwrite($h,$line);flock($h,LOCK_UN);
            } finally { fclose($h); }
        } catch (\Throwable) { /* Diagnostics must never interrupt the operation. */ }
    }
    public static function report(): string
    {
        $directory=getenv('AICONTROL_PRIVATE_DIR');$path=$directory.'/diagnostics.jsonl';
        $header=['format'=>'AIcontrol diagnostics v1','generated_utc'=>gmdate('c'),'php'=>PHP_VERSION,'model'=>getenv('AICONTROL_OPENAI_MODEL')?:'gpt-4.1-mini',
            'extensions'=>array_fill_keys(array_intersect(['curl','pdo_sqlite','mbstring','sodium'],get_loaded_extensions()),true),
            'provider_connected'=>AgentRunner::configured(),'log_writable'=>is_writable((string)$directory),'source_fingerprint'=>hash_file('sha256',__DIR__.'/Investigation.php')];
        $h=!is_link($path)?@fopen($path,'r'):false;$log='';
        if ($h) { try { if(flock($h,LOCK_SH)) {$log=stream_get_contents($h);flock($h,LOCK_UN);} } finally {fclose($h);} }
        return json_encode($header,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n\n".self::cleanReport($log);
    }
    private static function cleanReport(string $log): string
    {
        return implode("\n",array_map([self::class,'clean'],explode("\n",$log))) ?: 'No diagnostic events recorded yet.';
    }
}
