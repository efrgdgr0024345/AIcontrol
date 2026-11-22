<?php
declare(strict_types=1);
namespace AIcontrol;

use PDO;
use RuntimeException;

/** Owner-recorded planning provenance; a model assessment never approves a requirement. */
trait NasaIntegration
{
    private static function migrateIntegration(PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS nasa_provenance (
            id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,
            item_kind TEXT NOT NULL, item_key TEXT NOT NULL, subject_item_id INTEGER NOT NULL REFERENCES nasa_items(id) ON DELETE CASCADE,
            subject_hash TEXT NOT NULL, link_key TEXT NOT NULL, version INTEGER NOT NULL,
            source_kind TEXT NOT NULL, source_id INTEGER NOT NULL, research_project_id INTEGER, run_id INTEGER,
            snapshot_json TEXT NOT NULL, source_sha256 TEXT NOT NULL, note TEXT NOT NULL,
            status TEXT NOT NULL, review_note TEXT NOT NULL, created_by TEXT NOT NULL, created_at INTEGER NOT NULL,
            UNIQUE(project_id,link_key,version));
            CREATE INDEX IF NOT EXISTS nasa_provenance_subject ON nasa_provenance(project_id,item_kind,item_key);
            CREATE TABLE IF NOT EXISTS nasa_research_origins (
            id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,
            origin_key TEXT NOT NULL, origin_kind TEXT NOT NULL, origin_item_key TEXT NOT NULL,
            subject_hash TEXT NOT NULL, snapshot_json TEXT NOT NULL, research_project_id INTEGER NOT NULL, research_identity TEXT NOT NULL DEFAULT "",
            run_id INTEGER NOT NULL DEFAULT 0, run_identity TEXT NOT NULL DEFAULT "", node_id INTEGER, created_by TEXT NOT NULL, created_at INTEGER NOT NULL,
            UNIQUE(project_id,origin_key,run_id));');
        UsageMeter::columns($db,'nasa_research_origins',['research_identity'=>'TEXT NOT NULL DEFAULT ""','run_identity'=>'TEXT NOT NULL DEFAULT ""']);
    }

