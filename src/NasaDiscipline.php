<?php
declare(strict_types=1);
namespace AIcontrol;

use PDO;
use RuntimeException;

/** Local configuration snapshots and living interface contracts; no code/server rollback. */
trait NasaDiscipline
{
    private function initDiscipline(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS nasa_baseline_restores (
            id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,
            baseline_id INTEGER NOT NULL REFERENCES nasa_baselines(id) ON DELETE CASCADE,
            from_revision INTEGER NOT NULL, to_revision INTEGER NOT NULL,
            delta_json TEXT NOT NULL, created_at INTEGER NOT NULL
        );');
    }

    private static function installedCodeReference(): ?string
    {
        $sha=getenv('AICONTROL_INSTALLED_SHA');
        return is_string($sha)&&preg_match('/^[a-f0-9]{40}$/D',$sha)?$sha:null;
    }

    private function disciplineResearchSnapshot(PDO $db,array $source): array
    {
        $snapshot=['project'=>$source];
        $shapes=[
            'research_runs'=>['id','project_id','identity_token','status','phase','evidence_index','flow_version','rigour_version','evidence_version','evidence_frozen','verification_status','exploration_first','explore_count'],
            'research_nodes'=>['id','run_id','parent_id','type','status','title','content','sort_order','data_json','selected','path_weight','model_tier','weight_reason','speculative','layer','origin','provenance_tag','park_reason','locked_at','weight','knowledge_line_id'],
            'research_sources'=>['id','run_id','node_id','url','title','extract','domain','meta_json','shared_origin','dependence_flag'],
            'research_passages'=>['id','source_id','run_id','exact_text','locator','context_before','context_after','fetch_scope','content_hash'],
            'research_edges'=>['id','run_id','claim_id','passage_id','source_id','relation','rationale','strength','version'],
            'research_claim_versions'=>['id','claim_id','run_id','status','rationale','evidence_strength','created_by'],
        ];
        foreach($shapes as $table=>$wanted){
            $columns=array_column($db->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
            if(!$columns){$snapshot[$table]=[];continue;}
            $columns=array_values(array_intersect($wanted,$columns));
            $scope=$table==='research_runs'?'project_id=?':'run_id IN (SELECT id FROM research_runs WHERE project_id=?)';
            $q=$db->prepare('SELECT '.implode(',',$columns).' FROM '.$table.' WHERE '.$scope.' ORDER BY id');$q->execute([$source['id']]);
            $snapshot[$table]=$q->fetchAll(PDO::FETCH_ASSOC);
        }
        return $snapshot;
    }

    /** Pure consistent read of explicitly linked and owner-created focused research only. */
    public function researchState(int $id): array
    {
        $project=$this->project($id);$linked=$project['linked_research_id']?(int)$project['linked_research_id']:null;
        $origins=method_exists($this,'focusedResearchHistory')?$this->focusedResearchHistory($id):[];$focused=[];
        foreach($origins as $origin){$key=(int)$origin['research_project_id'].':'.($origin['research_identity']??'');$focused[$key]=['id'=>(int)$origin['research_project_id'],'identity'=>$origin['research_identity']??''];}ksort($focused,SORT_STRING);
        if(!$linked&&!$focused)return ['project_id'=>null,'available'=>false,'sha256'=>null,'snapshot'=>null,'reason'=>'No research project linked or created from planning.'];
        $db=$this->research();
        if(!$db)return ['project_id'=>$linked,'available'=>false,'sha256'=>null,'snapshot'=>null,'reason'=>'Research storage is unavailable.'];
        $db->exec('BEGIN');
        try{
            $projectColumns=array_column($db->query('PRAGMA table_info(research_projects)')->fetchAll(PDO::FETCH_ASSOC),'name');
            $q=$db->prepare('SELECT id,name,brief'.(in_array('identity_token',$projectColumns,true)?',identity_token':'').' FROM research_projects WHERE id=?');
            $snapshot=['project'=>null,'focused_projects'=>[]];$available=false;$selected=[];
            if($linked){$q->execute([$linked]);$source=$q->fetch();if($source){$snapshot=$this->disciplineResearchSnapshot($db,$source)+['focused_projects'=>[]];$available=true;$selected[$linked.':'.($source['identity_token']??'')]=true;}else $snapshot['linked_unavailable']=$linked;}
            foreach($focused as $key=>$origin){
                if(isset($selected[$key]))continue;
                $q->execute([$origin['id']]);$source=$q->fetch();
                if(!$source||$origin['identity']===''||!hash_equals($origin['identity'],$source['identity_token']??'')){$snapshot['focused_projects'][]=['project_id'=>$origin['id'],'identity'=>$origin['identity'],'available'=>false];continue;}
                $snapshot['focused_projects'][]=$this->disciplineResearchSnapshot($db,$source);$available=true;
            }
            $db->exec('COMMIT');
        }catch(\Throwable $error){$db->exec('ROLLBACK');throw $error;}
        $json=json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return ['project_id'=>$linked,'available'=>$available,'sha256'=>hash('sha256',$json),'snapshot'=>$snapshot,'reason'=>'Saved linked/focused research content only; excludes camera, focus timestamps, usage and request heartbeats.'];
    }

    public function disciplinedBaseline(int $id,string $title,?int $expectedRevision=null): int
    {
        $title=self::text($title,160);
        return $this->transaction(function()use($id,$title,$expectedRevision):int{
            $project=$this->project($id);$revision=(int)$project['revision'];
            if($expectedRevision!==null&&$revision!==$expectedRevision)throw new RuntimeException('Project changed. Refresh and review the current revision before saving a baseline.');
            $review=$this->rows("SELECT id FROM nasa_reviews WHERE project_id=? AND revision=? AND created_by='local' ORDER BY id DESC LIMIT 1",[$id,$revision])[0]??null;
            if(!$review)throw new RuntimeException('Record an explicit local review of the current revision before saving a baseline. AI advice cannot authorise a baseline.');
            $snapshot=['format'=>'AIcontrol planning baseline v2','phase'=>$project['phase'],'revision'=>$revision,'knowledge_version'=>NasaKnowledge::VERSION,'local_review_id'=>(int)$review['id'],'items'=>$this->items($id),'research'=>$this->researchState($id),'code_sha'=>self::installedCodeReference(),'provenance'=>method_exists($this,'provenance')?$this->provenance($id):[]];
            $json=json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $this->execute('INSERT INTO nasa_baselines(project_id,title,snapshot_json,sha256,created_at)VALUES(?,?,?,?,?)',[$id,$title,$json,hash('sha256',$json),time()]);
            return (int)$this->db->lastInsertId();
        });
    }

    private function disciplineBaseline(int $id,int $baseline): array
    {
        $row=$this->rows('SELECT * FROM nasa_baselines WHERE id=? AND project_id=?',[$baseline,$id])[0]??throw new RuntimeException('Choose a baseline from this project.');
        if(!hash_equals($row['sha256'],hash('sha256',$row['snapshot_json'])))throw new RuntimeException('Baseline integrity check failed.');
        $state=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($state)||!is_string($state['phase']??null)||!isset(NasaKnowledge::phases()[$state['phase']])||!is_array($state['items']??null)||!array_is_list($state['items']))throw new RuntimeException('Baseline contents could not be read. No records changed.');
        $identities=[];
        foreach($state['items'] as $item){
            if(!is_array($item)||!is_string($item['kind']??null)||!is_string($item['item_key']??null)||!is_string($item['status']??null)||!is_array($item['data']??null)||!is_int($item['version']??null)||$item['version']<1||isset($item['project_id'])&&(!is_int($item['project_id'])||$item['project_id']!==$id))throw new RuntimeException('Baseline record contents or project scope are invalid. No records changed.');
            $kinds=['artifact'=>['draft','reviewed','baseline'],'requirement'=>['unverified','verified'],'risk'=>['open','mitigated','accepted','closed'],'tpm'=>['recorded'],'decision'=>['proposed','decided'],'tailoring'=>['recorded'],'interface'=>['draft','reviewed']];
            if(!isset($kinds[$item['kind']])||!in_array($item['status'],$kinds[$item['kind']],true))throw new RuntimeException('Baseline record state is invalid. No records changed.');
            if($item['kind']==='artifact'){$keyValid=isset(NasaKnowledge::ARTIFACTS[$item['item_key']]);}
            else{$prefix=['requirement'=>'REQ','risk'=>'RISK','tpm'=>'TPM','decision'=>'DEC','tailoring'=>'TAIL','interface'=>'IF'][$item['kind']];$keyValid=(bool)preg_match('/^'.$prefix.'-[0-9]{3,10}$/D',$item['item_key']);}
            $identity=self::disciplineItemIdentity($item);
            if(!$keyValid||isset($identities[$identity]))throw new RuntimeException('Baseline record identities are invalid or duplicated. No records changed.');
            $identities[$identity]=true;self::text($item['title']??null,160);self::text($item['content']??null,$item['kind']==='artifact'?12000:3000);
            if($item['kind']==='requirement'&&!in_array($item['data']['method']??'', ['test','analysis','inspection','demonstration'],true))throw new RuntimeException('Baseline requirement verification method is invalid. No records changed.');
            if($item['kind']==='interface')$this->validateInterfaceRecord($item['data'],$item['status']);
        }
        if(array_key_exists('research',$state)){
            $research=$state['research'];if(!is_array($research)||isset($research['sha256'])&&(!is_string($research['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$research['sha256']))||isset($research['project_id'])&&(!is_int($research['project_id'])||$research['project_id']<1))throw new RuntimeException('Baseline research metadata is invalid. No records changed.');
        }
        if(isset($state['provenance'])&&(!is_array($state['provenance'])||!array_is_list($state['provenance'])))throw new RuntimeException('Baseline provenance metadata is invalid. No records changed.');
        return [$row,$state];
    }

    private static function disciplineItemIdentity(array $item): string {return $item['kind'].':'.$item['item_key'];}
    private static function disciplineItemHash(array $item): string
    {
        return hash('sha256',json_encode([$item['title'],$item['content'],$item['status'],$item['data']??json_decode($item['data_json']??'{}',true)],JSON_THROW_ON_ERROR));
    }

    public function baselineDelta(int $id,int $baseline): array
    {
        $project=$this->project($id);[$row,$state]=$this->disciplineBaseline($id,$baseline);
        $wanted=[];foreach($state['items'] as $item)$wanted[self::disciplineItemIdentity($item)]=$item;
        $current=[];foreach($this->items($id) as $item)$current[self::disciplineItemIdentity($item)]=$item;
        $added=[];$removed=[];$changed=[];
        foreach($current as $key=>$item){$short=['kind'=>$item['kind'],'item_key'=>$item['item_key'],'title'=>$item['title'],'current_version'=>(int)$item['version']];if(!isset($wanted[$key]))$added[]=$short;elseif(self::disciplineItemHash($item)!==self::disciplineItemHash($wanted[$key]))$changed[]=$short+['baseline_version'=>(int)$wanted[$key]['version']];}
        foreach($wanted as $key=>$item)if(!isset($current[$key]))$removed[]=['kind'=>$item['kind'],'item_key'=>$item['item_key'],'title'=>$item['title'],'baseline_version'=>(int)$item['version']];
        $research=$this->researchState($id);$saved=$state['research']??null;$savedHash=is_array($saved)?($saved['sha256']??null):null;
        $links=method_exists($this,'provenance')?$this->provenance($id):[];
        $provenanceKnown=array_key_exists('provenance',$state);
        return [
            'baseline_id'=>$baseline,'title'=>$row['title'],'baseline_revision'=>(int)($state['revision']??0),'current_revision'=>(int)$project['revision'],
            'phase'=>['baseline'=>$state['phase'],'current'=>$project['phase']],
            'planning'=>['added_since_baseline'=>$added,'missing_since_baseline'=>$removed,'changed_since_baseline'=>$changed],
            'research'=>['baseline_project_id'=>is_array($saved)?($saved['project_id']??null):null,'current_project_id'=>$research['project_id'],'baseline_sha256'=>$savedHash,'current_sha256'=>$research['sha256'],'baseline_recorded'=>$saved!==null,'changed'=>$saved===null?null:($savedHash!==$research['sha256']||($saved['project_id']??null)!==$research['project_id']),'restored'=>false],
            'provenance'=>['baseline_recorded'=>$provenanceKnown,'baseline_count'=>count($state['provenance']??[]),'current_count'=>count($links),'changed'=>$provenanceKnown?json_encode($state['provenance'],JSON_THROW_ON_ERROR)!==json_encode($links,JSON_THROW_ON_ERROR):null,'restored'=>false],
            'code'=>['baseline_sha'=>$state['code_sha']??null,'current_sha'=>self::installedCodeReference(),'restored'=>false],
        ];
    }

    /** Restore planning only; evidence reviews/research/code stay as recorded, and requirements lose verification. */
    public function disciplinedRestoreBaseline(int $id,int $baseline,string $confirmation,int $revision): array
    {
        if(!hash_equals('RESTORE BASELINE '.$baseline,trim($confirmation)))throw new RuntimeException('Type the exact baseline confirmation. No records changed.');
        return $this->transaction(function()use($id,$baseline,$revision):array{
            $project=$this->project($id);if((int)$project['revision']!==$revision)throw new RuntimeException('Project changed. Refresh before restoring.');
            [$row,$state]=$this->disciplineBaseline($id,$baseline);$delta=$this->baselineDelta($id,$baseline);$wanted=[];
            foreach($state['items'] as $item){
                if(!is_array($item)||!in_array($item['kind']??'', ['artifact','requirement','risk','tpm','decision','tailoring','interface'],true)||!is_string($item['item_key']??null)||!is_string($item['title']??null)||!is_string($item['content']??null)||!is_string($item['status']??null)||!is_array($item['data']??null))throw new RuntimeException('Baseline record contents are invalid. No records changed.');
                $wanted[self::disciplineItemIdentity($item)]=$item;
            }
            foreach($this->items($id,true) as $item)if(!isset($wanted[self::disciplineItemIdentity($item)])&&$item['status']!=='removed')$this->append($id,$item['kind'],$item['item_key'],$item['title'],$item['content'],'removed',$item['data'],'owner_baseline_restore',(int)$item['version']);
            foreach($wanted as $item){$current=$this->current($id,$item['kind'],$item['item_key']);$status=$item['kind']==='requirement'?'unverified':$item['status'];$data=$item['data'];if($item['kind']==='requirement')unset($data['confirm_verified'],$data['verification_confirmed'],$data['owner_confirmation']);$this->append($id,$item['kind'],$item['item_key'],$item['title'],$item['content'],$status,$data,'owner_baseline_restore',(int)($current['version']??0));}
            $this->execute('UPDATE nasa_projects SET phase=?,revision=revision+1 WHERE id=?',[$state['phase'],$id]);
            $this->execute('INSERT INTO nasa_baseline_restores(project_id,baseline_id,from_revision,to_revision,delta_json,created_at)VALUES(?,?,?,?,?,?)',[$id,$baseline,$revision,$revision+1,json_encode($delta,JSON_THROW_ON_ERROR),time()]);
            return $delta;
        });
    }

    public function baselineHistory(int $id): array
    {
        $this->project($id);$rows=$this->rows('SELECT * FROM nasa_baseline_restores WHERE project_id=? ORDER BY id DESC LIMIT 20',[$id]);
        foreach($rows as &$row)$row['delta']=json_decode($row['delta_json'],true,64,JSON_THROW_ON_ERROR);unset($row);return $rows;
    }

    private function validateInterfaceRecord(array $data,string $status): array
    {
        if(!in_array($status,['draft','reviewed'],true))throw new RuntimeException('Choose draft or owner-reviewed interface state.');
        $out=[];foreach(['side_a'=>160,'side_b'=>160,'payload'=>2000,'errors'=>2000,'auth'=>2000,'owner'=>160,'change_rule'=>1000] as $key=>$limit)$out[$key]=self::text($data[$key]??'',$limit);
        return $out;
    }
}
