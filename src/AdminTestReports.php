<?php
declare(strict_types=1);
namespace AIcontrol;

use PDO;
use RuntimeException;
require_once __DIR__.'/Diagnostics.php';
require_once __DIR__.'/Vault.php';

/** Retained observations behind Panel's existing owner/CSRF boundary; no execution or authentication API. */
final class AdminTestReports
{
    public const MAX_EVENTS=200;
    public const MAX_OUTPUT_BYTES=6000;
    public const MAX_REPORT_BYTES=524288;
    public const MAX_IMPORT_BYTES=12000;
    public const MAX_REPORTS=200;
    public const MAX_TOTAL_BYTES=16777216;
    private PDO $db;

    public function __construct(private readonly string $directory)
    {
        $file=$directory.'/panel.sqlite';if(!is_file($file)||is_link($file))throw new RuntimeException('Private panel storage is unavailable.');
        $this->db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;
            CREATE TABLE IF NOT EXISTS admin_test_reports(id INTEGER PRIMARY KEY AUTOINCREMENT,created_at TEXT NOT NULL,title TEXT NOT NULL,expected_release_sha TEXT,plan_json TEXT NOT NULL,header_json TEXT NOT NULL,header_sha256 TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS admin_test_events(id INTEGER PRIMARY KEY AUTOINCREMENT,report_id INTEGER NOT NULL REFERENCES admin_test_reports(id) ON DELETE CASCADE,sequence INTEGER NOT NULL,kind TEXT NOT NULL,created_at TEXT NOT NULL,origin TEXT NOT NULL,payload_json TEXT NOT NULL,previous_hash TEXT NOT NULL,event_sha256 TEXT NOT NULL,UNIQUE(report_id,sequence));
            CREATE INDEX IF NOT EXISTS admin_test_event_report ON admin_test_events(report_id,sequence);');
    }

    public static function defaultPlan(): array
    {
        $scope=in_array(PHP_SAPI,['cli','cli-server'],true)||getenv('AICONTROL_LOCAL_DEV')==='1'?'local':'hosted';
        return [['id'=>'php_runtime','title'=>'Serving PHP runtime and required extensions','scope'=>$scope],['id'=>'sqlite_health','title'=>'Read-only private database health','scope'=>$scope],['id'=>'release_match','title'=>'Serving release matches the expected commit','scope'=>$scope],['id'=>'research_flow','title'=>'Owner browser test of the research workflow','scope'=>'manual']];
    }

    private static function json(array $value): string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
    private function rows(string $sql,array $args=[]): array {$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll();}
    private function write(string $sql,array $args=[]): void {$q=$this->db->prepare($sql);$q->execute($args);}
    private function transaction(callable $work): mixed {$this->db->exec('BEGIN IMMEDIATE');try{$out=$work();$this->db->exec('COMMIT');return $out;}catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}}
    private static function text(mixed $value,int $bytes,bool $empty=false): string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||strlen($value)>$bytes||!$empty&&trim($value)==='')throw new RuntimeException('Use valid text within the report field limits.');
        return trim($value);
    }
    private static function sha(mixed $value): ?string
    {
        if($value===null||$value==='')return null;
        if(!is_string($value)||!preg_match('/^[a-f0-9]{40}$/D',$value))throw new RuntimeException('Use an exact 40-character lowercase Git commit SHA, or leave it unknown.');
        return $value;
    }
    private static function keys(array $value,array $allowed): void {if(array_diff(array_keys($value),$allowed))throw new RuntimeException('Report fields do not match the bounded observation format.');}
    private static function checkId(mixed $id): string {if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D',$id))throw new RuntimeException('Use a valid planned check identifier.');return $id;}
    private static function scope(mixed $scope): string {if(!in_array($scope,['fixture','local','live_provider','hosted','manual'],true))throw new RuntimeException('Choose an explicit fixture, local, live-provider, hosted or manual test scope.');return $scope;}

    private static function cleanText(string $value): string
    {
        $value=Diagnostics::clean($value);
        foreach(['SITEPIN','GH_TOKEN','GITHUB_TOKEN','AICONTROL_SITE_PIN'] as $name){$secret=getenv($name);if(is_string($secret)&&$secret!==''){
            $variants=[$secret,rawurlencode($secret),urlencode($secret)];foreach([0,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE] as $flags)$variants[]=substr(json_encode($secret,$flags|JSON_INVALID_UTF8_SUBSTITUTE),1,-1);
            $value=str_replace(array_unique($variants),'[redacted]',$value);
        }}
        $value=preg_replace('~-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z0-9 ]*PRIVATE KEY-----~','[private key redacted]',$value)??'';
        $value=preg_replace('~(?im)^([ \t]*(?:authorization|proxy-authorization|cookie|set-cookie|x-csrf-token)\s*:\s*)[^\r\n]*~','$1[redacted]',$value)??'';
        $value=preg_replace('~\b((?:authorization|proxy-authorization|cookie|set-cookie|x-csrf-token)["\x27]?\s*:\s*)[^\r\n]*~i','$1[redacted]',$value)??'';
        $value=preg_replace('~\b((?:password|passwd|pin|sitepin|csrf(?:_token)?|api[_-]?key|access[_-]?token|refresh[_-]?token|gh_token|github_token)["\x27]?\s*[=:]\s*)(?:"[^"]*"|\x27[^\x27]*\x27|[^\s,;&<>\r\n]+)~i','$1[redacted]',$value)??'';
        return $value;
    }
    private static function cleanValue(mixed $value,int $depth=0,bool $export=false): mixed
    {
        if($depth>($export?16:8))throw new RuntimeException('Report details are nested too deeply.');
        if(is_string($value)){if(!mb_check_encoding($value,'UTF-8'))throw new RuntimeException('Use valid UTF-8 report text.');return self::cleanText($value);}
        if(is_int($value)||is_bool($value)||$value===null)return $value;
        if(is_float($value)){if(!is_finite($value))throw new RuntimeException('Report numbers must be finite.');return $value;}
        if(!is_array($value)||count($value)>($export?256:64))throw new RuntimeException('Report details exceed the allowed structure.');
        $out=[];foreach($value as $key=>$item){
            if(!is_int($key)&&(!is_string($key)||strlen($key)>100))throw new RuntimeException('Report detail names exceed the field limits.');
            $normal=is_string($key)?strtolower(preg_replace('/[^a-z0-9]/i','',$key)??''):'';
            $sensitive=preg_match('/(?:password|passwd|sitepin|privatekey|authorization|cookie|csrf|apikey|accesstoken|refreshtoken|ghtoken|githubtoken|sessionid|environment|rawpost|domdump)/',$normal)||in_array($normal,['pin','token','secret','env','session'],true)||!$export&&$normal==='server';
            $out[$key]=$sensitive?'[redacted]':self::cleanValue($item,$depth+1,$export);
        }return $out;
    }
    private static function plan(array $plan): array
    {
        if(!$plan)$plan=self::defaultPlan();if(!array_is_list($plan)||count($plan)>30)throw new RuntimeException('Choose between one and thirty planned checks.');
        $out=[];$ids=[];foreach($plan as $check){if(!is_array($check)||array_is_list($check))throw new RuntimeException('Each planned check needs its identifier, title and scope.');self::keys($check,['id','title','scope']);$id=self::checkId($check['id']??null);if(isset($ids[$id]))throw new RuntimeException('Planned check identifiers must be unique.');$ids[$id]=true;$out[]=['id'=>$id,'title'=>self::cleanText(self::text($check['title']??'',160)),'scope'=>self::scope($check['scope']??null)];}return $out;
    }
    private static function server(): array
    {
        $raw=getenv('AICONTROL_INSTALLED_SHA');$sha=is_string($raw)&&preg_match('/^[a-f0-9]{40}$/D',$raw)?$raw:null;
        return ['installed_release_sha'=>$sha,'installed_release_source'=>$sha!==null?'serving_loader_runtime_environment':'unknown','php_version'=>PHP_VERSION,'sapi'=>PHP_SAPI,'report_code_sha256'=>hash_file('sha256',__FILE__),'scope'=>'This serving PHP request only; no external deployment, provider call or browser flow is established.'];
    }

    private function load(int $id): array
    {
        if($id<1)throw new RuntimeException('Choose a retained test report.');$row=$this->rows('SELECT * FROM admin_test_reports WHERE id=?',[$id])[0]??throw new RuntimeException('The selected test report is unavailable.');
        if(!hash_equals($row['header_sha256'],hash('sha256',$row['header_json'])))throw new RuntimeException('Retained report header failed its integrity check.');
        $header=json_decode($row['header_json'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($header)||($header['format']??null)!=='AIcontrol retained test report v1'||$row['created_at']!==($header['created_utc']??null)||$row['title']!==($header['title']??null)||$row['expected_release_sha']!==($header['expected_release_sha']??null)||$row['plan_json']!==self::json($header['plan']??[]))throw new RuntimeException('Retained report metadata does not match its header.');
        $previous=$row['header_sha256'];$sequence=0;$events=[];
        foreach($this->rows('SELECT * FROM admin_test_events WHERE report_id=? ORDER BY sequence',[$id]) as $event){
            $payload=json_decode($event['payload_json'],true,32,JSON_THROW_ON_ERROR);
            if((int)$event['sequence']!==++$sequence||!hash_equals($previous,$event['previous_hash'])||!hash_equals($event['event_sha256'],hash('sha256',$event['payload_json']))||!is_array($payload)||($payload['report_id']??null)!==$id||($payload['sequence']??null)!==$sequence||($payload['kind']??null)!==$event['kind']||($payload['created_utc']??null)!==$event['created_at']||($payload['origin']??null)!==$event['origin']||($payload['previous_hash']??null)!==$previous)throw new RuntimeException('Retained test events failed their integrity or sequence check.');
            $events[]=['id'=>(int)$event['id']]+$payload+['sha256'=>$event['event_sha256']];$previous=$event['event_sha256'];
        }
        return ['row'=>$row,'header'=>$header,'events'=>$events,'sequence'=>$sequence,'last_hash'=>$previous];
    }
    private static function lifecycle(array $events): string
    {
        $state='open';foreach($events as $event){if($event['kind']==='report.finished')$state='finished';if($event['kind']==='report.abandoned')$state='abandoned';}return $state;
    }
    private function appendEvent(int $id,int $expected,string $kind,array $fields,string $origin,bool $terminal=false): void
    {
        $saved=$this->load($id);if($saved['sequence']!==$expected)throw new RuntimeException('This report changed. Refresh before recording another event.');
        if(self::lifecycle($saved['events'])!=='open'&&$kind!=='correction')throw new RuntimeException('This report is closed. Start another report or append a correction.');
        $max=$terminal?self::MAX_EVENTS:self::MAX_EVENTS-1;if($expected>=$max)throw new RuntimeException('Report event limit reached. Export this record and start another report.');
        $fields=self::cleanValue($fields);$payload=['report_id'=>$id,'sequence'=>$expected+1,'kind'=>$kind,'created_utc'=>gmdate('c'),'origin'=>$origin,'server'=>self::server(),'fields'=>$fields,'previous_hash'=>$saved['last_hash']];$json=self::json($payload);
        $used=(int)$this->rows('SELECT COALESCE(SUM(length(CAST(payload_json AS BLOB))),0) AS n FROM admin_test_events WHERE report_id=?',[$id])[0]['n']+strlen($saved['row']['header_json']);
        $global=(int)$this->rows('SELECT (SELECT COALESCE(SUM(length(CAST(payload_json AS BLOB))),0) FROM admin_test_events)+(SELECT COALESCE(SUM(length(CAST(header_json AS BLOB))),0) FROM admin_test_reports) AS n')[0]['n'];
        if($used+strlen($json)>self::MAX_REPORT_BYTES-($terminal?0:8192)||$global+strlen($json)>self::MAX_TOTAL_BYTES)throw new RuntimeException('Retained test-report storage limit reached. Export reports and use explicit Admin cleanup before adding more.');
        $this->write('INSERT INTO admin_test_events(report_id,sequence,kind,created_at,origin,payload_json,previous_hash,event_sha256)VALUES(?,?,?,?,?,?,?,?)',[$id,$expected+1,$kind,$payload['created_utc'],$origin,$json,$saved['last_hash'],hash('sha256',$json)]);
    }
    private function create(string $title,?string $expected,array $plan): int
    {
        if((int)$this->rows('SELECT COUNT(*) AS n FROM admin_test_reports')[0]['n']>=self::MAX_REPORTS)throw new RuntimeException('Retained report count limit reached. Export reports before explicit Admin cleanup.');
        $title=self::cleanText(self::text($title,160));$expected=self::sha($expected);$plan=self::plan($plan);
        $header=['format'=>'AIcontrol retained test report v1','created_utc'=>gmdate('c'),'title'=>$title,'expected_release_sha'=>$expected,'expected_release_origin'=>'owner_reported','plan'=>$plan];$json=self::json($header);
        $this->write('INSERT INTO admin_test_reports(created_at,title,expected_release_sha,plan_json,header_json,header_sha256)VALUES(?,?,?,?,?,?)',[$header['created_utc'],$title,$expected,self::json($plan),$json,hash('sha256',$json)]);$id=(int)$this->db->lastInsertId();$this->appendEvent($id,0,'report.started',['summary'=>'Owner opened a retained test record. Planned checks are not executed by opening it.'],'owner_reported');return $id;
    }
    public function start(string $title,?string $expectedReleaseSha,array $selectedChecks=[]): int {return $this->transaction(fn()=>$this->create($title,$expectedReleaseSha,$selectedChecks));}

    private function clientFields(array $saved,string $kind,array $fields): array
    {
        $allowed=['check_id','summary','output','claimed_tested_sha','client_utc','details'];
        if($kind==='check.result')$allowed=array_merge($allowed,['outcome','duration_ms']);
        elseif($kind==='browser.observation')$allowed=array_merge($allowed,['outcome','path','http_status','error_kind']);
        elseif($kind==='correction')$allowed=['event_sequence','summary','output'];
        elseif($kind==='report.abandoned')$allowed=['summary'];
        elseif($kind!=='check.started')throw new RuntimeException('Choose a supported test-report event kind.');
        self::keys($fields,$allowed);$out=[];$plan=array_column($saved['header']['plan'],null,'id');
        if(in_array($kind,['check.started','check.result'],true)||isset($fields['check_id'])){$id=self::checkId($fields['check_id']??null);if(!isset($plan[$id]))throw new RuntimeException('Choose a check from this report’s retained plan.');$out['check_id']=$id;$out['scope']=$plan[$id]['scope'];}
        if($kind==='check.result'||$kind==='browser.observation'&&isset($fields['outcome'])){if(!isset($out['check_id'])||!in_array($fields['outcome']??null,['passed','failed','skipped','uncertain'],true))throw new RuntimeException('Choose a planned check and passed, failed, skipped or uncertain.');$out['outcome']=$fields['outcome'];}
        $out['summary']=self::cleanText(self::text($fields['summary']??($kind==='check.started'?'Check reported as started.':''),1600));
        if(isset($fields['output'])){$full=self::cleanText(self::text($fields['output'],65536,true));$text=mb_strcut($full,0,self::MAX_OUTPUT_BYTES,'UTF-8');$out['output']=$text;$out['output_retained_bytes']=strlen($text);$out['output_omitted_bytes']=strlen($full)-strlen($text);$out['redacted_output_sha256']=hash('sha256',$full);}
        if(array_key_exists('claimed_tested_sha',$fields))$out['claimed_tested_sha']=self::sha($fields['claimed_tested_sha']);
        if(isset($fields['client_utc'])){$date=self::text($fields['client_utc'],40);$parsed=date_parse($date);if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$date)||$parsed['warning_count']||$parsed['error_count'])throw new RuntimeException('Use an ISO 8601 client timestamp.');$out['client_utc']=$date;}
        if(isset($fields['duration_ms'])){if(!is_int($fields['duration_ms'])||$fields['duration_ms']<0||$fields['duration_ms']>86400000)throw new RuntimeException('Use a bounded nonnegative duration in milliseconds.');$out['duration_ms']=$fields['duration_ms'];}
        if(isset($fields['details'])){if(!is_array($fields['details']))throw new RuntimeException('Use structured report details.');$out['details']=self::cleanValue($fields['details']);if(strlen(self::json($out['details']))>4096)throw new RuntimeException('Report details exceed 4 KB. Keep a compact failure summary.');}
        if($kind==='correction'){if(!is_int($fields['event_sequence']??null)||$fields['event_sequence']<1||$fields['event_sequence']>$saved['sequence'])throw new RuntimeException('Choose an existing event sequence to correct.');$out['event_sequence']=$fields['event_sequence'];$out['scope']='Correction note only; earlier observations remain as recorded.';}
        if(isset($fields['path'])){$path=self::text($fields['path'],300,true);if($path!==''&&!str_starts_with($path,'/'))throw new RuntimeException('Record a relative browser path, not a remote URL.');$out['path']=explode('?',explode('#',$path,2)[0],2)[0];}
        if(isset($fields['http_status'])){if(!is_int($fields['http_status'])||$fields['http_status']<0||$fields['http_status']>599)throw new RuntimeException('Use a valid reported HTTP status.');$out['http_status']=$fields['http_status'];}
        if(isset($fields['error_kind']))$out['error_kind']=self::cleanText(self::text($fields['error_kind'],160,true));
        return $out;
    }
    public function append(int $report,int $expectedSequence,string $eventKind,array $fields): array
    {
        return $this->transaction(function()use($report,$expectedSequence,$eventKind,$fields):array{$saved=$this->load($report);$fields=$this->clientFields($saved,$eventKind,$fields);$this->appendEvent($report,$expectedSequence,$eventKind,$fields,$eventKind==='browser.observation'?'browser_reported':'owner_reported',$eventKind==='report.abandoned');return $this->report($report);});
    }

    private function localCheck(string $id,array $header): array
    {
        if($id==='release_match'){$observed=self::server()['installed_release_sha'];$expected=$header['expected_release_sha'];return ['outcome'=>$observed===null||$expected===null?'uncertain':($observed===$expected?'passed':'failed'),'summary'=>$observed===null?'Serving release SHA is not available.':($expected===null?'No expected release was recorded.':($observed===$expected?'Serving loader release matches the owner’s expected commit.':'Serving loader release differs from the owner’s expected commit.')),'details'=>['expected_release_sha'=>$expected,'server_observed_release_sha'=>$observed,'scope'=>'No GitHub publication, external test or installed-host activation is established beyond this serving request.']];}
        if($id==='php_runtime'){$needed=['curl','zip','tokenizer','mbstring','pdo_sqlite','session','sodium'];$missing=array_values(array_filter($needed,static fn($extension)=>!extension_loaded($extension)));$ok=PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80600&&!$missing;return ['outcome'=>$ok?'passed':'failed','summary'=>$ok?'Serving PHP version and required extensions are available.':'Serving PHP version or required extensions do not match current requirements.','details'=>['php_version'=>PHP_VERSION,'sapi'=>PHP_SAPI,'missing_extensions'=>$missing]];}
        if($id!=='sqlite_health')throw new RuntimeException('Choose php_runtime, sqlite_health or release_match.');
        $checks=[];$ok=true;foreach(['panel.sqlite','research.sqlite','systems.sqlite'] as $file){$path=$this->directory.'/'.$file;if(is_link($path)){$checks[]=['database'=>$file,'state'=>'unsafe_link'];$ok=false;continue;}if(!is_file($path)){$checks[]=['database'=>$file,'state'=>'not_present'];if($file==='panel.sqlite')$ok=false;continue;}
            $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=2000');$quick=$db->query('PRAGMA quick_check(10)')->fetchAll(PDO::FETCH_COLUMN);$foreign=(int)$db->query('SELECT COUNT(*) FROM pragma_foreign_key_check')->fetchColumn();$valid=$quick===['ok']&&$foreign===0;$checks[]=['database'=>$file,'quick_check'=>$quick===['ok']?'ok':'issues_reported','foreign_key_issue_count'=>$foreign];$ok=$ok&&$valid;
        }return ['outcome'=>$ok?'passed':'failed','summary'=>$ok?'Existing private SQLite files passed read-only health checks.':'A private SQLite file failed a read-only health check.','details'=>['databases'=>$checks,'scope'=>'Readability and SQLite relationships only; application behavior was not tested.']];
    }
    public function runLocalCheck(int $report,int $expectedSequence,string $checkId): array
    {
        if(!in_array($checkId,['php_runtime','sqlite_health','release_match'],true))throw new RuntimeException('Choose php_runtime, sqlite_health or release_match.');
        $header=$this->transaction(function()use($report,$expectedSequence,$checkId):array{$saved=$this->load($report);$fields=$this->clientFields($saved,'check.started',['check_id'=>$checkId,'summary'=>'Read-only serving-server check started.']);$this->appendEvent($report,$expectedSequence,'check.started',$fields,'server_observed');return $saved['header'];});
        $started=microtime(true);try{$result=$this->localCheck($checkId,$header);}catch(\Throwable $error){$result=['outcome'=>'uncertain','summary'=>'The read-only server check did not complete. Inspect its retained error.','output'=>get_class($error).': '.$error->getMessage()];}
        $result+=['check_id'=>$checkId,'duration_ms'=>(int)round((microtime(true)-$started)*1000)];
        return $this->transaction(function()use($report,$expectedSequence,$result):array{$saved=$this->load($report);$fields=$this->clientFields($saved,'check.result',$result);$this->appendEvent($report,$expectedSequence+1,'check.result',$fields,'server_observed');return $this->report($report);});
    }
    public function finish(int $report,int $expectedSequence,string $ownerSummary): array
    {
        return $this->transaction(function()use($report,$expectedSequence,$ownerSummary):array{$this->appendEvent($report,$expectedSequence,'report.finished',['summary'=>self::cleanText(self::text($ownerSummary,2400)),'scope'=>'Owner ended reporting; this does not establish test success or external side effects.'],'owner_reported',true);return $this->report($report);});
    }

    public function import(array $packet): int
    {
        if(strlen(self::json($packet))>self::MAX_IMPORT_BYTES)throw new RuntimeException('Imported report exceeds 12 KB. Split retained outputs across bounded append events.');
        self::keys($packet,['format','title','expected_release_sha','checks','summary','finished']);if(($packet['format']??null)!=='AIcontrol test import v1'||!is_array($packet['checks']??null)||!array_is_list($packet['checks'])||!$packet['checks']||count($packet['checks'])>30)throw new RuntimeException('Use the bounded AIcontrol test import v1 format.');
        if(isset($packet['finished'])&&!is_bool($packet['finished']))throw new RuntimeException('Imported finished state must be an explicit boolean.');
        $plan=[];$results=[];foreach($packet['checks'] as $check){if(!is_array($check)||array_is_list($check))throw new RuntimeException('Use structured imported check results.');self::keys($check,['id','title','scope','outcome','summary','output','claimed_tested_sha','duration_ms','client_utc','details']);$plan[]=['id'=>$check['id']??null,'title'=>$check['title']??'','scope'=>$check['scope']??null];$fields=$check;unset($fields['id'],$fields['title'],$fields['scope']);$fields['check_id']=$check['id']??null;$results[]=$fields;}
        $plan=self::plan($plan);$title=self::text($packet['title']??'',160);$expected=self::sha($packet['expected_release_sha']??null);$summary=self::text($packet['summary']??'Owner imported these reported test results. No external test or deployment is independently established.',2400);
        return $this->transaction(function()use($packet,$plan,$title,$expected,$summary,$results):int{$id=$this->create($title,$expected,$plan);$sequence=1;foreach($results as $fields){$saved=$this->load($id);$fields=$this->clientFields($saved,'check.result',$fields);$fields['imported']=true;$this->appendEvent($id,$sequence++,'check.result',$fields,'owner_reported');}if($packet['finished']??true)$this->appendEvent($id,$sequence,'report.finished',['summary'=>self::cleanText($summary),'scope'=>'Imported client results; not independent server observation of those tests.'],'owner_reported',true);return $id;});
    }

    public function report(int $id): array
    {
        $saved=$this->load($id);$lifecycle=self::lifecycle($saved['events']);$checks=[];foreach($saved['header']['plan'] as $check)$checks[$check['id']]=$check+['state'=>'pending','origin'=>null,'summary'=>'No result recorded.','sequence'=>null];
        $shas=[];$unknown=false;$claims=[];
        foreach($saved['events'] as $event){$sha=$event['server']['installed_release_sha']??null;if($sha===null)$unknown=true;else $shas[$sha]=true;$fields=$event['fields'];
            if(isset($fields['claimed_tested_sha']))$claims[$fields['claimed_tested_sha']]=true;
            if(isset($fields['check_id'])&&isset($checks[$fields['check_id']])&&(in_array($event['kind'],['check.started','check.result'],true)||$event['kind']==='browser.observation'&&isset($fields['outcome']))){$check=&$checks[$fields['check_id']];$check['state']=$event['kind']==='check.started'?'running':$fields['outcome'];$check['origin']=$event['origin'];$check['summary']=$fields['summary'];$check['sequence']=$event['sequence'];unset($check);}
        }
        $counts=array_fill_keys(['pending','running','passed','failed','skipped','uncertain'],0);foreach($checks as &$check){if($lifecycle!=='open'&&in_array($check['state'],['pending','running'],true)){$check['state']='uncertain';$check['summary']='No confirmed result before reporting ended.';}$counts[$check['state']]++;}unset($check);
        $expected=$saved['header']['expected_release_sha'];$shaList=array_keys($shas);$release=count($shaList)>1?'mixed':($expected===null?'not_specified':($unknown||!$shaList?'unknown':($shaList[0]===$expected?'matches_expected':'mismatch')));
        foreach(array_keys($claims) as $claim)if($expected!==null&&$claim!==$expected&&$release!=='mixed')$release='mismatch';
        $outcome=$counts['failed']?'failed':($counts['uncertain']?'uncertain':($counts['pending']||$counts['running']?($counts['passed']||$counts['skipped']?'incomplete':'not_run'):($counts['skipped']?'incomplete':'passed_as_reported')));
        if(in_array($release,['mixed','mismatch','unknown'],true)&&$outcome==='passed_as_reported')$outcome='uncertain';
        if($outcome==='passed_as_reported'&&array_filter($saved['events'],static fn($event)=>$event['kind']==='correction'))$outcome='uncertain';
        $last=$saved['events'][count($saved['events'])-1]??null;
        $failureEvents=count(array_filter($saved['events'],static fn($event)=>($event['fields']['outcome']??null)==='failed'));$uncertainEvents=count(array_filter($saved['events'],static fn($event)=>($event['fields']['outcome']??null)==='uncertain'));
        $pack=['format'=>'AIcontrol retained test report v1','report'=>['id'=>$id]+$saved['header'],'sequence'=>$saved['sequence'],'summary'=>['lifecycle'=>$lifecycle,'outcome'=>$outcome,'release_state'=>$release,'server_observed_release_sha'=>$last['server']['installed_release_sha']??null,'server_observed_release_shas'=>$shaList,'client_claimed_tested_shas'=>array_keys($claims),'counts'=>$counts,'failure_event_count'=>$failureEvents,'uncertain_event_count'=>$uncertainEvents,'checks'=>array_values($checks),'last_event_utc'=>$last['created_utc']??$saved['header']['created_utc']],
            'events'=>$saved['events'],'integrity'=>['verified'=>true,'scope'=>'Stored redacted header and event hash chain. This is an integrity check, not external attestation. Export-time redaction can further withhold retained text.'],
            'limits'=>['max_events'=>self::MAX_EVENTS,'max_output_bytes'=>self::MAX_OUTPUT_BYTES,'max_report_bytes'=>self::MAX_REPORT_BYTES,'max_import_bytes'=>self::MAX_IMPORT_BYTES],
            'redaction'=>['credentials'=>'Known protected values and sensitive fields are withheld before storage and again on read. Never paste credentials or raw session/DOM dumps.','retention'=>'Reports persist separately from the rotating diagnostic log; no automatic deletion.','claims'=>'Client/imported/browser outcomes remain reports from those sources. Server runtime observations do not prove external publication, browser behavior or provider operation.']];
        return self::cleanValue($pack,0,true);
    }
    public function list(int $limit=20,int $offset=0): array
    {
        if($limit<1||$limit>100||$offset<0||$offset>self::MAX_REPORTS)throw new RuntimeException('Choose a bounded page of retained reports.');$out=[];foreach($this->rows('SELECT id FROM admin_test_reports ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset) as $row){$pack=$this->report((int)$row['id']);$out[]=['id'=>(int)$row['id'],'title'=>$pack['report']['title'],'created_utc'=>$pack['report']['created_utc'],'expected_release_sha'=>$pack['report']['expected_release_sha'],'sequence'=>$pack['sequence'],'lifecycle'=>$pack['summary']['lifecycle'],'outcome'=>$pack['summary']['outcome'],'release_state'=>$pack['summary']['release_state'],'event_count'=>count($pack['events']),'last_event_utc'=>$pack['summary']['last_event_utc']];}return $out;
    }
    public function export(int $id,string $format='json'): string
    {
        $pack=$this->report($id);if($format==='json')return json_encode($pack,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if($format!=='markdown')throw new RuntimeException('Choose a JSON or Markdown test report export.');
        $line=static fn(string $text)=>str_replace(["\r","\n"],['',' · '],$text);$out='# Retained test report #'.$id.' · '.$line($pack['report']['title'])."\n\n";
        $out.='Expected commit (owner-reported): '.($pack['report']['expected_release_sha']??'unknown')."\n\nServing release observed on latest action: ".($pack['summary']['server_observed_release_sha']??'unknown')."\n\nLifecycle: ".$pack['summary']['lifecycle'].' · Outcome: '.$pack['summary']['outcome'].' · Release: '.$pack['summary']['release_state']."\n\n".$pack['redaction']['claims']."\n\n";
        foreach($pack['summary']['checks'] as $check)$out.='- '.$line($check['title']).' · '.$check['scope'].' · '.$check['state'].' · '.($check['origin']??'no result')."\n";
        foreach($pack['events'] as $event){$out.="\n## Event ".$event['sequence'].' · '.$event['kind']."\n\n".$event['created_utc'].' · '.$event['origin'].' · serving '.($event['server']['installed_release_sha']??'unknown')."\n\n";foreach(explode("\n",json_encode($event['fields'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) as $entry)$out.='    '.$entry."\n";}
        return self::cleanText($out);
    }
}
