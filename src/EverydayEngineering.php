<?php
declare(strict_types=1);
namespace AIcontrol;

use RuntimeException;

/** Everyday planning is owner-adopted memory, never an autonomous executor or approval channel. */
trait EverydayEngineering
{
    public function initEveryday(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS engineering_profile_versions (
            id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,
            version INTEGER NOT NULL,mode TEXT NOT NULL CHECK(mode IN ("everyday","rigorous")),
            reason TEXT NOT NULL,exposure TEXT NOT NULL,revisit TEXT NOT NULL,created_by TEXT NOT NULL,created_at INTEGER NOT NULL,
            UNIQUE(project_id,version));
            CREATE TABLE IF NOT EXISTS engineering_object_versions (
            id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,
            object_key TEXT NOT NULL,kind TEXT NOT NULL CHECK(kind IN ("intent","agreement","pathway","cycle","commitment")),
            version INTEGER NOT NULL,title TEXT NOT NULL,content TEXT NOT NULL,state TEXT NOT NULL,
            payload_json TEXT NOT NULL,origin_json TEXT NOT NULL,created_by TEXT NOT NULL,created_at INTEGER NOT NULL,
            UNIQUE(project_id,object_key,version));
            CREATE INDEX IF NOT EXISTS engineering_object_current ON engineering_object_versions(project_id,object_key,version);
            CREATE TABLE IF NOT EXISTS engineering_link_versions (
            id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,
            link_key TEXT NOT NULL,version INTEGER NOT NULL,from_ref TEXT NOT NULL,to_ref TEXT NOT NULL,
            relation TEXT NOT NULL,status TEXT NOT NULL,payload_json TEXT NOT NULL,created_by TEXT NOT NULL,created_at INTEGER NOT NULL,
            UNIQUE(project_id,link_key,version));
            CREATE TABLE IF NOT EXISTS engineering_proposal_versions (
            id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,
            proposal_key TEXT NOT NULL,version INTEGER NOT NULL,operation TEXT NOT NULL,request_id INTEGER NOT NULL,
            base_revision INTEGER NOT NULL,profile_version INTEGER NOT NULL,context_hash TEXT NOT NULL,
            manifest_json TEXT NOT NULL,payload_json TEXT NOT NULL,status TEXT NOT NULL,
            disposition_json TEXT NOT NULL,created_by TEXT NOT NULL,created_at INTEGER NOT NULL,
            UNIQUE(project_id,proposal_key,version));
            CREATE TABLE IF NOT EXISTS engineering_cycle_events (
            id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,
            cycle_key TEXT NOT NULL,cycle_version INTEGER NOT NULL,event TEXT NOT NULL,reason TEXT NOT NULL,
            configuration_json TEXT NOT NULL,configuration_sha256 TEXT NOT NULL,dispositions_json TEXT NOT NULL,
            created_by TEXT NOT NULL,created_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS engineering_result_versions (
            id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,
            result_key TEXT NOT NULL,version INTEGER NOT NULL,commitment_key TEXT NOT NULL,commitment_version INTEGER NOT NULL,
            work_state TEXT NOT NULL,origin TEXT NOT NULL,source TEXT NOT NULL,scope TEXT NOT NULL,
            observation TEXT NOT NULL,limitations TEXT NOT NULL,configuration_ref TEXT,
            snapshot_json TEXT NOT NULL,created_by TEXT NOT NULL,created_at INTEGER NOT NULL,
            UNIQUE(project_id,result_key,version));');
    }
    private static function engineeringJson(array $data): string {return json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    public function engineeringProfile(int $id): array
    {
        $this->project($id);$profile=$this->rows('SELECT * FROM engineering_profile_versions WHERE project_id=? ORDER BY version DESC LIMIT 1',[$id])[0]??null;
        return $profile?:['project_id'=>$id,'version'=>0,'mode'=>'rigorous','reason'=>'Existing NASA Managed workspace; no Everyday mode was inferred.','exposure'=>'Everyday exposure has not been assessed.','revisit'=>'An owner may explicitly choose another operating mode.','created_by'=>'legacy_preserved','legacy'=>true];
    }
    private function engineeringAppendProfile(int $id,int $expected,string $mode,string $reason,string $exposure,string $revisit): void
    {
        if((int)$this->engineeringProfile($id)['version']!==$expected)throw new RuntimeException('Operating profile changed. Refresh before choosing a mode.');
        $this->execute('INSERT INTO engineering_profile_versions(project_id,version,mode,reason,exposure,revisit,created_by,created_at)VALUES(?,?,?,?,?,?,?,?)',[$id,$expected+1,$mode,$reason,$exposure,$revisit,'owner',time()]);
    }
    private function engineeringObject(int $id,string $key,?int $version=null): ?array
    {
        if(!preg_match('/^(INT|AGR|PATH|CYC|COM)-[0-9]{3,10}$/D',$key))throw new RuntimeException('Choose an operating record from this project.');
        $row=$version===null?($this->rows('SELECT * FROM engineering_object_versions WHERE project_id=? AND object_key=? ORDER BY version DESC LIMIT 1',[$id,$key])[0]??null):($this->rows('SELECT * FROM engineering_object_versions WHERE project_id=? AND object_key=? AND version=?',[$id,$key,$version])[0]??null);
        return $row?$this->engineeringDecodeObject($row):null;
    }
    private function engineeringDecodeObject(array $row): array
    {
        $row['key']=$row['object_key'];$row['payload']=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$row['origin']=json_decode($row['origin_json'],true,64,JSON_THROW_ON_ERROR);$row['ref']=$row['kind'].':'.$row['object_key'].':v'.$row['version'];return $row;
    }
    private function engineeringAppendObject(int $id,string $key,string $kind,string $title,string $content,string $state,array $payload,array $origin,string $by,int $expected): int
    {
        $current=$this->engineeringObject($id,$key);if((int)($current['version']??0)!==$expected)throw new RuntimeException('Operating record changed. Earlier versions are retained; refresh before saving.');
        $this->execute('INSERT INTO engineering_object_versions(project_id,object_key,kind,version,title,content,state,payload_json,origin_json,created_by,created_at)VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,$key,$kind,$expected+1,self::text($title,160),self::text($content,$kind==='intent'?5000:3000),$state,self::engineeringJson($payload),self::engineeringJson($origin),$by,time()]);return (int)$this->db->lastInsertId();
    }
    public function engineeringState(int $id,bool $history=false): array
    {
        $this->project($id);$rows=$this->rows('SELECT o.* FROM engineering_object_versions o WHERE o.project_id=? AND NOT EXISTS(SELECT 1 FROM engineering_object_versions n WHERE n.project_id=o.project_id AND n.object_key=o.object_key AND n.version>o.version) ORDER BY o.kind,o.object_key',[$id]);
        $objects=array_map(fn($row)=>$this->engineeringDecodeObject($row),$rows);$groups=array_fill_keys(['intent','agreement','pathway','cycle','commitment'],[]);$active=null;
        foreach($objects as $object){$groups[$object['kind']][]=$object;if($object['kind']==='cycle'&&in_array($object['state'],['active','in_review','paused'],true)&&(!$active||(['active'=>3,'in_review'=>3,'paused'=>1][$object['state']]>(['active'=>3,'in_review'=>3,'paused'=>1][$active['state']]))))$active=$object;}
        $links=$this->rows('SELECT l.* FROM engineering_link_versions l WHERE l.project_id=? AND NOT EXISTS(SELECT 1 FROM engineering_link_versions n WHERE n.project_id=l.project_id AND n.link_key=l.link_key AND n.version>l.version) ORDER BY l.link_key',[$id]);foreach($links as &$link)$link['payload']=json_decode($link['payload_json'],true,64,JSON_THROW_ON_ERROR);unset($link);
        $results=$this->rows('SELECT r.* FROM engineering_result_versions r WHERE r.project_id=? AND NOT EXISTS(SELECT 1 FROM engineering_result_versions n WHERE n.project_id=r.project_id AND n.result_key=r.result_key AND n.version>r.version) ORDER BY r.id DESC',[$id]);foreach($results as &$result)$result['snapshot']=json_decode($result['snapshot_json'],true,64,JSON_THROW_ON_ERROR);unset($result);
        $state=['profile'=>$this->engineeringProfile($id),'objects'=>$objects,'groups'=>$groups,'active_cycle'=>$active,'links'=>$links,'results'=>$results,'cycle_events'=>$this->rows('SELECT * FROM engineering_cycle_events WHERE project_id=? ORDER BY id DESC',[$id])];
        if($history){$state['profile_history']=$this->rows('SELECT * FROM engineering_profile_versions WHERE project_id=? ORDER BY version',[$id]);$state['object_history']=array_map(fn($row)=>$this->engineeringDecodeObject($row),$this->rows('SELECT * FROM engineering_object_versions WHERE project_id=? ORDER BY id',[$id]));$state['link_history']=$this->rows('SELECT * FROM engineering_link_versions WHERE project_id=? ORDER BY id',[$id]);$state['result_history']=$this->rows('SELECT * FROM engineering_result_versions WHERE project_id=? ORDER BY id',[$id]);}
        return $state;
    }
    public function engineeringContext(int $id): array
    {
        $state=$this->engineeringState($id);$out=['profile'=>array_intersect_key($state['profile'],array_flip(['version','mode','reason','exposure','revisit'])),'objects'=>[],'links'=>[],'results'=>[],'cycle_configurations'=>[]];
        foreach($state['objects'] as $object)$out['objects'][]=array_intersect_key($object,array_flip(['kind','key','version','title','content','state','payload','ref']));
        foreach($state['links'] as $link)$out['links'][]=array_intersect_key($link,array_flip(['link_key','version','from_ref','to_ref','relation','status','payload']));
        foreach($state['results'] as $result)$out['results'][]=array_intersect_key($result,array_flip(['result_key','version','commitment_key','commitment_version','work_state','origin','source','scope','observation','limitations','configuration_ref','snapshot']));
        foreach($state['cycle_events'] as $event)$out['cycle_configurations'][]=array_intersect_key($event,array_flip(['id','cycle_key','cycle_version','event','reason','configuration_sha256','dispositions_json']));
        $out['full_state_sha256']=hash('sha256',self::engineeringJson($out));$current=$state['active_cycle'];if(!$current)foreach($state['groups']['cycle'] as $cycle)if($cycle['state']==='planned'&&($cycle['payload']['commitment_refs']??[])){$current=$cycle;break;}if(!$current)foreach($state['groups']['cycle'] as $cycle)if($cycle['state']==='planned'){$current=$cycle;break;}$activeWork=[];foreach($current['payload']['commitment_refs']??[] as $ref)$activeWork[explode(':',$ref)[1]??'']=true;
        $rank=static fn($row)=>match($row['kind']){'intent'=>0,'agreement'=>1,'pathway'=>2,'cycle'=>$row['key']===($current['key']??null)?3:($row['state']==='planned'?5:8),'commitment'=>isset($activeWork[$row['key']])?4:(in_array($row['state'],['planned','reported_in_progress','blocked'],true)?6:9)};
        usort($out['objects'],static fn($a,$b)=>($rank($a)<=>$rank($b))?:strnatcmp($a['key'],$b['key']));$coverage=[];
        foreach(['objects'=>20,'links'=>60,'results'=>8,'cycle_configurations'=>10] as $category=>$limit){$total=count($out[$category]);$out[$category]=array_slice($out[$category],0,$limit);$coverage[]=['category'=>'engineering_'.$category,'included'=>count($out[$category]),'total'=>$total,'omitted'=>max(0,$total-$limit),'summary'=>'Current intent, selected cycle and its commitments are prioritised; omitted records remain in the full state hash and history.'];}
        foreach($out['results'] as &$report){$report['observation_excerpted']=strlen($report['observation'])>1200;$report['observation']=mb_strcut($report['observation'],0,1200,'UTF-8');$report['limitations']=mb_strcut($report['limitations'],0,800,'UTF-8');if(isset($report['snapshot']['expected_behaviour'])){foreach($report['snapshot']['expected_behaviour'] as &$expected)$expected['statement']=mb_strcut($expected['statement'],0,500,'UTF-8');unset($expected);}}unset($report);
        foreach($out['cycle_configurations'] as &$event){$event['dispositions_excerpted']=strlen($event['dispositions_json'])>1200;$event['dispositions_json']=mb_strcut($event['dispositions_json'],0,1200,'UTF-8');}unset($event);$out['coverage']=$coverage;
        return $out;
    }
    private function engineeringSeedDefaults(int $id,array $project): void
    {
        if(!$this->engineeringObject($id,'INT-001'))$this->engineeringAppendObject($id,'INT-001','intent','Project intent',$project['source']['brief'],'adopted',['users'=>['Not yet specified by the owner.'],'problem'=>$project['source']['brief'],'outcome'=>$project['source']['brief'],'boundaries'=>'Clarify scope before adopting a delivery cycle.','constraints'=>[],'non_goals'=>[]],['source'=>'owner_selected_project_brief'],'owner',0);
        if(!$this->engineeringObject($id,'AGR-001'))$this->engineeringAppendObject($id,'AGR-001','agreement','Working agreement','The owner chooses commitments and reviews evidence; AI proposes drafts only.','adopted',['responsibilities'=>'The owner decides; collaborators propose and report with explicit source.','review_rhythm'=>'Review each chosen cycle before closure or replan.','evidence_rule'=>'Separate reported work, captured evidence, owner review and explicit verification.','change_rule'=>'Review exact changed versions and retain unresolved obligations.','release_rule'=>'Publishing and running work require their existing explicit authorisation.'],['source'=>'owner_started_everyday_defaults'],'owner',0);
    }
    public function startEveryday(int $id,int $revision): void
    {
        $this->transaction(function()use($id,$revision):void{
            $project=$this->project($id);if((int)$project['revision']!==$revision)throw new RuntimeException('Project changed. Refresh before starting Everyday work.');
            $profile=$this->engineeringProfile($id);if($profile['mode']==='everyday')return;
            $this->engineeringAppendProfile($id,(int)$profile['version'],'everyday','Owner explicitly chose Everyday work.','Unassessed exposure and existing formal obligations remain recorded.','Revisit when consequences, interfaces, evidence or release constraints change.');
            $this->engineeringSeedDefaults($id,$project);
            $this->bump($id);
        });
    }
    public function setEngineeringMode(int $id,string $mode,string $reason,string $exposure,string $revisit,int $revision): void
    {
        if(!in_array($mode,['everyday','rigorous'],true))throw new RuntimeException('Choose Everyday or NASA Managed.');$reason=self::text($reason,2000);$exposure=self::text($exposure,2000);$revisit=self::text($revisit,1000);
        $this->transaction(function()use($id,$mode,$reason,$exposure,$revisit,$revision):void{$project=$this->project($id);if((int)$project['revision']!==$revision)throw new RuntimeException('Project changed. Refresh before changing operating mode.');$profile=$this->engineeringProfile($id);$this->engineeringAppendProfile($id,(int)$profile['version'],$mode,$reason,$exposure,$revisit);if($mode==='everyday')$this->engineeringSeedDefaults($id,$project);$this->bump($id);});
    }
    private static function engineeringKeys(mixed $value,array $keys): array
    {
        if(!is_array($value)||array_is_list($value)||array_diff(array_keys($value),$keys)||array_diff($keys,array_keys($value)))throw new RuntimeException('Everyday proposal fields do not match the bounded draft format.');return $value;
    }
    private static function engineeringText(mixed $value,int $max=1200,bool $empty=false): void
    {
        self::text($value,$max*4,$empty);if(mb_strlen($value,'UTF-8')>$max)throw new RuntimeException('Everyday proposal text exceeds its field limit.');
    }
    private static function engineeringList(mixed $value,int $max,callable $validate): void
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>$max)throw new RuntimeException('Everyday proposal list exceeds its bounded format.');foreach($value as $entry)$validate($entry);
    }
    private static function engineeringRefs(mixed $value,int $max=20): void
    {
        self::engineeringList($value,$max,static function($ref):void{if(!is_string($ref)||!preg_match('/^(new:[a-z][a-z0-9_-]{0,39}|(intent|agreement|pathway|cycle|commitment|requirement|risk|decision|interface|artifact|tpm|tailoring|profile|check|result|evidence|configuration):[A-Za-z0-9_-]{1,50}:v[1-9][0-9]{0,9})$/D',$ref))throw new RuntimeException('Use an exact saved record version or declared local draft reference.');});
        if(count(array_unique($value))!==count($value))throw new RuntimeException('List each exact reference only once.');
    }
    public static function everydayPrinciples(): array
    {
        return ['intent'=>'4.1 / Appendix S','requirements'=>'4.2 / Appendix C','logical_decomposition'=>'4.3','recursion_iteration'=>'2.1','planning'=>'6.1 / Appendix J','requirements_management'=>'6.2','interface_management'=>'6.3 / Appendix L','risk_management'=>'6.4','verification'=>'5.3 / Appendix D','validation'=>'5.4 / Appendix E','decision_analysis'=>'6.8','configuration_management'=>'6.5 / Appendix M','technical_data_management'=>'6.6','technical_assessment'=>'6.7','product_maturity'=>'3.0 / Table 3.0-1','tailoring'=>'3.11'];
    }
    private static function engineeringPrinciples(mixed $value): void
    {
        self::engineeringList($value,8,static function($key):void{if(!is_string($key)||!isset(self::everydayPrinciples()[$key]))throw new RuntimeException('Choose a supplied handbook principle; references are resolved by the server.');});
    }
    /** Strict second validation also applies to owner-edited proposals; no authoritative model fields exist. */
    public static function validateEverydayEnvelope(array $envelope,string $operation): array
    {
        if(!in_array($operation,['pathway','cycle','posture','drift','formality'],true))throw new RuntimeException('Choose a supported Everyday proposal operation.');
        self::engineeringKeys($envelope,['headline','next_move','confidence_gap','assumptions','unknowns','owner_decisions','payload']);self::engineeringText($envelope['headline'],300);self::engineeringText($envelope['confidence_gap'],2000);
        $move=self::engineeringKeys($envelope['next_move'],['summary','why_now','record_refs','principle_ids']);self::engineeringText($move['summary'],1200);self::engineeringText($move['why_now'],1600);self::engineeringRefs($move['record_refs']);self::engineeringPrinciples($move['principle_ids']);
        foreach(['assumptions','unknowns'] as $key)self::engineeringList($envelope[$key],8,static fn($v)=>self::engineeringText($v,1000));
        self::engineeringList($envelope['owner_decisions'],8,static function($v):void{self::engineeringKeys($v,['question','consequence']);self::engineeringText($v['question'],1000);self::engineeringText($v['consequence'],1200);});
        $bundle=self::engineeringKeys($envelope['payload'],['objects','promises','risks','decisions','checks','findings','formality']);$ids=[];$kinds=array_fill_keys(['intent','agreement','pathway','cycle','commitment'],0);
        $local=static function($value)use(&$ids):void{if(!is_string($value)||!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$value)||isset($ids[$value]))throw new RuntimeException('Local draft IDs must be unique across the whole bundle.');$ids[$value]=true;};
        self::engineeringList($bundle['objects'],14,static function($object)use(&$kinds,$local):void{
            self::engineeringKeys($object,['local_id','kind','title','content','record_refs','principle_ids','details']);$local($object['local_id']);
            if(!is_string($object['kind'])||!array_key_exists($object['kind'],$kinds))throw new RuntimeException('Choose a known operating object type.');$kinds[$object['kind']]++;
            self::engineeringText($object['title'],160);self::engineeringText($object['content'],3000);self::engineeringRefs($object['record_refs']);self::engineeringPrinciples($object['principle_ids']);$d=$object['details'];
            if($object['kind']==='intent'){self::engineeringKeys($d,['users','problem','outcome','boundaries','constraints','non_goals']);foreach(['users','constraints','non_goals'] as $key)self::engineeringList($d[$key],8,static fn($v)=>self::engineeringText($v,700));foreach(['problem','outcome','boundaries'] as $key)self::engineeringText($d[$key],1600,$key==='boundaries');}
            elseif($object['kind']==='agreement'){self::engineeringKeys($d,['responsibilities','review_rhythm','evidence_rule','change_rule','release_rule']);foreach($d as $v)self::engineeringText($v,1600);}
            elseif($object['kind']==='pathway'){self::engineeringKeys($d,['significant_outcome','why_route','cycle_refs','dependencies','uncertainties']);self::engineeringText($d['significant_outcome'],1600);self::engineeringText($d['why_route'],1600);self::engineeringRefs($d['cycle_refs'],6);self::engineeringList($d['uncertainties'],8,static fn($v)=>self::engineeringText($v,1000));self::engineeringList($d['dependencies'],12,static function($v):void{self::engineeringKeys($v,['cycle_ref','depends_on']);self::engineeringRefs([$v['cycle_ref']],1);self::engineeringRefs($v['depends_on'],6);});}
            elseif($object['kind']==='cycle'){self::engineeringKeys($d,['outcome','scope','exclusions','entry_questions','success_questions','commitment_refs','dependency_refs']);foreach(['outcome','scope','exclusions'] as $key)self::engineeringText($d[$key],1600,$key==='exclusions');foreach(['entry_questions','success_questions'] as $key)self::engineeringList($d[$key],8,static fn($v)=>self::engineeringText($v,800));self::engineeringRefs($d['commitment_refs'],10);self::engineeringRefs($d['dependency_refs'],8);}
            else{self::engineeringKeys($d,['outcome','scope','promise_refs','risk_refs','decision_refs','check_refs','dependency_refs']);foreach(['outcome','scope'] as $key)self::engineeringText($d[$key],1600);foreach(['promise_refs','risk_refs','decision_refs','check_refs','dependency_refs'] as $key)self::engineeringRefs($d[$key],10);}
        });
        foreach($kinds as $kind=>$count)if($count>(['intent'=>1,'agreement'=>1,'pathway'=>1,'cycle'=>6,'commitment'=>5][$kind]))throw new RuntimeException('The Everyday bundle contains too many '.$kind.' objects.');
        if($operation==='pathway'&&($kinds['pathway']!==1||$kinds['cycle']<3||$kinds['cycle']>6))throw new RuntimeException('A pathway proposal needs one pathway and three to six forecast cycles.');
        if($operation==='cycle'&&$kinds['cycle']!==1)throw new RuntimeException('A cycle proposal needs exactly one bounded cycle.');
        self::engineeringList($bundle['promises'],5,static function($v)use($local):void{self::engineeringKeys($v,['local_id','title','statement','source','method','required_scope']);$local($v['local_id']);foreach(['title'=>160,'statement'=>3000,'source'=>1000] as $key=>$max)self::engineeringText($v[$key],$max);if(!in_array($v['method'],['test','analysis','inspection','demonstration'],true))throw new RuntimeException('Every promise needs a verification method.');if(!in_array($v['required_scope'],['unknown','fixture','local','live_provider','hosted','manual'],true))throw new RuntimeException('Choose a proposed evidence scope; unknown remains unverified.');});
        self::engineeringList($bundle['risks'],5,static function($v)use($local):void{self::engineeringKeys($v,['local_id','title','condition','owner','response','category','exposure']);$local($v['local_id']);foreach(['title'=>160,'condition'=>3000,'owner'=>160,'response'=>2000,'exposure'=>1600] as $key=>$max)self::engineeringText($v[$key],$max);if(!in_array($v['category'],['hallucinated_evidence','requirement_drift','overconfidence','context_loss','interface_mismatch','unverifiable_claim','other'],true))throw new RuntimeException('Choose a supplied uncertainty category.');});
        self::engineeringList($bundle['decisions'],5,static function($v)use($local):void{self::engineeringKeys($v,['local_id','title','rationale','alternatives','revisit']);$local($v['local_id']);foreach(['title'=>160,'rationale'=>3000,'alternatives'=>2000,'revisit'=>1000] as $key=>$max)self::engineeringText($v[$key],$max);});
        self::engineeringList($bundle['checks'],8,static function($v)use($local):void{self::engineeringKeys($v,['local_id','title','promise_refs','observation','contradiction','method','configuration','scope','limitations']);$local($v['local_id']);self::engineeringRefs($v['promise_refs'],5);foreach(['title'=>160,'observation'=>1600,'contradiction'=>1600,'configuration'=>1000,'limitations'=>1600] as $key=>$max)self::engineeringText($v[$key],$max);if(!in_array($v['method'],['test','analysis','inspection','demonstration'],true)||!in_array($v['scope'],['synthetic_fixture','local_test','live_provider','hosted_test','user_observation','other','unknown'],true))throw new RuntimeException('Choose a valid planned-check method and scope.');});
        self::engineeringList($bundle['findings'],8,static function($v):void{self::engineeringKeys($v,['kind','old_refs','new_refs','observation','consequence','uncertainty','proposed_action']);if(!in_array($v['kind'],['ai_concern','missing_context'],true))throw new RuntimeException('Model findings are concerns or missing context, not system authority.');self::engineeringRefs($v['old_refs']);self::engineeringRefs($v['new_refs']);foreach(['observation','consequence','uncertainty','proposed_action'] as $key)self::engineeringText($v[$key],1600);});
        self::engineeringList($bundle['formality'],3,static function($v):void{self::engineeringKeys($v,['recommendation','control','benefit','burden','residual_exposure','revisit']);if(!in_array($v['recommendation'],['retain','increase','reduce'],true))throw new RuntimeException('Model formality changes remain recommendations.');foreach(['control','benefit','burden','residual_exposure','revisit'] as $key)self::engineeringText($v[$key],1600);});
        if($operation==='formality'&&!$bundle['formality'])throw new RuntimeException('A formality review needs a concrete control recommendation.');
        if(strlen(self::engineeringJson($envelope))>10000)throw new RuntimeException('One Everyday proposal is too large for a bounded owner review.');return $envelope;
    }
    public function everydayContextHash(int $id): string {return $this->collaborationContextHash($id);}
    public function everydayContext(int $id): array
    {
        return $this->transaction(fn()=> $this->compileEverydayContext($id));
    }
    private function compileEverydayContext(int $id): array
    {
        $context=$this->context($id);$contextHash=hash('sha256',json_encode($context,JSON_THROW_ON_ERROR));$records=[];$history=[];$state=$this->engineeringState($id);$operative=[];$legacy=[];
        foreach($state['objects'] as $object)$records[]=['ref'=>$object['ref'],'kind'=>$object['kind'],'key'=>$object['key'],'version'=>(int)$object['version'],'title'=>$object['title'],'current'=>true,'read_only'=>in_array($object['state'],['removed','superseded','deferred'],true)];
        foreach($this->items($id) as $item){
            $ref=$item['kind'].':'.$item['item_key'].':v'.$item['version'];$readOnly=$item['created_by']==='ai_draft';
            $records[]=['ref'=>$ref,'kind'=>$item['kind'],'key'=>$item['item_key'],'version'=>(int)$item['version'],'title'=>$item['title'],'current'=>true,'read_only'=>$readOnly,'adopted'=>!$readOnly,'origin'=>$item['created_by']];
            if($readOnly){$legacy[]=['ref'=>$ref,'title'=>$item['title'],'excerpt'=>mb_strcut($item['content'],0,800,'UTF-8'),'read_only'=>true,'scope'=>'Legacy AI draft, not an adopted Everyday obligation.'];continue;}
            if($item['kind']==='requirement'){$item['effective_verification']=$this->isVerifiedRequirement($id,$item)?'owner_confirmed_with_current_compatible_evidence':'unverified_or_stale';$item['evidence_constraints']=['required_scope'=>$item['data']['required_scope']??'legacy_unspecified','configuration_required'=>(bool)($item['data']['configuration_required']??false),'required_configuration_ref'=>$item['data']['required_configuration_ref']??null];}
            $operative[]=$item;
        }
        $context['records']=$operative;$context['unadopted_legacy_records']=array_slice($legacy,0,20);
        foreach($state['links'] as $link)if($link['relation']==='planned_check')$records[]=['ref'=>'check:'.$link['link_key'].':v'.$link['version'],'kind'=>'check','key'=>$link['link_key'],'version'=>(int)$link['version'],'title'=>$link['payload']['title']??'Planned check','current'=>true];
        foreach($state['results'] as $result)$records[]=['ref'=>'result:'.$result['result_key'].':v'.$result['version'],'kind'=>'result','key'=>$result['result_key'],'version'=>(int)$result['version'],'title'=>'Owner-reported '.$result['work_state'],'current'=>true,'read_only'=>true,'origin'=>'owner_reported'];
        $context['provenance']=[];$links=$this->provenance($id);
        foreach(array_slice($links,0,20) as $link){$ref='evidence:'.$link['link_key'].':v'.$link['version'];$records[]=['ref'=>$ref,'kind'=>'evidence','key'=>$link['link_key'],'version'=>(int)$link['version'],'title'=>$link['source_snapshot']['title'],'current'=>true,'read_only'=>true];$snapshot=$link['source_snapshot'];$context['provenance'][]=['ref'=>$ref,'subject_ref'=>$link['item_kind'].':'.$link['item_key'].':v'.$link['item_version'],'source_kind'=>$link['source_kind'],'source_sha256'=>$link['source_sha256'],'owner_reviewed'=>(bool)$link['reviewed'],'source_current'=>(bool)$link['source_current'],'subject_current'=>(bool)$link['subject_current'],'evidence_metadata'=>$link['evidence_metadata']??null,'excerpt'=>$link['source_current']?mb_strcut($snapshot['content'],0,600,'UTF-8'):'Current source unavailable.','scope'=>'Captured evidence plus historical owner review, not independent verification.'];}
        foreach($state['cycle_events'] as $event)$records[]=['ref'=>'configuration:'.$event['id'].':v1','kind'=>'configuration','key'=>(string)$event['id'],'version'=>1,'title'=>$event['cycle_key'].' '.$event['event'].' configuration','sha256'=>$event['configuration_sha256'],'current'=>true,'read_only'=>true];
        if((int)$state['profile']['version']>0)$records[]=['ref'=>'profile:operating:v'.$state['profile']['version'],'kind'=>'profile','key'=>'operating','version'=>(int)$state['profile']['version'],'title'=>'Operating profile','current'=>true];
        $older=$this->rows('SELECT i.* FROM nasa_items i WHERE i.project_id=? AND EXISTS(SELECT 1 FROM nasa_items n WHERE n.project_id=i.project_id AND n.kind=i.kind AND n.item_key=i.item_key AND n.version>i.version) ORDER BY i.id DESC LIMIT 20',[$id]);
        foreach($older as $item){$ref=$item['kind'].':'.$item['item_key'].':v'.$item['version'];$records[]=['ref'=>$ref,'kind'=>$item['kind'],'key'=>$item['item_key'],'version'=>(int)$item['version'],'title'=>$item['title'],'current'=>false,'read_only'=>true];$history[]=['ref'=>$ref,'title'=>$item['title'],'content'=>$item['content'],'data'=>json_decode($item['data_json'],true,64,JSON_THROW_ON_ERROR),'status'=>$item['status']];}
        $olderObjects=$this->rows('SELECT o.* FROM engineering_object_versions o WHERE o.project_id=? AND EXISTS(SELECT 1 FROM engineering_object_versions n WHERE n.project_id=o.project_id AND n.object_key=o.object_key AND n.version>o.version) ORDER BY o.id DESC LIMIT 12',[$id]);
        foreach($olderObjects as $object){$object=$this->engineeringDecodeObject($object);$records[]=['ref'=>$object['ref'],'kind'=>$object['kind'],'key'=>$object['key'],'version'=>(int)$object['version'],'title'=>$object['title'],'current'=>false,'read_only'=>true];$history[]=['ref'=>$object['ref'],'title'=>$object['title'],'content'=>$object['content'],'details'=>$object['payload'],'state'=>$object['state']];}
        $total=(int)$this->rows('SELECT COUNT(*) AS n FROM nasa_items WHERE project_id=?',[$id])[0]['n']+(int)$this->rows('SELECT COUNT(*) AS n FROM engineering_object_versions WHERE project_id=?',[$id])[0]['n'];$omitted=max(0,$total-count(array_filter($records,static fn($r)=>!in_array($r['kind'],['profile','check','result','evidence','configuration'],true))));
        $coverage=$context['engineering']['coverage']??[];$omissions=[];if($omitted)$omissions[]=['count'=>$omitted,'reason'=>'Earlier historical versions outside this bounded comparison window.'];if(count($legacy)>20)$omissions[]=['count'=>count($legacy)-20,'reason'=>'Legacy unadopted AI drafts outside the first 20 summary records.'];
        $project=$this->project($id);
        if($project['task_project_id']){$taskTotal=(int)$this->rows('SELECT COUNT(*) AS n FROM tasks WHERE project_id=?',[$project['task_project_id']])[0]['n'];$outcomeTotal=(int)$this->rows('SELECT COUNT(*) AS n FROM agent_runs a JOIN tasks t ON t.id=a.task_id WHERE t.project_id=?',[$project['task_project_id']])[0]['n'];$coverage[]=['category'=>'tasks','included'=>count($context['tasks']),'total'=>$taskTotal,'omitted'=>max(0,$taskTotal-count($context['tasks'])),'summary'=>'Latest 40 selected-project tasks.'];$coverage[]=['category'=>'agent_outcomes','included'=>count($context['agent_outcomes']??[]),'total'=>$outcomeTotal,'omitted'=>max(0,$outcomeTotal-count($context['agent_outcomes']??[])),'summary'=>'Latest 20 outcome metadata records; no execution output included.'];}
        $db=$this->research();if($db&&$project['linked_research_id'])foreach(['ideas'=>"SELECT COUNT(*) FROM research_nodes n JOIN research_runs r ON r.id=n.run_id WHERE r.project_id=? AND n.type IN ('dream_claim','model_idea','question','association','counter','reframing','edge_case','synthesis')",'sources'=>'SELECT COUNT(*) FROM research_sources s JOIN research_runs r ON r.id=s.run_id WHERE r.project_id=?','passages'=>'SELECT COUNT(*) FROM research_passages p JOIN research_runs r ON r.id=p.run_id WHERE r.project_id=?'] as $category=>$sql){$q=$db->prepare($sql);$q->execute([$project['linked_research_id']]);$count=(int)$q->fetchColumn();$included=count($context['research'][$category]??[]);$coverage[]=['category'=>'research_'.$category,'included'=>$included,'total'=>$count,'omitted'=>max(0,$count-$included),'summary'=>$category==='ideas'?'Latest 50 provisional idea excerpts, at most 600 UTF-8 bytes each.':($category==='passages'?'Latest 12 captured passage excerpts, at most 600 UTF-8 bytes each.':'Latest 12 source metadata records.')];}
        $coverage[]=['category'=>'provenance','included'=>count($context['provenance']),'total'=>count($links),'omitted'=>max(0,count($links)-20),'summary'=>'First 20 current link versions, captured text excerpted to 600 UTF-8 bytes.'];
        foreach($coverage as $entry)if($entry['omitted'])$omissions[]=['count'=>$entry['omitted'],'reason'=>$entry['category'].' outside the supplied bounded excerpt.'];
        $manifest=['records'=>$records,'summaries'=>$coverage,'omissions'=>$omissions,'profile_version'=>(int)$state['profile']['version'],'knowledge_version'=>NasaKnowledge::VERSION];
        return $context+['historical_records'=>$history,'manifest'=>$manifest,'context_hash'=>$contextHash];
    }