    private static function integrationJson(array $value): string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }

    public static function subjectHash(string $kind,string $title,string $content,array $data): string
    {
        if($kind==='requirement'){
            $subject=['source'=>$data['source']??'','method'=>$data['method']??''];
            // Keep pre-scope requirement hashes byte-for-byte compatible. New
            // non-default constraints participate in review/confirmation identity.
            if(($data['required_scope']??'unknown')!=='unknown')$subject['required_scope']=$data['required_scope'];
            if(in_array($data['configuration_required']??false,[true,1,'1'],true))$subject['configuration_required']=true;
            if(trim((string)($data['required_configuration_ref']??''))!=='')$subject['required_configuration_ref']=trim($data['required_configuration_ref']);
            $data=$subject;
        }
        else {foreach(['evidence','verification_confirmed','owner_confirmed','knowledge_version'] as $field)unset($data[$field]);ksort($data);}
        return hash('sha256',self::integrationJson(['kind'=>$kind,'title'=>trim($title),'content'=>trim($content),'data'=>$data]));
    }

    /** Current explicit research scopes, including projects spawned by an owner planning action. */
    private function integrationResearchProjects(int $project): array
    {
        $p=$this->project($project);$ids=[];
        if($p['linked_research_id'])$ids[]=(int)$p['linked_research_id'];
        $db=$this->research();
        if($db)foreach($this->rows('SELECT DISTINCT research_project_id,research_identity FROM nasa_research_origins WHERE project_id=?',[$project]) as $row){
            $q=$db->prepare('SELECT identity_token FROM research_projects WHERE id=?');$q->execute([$row['research_project_id']]);$token=$q->fetchColumn();
            if(is_string($token)&&$token!==''&&hash_equals($row['research_identity'],$token))$ids[]=(int)$row['research_project_id'];
        }
        return array_values(array_unique($ids));
    }

    public function researchSource(int $project,string $sourceKind,int $sourceId,int $runId): array
    {
        $allowed=['research_node','research_source','research_passage','research_edge','research_claim_version'];
        if(!in_array($sourceKind,$allowed,true)||$sourceId<1||$runId<1)throw new RuntimeException('Choose a saved research source and its investigation.');
        $db=$this->research();if(!$db)throw new RuntimeException('Research storage is unavailable.');
        $scopes=$this->integrationResearchProjects($project);
        $q=$db->prepare('SELECT r.id,r.project_id,r.started_at,r.identity_token AS run_identity,p.name,p.identity_token FROM research_runs r JOIN research_projects p ON p.id=r.project_id WHERE r.id=?');$q->execute([$runId]);$run=$q->fetch();
        if(!$run||!in_array((int)$run['project_id'],$scopes,true))throw new RuntimeException('Choose research from this planning project’s linked or focused projects.');
        $queries=[
            'research_node'=>'SELECT n.* FROM research_nodes n JOIN research_runs r ON r.id=n.run_id WHERE n.id=? AND n.run_id=? AND r.project_id=?',
            'research_source'=>'SELECT s.* FROM research_sources s JOIN research_runs r ON r.id=s.run_id WHERE s.id=? AND s.run_id=? AND r.project_id=?',
            'research_passage'=>'SELECT p.*,s.url,s.title AS source_title,s.domain,s.retrieved_at FROM research_passages p JOIN research_sources s ON s.id=p.source_id AND s.run_id=p.run_id JOIN research_runs r ON r.id=p.run_id WHERE p.id=? AND p.run_id=? AND r.project_id=?',
            'research_claim_version'=>'SELECT v.*,n.title,n.content,n.data_json,n.selected,n.type FROM research_claim_versions v JOIN research_nodes n ON n.id=v.claim_id AND n.run_id=v.run_id JOIN research_runs r ON r.id=v.run_id WHERE v.id=? AND v.run_id=? AND r.project_id=?',
            'research_edge'=>'SELECT e.*,n.title AS claim_title,n.selected,n.type,p.exact_text,p.locator,p.fetch_scope,p.content_hash,s.url,s.title AS source_title,s.domain FROM research_edges e JOIN research_nodes n ON n.id=e.claim_id AND n.run_id=e.run_id JOIN research_sources s ON s.id=e.source_id AND s.run_id=e.run_id LEFT JOIN research_passages p ON p.id=e.passage_id AND p.run_id=e.run_id AND p.source_id=e.source_id JOIN research_runs r ON r.id=e.run_id WHERE e.id=? AND e.run_id=? AND r.project_id=?',
        ];
        $q=$db->prepare($queries[$sourceKind]);$q->execute([$sourceId,$runId,$run['project_id']]);$row=$q->fetch();
        if(!$row)throw new RuntimeException('The selected research source is unavailable or belongs to another investigation.');
        $snapshot=['source_kind'=>$sourceKind,'source_id'=>$sourceId,'run_id'=>$runId,'run_identity'=>$run['run_identity'],'research_project_id'=>(int)$run['project_id'],'research_identity'=>$run['identity_token'],'run_started_at'=>(int)$run['started_at'],'project_title'=>$run['name'],'title'=>'','content'=>'','reference'=>'','method'=>null,'eligible_evidence'=>false,'scope'=>'Saved research context, not independently verified.','evidence_scope'=>'unknown','configuration_ref'=>'','limitations'=>'No product runtime or tested configuration is established by this saved research record.'];
        if($sourceKind==='research_node'){
            if(!in_array($row['type'],['dream_claim','question','model_idea','association','counter','edge_case','reframing','synthesis','search'],true))throw new RuntimeException('Choose a saved research idea or finding.');
            $snapshot+=['node_type'=>$row['type'],'origin'=>$row['origin'],'provenance_tag'=>$row['provenance_tag'],'locked_at'=>$row['locked_at']];
            $snapshot['title']=$row['title'];$snapshot['content']=$row['content'];$snapshot['data']=json_decode($row['data_json'],true,64,JSON_THROW_ON_ERROR);
            $snapshot['scope']='Research idea or model finding; provisional context, never verification evidence.';
        }elseif($sourceKind==='research_source'){
            $snapshot['title']=$row['title'];$snapshot['content']=$row['extract'];$snapshot['reference']=$row['url'];$snapshot['scope']='Citation/source card only; no captured passage or owner verification.';
            $snapshot+=['domain'=>$row['domain'],'retrieved_at'=>(int)$row['retrieved_at']];
        }elseif($sourceKind==='research_passage'){
            $snapshot['title']=$row['source_title'];$snapshot['content']=$row['exact_text'];$snapshot['reference']=$row['url'];$snapshot['eligible_evidence']=true;
            $snapshot['scope']='Captured '.$row['fetch_scope'].'; owner review must assess relevance and limitations.';
            $snapshot['evidence_scope']='web_source';$snapshot['origin']='captured_web_source';$snapshot['limitations']='Captured web text can support a reviewed analysis or inspection. It does not establish that the product, provider or hosted website was tested.';
            $snapshot+=['source_record_id'=>(int)$row['source_id'],'locator'=>$row['locator'],'fetch_scope'=>$row['fetch_scope'],'content_hash'=>$row['content_hash'],'domain'=>$row['domain']];
        }elseif($sourceKind==='research_edge'){
            if($row['type']!=='dream_claim')throw new RuntimeException('Choose a captured evidence relation for a research claim.');
            $snapshot['title']=$row['claim_title'].' · '.$row['relation'];$snapshot['content']=($row['exact_text']??'')."\nRelation rationale: ".$row['rationale'];$snapshot['reference']=$row['url'];
            $snapshot['eligible_evidence']=$row['passage_id']!==null&&$row['exact_text']!==null&&$row['relation']==='supports'&&(int)$row['selected']===1;
            $snapshot['scope']='Recorded model evidence relation; only a captured supporting passage can be reviewed as evidence.';
            $snapshot['evidence_scope']=$row['passage_id']!==null&&$row['exact_text']!==null?'web_source':'unknown';$snapshot['origin']='research_model_relation';$snapshot['limitations']='The relation is a model assessment of captured text. Owner review does not turn it into an executed product test or independent verification.';
            $snapshot+=['claim_id'=>(int)$row['claim_id'],'source_record_id'=>(int)$row['source_id'],'passage_id'=>$row['passage_id']===null?null:(int)$row['passage_id'],'relation'=>$row['relation'],'strength'=>$row['strength'],'locator'=>$row['locator'],'fetch_scope'=>$row['fetch_scope'],'content_hash'=>$row['content_hash']];
        }else{
            if($row['type']!=='dream_claim')throw new RuntimeException('Choose a saved claim assessment.');
            $snapshot['title']=$row['title'].' · '.$row['status'];$snapshot['content']=$row['content']."\nAssessment: ".$row['rationale'];$snapshot['scope']='Grouped model claim assessment, not an owner review or verification result.';
            $snapshot+=['claim_id'=>(int)$row['claim_id'],'assessment_status'=>$row['status'],'assessment_by'=>$row['created_by'],'evidence_strength'=>$row['evidence_strength']];
        }
        return $snapshot;
    }

    public function researchChoices(int $project): array
    {
        $out=[];$db=$this->research();if(!$db)return $out;
        foreach($this->integrationResearchProjects($project) as $research){
            $q=$db->prepare('SELECT id FROM research_runs WHERE project_id=? ORDER BY id DESC LIMIT 20');$q->execute([$research]);
            foreach($q->fetchAll() as $run)foreach(['research_node'=>'research_nodes','research_source'=>'research_sources','research_passage'=>'research_passages','research_edge'=>'research_edges','research_claim_version'=>'research_claim_versions'] as $kind=>$table){
                $q=$db->prepare('SELECT id FROM '.$table.' WHERE run_id=? ORDER BY id DESC LIMIT 100');$q->execute([$run['id']]);
                foreach($q->fetchAll() as $row){try{$s=$this->researchSource($project,$kind,(int)$row['id'],(int)$run['id']);}catch(RuntimeException){continue;}
                    $out[]=['source_kind'=>$kind,'source_id'=>(int)$row['id'],'run_id'=>(int)$run['id'],'title'=>'Investigation #'.$run['id'].' · '.str_replace('_',' ',$kind).' #'.$row['id'].' · '.$s['title'],'content'=>mb_strcut($s['content'],0,1000,'UTF-8'),'eligible_evidence'=>$s['eligible_evidence']];
                    if(count($out)>=300)return $out;
                }
            }
        }
        return $out;
    }

    private function manualEvidence(array $manual): array
    {
        $method=$manual['method']??'';if(!in_array($method,['test','analysis','inspection','demonstration'],true))throw new RuntimeException('Choose the method used by this evidence.');
        $scope=$manual['scope']??'unknown';if($scope==='')$scope='unknown';if(!in_array($scope,['unknown','fixture','local','live_provider','hosted','manual'],true))throw new RuntimeException('Choose the actual evidence scope, or leave it unknown.');
        return ['source_kind'=>'manual_evidence','source_id'=>0,'run_id'=>0,'research_project_id'=>null,'title'=>self::text($manual['title']??'',160),'content'=>self::text($manual['content']??'',6000),'reference'=>self::text($manual['reference']??'',2000),'method'=>$method,'eligible_evidence'=>true,'scope'=>'Owner-recorded evidence and result reference; not independently observed by AIcontrol.','origin'=>'owner_reported','evidence_scope'=>$scope,'configuration_ref'=>self::text($manual['configuration_ref']??'',1000,true),'limitations'=>self::text($manual['limitations']??'',2000,true)];
    }

    /** Compare old research snapshots without rewriting their historical bytes. */
    private static function comparableResearchSnapshot(array $live,array $recorded): array
    {
        foreach(['evidence_scope','configuration_ref','limitations','origin'] as $key)if(!array_key_exists($key,$recorded))unset($live[$key]);
        return $live;
    }

    public static function evidenceMetadata(array $snapshot): array
    {
        $scope=$snapshot['evidence_scope']??'unknown';if(!in_array($scope,['unknown','fixture','local','live_provider','hosted','manual','web_source'],true))$scope='unknown';
        return ['evidence_scope'=>$scope,'configuration_ref'=>is_string($snapshot['configuration_ref']??null)?$snapshot['configuration_ref']:'','limitations'=>is_string($snapshot['limitations']??null)?$snapshot['limitations']:'Scope, tested configuration and limitations were not recorded in this older snapshot.','origin'=>($snapshot['source_kind']??'')==='manual_evidence'?'owner_reported':($snapshot['origin']??'legacy_unknown')];
    }

    private function appendProvenance(array $item,array $snapshot,string $note,?array $previous=null,string $status='linked',string $reviewNote=''): int
    {
        $json=self::integrationJson($snapshot);$data=json_decode($item['data_json'],true,64,JSON_THROW_ON_ERROR);
        $this->execute('INSERT INTO nasa_provenance(project_id,item_kind,item_key,subject_item_id,subject_hash,link_key,version,source_kind,source_id,research_project_id,run_id,snapshot_json,source_sha256,note,status,review_note,created_by,created_at)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
            $item['project_id'],$item['kind'],$item['item_key'],$item['id'],self::subjectHash($item['kind'],$item['title'],$item['content'],$data),$previous['link_key']??bin2hex(random_bytes(16)),(int)($previous['version']??0)+1,$snapshot['source_kind'],$snapshot['source_id'],$snapshot['research_project_id'],$snapshot['run_id']?:null,$json,hash('sha256',$json),$note,$status,$reviewNote,'owner',time()]);
        return (int)$this->db->lastInsertId();
    }

    private function integrationItem(int $project,string $kind,string $key,int $expected): array
    {
        $this->project($project);$item=$this->current($project,$kind,$key);
        if(!$item||$item['status']==='removed'||(int)$item['version']!==$expected)throw new RuntimeException('This planning record changed. Refresh before linking or reviewing evidence.');
        return $item;
    }

    public function attachProvenance(int $project,string $kind,string $key,int $expectedItemVersion,string $sourceKind,int $sourceId,int $runId,string $note='',array $manual=[]): int
    {
        $note=self::text($note,2000,true);
        return $this->transaction(function()use($project,$kind,$key,$expectedItemVersion,$sourceKind,$sourceId,$runId,$note,$manual):int{
            $item=$this->integrationItem($project,$kind,$key,$expectedItemVersion);
            $snapshot=$sourceKind==='manual_evidence'?$this->manualEvidence($manual):$this->researchSource($project,$sourceKind,$sourceId,$runId);
            $link=$this->appendProvenance($item,$snapshot,$note);$this->bump($project);return $link;
        });
    }

    public function provenance(int $project,?string $kind=null,?string $key=null,bool $history=false): array
    {
        $this->project($project);$sql='SELECT l.*,i.version AS item_version FROM nasa_provenance l JOIN nasa_items i ON i.id=l.subject_item_id WHERE l.project_id=?';$args=[$project];
        if($kind!==null){$sql.=' AND l.item_kind=?';$args[]=$kind;}if($key!==null){$sql.=' AND l.item_key=?';$args[]=$key;}
        if(!$history)$sql.=' AND NOT EXISTS(SELECT 1 FROM nasa_provenance newer WHERE newer.project_id=l.project_id AND newer.link_key=l.link_key AND newer.version>l.version)';
        $links=$this->rows($sql.' ORDER BY l.id',$args);
        foreach($links as &$link){$link['kind']=$link['item_kind'];$link['snapshot']=$link['source_snapshot']=json_decode($link['snapshot_json'],true,64,JSON_THROW_ON_ERROR);$link['reviewed']=$link['status']==='reviewed';$link['eligible_evidence']=(bool)$link['snapshot']['eligible_evidence'];$link['source_current']=true;
            if(!hash_equals($link['source_sha256'],hash('sha256',self::integrationJson($link['snapshot']))))$link['source_current']=false;
            if($link['source_kind']!=='manual_evidence')try{$live=self::comparableResearchSnapshot($this->researchSource($project,$link['source_kind'],(int)$link['source_id'],(int)$link['run_id']),$link['snapshot']);$link['source_current']=$link['source_current']&&hash_equals($link['source_sha256'],hash('sha256',self::integrationJson($live)));}catch(RuntimeException){$link['source_current']=false;}
            $current=$this->current($project,$link['item_kind'],$link['item_key']);$link['subject_current']=$current&&$current['status']!=='removed'&&hash_equals($link['subject_hash'],self::subjectHash($current['kind'],$current['title'],$current['content'],json_decode($current['data_json'],true,64,JSON_THROW_ON_ERROR)));
            $link['evidence_metadata']=self::evidenceMetadata($link['snapshot']);
        }unset($link);return $links;
    }

    public function reviewProvenance(int $project,int $linkId,int $expectedLinkVersion,int $expectedItemVersion,string $reviewNote,bool $reviewed=true): int
    {
        $reviewNote=self::text($reviewNote,2000);
        return $this->transaction(function()use($project,$linkId,$expectedLinkVersion,$expectedItemVersion,$reviewNote,$reviewed):int{
            $old=$this->rows('SELECT * FROM nasa_provenance WHERE id=? AND project_id=?',[$linkId,$project])[0]??throw new RuntimeException('Choose an evidence link from this planning project.');
            $latest=$this->rows('SELECT id,version FROM nasa_provenance WHERE project_id=? AND link_key=? ORDER BY version DESC LIMIT 1',[$project,$old['link_key']])[0];
            if((int)$old['version']!==$expectedLinkVersion||(int)$latest['id']!==$linkId)throw new RuntimeException('This evidence review changed. Refresh before reviewing it again.');
            $item=$this->integrationItem($project,$old['item_kind'],$old['item_key'],$expectedItemVersion);$data=json_decode($item['data_json'],true,64,JSON_THROW_ON_ERROR);
            if(!hash_equals($old['subject_hash'],self::subjectHash($item['kind'],$item['title'],$item['content'],$data)))throw new RuntimeException('The planning subject changed. Attach evidence to its current version before reviewing it.');
            $snapshot=json_decode($old['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            if(!hash_equals($old['source_sha256'],hash('sha256',self::integrationJson($snapshot))))throw new RuntimeException('Evidence snapshot integrity check failed.');
            if($reviewed&&$old['source_kind']!=='manual_evidence'){$live=self::comparableResearchSnapshot($this->researchSource($project,$old['source_kind'],(int)$old['source_id'],(int)$old['run_id']),$snapshot);if(!hash_equals($old['source_sha256'],hash('sha256',self::integrationJson($live))))throw new RuntimeException('The research source changed. Attach its current version before reviewing it.');}
            $id=$this->appendProvenance($item,$snapshot,$old['note'],$old,$reviewed?'reviewed':'revoked',$reviewNote);$this->bump($project);return $id;
        });
    }

    public function hasReviewedEvidence(int $project,string $key,string $title,string $content,array $data): bool
    {
        $subject=self::subjectHash('requirement',$title,$content,$data);
        foreach($this->provenance($project,'requirement',$key) as $link)if($link['reviewed']&&$link['eligible_evidence']&&$link['source_current']&&$link['subject_current']&&hash_equals($subject,$link['subject_hash'])&&($link['source_kind']!=='manual_evidence'||$link['snapshot']['method']===($data['method']??null)))return true;
        return false;
    }

    private static function knownEvidenceConfiguration(string $reference): bool
    {
        $value=mb_strtolower(trim($reference),'UTF-8');
        return $value!==''&&!in_array($value,['unknown','not known','not recorded','not specified','not provided','none','n/a','na','tbd','tbc','unavailable','unspecified','unrecorded','[unknown]','?'],true);
    }

    /** Additional Everyday constraints; the heritage evidence gate stays separate. */
    public function hasScopedReviewedEvidence(int $project,string $key,string $title,string $content,array $data): bool
    {
        $requiredScope=$data['required_scope']??'unknown';
        if(!in_array($requiredScope,['unknown','fixture','local','live_provider','hosted','manual','web_source'],true))return false;
        $requiredReference=trim((string)($data['required_configuration_ref']??''));
        $needsConfiguration=in_array($data['configuration_required']??false,[true,1,'1'],true)||$requiredReference!=='';
        if($needsConfiguration&&($requiredScope==='unknown'||!self::knownEvidenceConfiguration($requiredReference)))return false;
        $subject=self::subjectHash('requirement',$title,$content,$data);
        foreach($this->provenance($project,'requirement',$key) as $link){
            if(!$link['reviewed']||!$link['eligible_evidence']||!$link['source_current']||!$link['subject_current']||!hash_equals($subject,$link['subject_hash']))continue;
            if($link['source_kind']==='manual_evidence'&&$link['snapshot']['method']!==($data['method']??null))continue;
            $metadata=$link['evidence_metadata'];
            if($requiredScope!=='unknown'&&$metadata['evidence_scope']!==$requiredScope)continue;
            if($metadata['evidence_scope']==='web_source'&&in_array($data['method']??null,['test','demonstration'],true))continue;
            if($needsConfiguration&&!self::knownEvidenceConfiguration($metadata['configuration_ref']))continue;
            if($requiredReference!==''&&!hash_equals($requiredReference,trim($metadata['configuration_ref'])))continue;
            return true;
        }
        return false;
    }

    public function assertVerifiedEvidence(int $project,string $key,string $title,string $content,array $data,string $by='owner'): void
    {
        if($by!=='owner'||!in_array($data['verification_confirmed']??null,[true,'1'],true))throw new RuntimeException('Explicitly confirm that you reviewed the linked evidence before marking this requirement verified.');
        $requiredReference=trim((string)($data['required_configuration_ref']??''));
        $needsConfiguration=in_array($data['configuration_required']??false,[true,1,'1'],true)||$requiredReference!=='';
        if($needsConfiguration&&($data['required_scope']??'unknown')==='unknown')throw new RuntimeException('Choose the evidence scope required by this promise before confirming verification.');
        if($needsConfiguration&&!self::knownEvidenceConfiguration($requiredReference))throw new RuntimeException('Record the expected project configuration reference before confirming this promise. A reported tested build alone does not establish which configuration the promise applies to.');
        if($this->hasReviewedEvidence($project,$key,$title,$content,$data))return;
        throw new RuntimeException('Attach and explicitly review relevant evidence for this exact requirement and verification method before marking it verified.');
    }

    public function promoteResearch(int $project,string $sourceKind,int $sourceId,int $runId,string $targetKind,string $targetKey,string $title,string $content,array $data,int $expectedVersion): string
    {
        if(!in_array($targetKind,['artifact','requirement','risk','decision'],true))throw new RuntimeException('Choose a draft requirement, risk, decision or artifact.');
        return $this->transaction(function()use($project,$sourceKind,$sourceId,$runId,$targetKind,$targetKey,$title,$content,$data,$expectedVersion):string{
            $snapshot=$this->researchSource($project,$sourceKind,$sourceId,$runId);$draftTitle=$title===''?mb_strcut($snapshot['title'],0,160,'UTF-8'):self::text($title,160);$draftContent=self::text($content===''?$snapshot['content']:$content,$targetKind==='artifact'?12000:3000);
            $provenanceNote=($data['provenance_note']??'')===''?'Seeded from research by the owner; editable draft, not verification.':self::text($data['provenance_note'],2000);
            if($targetKind==='artifact'){
                if(!isset(NasaKnowledge::ARTIFACTS[$targetKey]))throw new RuntimeException('Choose a known artifact for this research draft.');
                $draftTitle=NasaKnowledge::ARTIFACTS[$targetKey][0];$draftData=['reference'=>NasaKnowledge::ARTIFACTS[$targetKey][2]];$status='draft';
            }else{
                $everyday=$this->engineeringProfile($project)['mode']==='everyday';
                if($targetKind==='requirement'){
                    $previous=$targetKey!==''?$this->current($project,'requirement',$targetKey):null;
                    if($previous){
                        // Research can seed a new text version, not silently
                        // replace the owner's existing verification policy.
                        $previousData=json_decode($previous['data_json'],true,64,JSON_THROW_ON_ERROR);
                        foreach(['configuration_required','required_scope','required_configuration_ref'] as $constraint)if(array_key_exists($constraint,$previousData))$data[$constraint]=$previousData[$constraint];
                    }elseif($everyday&&$targetKey===''){
                        $data['configuration_required']=true;$data['required_scope']='unknown';unset($data['required_configuration_ref']);
                    }
                }elseif($targetKind==='risk'&&$everyday&&$targetKey===''){
                    $data['rating_status']='not_assessed';foreach(['likelihood','consequence','residual_likelihood','residual_consequence'] as $rating)$data[$rating]=null;
                }
                $status=['requirement'=>'unverified','risk'=>'open','decision'=>'proposed'][$targetKind];$draftData=$this->validateRecord($project,$targetKind,$draftTitle,$draftContent,$data,$status);
                $prefix=['requirement'=>'REQ','risk'=>'RISK','decision'=>'DEC'][$targetKind];
                if($targetKey===''){$number=(int)$this->rows('SELECT COUNT(DISTINCT item_key) AS n FROM nasa_items WHERE project_id=? AND kind=?',[$project,$targetKind])[0]['n']+1;$targetKey=$prefix.'-'.str_pad((string)$number,3,'0',STR_PAD_LEFT);if($expectedVersion!==0)throw new RuntimeException('Use version zero for a new planning draft.');}
                elseif(!preg_match('/^'.$prefix.'-[0-9]{3,10}$/D',$targetKey)||!$this->current($project,$targetKind,$targetKey))throw new RuntimeException('Choose an existing planning record from this project.');
            }
            $row=$this->append($project,$targetKind,$targetKey,$draftTitle,$draftContent,$status,$draftData,'owner_research_draft',$expectedVersion);$item=$this->rows('SELECT * FROM nasa_items WHERE id=?',[$row])[0];
            $this->appendProvenance($item,$snapshot,$provenanceNote);$this->bump($project);return $targetKey;
        });
    }

    public function focusedResearchHistory(int $project): array
    {
        $this->project($project);return $this->rows('SELECT * FROM nasa_research_origins WHERE project_id=? ORDER BY id DESC',[$project]);
    }

    private function focusedSubject(int $project,string $kind,string $key,int $expectedItemVersion): array
    {
        if($kind==='gap'){
            if($expectedItemVersion!==0)throw new RuntimeException('Refresh the current readiness gap before creating research.');
            $p=$this->project($project);$check=null;foreach($this->readiness($project)['checks'] as $candidate)if($candidate['key']===$key&&!$candidate['passed']){$check=$candidate;break;}
            if(!$check)throw new RuntimeException('Choose an unresolved readiness gap from this project.');
            return ['kind'=>'gap','key'=>$key,'phase'=>$p['phase'],'title'=>$check['title'],'content'=>$check['detail']];
        }
        if($kind!=='risk')throw new RuntimeException('Choose an unresolved readiness gap or open high or unassessed residual risk.');
        $item=$this->integrationItem($project,'risk',$key,$expectedItemVersion);$data=json_decode($item['data_json'],true,64,JSON_THROW_ON_ERROR);
        $score=self::riskScore($data);if($item['status']!=='open'||$score!==null&&$score<15)throw new RuntimeException('Choose an open high or unassessed residual risk for focused research.');
        return ['kind'=>'risk','key'=>$key,'version'=>(int)$item['version'],'title'=>$item['title'],'content'=>$item['content'],'data'=>$data];
    }

    public function focusedResearch(int $project,string $kind,string $key,int $expectedItemVersion,int $expectedRevision,int $runId=0): array
    {
        $p=$this->project($project);if((int)$p['revision']!==$expectedRevision)throw new RuntimeException('Project changed. Refresh before creating focused research.');
        $subject=$this->focusedSubject($project,$kind,$key,$expectedItemVersion);$hash=hash('sha256',self::integrationJson($subject));$origin='nasa-gap:'.$project.':'.$hash;
        $brief=mb_strcut('Investigate this engineering '.$kind.': '.$subject['title']."\n\n".$subject['content']."\n\nProject context: ".$p['source']['brief']."\n\nExplore alternative explanations, unresolved uncertainty and a discriminating test. Model ideas are provisional. Web Evidence requires a separate explicit user selection.",0,4000,'UTF-8');
        $result=static fn(array $row,bool $created)=>['research_project_id'=>(int)$row['research_project_id'],'run_id'=>(int)$row['run_id']?:null,'node_id'=>$row['node_id']===null?null:(int)$row['node_id'],'created'=>$created,'requires_start'=>(int)$row['run_id']===0,'location'=>'/loader.php?view=research&project='.$row['research_project_id'].((int)$row['run_id']?'&run='.$row['run_id']:'')];
        if($runId>0){
            $engine=new Investigation($this->directory);
            return $this->transaction(function()use($project,$kind,$key,$expectedItemVersion,$expectedRevision,$runId,$subject,$hash,$origin,$brief,$result,$engine):array{
                if((int)$this->project($project)['revision']!==$expectedRevision)throw new RuntimeException('Project changed. Refresh before creating focused research.');
                $db=$this->research();if(!$db)throw new RuntimeException('Research storage is unavailable.');$q=$db->prepare('SELECT r.id,r.project_id,r.identity_token AS run_identity,p.identity_token FROM research_runs r JOIN research_projects p ON p.id=r.project_id WHERE r.id=?');$q->execute([$runId]);$run=$q->fetch();$q->closeCursor();
                if(!$run||!in_array((int)$run['project_id'],$this->integrationResearchProjects($project),true))throw new RuntimeException('Choose an idle investigation from this planning project’s research.');
                $proposalOrigin='nasa-gap:'.$project.':'.hash('sha256',$hash.$run['run_identity']);
                $old=$this->rows('SELECT * FROM nasa_research_origins WHERE project_id=? AND origin_key=? AND run_id=?',[$project,$proposalOrigin,$runId]);
                if($old){$q=$db->prepare("SELECT data_json FROM research_nodes WHERE id=? AND run_id=? AND origin='user'");$q->execute([$old[0]['node_id'],$runId]);$stored=$q->fetchColumn();$q->closeCursor();
                    if($old[0]['research_identity']!==$run['identity_token']||$old[0]['run_identity']!==$run['run_identity']||!is_string($stored)||(json_decode($stored,true)['proposal_key']??null)!==$proposalOrigin)throw new RuntimeException('The earlier research proposal no longer matches this investigation. Refresh and choose a current run.');
                    return $result($old[0],false);
                }
                $node=$engine->addUserIdea($runId,mb_strcut($subject['title'],0,160,'UTF-8'),$brief,$proposalOrigin);
                $this->execute('INSERT INTO nasa_research_origins(project_id,origin_key,origin_kind,origin_item_key,subject_hash,snapshot_json,research_project_id,research_identity,run_id,run_identity,node_id,created_by,created_at)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$project,$proposalOrigin,$kind,$key,$hash,self::integrationJson($subject),$run['project_id'],$run['identity_token'],$runId,$run['run_identity'],$node,'owner',time()]);$this->bump($project);
                return $result(['research_project_id'=>$run['project_id'],'run_id'=>$runId,'node_id'=>$node],true);
            },false);
        }
        // A local project and its origin are committed together; no key, model request or Evidence stage is started.
        require_once __DIR__.'/Research.php';new Research($this->directory);$alias='nasa_focus_research';
        $this->db->exec('ATTACH DATABASE '.$this->db->quote($this->directory.'/research.sqlite').' AS '.$alias);
        try{return $this->transaction(function()use($project,$kind,$key,$expectedRevision,$subject,$hash,$origin,$brief,$result,$alias):array{
            if((int)$this->project($project)['revision']!==$expectedRevision)throw new RuntimeException('Project changed. Refresh before creating focused research.');
            $old=$this->rows('SELECT o.* FROM nasa_research_origins o JOIN '.$alias.'.research_projects p ON p.id=o.research_project_id AND p.identity_token=o.research_identity WHERE o.project_id=? AND o.subject_hash=? AND o.run_id=0 ORDER BY o.id DESC LIMIT 1',[$project,$hash]);if($old)return $result($old[0],false);
            $name=mb_strcut('Engineering research: '.$subject['title'],0,160,'UTF-8');$this->execute('INSERT INTO '.$alias.'.research_projects(name,brief)VALUES(?,?)',[$name,$brief]);$research=(int)$this->db->lastInsertId();
            $identity=$this->rows('SELECT identity_token FROM '.$alias.'.research_projects WHERE id=?',[$research])[0]['identity_token'];
            $recordOrigin='nasa-gap:'.$project.':'.hash('sha256',$hash.$identity);
            $this->execute('INSERT INTO nasa_research_origins(project_id,origin_key,origin_kind,origin_item_key,subject_hash,snapshot_json,research_project_id,research_identity,run_id,node_id,created_by,created_at)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$project,$recordOrigin,$kind,$key,$hash,self::integrationJson($subject),$research,$identity,0,null,'owner',time()]);$this->bump($project);
            return $result(['research_project_id'=>$research,'run_id'=>0,'node_id'=>null],true);
        });}finally{$this->db->exec('DETACH DATABASE '.$alias);}
    }
}
