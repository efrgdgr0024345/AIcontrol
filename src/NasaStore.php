<?php
declare(strict_types=1);
namespace AIcontrol;
use PDO;
use RuntimeException;
require_once __DIR__.'/NasaKnowledge.php';
require_once __DIR__.'/UsageMeter.php';
require_once __DIR__.'/NasaIntegration.php';
require_once __DIR__.'/NasaCollaboration.php';
require_once __DIR__.'/NasaDiscipline.php';
require_once __DIR__.'/NasaContextPack.php';

/** Append-only engineering records in the existing private panel database. */
final class NasaStore
{
    use NasaIntegration;
    use NasaCollaboration;
    use NasaDiscipline;
    use NasaContextPack;
    public PDO $db;
    public function __construct(public readonly string $directory)
    {
        if(is_file($directory.'/research.sqlite')&&!is_link($directory.'/research.sqlite')){require_once __DIR__.'/Research.php';new Research($directory);}
        $file=$directory.'/panel.sqlite';if(!is_file($file)||is_link($file))throw new RuntimeException('Project storage is unavailable.');
        $this->db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;
            CREATE TABLE IF NOT EXISTS nasa_projects(id INTEGER PRIMARY KEY AUTOINCREMENT,source_kind TEXT NOT NULL,source_id INTEGER NOT NULL,phase TEXT NOT NULL DEFAULT "pre",revision INTEGER NOT NULL DEFAULT 0,linked_research_id INTEGER,task_project_id INTEGER REFERENCES projects(id) ON DELETE SET NULL,created_at INTEGER NOT NULL,UNIQUE(source_kind,source_id));
            CREATE TABLE IF NOT EXISTS nasa_items(id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,kind TEXT NOT NULL,item_key TEXT NOT NULL,version INTEGER NOT NULL,title TEXT NOT NULL,content TEXT NOT NULL,status TEXT NOT NULL,data_json TEXT NOT NULL,created_by TEXT NOT NULL,created_at INTEGER NOT NULL,UNIQUE(project_id,kind,item_key,version));
            CREATE INDEX IF NOT EXISTS nasa_item_versions ON nasa_items(project_id,kind,item_key,version);
            CREATE TABLE IF NOT EXISTS nasa_reviews(id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,revision INTEGER NOT NULL,review_type TEXT NOT NULL,created_by TEXT NOT NULL,report_json TEXT NOT NULL,created_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS nasa_baselines(id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,title TEXT NOT NULL,snapshot_json TEXT NOT NULL,sha256 TEXT NOT NULL,created_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS nasa_links(id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,review_id INTEGER NOT NULL REFERENCES nasa_reviews(id) ON DELETE CASCADE,finding_index INTEGER NOT NULL,target_kind TEXT NOT NULL,target_id INTEGER NOT NULL,UNIQUE(project_id,review_id,finding_index,target_kind));
            CREATE TABLE IF NOT EXISTS nasa_ai_requests(id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL,source_kind TEXT NOT NULL,source_id INTEGER NOT NULL,revision INTEGER NOT NULL,action TEXT NOT NULL,status TEXT NOT NULL,model TEXT NOT NULL,reserved_usd REAL NOT NULL,estimated_usd REAL,billing_json TEXT,result_json TEXT,error TEXT,created_at INTEGER NOT NULL,finished_at INTEGER);
            CREATE INDEX IF NOT EXISTS nasa_ai_window ON nasa_ai_requests(created_at);');
        self::migrateIntegration($this->db);
        $this->initCollaboration();
        $this->initDiscipline();
    }
    public function rows(string $sql,array $args=[]): array {$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll();}
    public function execute(string $sql,array $args=[]): void {$q=$this->db->prepare($sql);$q->execute($args);}
    public function transaction(callable $work,bool $lockResearch=true): mixed
    {
        // A planning decision and the research fingerprint it relies on must
        // share one SQLite write reservation. Separate PDO reads alone race.
        $attached=false;$file=$this->directory.'/research.sqlite';
        if($lockResearch&&is_file($file)&&!is_link($file)){
            $already=false;foreach($this->db->query('PRAGMA database_list')->fetchAll() as $database)if($database['file']===$file)$already=true;
            if(!$already){$q=$this->db->prepare('ATTACH DATABASE ? AS nasa_atomic_research');$q->execute([$file]);$q->closeCursor();$attached=true;}
        }
        try{$this->db->exec('BEGIN IMMEDIATE');try{$out=$work();$this->db->exec('COMMIT');return $out;}catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}}
        finally{if($attached)$this->db->exec('DETACH DATABASE nasa_atomic_research');}
    }
    public static function text(mixed $value,int $max,bool $empty=false): string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||strlen($value)>$max||!$empty&&trim($value)==='')throw new RuntimeException('Use valid text within the displayed field limits.');
        return trim($value);
    }
    public static function id(mixed $value,bool $zero=false): int
    {
        if(!is_string($value)&&!is_int($value)||!preg_match($zero?'/^(0|[1-9][0-9]{0,9})$/D':'/^[1-9][0-9]{0,9}$/D',(string)$value))throw new RuntimeException('Choose a valid saved record.');return (int)$value;
    }
    private function research(): ?PDO
    {
        $file=$this->directory.'/research.sqlite';if(is_link($file))throw new RuntimeException('Research storage is unavailable.');
        if(!is_file($file))return null;
        return new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    }
    public function source(string $kind,int $id): array
    {
        if(!in_array($kind,['project','research'],true)||$id<1)throw new RuntimeException('Choose a task or research project.');
        $db=$kind==='project'?$this->db:$this->research();if(!$db)throw new RuntimeException('The selected project no longer exists.');
        $q=$db->prepare('SELECT id,name,brief FROM '.($kind==='project'?'projects':'research_projects').' WHERE id=?');$q->execute([$id]);
        return $q->fetch()?:throw new RuntimeException('The selected project no longer exists.');
    }
    public function sources(): array
    {
        $out=[];$managed=$this->rows('SELECT * FROM nasa_projects');$index=[];foreach($managed as $p)$index[$p['source_kind'].':'.$p['source_id']]=$p['id'];
        foreach(['project'=>$this->db,'research'=>$this->research()] as $kind=>$db){if(!$db)continue;foreach($db->query('SELECT id,name,brief FROM '.($kind==='project'?'projects':'research_projects').' ORDER BY id DESC')->fetchAll() as $p)$out[]=$p+['kind'=>$kind,'managed_id'=>$index[$kind.':'.$p['id']]??null];}
        return $out;
    }
    public function start(string $kind,int $source,string $phase='pre',string $reason=''): int
    {
        $this->source($kind,$source);
        if(!isset(NasaKnowledge::phases()[$phase]))throw new RuntimeException('Choose a known starting phase.');$reason=self::text($reason,1000,$phase==='pre');
        return $this->transaction(function()use($kind,$source,$phase,$reason):int{
            $existing=$this->rows('SELECT id FROM nasa_projects WHERE source_kind=? AND source_id=?',[$kind,$source]);if($existing)return (int)$existing[0]['id'];
            $this->execute('INSERT INTO nasa_projects(source_kind,source_id,phase,linked_research_id,task_project_id,created_at)VALUES(?,?,?,?,?,?)',[$kind,$source,$phase,$kind==='research'?$source:null,$kind==='project'?$source:null,time()]);$id=(int)$this->db->lastInsertId();
            if($phase!=='pre'){$this->append($id,'decision','DEC-001','Starting phase selected by owner',$reason,'decided',['alternatives'=>'Begin a new concept study at Pre-Phase A.','revisit'=>'Revisit the phase after reviewing existing evidence; earlier gates are not certified.'],'owner',0);$this->bump($id);}return $id;
        });
    }
    public function project(int $id): array
    {
        $p=$this->rows('SELECT * FROM nasa_projects WHERE id=?',[$id])[0]??throw new RuntimeException('Choose a managed project.');$p['source']=$this->source($p['source_kind'],(int)$p['source_id']);return $p;
    }
    public function items(int $project,bool $removed=false): array
    {
        $items=$this->rows('SELECT i.* FROM nasa_items i WHERE i.project_id=? AND NOT EXISTS(SELECT 1 FROM nasa_items n WHERE n.project_id=i.project_id AND n.kind=i.kind AND n.item_key=i.item_key AND n.version>i.version) ORDER BY i.kind,i.item_key',[$project]);
        foreach($items as &$i)$i['data']=json_decode($i['data_json'],true,64,JSON_THROW_ON_ERROR);unset($i);
        return $removed?$items:array_values(array_filter($items,static fn($i)=>$i['status']!=='removed'));
    }
    public function current(int $project,string $kind,string $key): ?array
    {
        return $this->rows('SELECT * FROM nasa_items WHERE project_id=? AND kind=? AND item_key=? ORDER BY version DESC LIMIT 1',[$project,$kind,$key])[0]??null;
    }
    private function append(int $project,string $kind,string $key,string $title,string $content,string $status,array $data,string $by,int $expected): int
    {
        $old=$this->current($project,$kind,$key);if((int)($old['version']??0)!==$expected)throw new RuntimeException('This record changed. Refresh before saving; your earlier versions are retained.');
        $this->execute('INSERT INTO nasa_items(project_id,kind,item_key,version,title,content,status,data_json,created_by,created_at)VALUES(?,?,?,?,?,?,?,?,?,?)',[$project,$kind,$key,$expected+1,$title,$content,$status,json_encode($data,JSON_THROW_ON_ERROR),$by,time()]);return (int)$this->db->lastInsertId();
    }
    private function bump(int $id): void {$this->execute('UPDATE nasa_projects SET revision=revision+1 WHERE id=?',[$id]);}
    public function saveArtifact(int $id,string $type,string $content,string $status,int $version,string $by='owner'): int
    {
        $this->project($id);if(!isset(NasaKnowledge::ARTIFACTS[$type])||!in_array($status,['draft','reviewed','baseline'],true))throw new RuntimeException('Choose a known artifact and status.');
        $content=self::text($content,12000);
        return $this->transaction(function()use($id,$type,$content,$status,$version,$by):int{$n=$this->append($id,'artifact',$type,NasaKnowledge::ARTIFACTS[$type][0],$content,$status,['reference'=>NasaKnowledge::ARTIFACTS[$type][2]],$by,$version);$this->bump($id);return $n;});
    }
    public function restoreArtifact(int $id,int $record,int $version): void
    {
        $old=$this->rows("SELECT * FROM nasa_items WHERE id=? AND project_id=? AND kind='artifact'",[$record,$id])[0]??throw new RuntimeException('Choose a version from this project.');
        $this->saveArtifact($id,$old['item_key'],$old['content'],'draft',$version,'owner_restored');
    }
    private function validateRecord(int $id,string $kind,string $title,string $content,array $d,string $status): array
    {
        self::text($title,160);self::text($content,3000);
        $need=static fn($k,$max=2000,$empty=false)=>self::text($d[$k]??'',$max,$empty);
        if($kind==='requirement'){
            if(!in_array($status,['unverified','verified'],true)||!in_array($d['method']??'', ['test','analysis','inspection','demonstration'],true))throw new RuntimeException('Choose a verification method and state.');
            $data=['source'=>$need('source',1000),'method'=>$d['method'],'evidence'=>$need('evidence',2000,true)];if($status==='verified'&&$data['evidence']==='')throw new RuntimeException('Record actual verification evidence before marking a requirement verified.');
        }elseif($kind==='risk'){
            if(!in_array($status,['open','mitigated','accepted','closed'],true))throw new RuntimeException('Choose a risk state.');
            $data=['owner'=>$need('owner',160),'mitigation'=>$need('mitigation'),'requirement'=>$need('requirement',40,true),'artifact'=>$need('artifact',40,true)];
            foreach(['likelihood','consequence','residual_likelihood','residual_consequence'] as $key){$v=$d[$key]??null;if(!is_int($v)||$v<1||$v>5)throw new RuntimeException('Use likelihood and impact values from 1 to 5.');$data[$key]=$v;}
            if($data['requirement']!==''&&(!$linked=$this->current($id,'requirement',$data['requirement'])||$linked['status']==='removed'))throw new RuntimeException('Link a requirement from this project.');
            if($data['artifact']!==''&&!isset(NasaKnowledge::ARTIFACTS[$data['artifact']]))throw new RuntimeException('Choose a known artifact link.');
        }elseif($kind==='tpm'){
            if($status!=='recorded'||!in_array($d['direction']??'', ['at_most','at_least'],true))throw new RuntimeException('Choose a measure direction.');
            $data=['units'=>$need('units',80),'method'=>$need('method',1000),'direction'=>$d['direction']];
            foreach(['target','observed'] as $key){$v=$d[$key]??null;if(!is_int($v)&&!is_float($v)||!is_finite((float)$v)||abs($v)>1e12)throw new RuntimeException('Use finite measurements within the displayed limits.');$data[$key]=$v;}
        }elseif($kind==='decision'){
            if(!in_array($status,['proposed','decided'],true))throw new RuntimeException('Choose a decision state.');$data=['alternatives'=>$need('alternatives'),'revisit'=>$need('revisit',1000)];
        }elseif($kind==='tailoring'){
            if($status!=='recorded'||!isset(NasaKnowledge::phases()[$d['phase']??''])||!isset(NasaKnowledge::ARTIFACTS[$d['artifact']??'']))throw new RuntimeException('Choose a phase and artifact to tailor.');
            $criteria=$d['criteria']??[];
            if(!is_array($criteria)||!array_is_list($criteria)||!$criteria||count($criteria)>8||array_filter($criteria,static fn($v)=>!is_string($v))||array_diff($criteria,array_keys(NasaKnowledge::tailoringCriteria())))throw new RuntimeException('Choose at least one handbook tailoring criterion.');
            $data=['phase'=>$d['phase'],'artifact'=>$d['artifact'],'criteria'=>array_values(array_unique($criteria)),'residual_risk'=>$need('residual_risk'),'revisit'=>$need('revisit',1000),'knowledge_version'=>NasaKnowledge::VERSION];
        }elseif($kind==='interface'){$data=$this->validateInterfaceRecord($d,$status);
        }else throw new RuntimeException('Choose a known engineering record type.');
        return $data;
    }
    public function saveRecord(int $id,string $kind,string $key,string $title,string $content,string $status,array $data,int $version,string $by='owner'): string
    {
        $this->project($id);$inputData=$data;$data=$this->validateRecord($id,$kind,$title,$content,$data,$status);
        return $this->transaction(function()use($id,$kind,$key,$title,$content,$status,$data,$inputData,$version,$by):string{
            if($kind==='requirement'&&$status==='verified'){$this->assertVerifiedEvidence($id,$key,$title,$content,$data+['verification_confirmed'=>$inputData['verification_confirmed']??false],$by);$data['owner_confirmation']=['subject_hash'=>self::subjectHash($kind,$title,$content,$data),'by'=>'owner','created_at'=>time()];}
            if($key===''){$prefix=['requirement'=>'REQ','risk'=>'RISK','tpm'=>'TPM','decision'=>'DEC','tailoring'=>'TAIL','interface'=>'IF'][$kind];$number=(int)$this->rows('SELECT COUNT(DISTINCT item_key) AS n FROM nasa_items WHERE project_id=? AND kind=?',[$id,$kind])[0]['n']+1;$key=$prefix.'-'.str_pad((string)$number,3,'0',STR_PAD_LEFT);}
            elseif(!preg_match('/^(REQ|RISK|TPM|DEC|TAIL|IF)-[0-9]{3,10}$/D',$key)||!$this->current($id,$kind,$key))throw new RuntimeException('Choose a record from this project.');
            $this->append($id,$kind,$key,trim($title),trim($content),$status,$data,$by,$version);$this->bump($id);return $key;
        });
    }
    public static function requirementWarnings(string $statement): array
    {
        $out=[];if(!preg_match('/\bshall\b/ui',$statement))$out[]='Use “shall” to state a system obligation.';
        if(preg_match('/\b(fast|easy|robust|user.friendly|as needed|etc|TBD|TBC)\b/ui',$statement))$out[]='Replace vague or unresolved wording with a measurable condition.';
        if(!preg_match('/[0-9]|\b(reject|retain|record|prevent|display|encrypt|delete|return)\b/ui',$statement))$out[]='Describe an observable result or measurable limit.';
        if(substr_count(mb_strtolower($statement),' shall ')>1)$out[]='Consider splitting multiple obligations into separate requirements.';return $out;
    }
    public function archiveRecord(int $id,string $kind,string $key,int $version,bool $restore=false): void
    {
        $this->project($id);if(!in_array($kind,['requirement','risk','tpm','decision','tailoring','interface'],true))throw new RuntimeException('Choose a structured record to archive or restore.');
        $this->transaction(function()use($id,$kind,$key,$version,$restore):void{
            $r=$this->current($id,$kind,$key);if(!$r||$restore&&$r['status']!=='removed'||!$restore&&$r['status']==='removed')throw new RuntimeException('Refresh the record before changing its archive state.');
            $status=$restore?['requirement'=>'unverified','risk'=>'open','tpm'=>'recorded','decision'=>'proposed','tailoring'=>'recorded','interface'=>'draft'][$kind]:'removed';
            $this->append($id,$kind,$key,$r['title'],$r['content'],$status,json_decode($r['data_json'],true,64,JSON_THROW_ON_ERROR),$restore?'owner_restored':'owner_archived',$version);$this->bump($id);
        });
    }
    public function isVerifiedRequirement(int $id,array $record): bool
    {
        $data=$record['data']??json_decode($record['data_json']??'{}',true,64,JSON_THROW_ON_ERROR);$confirmation=$data['owner_confirmation']??null;
        return ($record['status']??'')==='verified'&&is_array($confirmation)&&($confirmation['by']??'')==='owner'&&is_int($confirmation['created_at']??null)&&$confirmation['created_at']>0&&is_string($confirmation['subject_hash']??null)&&hash_equals($confirmation['subject_hash'],self::subjectHash('requirement',$record['title'],$record['content'],$data))&&$this->hasReviewedEvidence($id,$record['item_key'],$record['title'],$record['content'],$data);
    }
    public function readiness(int $id,?string $phase=null): array
    {
        $p=$this->project($id);$phase??=$p['phase'];$spec=NasaKnowledge::phases()[$phase]??throw new RuntimeException('Choose a known phase.');$items=$this->items($id);$docs=[];$waivers=[];$req=[];$risks=[];
        foreach($items as $i){if($i['kind']==='artifact')$docs[$i['item_key']]=$i;if($i['kind']==='tailoring'&&($i['data']['phase']??'')===$phase&&self::completeTailoring($i['data']))$waivers[$i['data']['artifact']]=$i;if($i['kind']==='requirement')$req[]=$i;if($i['kind']==='risk')$risks[]=$i;}
        $checks=[];foreach($spec[4] as $type){$doc=$docs[$type]??null;$waiver=$waivers[$type]??null;$checks[]=['key'=>$type,'title'=>NasaKnowledge::ARTIFACTS[$type][0],'passed'=>($doc&&in_array($doc['status'],['reviewed','baseline'],true))||!!$waiver,'waived'=>!!$waiver,'detail'=>$waiver?'Tailored: '.$waiver['content']:($doc?'Current status: '.$doc['status']:'No document saved.')];}
        if(in_array($phase,['A','B','C','D','E'],true))$checks[]=['key'=>'requirements','title'=>'Traceable, testable requirements','passed'=>isset($waivers['requirements'])||count($req)>0&&!array_filter($req,static fn($r)=>self::requirementWarnings($r['content'])!==[]),'waived'=>isset($waivers['requirements']),'detail'=>'Use the matrix: record the need, an observable shall statement and its verification method.'];
        if(in_array($phase,['D','E'],true))$checks[]=['key'=>'verification','title'=>'Recorded requirement evidence','passed'=>isset($waivers['verification'])||count($req)>0&&!array_filter($req,fn($r)=>!$this->isVerifiedRequirement($id,$r)),'waived'=>isset($waivers['verification']),'detail'=>'Owner-recorded evidence is needed for every active requirement; this does not run tests.'];
        $high=array_values(array_filter($risks,static fn($r)=>$r['status']==='open'&&$r['data']['residual_likelihood']*$r['data']['residual_consequence']>=15));
        $checks[]=['key'=>'risks','title'=>'High residual risks have a recorded response','passed'=>!$high,'waived'=>false,'detail'=>$high?count($high).' high risks remain open. Mitigate or explicitly accept them with a rationale.':'No high residual risk is currently recorded as open. This is not proof all risks were found.'];
        foreach($checks as &$check)if(isset($waivers[$check['key']])){$w=$waivers[$check['key']];$check['tailoring']=['item_key'=>$w['item_key'],'version'=>$w['version'],'rationale'=>$w['content']]+$w['data'];$check['detail']='Tailored — see rationale. '.$w['content'];}unset($check);
        $passed=count(array_filter($checks,static fn($c)=>$c['passed']));return ['checks'=>$checks,'passed'=>$passed,'total'=>count($checks),'percent'=>(int)round(100*$passed/max(1,count($checks))),'ready'=>$passed===count($checks),'phase'=>$phase];
    }
    public static function completeTailoring(array $data): bool
    {
        $criteria=$data['criteria']??null;
        return is_array($criteria)&&array_is_list($criteria)&&count($criteria)>0&&count($criteria)<=8&&!array_filter($criteria,static fn($v)=>!is_string($v))&&!array_diff($criteria,array_keys(NasaKnowledge::tailoringCriteria()))&&is_string($data['residual_risk']??null)&&trim($data['residual_risk'])!==''&&is_string($data['revisit']??null)&&trim($data['revisit'])!=='';
    }
    public function review(int $id,string $by='local',?array $ai=null): int
    {
        $p=$this->project($id);$ready=$this->readiness($id);$gaps=[];
        foreach($ready['checks'] as $c)if(!$c['passed'])$gaps[]=['severity'=>$c['key']==='risks'?'high':'medium','title'=>$c['title'],'detail'=>$c['detail'],'artifact'=>$c['key']];
        $report=$ai??['strengths'=>[$ready['passed'].' of '.$ready['total'].' local checks recorded.'],'gaps'=>$gaps,'next_actions'=>array_map(static fn($g)=>'Address: '.$g['title'],$gaps),'summary'=>$ready['ready']?'Recorded readiness checks are complete. Review assumptions before advancing.':'Start with the first gap. Draft, inspect and review one useful artifact at a time.'];
        $report['recorded_checks']=$ready['checks'];$report['knowledge_version']=NasaKnowledge::VERSION;
        $this->execute('INSERT INTO nasa_reviews(project_id,revision,review_type,created_by,report_json,created_at)VALUES(?,?,?,?,?,?)',[$id,$p['revision'],NasaKnowledge::phases()[$p['phase']][5],$by,json_encode($report,JSON_THROW_ON_ERROR),time()]);return (int)$this->db->lastInsertId();
    }
    public function finding(int $project,int $review,int $index): array
    {
        $row=$this->rows('SELECT * FROM nasa_reviews WHERE id=? AND project_id=?',[$review,$project])[0]??throw new RuntimeException('Choose a review from this project.');
        $report=json_decode($row['report_json'],true,64,JSON_THROW_ON_ERROR);return $report['gaps'][$index]??throw new RuntimeException('Choose a finding from this review.');
    }
    public function advance(int $id,int $revision): void
    {
        $this->transaction(function()use($id,$revision):void{$p=$this->project($id);if((int)$p['revision']!==$revision)throw new RuntimeException('Project changed. Refresh readiness before advancing.');if(!$this->readiness($id)['ready'])throw new RuntimeException('Address or explicitly tailor the missing readiness checks first.');
            if(!$this->rows("SELECT id FROM nasa_reviews WHERE project_id=? AND revision=? AND created_by='local'",[$id,$revision]))throw new RuntimeException('Record a review of this revision before advancing.');
            $keys=array_keys(NasaKnowledge::phases());$next=$keys[array_search($p['phase'],$keys,true)+1]??null;if(!$next)throw new RuntimeException('This is the final lifecycle phase.');$this->execute('UPDATE nasa_projects SET phase=?,revision=revision+1 WHERE id=?',[$next,$id]);});
    }
    public function linkResearch(int $id,int $research,int $revision): void
    {
        $p=$this->project($id);if($p['source_kind']==='research')throw new RuntimeException('This project already uses its own research context.');if($research)$this->source('research',$research);
        $this->transaction(function()use($id,$research,$revision):void{$p=$this->project($id);if((int)$p['revision']!==$revision)throw new RuntimeException('Project changed. Refresh before linking research.');$this->execute('UPDATE nasa_projects SET linked_research_id=?,revision=revision+1 WHERE id=?',[$research?:null,$id]);});
    }
    public function createTask(int $id,int $review,int $index): int
    {
        return $this->transaction(function()use($id,$review,$index):int{
            $p=$this->project($id);$finding=$this->finding($id,$review,$index);$old=$this->rows("SELECT target_id FROM nasa_links WHERE project_id=? AND review_id=? AND finding_index=? AND target_kind='task'",[$id,$review,$index]);if($old&&$this->rows('SELECT id FROM tasks WHERE id=?',[$old[0]['target_id']]))return (int)$old[0]['target_id'];
            $target=$p['task_project_id'];if(!$target||!$this->rows('SELECT id FROM projects WHERE id=?',[$target])){$this->execute('INSERT INTO projects(name,brief,created_at)VALUES(?,?,?)',[$p['source']['name'],$p['source']['brief'],gmdate('c')]);$target=(int)$this->db->lastInsertId();$this->execute('UPDATE nasa_projects SET task_project_id=? WHERE id=?',[$target,$id]);}
            $this->execute('INSERT INTO tasks(project_id,title,criteria,created_at)VALUES(?,?,?,?)',[$target,mb_strcut('NASA review: '.$finding['title'],0,160,'UTF-8'),mb_strcut($finding['detail']."\nAcceptance: record the change, supporting evidence and remaining uncertainty.",0,4000,'UTF-8'),gmdate('c')]);$task=(int)$this->db->lastInsertId();
            $this->execute("INSERT INTO events(kind,project_id,task_id,detail,created_at)VALUES('task.proposed',?,?,?,?)",[$target,$task,'Owner created a task from NASA Managed review #'.$review.'. No agent dispatched.',gmdate('c')]);$this->execute("INSERT OR REPLACE INTO nasa_links(project_id,review_id,finding_index,target_kind,target_id)VALUES(?,?,?,'task',?)",[$id,$review,$index,$task]);return $task;
        });
    }
    public function baseline(int $id,string $title,?int $expectedRevision=null): int
    {
        return $this->disciplinedBaseline($id,$title,$expectedRevision);
    }
    public function restoreBaseline(int $id,int $baseline,string $confirmation,int $revision): array
    {
        return $this->disciplinedRestoreBaseline($id,$baseline,$confirmation,$revision);
    }
    private function baselineDeltas(int $id): array
    {
        $out=[];foreach($this->rows('SELECT id FROM nasa_baselines WHERE project_id=? ORDER BY id DESC LIMIT 20',[$id]) as $row){try{$out[$row['id']]=$this->baselineDelta($id,(int)$row['id']);}catch(\Throwable $e){Diagnostics::record('nasa.baseline.inspect_failed',['project_id'=>$id,'baseline_id'=>$row['id']],$e);$out[$row['id']]=['error'=>'Saved baseline failed its integrity or compatibility check; inspect Admin diagnostics.'];}}return $out;
    }
    public function context(int $id): array
    {
        $p=$this->project($id);$out=['knowledge_version'=>NasaKnowledge::VERSION,'installed_code_sha'=>self::installedCodeReference(),'project'=>['name'=>$p['source']['name'],'brief'=>$p['source']['brief'],'phase'=>$p['phase'],'revision'=>$p['revision']],'records'=>$this->items($id),'tasks'=>[],'research'=>[],'systems'=>[],'scope'=>'Saved workspace records only; no repository checkout or live system output inspected.'];
        if($p['task_project_id']){$out['tasks']=$this->rows('SELECT id,title,criteria,status FROM tasks WHERE project_id=? ORDER BY id DESC LIMIT 40',[$p['task_project_id']]);$out['agent_outcomes']=$this->rows('SELECT a.id,a.status,a.finished_at FROM agent_runs a JOIN tasks t ON t.id=a.task_id WHERE t.project_id=? ORDER BY a.id DESC LIMIT 20',[$p['task_project_id']]);}
        $db=$this->research();if($p['linked_research_id']&&$db){$q=$db->prepare('SELECT id,name,brief FROM research_projects WHERE id=?');$q->execute([$p['linked_research_id']]);if($rp=$q->fetch()){$out['research']['project']=$rp;$q=$db->prepare("SELECT n.id,n.type,n.title,n.content,n.origin,n.provenance_tag FROM research_nodes n JOIN research_runs r ON r.id=n.run_id WHERE r.project_id=? AND n.type IN ('dream_claim','model_idea','question','association','counter','reframing','edge_case','synthesis') ORDER BY n.id DESC LIMIT 50");$q->execute([$rp['id']]);$out['research']['ideas']=$q->fetchAll();foreach($out['research']['ideas'] as &$idea)$idea['content']=mb_strcut($idea['content'],0,600,'UTF-8');unset($idea);$q=$db->prepare('SELECT s.id,s.url,s.title,s.domain,s.retrieved_at FROM research_sources s JOIN research_runs r ON r.id=s.run_id WHERE r.project_id=? ORDER BY s.id DESC LIMIT 12');$q->execute([$rp['id']]);$out['research']['sources']=$q->fetchAll();$q=$db->prepare('SELECT p.id,p.source_id,p.exact_text,p.fetch_scope,p.locator FROM research_passages p JOIN research_runs r ON r.id=p.run_id WHERE r.project_id=? ORDER BY p.id DESC LIMIT 12');$q->execute([$rp['id']]);$out['research']['passages']=$q->fetchAll();foreach($out['research']['passages'] as &$passage)$passage['exact_text']=mb_strcut($passage['exact_text'],0,600,'UTF-8');unset($passage);}}
        if(is_file($this->directory.'/systems.sqlite')&&!is_link($this->directory.'/systems.sqlite')&&$p['task_project_id']){require_once __DIR__.'/Systems.php';new Systems($this->directory);$s=new PDO('sqlite:'.$this->directory.'/systems.sqlite');$q=$s->prepare('SELECT DISTINCT s.name,s.risk_level,s.enabled,s.verified_at FROM systems s JOIN system_runs r ON r.system_id=s.id WHERE r.project_id=?');$q->execute([$p['task_project_id']]);$out['systems']=$q->fetchAll(PDO::FETCH_ASSOC);}
        if($p['task_project_id']){$taskState=['tasks'=>$this->rows('SELECT id,title,criteria,status FROM tasks WHERE project_id=? ORDER BY id',[$p['task_project_id']]),'agent_outcomes'=>$this->rows('SELECT a.id,a.task_id,a.status,a.finished_at FROM agent_runs a JOIN tasks t ON t.id=a.task_id WHERE t.project_id=? ORDER BY a.id',[$p['task_project_id']])];$out['task_state_sha256']=hash('sha256',json_encode($taskState,JSON_THROW_ON_ERROR));}
        $out['readiness']=$this->readiness($id);$out['research_state_sha256']=$this->researchState($id)['sha256'];$out['provenance']=[];
        foreach(array_slice($this->provenance($id),0,20) as $link){$snapshot=$link['source_snapshot'];$out['provenance'][]=['item_key'=>$link['item_key'],'item_version'=>$link['item_version'],'source_kind'=>$link['source_kind'],'source_id'=>$link['source_id'],'source_sha256'=>$link['source_sha256'],'reviewed'=>$link['reviewed'],'source_current'=>$link['source_current'],'subject_current'=>$link['subject_current'],'note'=>$link['note'],'title'=>$snapshot['title'],'content'=>$link['source_current']?mb_strcut($snapshot['content'],0,600,'UTF-8'):'Source unavailable or no longer in the current linked scope.'];}
        return $out;
    }
    public function workspace(int $id): array
    {
        $p=$this->project($id);$items=$this->items($id);foreach($items as &$item)if($item['kind']==='requirement'){$item['verification_supported']=$this->hasReviewedEvidence($id,$item['item_key'],$item['title'],$item['content'],$item['data']);$item['verification_confirmed']=$this->isVerifiedRequirement($id,$item);}unset($item);$requests=$this->rows('SELECT id,action,status,model,estimated_usd,reserved_usd,error,created_at FROM nasa_ai_requests WHERE project_id=? ORDER BY id DESC LIMIT 15',[$id]);
        $runs=[];if($p['linked_research_id']&&($db=$this->research())){$q=$db->prepare("SELECT id,phase FROM research_runs WHERE project_id=? AND flow_version>=2 AND status='running' AND phase IN ('exploring','verification_review') AND busy IS NULL ORDER BY id DESC LIMIT 10");$q->execute([$p['linked_research_id']]);$runs=$q->fetchAll();}
        return ['project'=>$p,'items'=>$items,'history'=>$this->rows('SELECT id,kind,item_key,version,status,created_by,created_at,content FROM nasa_items WHERE project_id=? ORDER BY id DESC LIMIT 200',[$id]),'readiness'=>$this->readiness($id),
            'reviews'=>$this->rows('SELECT * FROM nasa_reviews WHERE project_id=? ORDER BY id DESC LIMIT 20',[$id]),'baselines'=>$this->rows('SELECT id,title,sha256,created_at FROM nasa_baselines WHERE project_id=? ORDER BY id DESC LIMIT 20',[$id]),'requests'=>$requests,'proposals'=>$this->proposals($id),'baseline_deltas'=>$this->baselineDeltas($id),'baseline_restores'=>$this->baselineHistory($id),'provenance'=>$this->provenance($id),'research_choices'=>$this->researchChoices($id),'research_origins'=>$this->focusedResearchHistory($id),'sources'=>$this->sources(),'map_runs'=>$runs,'archived'=>array_values(array_filter($this->items($id,true),static fn($i)=>$i['status']==='removed'))];
    }
    public function applyAi(int $id,int $revision,string $action,array $result,?string $contextHash=null): bool
    {
        return $this->transaction(function()use($id,$revision,$action,$result,$contextHash):bool{
            if((int)$this->project($id)['revision']!==$revision||$contextHash!==null&&!hash_equals($contextHash,hash('sha256',json_encode($this->context($id),JSON_THROW_ON_ERROR))))return false;
            if($action==='review'){$this->review($id,'ai_draft',$result);return true;}
            foreach($result['artifacts'] as $a){$old=$this->current($id,'artifact',$a['type']);$this->append($id,'artifact',$a['type'],NasaKnowledge::ARTIFACTS[$a['type']][0],$a['content'],'draft',['reference'=>NasaKnowledge::ARTIFACTS[$a['type']][2]],'ai_draft',(int)($old['version']??0));}
            foreach(['requirement'=>$result['requirements'],'risk'=>$result['risks']] as $kind=>$records){foreach($records as $r){$content=$kind==='requirement'?$r['statement']:$r['condition'];$existing=array_filter($this->items($id),static fn($i)=>$i['kind']===$kind&&mb_strtolower($i['title'])===mb_strtolower($r['title'])&&mb_strtolower($i['content'])===mb_strtolower($content));if($existing)continue;
                $data=$kind==='requirement'?['source'=>$r['source'],'method'=>$r['method'],'evidence'=>'']:['owner'=>$r['owner'],'mitigation'=>$r['mitigation'],'requirement'=>'','artifact'=>'risks','likelihood'=>$r['likelihood'],'consequence'=>$r['consequence'],'residual_likelihood'=>$r['likelihood'],'residual_consequence'=>$r['consequence']];
                $status=$kind==='requirement'?'unverified':'open';$data=$this->validateRecord($id,$kind,$r['title'],$content,$data,$status);$n=(int)$this->rows('SELECT COUNT(DISTINCT item_key) AS n FROM nasa_items WHERE project_id=? AND kind=?',[$id,$kind])[0]['n']+1;
                $this->append($id,$kind,($kind==='requirement'?'REQ':'RISK').'-'.str_pad((string)$n,3,'0',STR_PAD_LEFT),$r['title'],$content,$status,$data,'ai_draft',0);
            }}$this->bump($id);return true;
        });
    }
    public function export(int $id): array
    {
        $s=$this->workspace($id);unset($s['sources']);$s['proposal_history']=$this->proposalHistory($id);$s['provenance_history']=$this->provenance($id,null,null,true);unset($s['research_choices']);$s['artifact_history']=$this->rows('SELECT * FROM nasa_items WHERE project_id=? ORDER BY id',[$id]);$s['ai_results']=$this->rows('SELECT * FROM nasa_ai_requests WHERE project_id=? ORDER BY id',[$id]);return ['format'=>'AIcontrol NASA Managed v1','generated_utc'=>gmdate('c'),'reference'=>['title'=>'NASA/SP-2016-6105 Rev 2','url'=>NasaKnowledge::SOURCE,'knowledge_version'=>NasaKnowledge::VERSION],'planning_only'=>true,'workspace'=>$s];
    }
}