    private function engineeringDecodeProposal(array $row): array
    {
        foreach(['payload','manifest','disposition'] as $key)$row[$key]=json_decode($row[$key.'_json'],true,64,JSON_THROW_ON_ERROR);return $row;
    }
    public function everydayProposal(int $id,string $key): array
    {
        $this->project($id);if(!preg_match('/^EPROP-[1-9][0-9]{0,9}$/D',$key))throw new RuntimeException('Choose an Everyday proposal from this project.');$row=$this->rows('SELECT * FROM engineering_proposal_versions WHERE project_id=? AND proposal_key=? ORDER BY version DESC LIMIT 1',[$id,$key])[0]??throw new RuntimeException('Choose an Everyday proposal from this project.');return $this->engineeringDecodeProposal($row);
    }
    private function engineeringAppendProposal(int $id,string $key,int $expected,string $operation,int $request,int $revision,int $profileVersion,string $hash,array $manifest,array $payload,string $status,array $disposition,string $by): int
    {
        $current=$this->rows('SELECT version FROM engineering_proposal_versions WHERE project_id=? AND proposal_key=? ORDER BY version DESC LIMIT 1',[$id,$key])[0]??null;if((int)($current['version']??0)!==$expected)throw new RuntimeException('Everyday proposal changed. Refresh before deciding.');
        $this->execute('INSERT INTO engineering_proposal_versions(project_id,proposal_key,version,operation,request_id,base_revision,profile_version,context_hash,manifest_json,payload_json,status,disposition_json,created_by,created_at)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$key,$expected+1,$operation,$request,$revision,$profileVersion,$hash,self::engineeringJson($manifest),self::engineeringJson($payload),$status,self::engineeringJson($disposition),$by,time()]);return (int)$this->db->lastInsertId();
    }
    public function retainEverydayProposal(int $id,int $revision,string $operation,array $payload,int $requestId,string $contextHash,array $manifest): int
    {
        $payload=self::validateEverydayEnvelope($payload,$operation);
        return $this->transaction(function()use($id,$revision,$operation,$payload,$requestId,$contextHash,$manifest):int{
            $project=$this->project($id);$profile=$this->engineeringProfile($id);
            if(!$this->rows("SELECT id FROM nasa_ai_requests WHERE id=? AND project_id=? AND revision=? AND action=? AND status='pending'",[$requestId,$id,$revision,$operation]))throw new RuntimeException('Everyday advice has no matching bounded provider request.');
            if((int)$project['revision']!==$revision||!hash_equals($contextHash,$this->everydayContextHash($id)))return 0;
            $this->engineeringValidateReferences($id,$payload,$manifest);
            return $this->engineeringAppendProposal($id,'EPROP-'.$requestId,0,$operation,$requestId,$revision,(int)$profile['version'],$contextHash,$manifest,$payload,'pending',[],'ai_proposal');
        });
    }
    private function engineeringPending(int $id,string $key,int $version,bool $fresh=true): array
    {
        $proposal=$this->everydayProposal($id,$key);if((int)$proposal['version']!==$version||$proposal['status']!=='pending')throw new RuntimeException('Everyday proposal was edited or decided. Refresh the saved version.');
        if($fresh&&((int)$this->project($id)['revision']!==(int)$proposal['base_revision']||(int)$this->engineeringProfile($id)['version']!==(int)$proposal['profile_version']||!hash_equals($proposal['context_hash'],$this->everydayContextHash($id))))throw new RuntimeException('Intent, commitments, research, evidence or project context changed. Request fresh advice or reject this retained proposal.');return $proposal;
    }
    public function editEverydayProposal(int $id,string $key,int $version,array $payload): int
    {
        return $this->transaction(function()use($id,$key,$version,$payload):int{$proposal=$this->engineeringPending($id,$key,$version);$payload=self::validateEverydayEnvelope($payload,$proposal['operation']);$this->engineeringValidateReferences($id,$payload,$proposal['manifest']);return $this->engineeringAppendProposal($id,$key,$version,$proposal['operation'],(int)$proposal['request_id'],(int)$proposal['base_revision'],(int)$proposal['profile_version'],$proposal['context_hash'],$proposal['manifest'],$payload,'pending',[],'owner_edited');});
    }
    private function engineeringReference(int $id,string $ref,bool $historical=false): array
    {
        if(!preg_match('/^([a-z_]+):([A-Za-z0-9_-]{1,50}):v([1-9][0-9]{0,9})$/D',$ref,$match))throw new RuntimeException('Use an exact saved record reference.');[, $kind,$key,$version]=$match;$version=(int)$version;
        if(in_array($kind,['intent','agreement','pathway','cycle','commitment'],true)){$row=$this->engineeringObject($id,$key,$historical?$version:null);if(!$row||$row['kind']!==$kind||(int)$row['version']!==$version||!$historical&&in_array($row['state'],['removed','superseded','deferred'],true))throw new RuntimeException('A referenced operating version is missing or changed.');return ['kind'=>$kind,'key'=>$key,'version'=>$version,'row'=>$row,'ref'=>$ref];}
        if($kind==='profile'){$row=$this->engineeringProfile($id);if($key!=='operating'||(int)$row['version']!==$version)throw new RuntimeException('The operating profile reference changed.');return ['kind'=>$kind,'key'=>$key,'version'=>$version,'row'=>$row,'ref'=>$ref];}
        if($kind==='check'){$row=$this->rows('SELECT * FROM engineering_link_versions WHERE project_id=? AND link_key=? ORDER BY version DESC LIMIT 1',[$id,$key])[0]??null;if(!$row||(int)$row['version']!==$version||$row['relation']!=='planned_check'||$row['status']!=='active')throw new RuntimeException('The planned-check reference is unavailable.');$row['payload']=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);return ['kind'=>$kind,'key'=>$key,'version'=>$version,'row'=>$row,'ref'=>$ref];}
        if($kind==='result'){$row=$this->rows('SELECT * FROM engineering_result_versions WHERE project_id=? AND result_key=? AND version=?',[$id,$key,$version])[0]??null;if(!$row)throw new RuntimeException('The owner result reference is unavailable.');return ['kind'=>$kind,'key'=>$key,'version'=>$version,'row'=>$row,'ref'=>$ref,'read_only'=>true];}
        if($kind==='evidence'){$row=$this->rows('SELECT * FROM nasa_provenance WHERE project_id=? AND link_key=? AND version=?',[$id,$key,$version])[0]??null;if(!$row)throw new RuntimeException('The captured evidence reference is unavailable.');return ['kind'=>$kind,'key'=>$key,'version'=>$version,'row'=>$row,'ref'=>$ref,'read_only'=>true];}
        if($kind==='configuration'){$row=ctype_digit($key)&&$version===1?($this->rows('SELECT * FROM engineering_cycle_events WHERE project_id=? AND id=?',[$id,(int)$key])[0]??null):null;if(!$row||!hash_equals($row['configuration_sha256'],hash('sha256',$row['configuration_json'])))throw new RuntimeException('The captured cycle configuration is unavailable or inconsistent.');return ['kind'=>$kind,'key'=>$key,'version'=>$version,'row'=>$row,'ref'=>$ref,'read_only'=>true];}
        if(!in_array($kind,['requirement','risk','decision','interface','artifact','tpm','tailoring'],true))throw new RuntimeException('Choose a project planning reference.');
        $row=$historical?($this->rows('SELECT * FROM nasa_items WHERE project_id=? AND kind=? AND item_key=? AND version=?',[$id,$kind,$key,$version])[0]??null):$this->current($id,$kind,$key);
        if(!$row||(int)$row['version']!==$version||!$historical&&($row['status']==='removed'||$row['created_by']==='ai_draft'))throw new RuntimeException('A referenced planning version was deleted, changed or remains an unadopted legacy AI draft.');$row['data']=json_decode($row['data_json'],true,64,JSON_THROW_ON_ERROR);return ['kind'=>$kind,'key'=>$key,'version'=>$version,'row'=>$row,'ref'=>$ref];
    }
    private function engineeringValidateReferences(int $id,array $envelope,array $manifest): void
    {
        $allowed=[];foreach($manifest['records']??[] as $record)if(is_array($record)&&is_string($record['ref']??null))$allowed[$record['ref']]=$record;
        $locals=[];foreach(['objects','promises','risks','decisions','checks'] as $category)foreach($envelope['payload'][$category] as $row)$locals[$row['local_id']]=$category==='objects'?$row['kind']:['promises'=>'requirement','risks'=>'risk','decisions'=>'decision','checks'=>'check'][$category];
        $check=function(string $ref,bool $old=false,bool $operative=false)use($id,$allowed,$locals):void{if(str_starts_with($ref,'new:')){if(!isset($locals[substr($ref,4)]))throw new RuntimeException('A local draft dependency is missing from the coherent bundle.');return;}if(!isset($allowed[$ref]))throw new RuntimeException('The proposal cited a record outside its supplied context manifest.');$record=$allowed[$ref];$readOnly=($record['read_only']??false)||!($record['current']??true)||($record['adopted']??true)===false;if($operative&&$readOnly)throw new RuntimeException('A working commitment cannot treat a historical, result or unadopted draft reference as an operative promise.');$this->engineeringReference($id,$ref,$old||$readOnly);};
        $walk=function(mixed $value,string $field='')use(&$walk,$check):void{if(!is_array($value))return;foreach($value as $key=>$entry){$name=is_string($key)?$key:$field;if(in_array($name,['record_refs','cycle_refs','commitment_refs','dependency_refs','promise_refs','risk_refs','decision_refs','check_refs','depends_on','old_refs','new_refs'],true)){foreach($entry as $ref)$check($ref,$name==='old_refs',!in_array($name,['record_refs','old_refs','new_refs'],true));}elseif($name==='cycle_ref')$check($entry,false,true);else $walk($entry,$name);}};$walk($envelope);
        $kindOf=static function(string $ref)use($locals):string{return str_starts_with($ref,'new:')?($locals[substr($ref,4)]??''):explode(':',$ref,2)[0];};
        foreach($envelope['payload']['objects'] as $object){$d=$object['details'];foreach(['cycle_refs'=>'cycle','commitment_refs'=>'commitment','promise_refs'=>'requirement','risk_refs'=>'risk','decision_refs'=>'decision','check_refs'=>'check'] as $field=>$kind)foreach($d[$field]??[] as $ref)if($kindOf($ref)!==$kind)throw new RuntimeException('A dependency points to the wrong type of planning object.');
            if(in_array($object['kind'],['cycle','commitment'],true))foreach($d['dependency_refs'] as $ref)if($kindOf($ref)!==$object['kind'])throw new RuntimeException('Cycle dependencies must name cycles; work dependencies must name commitments.');
            if($object['kind']==='pathway'){foreach($d['dependencies'] as $dependency){if($kindOf($dependency['cycle_ref'])!=='cycle')throw new RuntimeException('A pathway dependency must name a cycle.');foreach($dependency['depends_on'] as $ref)if($kindOf($ref)!=='cycle')throw new RuntimeException('A pathway prerequisite must name a cycle.');}foreach($envelope['payload']['objects'] as $member)if($member['kind']==='cycle'&&!in_array('new:'.$member['local_id'],$d['cycle_refs'],true))throw new RuntimeException('Every proposed forecast cycle must be visible in the proposed pathway.');}
        }
        foreach($envelope['payload']['checks'] as $planned)foreach($planned['promise_refs'] as $ref)if($kindOf($ref)!=='requirement')throw new RuntimeException('A planned check must reference a promise rather than another kind of record.');
        $detailedCycles=[];$newObjects=[];foreach($envelope['payload']['objects'] as $object)$newObjects['new:'.$object['local_id']]=$object;
        foreach($envelope['payload']['objects'] as $object)if($object['kind']==='pathway')foreach($object['details']['cycle_refs'] as $index=>$ref){$cycle=$newObjects[$ref]??null;if($cycle&&$index>0&&$cycle['details']['commitment_refs'])throw new RuntimeException('Later pathway cycles remain forecasts; detail commitments for the first chosen cycle only.');if($index===0)$detailedCycles[]=$cycle?$cycle['details']['commitment_refs']:($this->engineeringReference($id,$ref)['row']['payload']['commitment_refs']??[]);}
        if($envelope['payload']['objects']&&count(array_filter($envelope['payload']['objects'],static fn($o)=>$o['kind']==='pathway'))===0)foreach($envelope['payload']['objects'] as $object)if($object['kind']==='cycle')$detailedCycles[]=$object['details']['commitment_refs'];
        if($detailedCycles){$members=array_merge(...$detailedCycles);if(!$members)throw new RuntimeException('A detailed cycle needs at least one explicit work commitment.');foreach($envelope['payload']['objects'] as $object)if($object['kind']==='commitment'&&!in_array('new:'.$object['local_id'],$members,true))throw new RuntimeException('Every proposed commitment must be visible in the detailed cycle rather than hidden elsewhere in the bundle.');}
        $graph=[];foreach($envelope['payload']['objects'] as $object)$graph['new:'.$object['local_id']]=$object['details']['dependency_refs']??[];foreach($envelope['payload']['objects'] as $object)if($object['kind']==='pathway')foreach($object['details']['dependencies'] as $dependency)$graph[$dependency['cycle_ref']]=array_values(array_unique(array_merge($graph[$dependency['cycle_ref']]??[],$dependency['depends_on'])));
        $visiting=[];$done=[];$visit=function(string $ref)use(&$visit,&$visiting,&$done,$graph):void{if(isset($done[$ref]))return;if(isset($visiting[$ref]))throw new RuntimeException('The proposal contains a circular dependency.');$visiting[$ref]=true;foreach($graph[$ref]??[] as $dependency)$visit($dependency);unset($visiting[$ref]);$done[$ref]=true;};foreach(array_keys($graph) as $ref)$visit($ref);
    }
    private function engineeringNextKey(int $id,string $kind,array &$numbers): string
    {
        $prefix=['intent'=>'INT','agreement'=>'AGR','pathway'=>'PATH','cycle'=>'CYC','commitment'=>'COM','requirement'=>'REQ','risk'=>'RISK','decision'=>'DEC','check'=>'CHECK'][$kind];
        if(!isset($numbers[$kind])){$table=in_array($kind,['requirement','risk','decision'],true)?'nasa_items':'engineering_object_versions';$column=$table==='nasa_items'?'item_key':'object_key';$numbers[$kind]=$kind==='check'?(int)$this->rows("SELECT COUNT(DISTINCT link_key) AS n FROM engineering_link_versions WHERE project_id=? AND relation='planned_check'",[$id])[0]['n']:(int)$this->rows('SELECT COUNT(DISTINCT '.$column.') AS n FROM '.$table.' WHERE project_id=? AND kind=?',[$id,$kind])[0]['n'];}
        return $prefix.'-'.str_pad((string)++$numbers[$kind],3,'0',STR_PAD_LEFT);
    }
    private function engineeringResolveLocals(mixed $value,array $mapping): mixed
    {
        if(is_string($value)&&preg_match('/^new:([a-z][a-z0-9_-]{0,39})$/D',$value,$match))return $mapping[$match[1]]['ref']??throw new RuntimeException('A proposed local dependency is unavailable.');
        if(is_array($value)){foreach($value as &$entry)$entry=$this->engineeringResolveLocals($entry,$mapping);unset($entry);}return $value;
    }
    private static function engineeringReferenceHash(array $reference): string
    {
        $row=$reference['row'];if(in_array($reference['kind'],['requirement','risk','decision','interface','artifact','tpm','tailoring'],true))return self::subjectHash($reference['kind'],$row['title'],$row['content'],$row['data']);
        if(in_array($reference['kind'],['intent','agreement','pathway','cycle','commitment'],true)){$payload=$row['payload'];foreach(['last_disposition','configuration_snapshot','last_work_report'] as $key)unset($payload[$key]);return hash('sha256',self::engineeringJson(['title'=>$row['title'],'content'=>$row['content'],'payload'=>$payload]));}
        if($reference['kind']==='evidence')return hash('sha256',self::engineeringJson(['subject_hash'=>$row['subject_hash'],'source_sha256'=>$row['source_sha256'],'review_state'=>$row['status']]));
        if($reference['kind']==='configuration')return $row['configuration_sha256'];
        return hash('sha256',self::engineeringJson($row));
    }
    private function engineeringAppendLink(int $id,string $from,string $to,string $relation,array $payload=[]): int
    {
        $number=(int)$this->rows('SELECT COUNT(DISTINCT link_key) AS n FROM engineering_link_versions WHERE project_id=?',[$id])[0]['n']+1;$key='EL-'.str_pad((string)$number,3,'0',STR_PAD_LEFT);
        $analysis=$relation==='trace'||in_array(explode(':',$to,2)[0],['result','evidence','configuration'],true);$target=$this->engineeringReference($id,$to,$analysis);$payload['subject_hash']=self::engineeringReferenceHash($target);$payload['analysis_only']=$analysis;
        $this->execute('INSERT INTO engineering_link_versions(project_id,link_key,version,from_ref,to_ref,relation,status,payload_json,created_by,created_at)VALUES(?,?,1,?,?,?,?,?,?,?)',[$id,$key,$from,$to,$relation,'active',self::engineeringJson($payload),'owner_adopted_everyday',time()]);return (int)$this->db->lastInsertId();
    }
    private function engineeringPreserveCommitments(int $id,array $envelope): void
    {
        $pathways=array_values(array_filter($envelope['payload']['objects'],static fn($object)=>$object['kind']==='pathway'));if(!$pathways)return;
        $newObjects=[];foreach($envelope['payload']['objects'] as $object)$newObjects['new:'.$object['local_id']]=$object;
        $covered=[];
        // Only structural placement in the adopted pathway carries an obligation.
        // A citation in rationale, a drift finding or a next-move paragraph does not.
        foreach($pathways[0]['details']['cycle_refs'] as $cycleRef){
            if(str_starts_with($cycleRef,'new:')){$cycle=$newObjects[$cycleRef]??null;if(!$cycle||$cycle['kind']!=='cycle')throw new RuntimeException('The pathway must name declared cycle members.');$refs=$cycle['details']['commitment_refs'];}
            else{$reference=$this->engineeringReference($id,$cycleRef);if($reference['kind']!=='cycle')throw new RuntimeException('The pathway must name cycle records.');if(!in_array($reference['row']['state'],['planned','active','in_review','paused'],true))continue;$refs=$reference['row']['payload']['commitment_refs']??[];}
            foreach($refs as $ref)if(!str_starts_with($ref,'new:')){$reference=$this->engineeringReference($id,$ref);if($reference['kind']==='commitment')$covered[$reference['key']]=true;}
        }
        foreach($this->engineeringState($id)['groups']['commitment'] as $commitment){if(!in_array($commitment['state'],['planned','reported_in_progress','blocked'],true))continue;$last=$commitment['payload']['last_disposition']['action']??null;if(in_array($last,['deferred','superseded','removed_from_scope'],true))continue;if(!isset($covered[$commitment['key']]))throw new RuntimeException('The replacement pathway would omit an unresolved commitment. Place its exact reference in a retained planned/current cycle, or record a deliberate disposition through cycle replan first.');}
    }

