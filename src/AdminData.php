<?php
declare(strict_types=1);
namespace AIcontrol;
use PDO;
use RuntimeException;
require_once __DIR__.'/ProviderGate.php';

/** Allowlisted data management. No raw SQL execution, file path inputs or secret exports. */
final class AdminData
{
    private const CONFIRM=[
        'delete_research_project'=>'DELETE RESEARCH PROJECT','reset_research_project'=>'CLEAR RESEARCH HISTORY',
        'delete_research_run'=>'DELETE INVESTIGATION','clear_research_chat'=>'CLEAR CONVERSATION',
        'delete_project'=>'DELETE TASK PROJECT','reset_project'=>'CLEAR TASKS AND RUNS',
        'delete_task'=>'DELETE TASK','reset_task'=>'CLEAR TASK HISTORY',
        'clear_activity'=>'CLEAR TASK ACTIVITY','clear_diagnostics'=>'CLEAR DIAGNOSTICS',
        'clear_suggestions'=>'CLEAR SUGGESTION HISTORY','reset_workspace'=>'RESET ALL PROJECT DATA'
    ];
    public function __construct(private string $directory) {if(is_link($directory.'/research.sqlite'))throw new RuntimeException('A managed database is unavailable.');new Research($directory);}
    public static function token(string $operation,int $id=0): string
    {
        if(!isset(self::CONFIRM[$operation]))throw new RuntimeException('Unknown data operation.');
        return self::CONFIRM[$operation].(in_array($operation,['clear_activity','clear_diagnostics','clear_suggestions','reset_workspace'],true)?'':' '.$id);
    }
    private function connect(string $file): PDO
    {
        $path=$this->directory.'/'.$file;
        if(is_link($path)||!is_file($path))throw new RuntimeException('A managed database is unavailable.');
        $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');return $db;
    }
    private static function rows(PDO $db,string $sql,array $params=[]): array {$q=$db->prepare($sql);$q->execute($params);return $q->fetchAll();}
    private static function exec(PDO $db,string $sql,array $params=[]): void {$q=$db->prepare($sql);$q->execute($params);}
    private static function requireRow(PDO $db,string $sql,int $id): void {if(!self::rows($db,$sql,[$id]))throw new RuntimeException('The selected record no longer exists. Refresh Admin.');}
    private static function deleteRuns(PDO $db,string $condition,array $params=[]): void
    {
        // Delete dependencies before parents. Self-parent node links disappear together.
        $runIds='SELECT id FROM research.research_runs'.($condition!==''?' WHERE '.$condition:'');
        foreach(['research_edges','research_claim_versions','research_path_rankings','research_passages','research_requests','research_sources','research_nodes'] as $table)self::exec($db,'DELETE FROM research.'.$table.($condition!==''?' WHERE run_id IN ('.$runIds.')':''),$params);
        self::exec($db,'DELETE FROM research.research_runs'.($condition!==''?' WHERE '.$condition:''),$params);
    }
    private static function taskHistory(PDO $db,string $condition,array $params): void
    {
        self::exec($db,'DELETE FROM main.agent_runs WHERE task_id IN (SELECT id FROM main.tasks WHERE '.$condition.')',$params);
        self::exec($db,'DELETE FROM main.events WHERE task_id IN (SELECT id FROM main.tasks WHERE '.$condition.')',$params);
    }
    public function change(string $operation,int $id,string $confirmation): void
    {
        $expected=self::token($operation,$id);
        if(!hash_equals($expected,trim($confirmation)))throw new RuntimeException('Confirmation did not match. No data was changed.');
        if(!in_array($operation,['clear_activity','clear_diagnostics','clear_suggestions','reset_workspace'],true)&&$id<1)throw new RuntimeException('Choose a valid record.');
        ProviderGate::run($this->directory,function()use($operation,$id):void {
            if($operation==='clear_diagnostics'){$this->clearLog();}
            elseif($operation==='clear_suggestions'){$this->clearSuggestions();}
            else {
                $db=$this->connect('panel.sqlite');$query=$db->prepare('ATTACH DATABASE ? AS research');$query->execute([$this->directory.'/research.sqlite']);$db->exec('BEGIN IMMEDIATE');
                try {
                    if(in_array($operation,['delete_research_project','reset_research_project','clear_research_chat'],true)){
                        self::requireRow($db,'SELECT id FROM research.research_projects WHERE id=?',$id);
                        if($operation!=='clear_research_chat')self::deleteRuns($db,'project_id=?',[$id]);
                        self::exec($db,'DELETE FROM research.research_messages WHERE project_id=?',[$id]);
                        if($operation==='delete_research_project')self::exec($db,'DELETE FROM research.research_projects WHERE id=?',[$id]);
                    } elseif($operation==='delete_research_run'){
                        self::requireRow($db,'SELECT id FROM research.research_runs WHERE id=?',$id);self::deleteRuns($db,'id=?',[$id]);
                    } elseif(in_array($operation,['delete_project','reset_project'],true)){
                        self::requireRow($db,'SELECT id FROM main.projects WHERE id=?',$id);self::taskHistory($db,'project_id=?',[$id]);
                        self::exec($db,'DELETE FROM main.events WHERE project_id=?',[$id]);self::exec($db,'DELETE FROM main.tasks WHERE project_id=?',[$id]);
                        if($operation==='delete_project')self::exec($db,'DELETE FROM main.projects WHERE id=?',[$id]);
                    } elseif(in_array($operation,['delete_task','reset_task'],true)){
                        self::requireRow($db,'SELECT id FROM main.tasks WHERE id=?',$id);self::taskHistory($db,'id=?',[$id]);
                        if($operation==='delete_task')self::exec($db,'DELETE FROM main.tasks WHERE id=?',[$id]);
                        else self::exec($db,"UPDATE main.tasks SET status='draft',evidence='' WHERE id=?",[$id]);
                    } elseif($operation==='clear_activity'){$db->exec('DELETE FROM main.events');}
                    elseif($operation==='reset_workspace'){
                        self::deleteRuns($db,'');$db->exec('DELETE FROM research.research_messages; DELETE FROM research.research_projects; DELETE FROM main.agent_runs; DELETE FROM main.events; DELETE FROM main.tasks; DELETE FROM main.projects');
                    }
                    // Check both attached databases before committing any destructive change.
                    if(self::rows($db,'PRAGMA main.foreign_key_check')||self::rows($db,'PRAGMA research.foreign_key_check'))throw new RuntimeException('Database relationships require repair; no data was changed.');
                    $db->exec('COMMIT');
                } catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
            }
            Diagnostics::record('admin.data.changed',['operation'=>$operation,'record_id'=>$id]);
        });
    }
    private function clearLog(): void
    {
        $path=$this->directory.'/diagnostics.jsonl';if(is_link($path))throw new RuntimeException('Diagnostic log is unavailable.');
        $handle=fopen($path,'c+');if(!$handle)throw new RuntimeException('Diagnostic log is unavailable.');
        try {chmod($path,0600);if(!flock($handle,LOCK_EX)||!ftruncate($handle,0))throw new RuntimeException('Diagnostic log could not be cleared.');flock($handle,LOCK_UN);}finally{fclose($handle);}
    }
    private function clearSuggestions(): void
    {
        $path=$this->directory.'/brief-suggestions.json';if(is_link($path))throw new RuntimeException('Suggestion usage journal is unavailable.');
        $temp=tempnam($this->directory,'.admin-');try{chmod($temp,0600);if(file_put_contents($temp,'[]')!==2||!rename($temp,$path))throw new RuntimeException('Suggestion usage journal could not be cleared.');}finally{if(is_file($temp))unlink($temp);}
    }
    public function projects(): array
    {
        $panel=$this->connect('panel.sqlite');$research=$this->connect('research.sqlite');
        return ['projects'=>self::rows($panel,'SELECT p.*, (SELECT COUNT(*) FROM tasks WHERE project_id=p.id) AS tasks,(SELECT COUNT(*) FROM agent_runs a JOIN tasks t ON t.id=a.task_id WHERE t.project_id=p.id) AS runs FROM projects p ORDER BY p.id DESC'),
            'tasks'=>self::rows($panel,'SELECT id,project_id,title,status FROM tasks ORDER BY id DESC'),
            'research'=>self::rows($research,'SELECT p.*, (SELECT COUNT(*) FROM research_runs WHERE project_id=p.id) AS runs,(SELECT COUNT(*) FROM research_messages WHERE project_id=p.id) AS messages FROM research_projects p ORDER BY p.id DESC'),
            'runs'=>self::rows($research,'SELECT id,project_id,status,phase FROM research_runs ORDER BY id DESC')];
    }
    public function report(): array
    {
        $extensions=[];foreach(get_loaded_extensions() as $name)$extensions[$name]=phpversion($name)?:'built in';ksort($extensions);
        $settings=[];foreach(['memory_limit','max_execution_time','max_input_time','post_max_size','upload_max_filesize','display_errors','log_errors','error_log','open_basedir','disable_functions','output_buffering','zlib.output_compression','default_charset'] as $key)$settings[$key]=ini_get($key);
        $report=['generated_utc'=>gmdate('c'),'installed_release'=>getenv('AICONTROL_INSTALLED_SHA')?:null,
            'database_setup'=>'Embedded SQLite through PDO; no MySQL server is used by this application.',
            'php'=>['version'=>PHP_VERSION,'sapi'=>PHP_SAPI,'os_family'=>PHP_OS_FAMILY,'ini_file'=>php_ini_loaded_file()?:null,'additional_ini_files'=>php_ini_scanned_files()?:null,'error_reporting_mask'=>error_reporting(),'timezone'=>date_default_timezone_get(),'pdo_drivers'=>PDO::getAvailableDrivers(),'memory_used_bytes'=>memory_get_usage(true),'settings'=>$settings,'extensions'=>$extensions],
            'databases'=>[],'private_files'=>[]];
        foreach(['panel.sqlite'=>'Task projects, tasks, agent outputs, activity and login throttling','research.sqlite'=>'Research projects, conversations, investigations, evidence ledger and usage'] as $file=>$purpose){
            $db=$this->connect($file);$db->exec('PRAGMA query_only=ON');$list=self::rows($db,'PRAGMA database_list');$path=$list[0]['file'];$tables=[];
            foreach(self::rows($db,"SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table){
                $identifier='"'.str_replace('"','""',$table['name']).'"';
                $tables[]=['name'=>$table['name'],'rows'=>(int)$db->query('SELECT COUNT(*) FROM '.$identifier)->fetchColumn(),'columns'=>self::rows($db,'PRAGMA table_info('.$identifier.')'),'foreign_keys'=>self::rows($db,'PRAGMA foreign_key_list('.$identifier.')'),'schema'=>$table['sql']];
            }
            clearstatcache(true,$path);$report['databases'][]=['file'=>$file,'purpose'=>$purpose,'driver'=>$db->getAttribute(PDO::ATTR_DRIVER_NAME),'path'=>$path,'size_bytes'=>filesize($path),'permissions'=>sprintf('%04o',fileperms($path)&0777),'writable'=>is_writable($path),'sqlite_version'=>$db->query('SELECT sqlite_version()')->fetchColumn(),'journal_mode'=>$db->query('PRAGMA journal_mode')->fetchColumn(),'foreign_keys_enabled'=>(bool)$db->query('PRAGMA foreign_keys')->fetchColumn(),'quick_check'=>$db->query('PRAGMA quick_check')->fetchColumn(),'foreign_key_issues'=>self::rows($db,'PRAGMA foreign_key_check'),'tables'=>$tables];
        }
        foreach(['config.json'=>'Installation settings; protected','vault.json'=>'Encrypted keys/notes; manage individual entries below','vault.key'=>'Vault encryption key; protected','diagnostics.jsonl'=>'Diagnostic events; clearable','brief-suggestions.json'=>'Writing-help usage and local allowance; clearable','sessions'=>'Panel sessions; protected'] as $file=>$purpose){$path=$this->directory.'/'.$file;clearstatcache(true,$path);$report['private_files'][]=['file'=>$file,'present'=>file_exists($path),'bytes'=>is_file($path)?filesize($path):null,'permissions'=>file_exists($path)?sprintf('%04o',fileperms($path)&0777):null,'purpose'=>$purpose];}
        return $report;
    }
}
