<?php
declare(strict_types=1);
namespace AIcontrol;
use RuntimeException;

/** Append-only AI proposals. Only an explicit owner decision materialises drafts. */
trait NasaCollaboration
{
    public function initCollaboration(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS nasa_proposals(
            id INTEGER PRIMARY KEY,
            project_id INTEGER NOT NULL REFERENCES nasa_projects(id) ON DELETE CASCADE,
            proposal_key TEXT NOT NULL,
            version INTEGER NOT NULL,
            request_id INTEGER NOT NULL,
            base_revision INTEGER NOT NULL,
            context_hash TEXT NOT NULL,
            types_json TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ("pending","accepted","rejected","superseded")),
            payload_json TEXT NOT NULL,
            disposition_json TEXT NOT NULL,
            created_by TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            UNIQUE(project_id,proposal_key,version));
            CREATE INDEX IF NOT EXISTS nasa_proposal_versions ON nasa_proposals(project_id,proposal_key,version);');
    }
    private static function proposalTypes(array $types): void
    {
        if(!array_is_list($types)||count($types)>3||array_filter($types,static fn($v)=>!is_string($v))||count(array_unique($types))!==count($types)||array_diff($types,array_keys(NasaKnowledge::ARTIFACTS)))throw new RuntimeException('Choose up to three known artifact types for one proposal bundle.');
    }
    public static function proposalSchema(array $types=[]): array
    {
        self::proposalTypes($types);
        $object=static fn(array $p)=>['type'=>'object','additionalProperties'=>false,'properties'=>$p,'required'=>array_keys($p)];
        $text=static fn(int $max)=>['type'=>'string','minLength'=>1,'maxLength'=>$max];
        $list=static fn(array $item,int $max,int $min=0)=>['type'=>'array','items'=>$item,'minItems'=>$min,'maxItems'=>$max];
        $next=$object(['process'=>['type'=>'string','enum'=>array_keys(NasaKnowledge::processes())],'title'=>$text(160),'why'=>$text(1200),'next_step'=>$text(1200)]);
        $rating=['type'=>'integer','minimum'=>1,'maximum'=>5];
        return $object([
            'primary'=>$next,
            'alternatives'=>$list($next,3,2),
            'assumptions'=>$list($text(500),8),
            'unknowns'=>$list($text(500),8),
            'bundle'=>$object([
                'artifacts'=>$list($object(['type'=>['type'=>'string','enum'=>array_keys(NasaKnowledge::ARTIFACTS)],'content'=>$text(3000)]),count($types),count($types)),
                'requirements'=>$list($object(['title'=>$text(40),'statement'=>$text(700),'source'=>$text(250),'method'=>['type'=>'string','enum'=>['test','analysis','inspection','demonstration']]]),in_array('requirements',$types,true)?6:0),
                'risks'=>$list($object(['title'=>$text(40),'condition'=>$text(700),'owner'=>$text(40),'mitigation'=>$text(500),'likelihood'=>$rating,'consequence'=>$rating]),in_array('risks',$types,true)?6:0),
            ]),
        ]);
    }
    private static function validateProposalField(mixed $value,array $schema): void
    {
        switch($schema['type']){
            case 'object':
                if(!is_array($value)||array_is_list($value)||array_diff(array_keys($value),array_keys($schema['properties']))||array_diff($schema['required'],array_keys($value)))throw new RuntimeException('Proposal fields do not match the bounded draft format.');
                foreach($schema['properties'] as $key=>$field)self::validateProposalField($value[$key],$field);
                return;
            case 'array':
                if(!is_array($value)||!array_is_list($value)||count($value)<$schema['minItems']||count($value)>$schema['maxItems'])throw new RuntimeException('Proposal list is outside the allowed bounds.');
                foreach($value as $entry)self::validateProposalField($entry,$schema['items']);
                return;
            case 'string':
                self::text($value,($schema['maxLength']??1000)*4);
                if(isset($schema['maxLength'])&&mb_strlen($value,'UTF-8')>$schema['maxLength']||isset($schema['enum'])&&!in_array($value,$schema['enum'],true))throw new RuntimeException('Proposal text or selection is outside the allowed bounds.');
                return;
            case 'integer':
                if(!is_int($value)||$value<$schema['minimum']||$value>$schema['maximum'])throw new RuntimeException('Proposal risk ratings must be whole numbers from 1 to 5.');
                return;
        }
        throw new RuntimeException('Unknown proposal field format.');
    }
    public static function validateProposal(array $result,array $types=[]): array
    {
        self::validateProposalField($result,self::proposalSchema($types));
        $returned=array_column($result['bundle']['artifacts'],'type');
        if(count(array_unique($returned))!==count($returned)||array_diff($returned,$types)||array_diff($types,$returned))throw new RuntimeException('Proposal bundle exceeded the selected artifact scope.');
        if(strlen(json_encode($result,JSON_THROW_ON_ERROR))>65000)throw new RuntimeException('Proposal bundle is too large for one bounded owner review.');
        return $result;
    }
    /** Restore omitted empty HTML lists and strict whole-number ratings only. */
    public static function proposalFromForm(array $form): array
    {
        foreach(['assumptions','unknowns'] as $key)if(!array_key_exists($key,$form))$form[$key]=[];
        if(!isset($form['bundle'])||!is_array($form['bundle']))throw new RuntimeException('Use the saved proposal edit form.');
        foreach(['artifacts','requirements','risks'] as $key)if(!array_key_exists($key,$form['bundle']))$form['bundle'][$key]=[];
        if(!is_array($form['bundle']['risks'])||!array_is_list($form['bundle']['risks']))throw new RuntimeException('Use a valid proposal risk list.');
        foreach($form['bundle']['risks'] as &$risk){
            if(!is_array($risk))throw new RuntimeException('Use a valid proposal risk.');
            foreach(['likelihood','consequence'] as $key){$value=$risk[$key]??null;if(!is_string($value)||!preg_match('/^[1-5]$/D',$value))throw new RuntimeException('Proposal risk ratings must be whole numbers from 1 to 5.');$risk[$key]=(int)$value;}
        }unset($risk);
        return $form;
    }
    public function collaborationContextHash(int $id): string
    {
        // Proposals are deliberately absent from context(), so editing or rejecting
        // advice cannot invalidate its own saved planning/research context.
        return hash('sha256',json_encode($this->context($id),JSON_THROW_ON_ERROR));
    }
    private function decodeProposal(array $row): array
    {
        $row['payload']=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);
        $row['types']=json_decode($row['types_json'],true,16,JSON_THROW_ON_ERROR);
        $row['disposition']=json_decode($row['disposition_json'],true,64,JSON_THROW_ON_ERROR);
        return $row;
    }
    public function proposal(int $id,string $key): array
    {
        if(!preg_match('/^PROP-[1-9][0-9]{0,9}$/D',$key))throw new RuntimeException('Choose a saved proposal from this project.');
        $this->project($id);
        $row=$this->rows('SELECT * FROM nasa_proposals WHERE project_id=? AND proposal_key=? ORDER BY version DESC LIMIT 1',[$id,$key])[0]??throw new RuntimeException('Choose a saved proposal from this project.');
        return $this->decodeProposal($row);
    }
    public function proposals(int $id): array
    {
        $this->project($id);$hash=$this->collaborationContextHash($id);
        $rows=$this->rows('SELECT p.* FROM nasa_proposals p WHERE p.project_id=? AND NOT EXISTS(SELECT 1 FROM nasa_proposals n WHERE n.project_id=p.project_id AND n.proposal_key=p.proposal_key AND n.version>p.version) ORDER BY p.id DESC LIMIT 30',[$id]);
        return array_map(function(array $row)use($hash):array{$row=$this->decodeProposal($row);$row['fresh']=hash_equals($row['context_hash'],$hash);return $row;},$rows);
    }
    public function proposalHistory(int $id): array
    {
        $this->project($id);return array_map(fn(array $r)=>$this->decodeProposal($r),$this->rows('SELECT * FROM nasa_proposals WHERE project_id=? ORDER BY id',[$id]));
    }
    private function appendProposal(int $id,string $key,int $expected,int $request,int $revision,string $hash,array $types,array $payload,string $status,array $disposition,string $by): int
    {
        $old=$this->rows('SELECT version FROM nasa_proposals WHERE project_id=? AND proposal_key=? ORDER BY version DESC LIMIT 1',[$id,$key])[0]??null;
        if((int)($old['version']??0)!==$expected)throw new RuntimeException('This proposal changed. Refresh before saving or deciding.');
        $this->execute('INSERT INTO nasa_proposals(project_id,proposal_key,version,request_id,base_revision,context_hash,types_json,status,payload_json,disposition_json,created_by,created_at)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$key,$expected+1,$request,$revision,$hash,json_encode($types,JSON_THROW_ON_ERROR),$status,json_encode($payload,JSON_THROW_ON_ERROR),json_encode($disposition,JSON_THROW_ON_ERROR),$by,time()]);
        return (int)$this->db->lastInsertId();
    }
    public function retainProposal(int $id,int $request,int $revision,string $contextHash,array $types,array $result): bool
    {
        $result=self::validateProposal($result,$types);
        return $this->transaction(function()use($id,$request,$revision,$contextHash,$types,$result):bool{
            $p=$this->project($id);
            $r=$this->rows("SELECT id FROM nasa_ai_requests WHERE id=? AND project_id=? AND revision=? AND action='collaborate' AND status='pending'",[$request,$id,$revision]);
            if(!$r)throw new RuntimeException('The proposal has no matching bounded AI request.');
            if((int)$p['revision']!==$revision||!hash_equals($contextHash,$this->collaborationContextHash($id)))return false;
            $this->appendProposal($id,'PROP-'.$request,0,$request,$revision,$contextHash,$types,$result,'pending',[],'ai_proposal');
            return true;
        });
    }
    private function pendingProposal(int $id,string $key,int $version,bool $fresh=true): array
    {
        $p=$this->proposal($id,$key);
        if((int)$p['version']!==$version||$p['status']!=='pending')throw new RuntimeException('This proposal was already edited or decided. Refresh before continuing.');
        if($fresh&&((int)$this->project($id)['revision']!==(int)$p['base_revision']||!hash_equals($p['context_hash'],$this->collaborationContextHash($id))))throw new RuntimeException('Planning or research context changed. This proposal cannot be applied; request fresh advice or reject it.');
        return $p;
    }
    public function editProposal(int $id,string $proposalKey,int $version,array $payload): int
    {
        return $this->transaction(function()use($id,$proposalKey,$version,$payload):int{
            $p=$this->pendingProposal($id,$proposalKey,$version);$payload=self::validateProposal($payload,$p['types']);
            return $this->appendProposal($id,$proposalKey,$version,(int)$p['request_id'],(int)$p['base_revision'],$this->collaborationContextHash($id),$p['types'],$payload,'pending',[],'owner_edited');
        });
    }
    public function decideProposal(int $id,string $proposalKey,int $version,string $decision): array
    {
        if(!in_array($decision,['accept','reject'],true))throw new RuntimeException('Choose accept or reject for this proposal.');
        return $this->transaction(function()use($id,$proposalKey,$version,$decision):array{
            $p=$this->pendingProposal($id,$proposalKey,$version,$decision==='accept');$saved=[];
            $payload=self::validateProposal($p['payload'],$p['types']);
            if($decision==='accept'){
                if(!$p['types'])throw new RuntimeException('This proposal contains advice only. Request a draft bundle before accepting records.');
                // Prepare and validate every member before the first append. The
                // enclosing transaction also rolls back a later storage failure.
                $prepared=[];
                foreach($payload['bundle']['artifacts'] as $a){$old=$this->current($id,'artifact',$a['type']);$prepared[]=['kind'=>'artifact','key'=>$a['type'],'title'=>NasaKnowledge::ARTIFACTS[$a['type']][0],'content'=>self::text($a['content'],12000),'status'=>'draft','data'=>['reference'=>NasaKnowledge::ARTIFACTS[$a['type']][2]],'expected'=>(int)($old['version']??0)];}
                foreach(['requirement'=>$payload['bundle']['requirements'],'risk'=>$payload['bundle']['risks']] as $kind=>$records){
                    $number=(int)$this->rows('SELECT COUNT(DISTINCT item_key) AS n FROM nasa_items WHERE project_id=? AND kind=?',[$id,$kind])[0]['n'];
                    foreach($records as $r){
                        $content=$kind==='requirement'?$r['statement']:$r['condition'];$status=$kind==='requirement'?'unverified':'open';
                        $data=$kind==='requirement'?['source'=>$r['source'],'method'=>$r['method'],'evidence'=>'']:['owner'=>$r['owner'],'mitigation'=>$r['mitigation'],'requirement'=>'','artifact'=>'risks','likelihood'=>$r['likelihood'],'consequence'=>$r['consequence'],'residual_likelihood'=>$r['likelihood'],'residual_consequence'=>$r['consequence']];
                        $data=$this->validateRecord($id,$kind,$r['title'],$content,$data,$status);
                        $key=($kind==='requirement'?'REQ':'RISK').'-'.str_pad((string)++$number,3,'0',STR_PAD_LEFT);
                        $prepared[]=['kind'=>$kind,'key'=>$key,'title'=>trim($r['title']),'content'=>trim($content),'status'=>$status,'data'=>$data,'expected'=>0];
                    }
                }
                foreach($prepared as $item){
                    $data=$item['data']+['proposal'=>['key'=>$proposalKey,'version'=>$version,'request_id'=>(int)$p['request_id'],'context_hash'=>$p['context_hash']]];
                    $record=$this->append($id,$item['kind'],$item['key'],$item['title'],$item['content'],$item['status'],$data,'owner_accepted_ai',$item['expected']);
                    $saved[]=['id'=>$record,'kind'=>$item['kind'],'item_key'=>$item['key'],'version'=>$item['expected']+1];
                }
                $this->bump($id);
            }
            $this->appendProposal($id,$proposalKey,$version,(int)$p['request_id'],(int)$p['base_revision'],$p['context_hash'],$p['types'],$payload,$decision==='accept'?'accepted':'rejected',['records'=>$saved],'owner');
            return $saved;
        });
    }
}