    public function decideEverydayProposal(int $id,string $key,int $version,string $decision): array
    {
        if(!in_array($decision,['adopt','reject'],true))throw new RuntimeException('Choose adopt or reject for the Everyday proposal.');
        return $this->transaction(function()use($id,$key,$version,$decision):array{
            $proposal=$this->engineeringPending($id,$key,$version,$decision==='adopt');$envelope=self::validateEverydayEnvelope($proposal['payload'],$proposal['operation']);$saved=[];
            if($decision==='adopt'){
                if($this->engineeringProfile($id)['mode']!=='everyday')throw new RuntimeException('Choose Everyday work explicitly before adopting an Everyday cycle.');
                $this->engineeringValidateReferences($id,$envelope,$proposal['manifest']);$this->engineeringPreserveCommitments($id,$envelope);$bundle=$envelope['payload'];$mapping=[];$numbers=[];$prepared=[];
                foreach($bundle['objects'] as $object){$kind=$object['kind'];$objectKey=in_array($kind,['intent','agreement','pathway'],true)?['intent'=>'INT-001','agreement'=>'AGR-001','pathway'=>'PATH-001'][$kind]:$this->engineeringNextKey($id,$kind,$numbers);$old=$this->engineeringObject($id,$objectKey);$expected=(int)($old['version']??0);$mapping[$object['local_id']]=['kind'=>$kind,'key'=>$objectKey,'version'=>$expected+1,'ref'=>$kind.':'.$objectKey.':v'.($expected+1)];$prepared[]=['category'=>'object','source'=>$object,'key'=>$objectKey,'expected'=>$expected];}
                foreach(['promises'=>'requirement','risks'=>'risk','decisions'=>'decision'] as $category=>$kind)foreach($bundle[$category] as $record){
                    $existing=null;if($kind==='requirement')foreach($this->items($id) as $item)if($item['kind']==='requirement'&&$item['created_by']!=='ai_draft'&&($item['data']['configuration_required']??false)===true&&trim($item['content'])===trim($record['statement'])&&($item['data']['source']??'')===trim($record['source'])&&($item['data']['method']??'')===$record['method']&&($item['data']['required_scope']??'unknown')===$record['required_scope']){$existing=$item;break;}
                    $recordKey=$existing?$existing['item_key']:$this->engineeringNextKey($id,$kind,$numbers);$recordVersion=$existing?(int)$existing['version']:1;$mapping[$record['local_id']]=['kind'=>$kind,'key'=>$recordKey,'version'=>$recordVersion,'ref'=>$kind.':'.$recordKey.':v'.$recordVersion];
                    if($existing)continue;
                    $status=['requirement'=>'unverified','risk'=>'open','decision'=>'proposed'][$kind];$content=$kind==='requirement'?$record['statement']:($kind==='risk'?$record['condition']:$record['rationale']);
                    $data=match($kind){'requirement'=>['source'=>$record['source'],'method'=>$record['method'],'evidence'=>'','required_scope'=>$record['required_scope'],'configuration_required'=>true], 'risk'=>['owner'=>$record['owner'],'mitigation'=>$record['response'],'requirement'=>'','artifact'=>'','rating_status'=>'not_assessed','likelihood'=>null,'consequence'=>null,'residual_likelihood'=>null,'residual_consequence'=>null], 'decision'=>['alternatives'=>$record['alternatives'],'revisit'=>$record['revisit']]};
                    $data=$this->validateRecord($id,$kind,$record['title'],$content,$data,$status);if($kind==='risk')$data+=['category'=>$record['category'],'exposure'=>$record['exposure'],'response_state'=>'proposed_not_observed'];if($kind==='requirement')$data+=['required_scope'=>$record['required_scope'],'configuration_required'=>true];
                    $prepared[]=['category'=>'item','kind'=>$kind,'key'=>$recordKey,'title'=>$record['title'],'content'=>$content,'status'=>$status,'data'=>$data];
                }
                foreach($bundle['checks'] as $check){$checkKey=$this->engineeringNextKey($id,'check',$numbers);$mapping[$check['local_id']]=['kind'=>'check','key'=>$checkKey,'version'=>1,'ref'=>'check:'.$checkKey.':v1'];}
                $pathDependencies=[];foreach($bundle['objects'] as $route)if($route['kind']==='pathway')foreach($route['details']['dependencies'] as $dependency){$resolved=$this->engineeringResolveLocals($dependency,$mapping);$pathDependencies[$resolved['cycle_ref']]=array_values(array_unique(array_merge($pathDependencies[$resolved['cycle_ref']]??[],$resolved['depends_on'])));}
                $origin=['proposal_key'=>$key,'proposal_version'=>$version,'request_id'=>(int)$proposal['request_id'],'context_hash'=>$proposal['context_hash'],'knowledge_version'=>NasaKnowledge::VERSION];
                // All dependencies and shared records have been validated before the first append.
                foreach($prepared as $member)if($member['category']==='item'){$data=$member['data']+['everyday_proposal'=>$origin];$row=$this->append($id,$member['kind'],$member['key'],$member['title'],$member['content'],$member['status'],$data,'owner_adopted_everyday',0);$saved[]=['kind'=>$member['kind'],'key'=>$member['key'],'version'=>1,'id'=>$row];}
                foreach($prepared as $member)if($member['category']==='object'){$object=$member['source'];$payload=$this->engineeringResolveLocals($object['details'],$mapping);if($object['kind']==='cycle')$payload['dependency_refs']=array_values(array_unique(array_merge($payload['dependency_refs'],$pathDependencies[$mapping[$object['local_id']]['ref']]??[])));$payload['record_refs']=$this->engineeringResolveLocals($object['record_refs'],$mapping);$payload['principle_applications']=[];foreach($object['principle_ids'] as $principle)$payload['principle_applications'][]=['principle_id'=>$principle,'reference'=>self::everydayPrinciples()[$principle],'knowledge_version'=>NasaKnowledge::VERSION,'application'=>$object['content']];$state=in_array($object['kind'],['cycle','commitment'],true)?'planned':'adopted';$row=$this->engineeringAppendObject($id,$member['key'],$object['kind'],$object['title'],$object['content'],$state,$payload,$origin,'owner_adopted_everyday',$member['expected']);$saved[]=['kind'=>$object['kind'],'key'=>$member['key'],'version'=>$member['expected']+1,'id'=>$row];}
                $objects=$this->engineeringState($id);$anchor=$objects['groups']['cycle']?end($objects['groups']['cycle'])['ref']:($objects['groups']['intent'][0]['ref']??null);
                foreach($bundle['checks'] as $check){$descriptor=$mapping[$check['local_id']];$payload=$this->engineeringResolveLocals($check,$mapping);$payload['planned_only']=true;$target=$payload['promise_refs'][0]??($objects['groups']['intent'][0]['ref']??null);if(!$anchor||!$target)throw new RuntimeException('A planned check needs an adopted intent or cycle.');$reference=$this->engineeringReference($id,$target);$payload['subject_hash']=self::engineeringReferenceHash($reference);$this->execute('INSERT INTO engineering_link_versions(project_id,link_key,version,from_ref,to_ref,relation,status,payload_json,created_by,created_at)VALUES(?,?,1,?,?,?,?,?,?,?)',[$id,$descriptor['key'],$anchor,$target,'planned_check','active',self::engineeringJson($payload),'owner_adopted_everyday',time()]);$saved[]=['kind'=>'check','key'=>$descriptor['key'],'version'=>1];}
                foreach($prepared as $member)if($member['category']==='object'){$object=$member['source'];$from=$mapping[$object['local_id']]['ref'];$payload=$this->engineeringResolveLocals($object['details'],$mapping);$refs=['record_refs'=>$this->engineeringResolveLocals($object['record_refs'],$mapping)]+$payload;foreach(['record_refs'=>'trace','cycle_refs'=>'contains','commitment_refs'=>'contains','promise_refs'=>'fulfills','risk_refs'=>'addresses','decision_refs'=>'considers','check_refs'=>'planned_check_reference','dependency_refs'=>'depends_on'] as $field=>$relation)foreach($refs[$field]??[] as $to)$this->engineeringAppendLink($id,$from,$to,$relation,['proposal'=>$origin]);}
                if($saved)$this->bump($id);
            }
            $this->engineeringAppendProposal($id,$key,$version,$proposal['operation'],(int)$proposal['request_id'],(int)$proposal['base_revision'],(int)$proposal['profile_version'],$proposal['context_hash'],$proposal['manifest'],$envelope,$decision==='adopt'?'adopted':'rejected',['records'=>$saved,'effect'=>'Planning only; cycles remain unstarted and verification/acceptance authority is unchanged.'],'owner');return $saved;
        });
    }
    public function saveEverydayObject(int $id,string $key,int $version,string $title,string $content,int $revision): void
    {
        $title=self::text($title,160);$content=self::text($content,5000);
        $this->transaction(function()use($id,$key,$version,$title,$content,$revision):void{
            if((int)$this->project($id)['revision']!==$revision)throw new RuntimeException('Project changed. Refresh before correcting the operating agreement.');$object=$this->engineeringObject($id,$key);if(!$object||(int)$object['version']!==$version||!in_array($object['kind'],['intent','agreement'],true))throw new RuntimeException('Only the current intent or working agreement can be corrected with this form.');
            $payload=$object['payload'];if($object['kind']==='intent'){$payload['problem']=$content;$payload['outcome']=$content;}else $payload['owner_correction']=$content;
            $this->engineeringAppendObject($id,$key,$object['kind'],$title,$content,'adopted',$payload,['source'=>'owner_correction','prior_ref'=>$object['ref']],'owner',$version);$this->bump($id);
        });
    }
    private function engineeringCurrentReference(int $id,string $ref): array
    {
        if(!preg_match('/^([a-z_]+):([A-Za-z0-9_-]+):v[1-9][0-9]*$/D',$ref,$m))throw new RuntimeException('A saved reference is invalid.');$kind=$m[1];$key=$m[2];
        if(in_array($kind,['intent','agreement','pathway','cycle','commitment'],true)){$row=$this->engineeringObject($id,$key);if(!$row)throw new RuntimeException('Referenced operating record is missing.');return $this->engineeringReference($id,$kind.':'.$key.':v'.$row['version'],true);}
        if($kind==='check'){$row=$this->rows('SELECT version FROM engineering_link_versions WHERE project_id=? AND link_key=? ORDER BY version DESC LIMIT 1',[$id,$key])[0]??null;if(!$row)throw new RuntimeException('Planned check is missing.');return $this->engineeringReference($id,$kind.':'.$key.':v'.$row['version']);}
        if($kind==='result'||$kind==='evidence'){$table=$kind==='result'?'engineering_result_versions':'nasa_provenance';$column=$kind==='result'?'result_key':'link_key';$row=$this->rows('SELECT version FROM '.$table.' WHERE project_id=? AND '.$column.'=? ORDER BY version DESC LIMIT 1',[$id,$key])[0]??null;if(!$row)throw new RuntimeException('Captured result or evidence is unavailable.');return $this->engineeringReference($id,$kind.':'.$key.':v'.$row['version'],true);}
        if($kind==='configuration')return $this->engineeringReference($id,$ref,true);
        if($kind==='profile')return $this->engineeringReference($id,'profile:operating:v'.$this->engineeringProfile($id)['version']);
        $row=$this->current($id,$kind,$key);if(!$row||$row['status']==='removed')throw new RuntimeException('Referenced planning record is missing.');return $this->engineeringReference($id,$kind.':'.$key.':v'.$row['version']);
    }
    public function engineeringCycleObligations(int $id,string $cycleKey): array
    {
        $cycle=$this->engineeringObject($id,$cycleKey);if(!$cycle||$cycle['kind']!=='cycle')throw new RuntimeException('Choose a cycle from this project.');$obligations=[];$references=$cycle['payload']['record_refs']??[];
        foreach($cycle['payload']['commitment_refs']??[] as $ref){$current=$this->engineeringCurrentReference($id,$ref);$commitment=$current['row'];if($current['kind']!=='commitment')throw new RuntimeException('A cycle commitment reference has the wrong type.');
            if(!in_array($commitment['state'],['superseded','removed','deferred'],true))$obligations[$commitment['key']]=['key'=>$commitment['key'],'kind'=>'commitment','ref'=>$current['ref'],'title'=>$commitment['title'],'state'=>$commitment['state'],'reason'=>'Work reports do not discharge persistent product promises.'];
            foreach(['promise_refs','risk_refs','decision_refs'] as $field)$references=array_merge($references,$commitment['payload'][$field]??[]);
        }
        foreach(array_unique($references) as $ref){$current=$this->engineeringCurrentReference($id,$ref);$row=$current['row'];$kind=$current['kind'];$open=$kind==='requirement'?!$this->isVerifiedRequirement($id,$row):($kind==='risk'?$row['status']!=='closed':($kind==='decision'?$row['status']!=='decided':false));if($open)$obligations[$current['key']]=['key'=>$current['key'],'kind'=>$kind,'ref'=>$current['ref'],'title'=>$row['title'],'state'=>$row['status'],'reason'=>'This obligation remains recorded after the cycle ends.'];}
        return array_values($obligations);
    }
    private function engineeringDispositions(int $id,array $obligations,array $dispositions): array
    {
        $wanted=array_column($obligations,null,'key');if(array_diff(array_keys($dispositions),array_keys($wanted))||array_diff(array_keys($wanted),array_keys($dispositions)))throw new RuntimeException('Record an explicit disposition for every unresolved commitment, promise, risk and decision before replan or closure.');
        $out=[];foreach($wanted as $key=>$obligation){$d=self::engineeringKeys($dispositions[$key],['action','reason','revisit','replacement_ref']);if(!in_array($d['action'],['carried_forward','deferred','superseded','removed_from_scope'],true))throw new RuntimeException('Choose a deliberate obligation disposition.');$d['reason']=self::text($d['reason'],2000);$d['revisit']=self::text($d['revisit'],1000);$d['replacement_ref']=self::text($d['replacement_ref'],160,true);
            if(in_array($d['action'],['superseded','removed_from_scope'],true)){$replacement=$this->engineeringReference($id,$d['replacement_ref']);if($d['replacement_ref']===$obligation['ref'])throw new RuntimeException('A replacement must be another explicit record.');if($d['action']==='superseded'&&$replacement['kind']!==$obligation['kind'])throw new RuntimeException('A superseding reference must name the same kind of obligation.');if($d['action']==='removed_from_scope'&&($replacement['kind']!=='decision'||$replacement['row']['status']!=='decided'))throw new RuntimeException('Removing an obligation needs a separate owner-decided scope decision.');}
            $out[$key]=$d+['subject_ref'=>$obligation['ref'],'subject_kind'=>$obligation['kind']];
        }return $out;
    }
    private function engineeringConfiguration(int $id,array $cycle,?string $configurationRef): array
    {
        $project=$this->project($id);$state=$this->engineeringState($id);$objects=[];foreach($state['objects'] as $object)$objects[]=['ref'=>$object['ref'],'kind'=>$object['kind'],'title'=>$object['title'],'state'=>$object['state'],'content'=>$object['content'],'details'=>$object['payload']];$items=[];foreach($this->items($id) as $item)$items[]=['ref'=>$item['kind'].':'.$item['item_key'].':v'.$item['version'],'kind'=>$item['kind'],'title'=>$item['title'],'content'=>$item['content'],'data'=>$item['data'],'state'=>$item['status']];
        $sha=getenv('AICONTROL_INSTALLED_SHA');return ['format'=>'AIcontrol cycle commitment configuration v1','project_id'=>$id,'project_revision'=>(int)$project['revision'],'profile'=>array_intersect_key($state['profile'],array_flip(['version','mode','reason','exposure','revisit'])),'cycle_ref'=>$cycle['ref'],'objects'=>$objects,'planning_records'=>$items,'links'=>$state['links'],'project_code_ref'=>$configurationRef,'control_panel_sha'=>is_string($sha)&&preg_match('/^[a-f0-9]{40}$/D',$sha)?$sha:null,'research_sha256'=>$this->researchState($id)['sha256'],'evidence_refs'=>array_map(static fn($link)=>['ref'=>'evidence:'.$link['link_key'].':v'.$link['version'],'subject_hash'=>$link['subject_hash'],'source_sha256'=>$link['source_sha256'],'owner_reviewed'=>(bool)$link['reviewed']],$this->provenance($id)),'scope'=>'Saved exact configuration; this is not a formal baseline, execution result or verification.'];
    }
    public function transitionCycle(int $id,string $key,int $version,string $transition,string $reason,array $dispositions,int $revision,?string $configurationRef=null): array
    {
        if(!in_array($transition,['start','review','pause','replan','close','close_incomplete'],true))throw new RuntimeException('Choose an explicit cycle action.');$reason=self::text($reason,2000);$configurationRef=$configurationRef===null||$configurationRef===''?null:self::text($configurationRef,1000);
        return $this->transaction(function()use($id,$key,$version,$transition,$reason,$dispositions,$revision,$configurationRef):array{
            if((int)$this->project($id)['revision']!==$revision)throw new RuntimeException('Project changed. Refresh the cycle before choosing its next state.');$cycle=$this->engineeringObject($id,$key);if(!$cycle||$cycle['kind']!=='cycle'||(int)$cycle['version']!==$version)throw new RuntimeException('Choose the current cycle version.');
            $allowed=['start'=>['planned','paused'],'review'=>['active'],'pause'=>['active','in_review'],'replan'=>['planned','active','in_review','paused'],'close'=>['in_review'],'close_incomplete'=>['planned','active','in_review','paused']];if(!in_array($cycle['state'],$allowed[$transition],true))throw new RuntimeException('That transition is not available from the recorded cycle state.');
            if($transition==='start'){
                if(!($cycle['payload']['commitment_refs']??[]))throw new RuntimeException('This cycle is still a forecast. Adopt a detailed cycle with explicit commitments before starting work.');
                foreach($this->engineeringState($id)['groups']['cycle'] as $other)if($other['key']!==$key&&in_array($other['state'],['active','in_review'],true))throw new RuntimeException('Pause or close the current active cycle before starting another.');
                foreach($cycle['payload']['commitment_refs']??[] as $ref){$commitment=$this->engineeringCurrentReference($id,$ref);if($commitment['kind']!=='commitment'||in_array($commitment['row']['state'],['removed','superseded','deferred'],true))throw new RuntimeException('A cycle commitment was deliberately deferred, superseded or removed. Adopt an explicit revised cycle before starting it.');}
                $required=$cycle['payload']['dependency_refs']??[];foreach($this->engineeringState($id)['groups']['pathway'] as $route)foreach($route['payload']['dependencies']??[] as $dependency)if((explode(':',$dependency['cycle_ref'])[1]??'')===$key)$required=array_merge($required,$dependency['depends_on']);foreach(array_unique($required) as $ref){$dependency=$this->engineeringCurrentReference($id,$ref);if($dependency['kind']!=='cycle'||$dependency['row']['state']!=='closed')throw new RuntimeException('A recorded prerequisite cycle is not closed. Replan explicitly if that dependency should change.');}
            }
            $recorded=[];if(in_array($transition,['replan','close','close_incomplete'],true))$recorded=$this->engineeringDispositions($id,$this->engineeringCycleObligations($id,$key),$dispositions);elseif($dispositions)throw new RuntimeException('Obligation dispositions apply to replan and closure only.');
            $next=match($transition){'start'=>'active','review'=>'in_review','pause','replan'=>'paused','close'=>'closed','close_incomplete'=>'closed_incomplete'};$payload=$cycle['payload'];
            foreach($recorded as $disposition)if($disposition['subject_kind']==='commitment'){$reference=$this->engineeringCurrentReference($id,$disposition['subject_ref']);$commitment=$reference['row'];$data=$commitment['payload'];$data['last_disposition']=$disposition;$state=match($disposition['action']){'deferred'=>'deferred','superseded'=>'superseded','removed_from_scope'=>'removed',default=>$commitment['state']};$this->engineeringAppendObject($id,$commitment['key'],'commitment',$commitment['title'],$commitment['content'],$state,$data,['source'=>'owner_cycle_'.$transition,'cycle_ref'=>$cycle['ref']],'owner',(int)$commitment['version']);}
            $configuration=$this->engineeringConfiguration($id,$cycle,$configurationRef);$configurationJson=self::engineeringJson($configuration);$configurationHash=hash('sha256',$configurationJson);$payload['configuration_snapshot']=['sha256'=>$configurationHash,'project_code_ref'=>$configurationRef,'event'=>$transition];
            $this->engineeringAppendObject($id,$key,'cycle',$cycle['title'],$cycle['content'],$next,$payload,['source'=>'owner_cycle_'.$transition,'reason'=>$reason],'owner',$version);
            $this->execute('INSERT INTO engineering_cycle_events(project_id,cycle_key,cycle_version,event,reason,configuration_json,configuration_sha256,dispositions_json,created_by,created_at)VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$key,$version+1,$transition,$reason,$configurationJson,$configurationHash,self::engineeringJson($recorded),'owner',time()]);$event=(int)$this->db->lastInsertId();$this->bump($id);return ['cycle_key'=>$key,'version'=>$version+1,'state'=>$next,'configuration_id'=>$event,'configuration_sha256'=>$configurationHash,'dispositions'=>$recorded];
        });
    }
    public function reportCommitment(int $id,string $key,int $version,string $state,string $source,string $scope,string $observation,string $limitations,int $revision,?string $configurationRef=null): int
    {
        if(!in_array($state,['reported_in_progress','reported_complete','blocked','deferred'],true)||!in_array($scope,['fixture','local','live_provider','hosted','manual','unknown'],true))throw new RuntimeException('Choose a reported work state and honest observation scope.');$source=self::text($source,1000);$observation=self::text($observation,6000);$limitations=self::text($limitations,3000);$configurationRef=$configurationRef===null||$configurationRef===''?null:self::text($configurationRef,1000);
        return $this->transaction(function()use($id,$key,$version,$state,$source,$scope,$observation,$limitations,$revision,$configurationRef):int{
            if((int)$this->project($id)['revision']!==$revision)throw new RuntimeException('Project changed. Refresh before recording an observation.');$commitment=$this->engineeringObject($id,$key);if(!$commitment||$commitment['kind']!=='commitment'||(int)$commitment['version']!==$version||in_array($commitment['state'],['superseded','removed'],true))throw new RuntimeException('Choose a current commitment for this owner report.');
            $expected=[];foreach($commitment['payload']['promise_refs']??[] as $ref){$reference=$this->engineeringReference($id,$ref,true);$expected[]=['ref'=>$ref,'statement'=>$reference['row']['content'],'method'=>$reference['row']['data']['method'],'required_scope'=>$reference['row']['data']['required_scope']??'unknown'];}
            $number=(int)$this->rows('SELECT COUNT(DISTINCT result_key) AS n FROM engineering_result_versions WHERE project_id=?',[$id])[0]['n']+1;$resultKey='RES-'.str_pad((string)$number,3,'0',STR_PAD_LEFT);$snapshot=['commitment_ref'=>$commitment['ref'],'expected_behaviour'=>$expected,'project_code_ref'=>$configurationRef,'scope'=>$scope,'owner_state'=>'not_reviewed','supports'=>'Owner-reported observation only; capture and review compatible evidence separately.','limitations'=>$limitations,'remaining'=>'Review the result and recorded assumptions; verification stays unchanged.'];
            $this->execute('INSERT INTO engineering_result_versions(project_id,result_key,version,commitment_key,commitment_version,work_state,origin,source,scope,observation,limitations,configuration_ref,snapshot_json,created_by,created_at)VALUES(?,?,1,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$resultKey,$key,$version,$state,'owner_reported',$source,$scope,$observation,$limitations,$configurationRef,self::engineeringJson($snapshot),'owner',time()]);$result=(int)$this->db->lastInsertId();
            $payload=$commitment['payload'];$payload['last_work_report']=['ref'=>'result:'.$resultKey.':v1','origin'=>'owner_reported','scope'=>$scope];$this->engineeringAppendObject($id,$key,'commitment',$commitment['title'],$commitment['content'],$state,$payload,['source'=>'owner_work_report','result_ref'=>'result:'.$resultKey.':v1'],'owner',$version);$this->bump($id);return $result;
        });
    }
    public function engineeringWorkspace(int $id): array
    {
        $state=$this->engineeringState($id);$hash=$this->everydayContextHash($id);$rows=$this->rows('SELECT p.* FROM engineering_proposal_versions p WHERE p.project_id=? AND NOT EXISTS(SELECT 1 FROM engineering_proposal_versions n WHERE n.project_id=p.project_id AND n.proposal_key=p.proposal_key AND n.version>p.version) ORDER BY p.id DESC LIMIT 30',[$id]);$proposals=[];
        foreach($rows as $row){$row=$this->engineeringDecodeProposal($row);$row['fresh']=hash_equals($row['context_hash'],$hash)&&(int)$row['base_revision']===(int)$this->project($id)['revision'];$proposals[]=$row;}
        $mismatches=[];foreach($state['links'] as $link){if($link['status']!=='active')continue;try{$current=$this->engineeringCurrentReference($id,$link['to_ref']);if(isset($link['payload']['subject_hash'])&&!hash_equals($link['payload']['subject_hash'],self::engineeringReferenceHash($current)))$mismatches[]=['kind'=>'system_mismatch','link_key'=>$link['link_key'],'old_ref'=>$link['to_ref'],'current_ref'=>$current['ref'],'observation'=>'The referenced subject changed semantically.','consequence'=>'Revisit the affected commitment or planned check; prior records remain retained.'];}catch(RuntimeException){$mismatches[]=['kind'=>'system_mismatch','link_key'=>$link['link_key'],'old_ref'=>$link['to_ref'],'current_ref'=>null,'observation'=>'The saved reference is missing or unavailable.','consequence'=>'Resolve the reference before relying on this commitment.'];}}
        $working=[];$linked=[];foreach($state['links'] as $link)if(!($link['payload']['analysis_only']??false))$linked[$link['to_ref']]=true;foreach($this->items($id) as $item)if($item['created_by']!=='ai_draft'||isset($linked[$item['kind'].':'.$item['item_key'].':v'.$item['version']]))$working[]=$item;
        $current=$state['active_cycle'];if(!$current)foreach($state['groups']['cycle'] as $cycle)if($cycle['state']==='planned'&&($cycle['payload']['commitment_refs']??[])){$current=$cycle;break;}if(!$current)foreach($state['groups']['cycle'] as $cycle)if($cycle['state']==='planned'){$current=$cycle;break;}$obligations=[];if($current)try{$obligations=$this->engineeringCycleObligations($id,$current['key']);}catch(RuntimeException $error){$mismatches[]=['kind'=>'system_mismatch','observation'=>$error->getMessage(),'consequence'=>'Review missing cycle references.'];}
        $history=array_map(fn($row)=>$this->engineeringDecodeObject($row),$this->rows('SELECT * FROM engineering_object_versions WHERE project_id=? ORDER BY id DESC',[$id]));$proposalHistory=array_map(fn($row)=>$this->engineeringDecodeProposal($row),$this->rows('SELECT * FROM engineering_proposal_versions WHERE project_id=? ORDER BY id DESC',[$id]));
        $state['cycle_events']=$this->rows('SELECT * FROM engineering_cycle_events WHERE project_id=? ORDER BY id DESC',[$id]);$resultHistory=$this->rows('SELECT * FROM engineering_result_versions WHERE project_id=? ORDER BY id DESC',[$id]);foreach($resultHistory as &$report)$report['snapshot']=json_decode($report['snapshot_json'],true,64,JSON_THROW_ON_ERROR);unset($report);
        return $state+['proposals'=>$proposals,'current_cycle'=>$current,'system_mismatches'=>$mismatches,'cycle_obligations'=>$obligations,'working_items'=>$working,'history'=>$history,'proposal_history'=>$proposalHistory,'result_history'=>$resultHistory,'profile_history'=>$this->rows('SELECT * FROM engineering_profile_versions WHERE project_id=? ORDER BY version DESC',[$id])];
    }
}
