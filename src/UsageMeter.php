<?php
declare(strict_types=1);
namespace AIcontrol;

use PDO;
use RuntimeException;

/** Local estimates from returned usage, never an assertion about an account invoice. */
final class UsageMeter
{
    public const CHECKED = '2026-11-10';
    public const SOURCE = 'https://developers.openai.com/api/docs/pricing';
    private const RATES = [
        'gpt-4.1'=>[2.0,0.5,8.0],
        'gpt-4.1-mini'=>[0.4,0.1,1.6],
        'gpt-4.1-nano'=>[0.1,0.025,0.4],
    ];

    public static function columns(PDO $db,string $table,array $fields): void
    {
        $columns=array_column($db->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
        foreach($fields as $name=>$definition)if(!in_array($name,$columns,true))$db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$definition);
    }
    public static function migrate(PDO $db,string $table): void
    {
        self::columns($db,$table,['billing_json'=>'TEXT','estimated_usd'=>'REAL','reserved_usd'=>'REAL NOT NULL DEFAULT 0','cached_tokens'=>'INTEGER','tool_calls'=>'INTEGER']);
    }
    public static function rates(string $model): ?array
    {
        // Recognise only explicitly priced aliases/snapshots, never an arbitrary prefix match.
        $alias=preg_replace('/-2025-04-14$/D','',$model);
        if(!isset(self::RATES[$alias]))return null;
        [$input,$cached,$output]=self::RATES[$alias];
        return ['input_per_million'=>$input,'cached_per_million'=>$cached,'output_per_million'=>$output,
            'preview_search_per_call'=>0.025,'currency'=>'USD','checked_at'=>self::CHECKED,
            'source'=>self::SOURCE,'model_source'=>'https://developers.openai.com/api/docs/models/'.$alias];
    }
    private static function count(mixed $value): ?int { return is_int($value)&&$value>=0?$value:null; }
    public static function measure(array $response,string $requestedModel): array
    {
        $model=is_string($response['model']??null)?$response['model']:$requestedModel;
        $input=self::count($response['usage']['input_tokens']??null);
        $output=self::count($response['usage']['output_tokens']??null);
        $cached=$input===null?null:self::count($response['usage']['input_tokens_details']['cached_tokens']??0);
        $reasoning=$output===null?null:self::count($response['usage']['output_tokens_details']['reasoning_tokens']??0);
        $calls=0;$toolsKnown=true;
        foreach($response['output']??[] as $item)if(($item['type']??'')==='web_search_call'){
            if(($item['status']??'')==='completed')$calls++;else $toolsKnown=false;
        }
        $rates=self::rates($model);$tier=$response['service_tier']??'default';
        $known=$input!==null&&$output!==null&&$cached!==null&&$cached<=$input&&$toolsKnown&&$rates!==null&&in_array($tier,['default','standard'],true);
        $cost=$known?round((($input-$cached)*$rates['input_per_million']+$cached*$rates['cached_per_million']+$output*$rates['output_per_million'])/1e6+$calls*$rates['preview_search_per_call'],9):null;
        return ['model'=>$model,'requested_model'=>$requestedModel,'input_tokens'=>$input,'output_tokens'=>$output,'cached_tokens'=>$cached,'reasoning_tokens'=>$reasoning,
            'tool_calls'=>$toolsKnown?$calls:null,'estimated_usd'=>$cost,'basis'=>'API usage × saved standard rates; web_search_preview non-reasoning fee',
            'currency'=>'USD','rates'=>$rates,'provider_response_id'=>is_string($response['id']??null)?mb_strcut($response['id'],0,200,'UTF-8'):null,
            'recorded_at'=>time(),'invoice_verified'=>false];
    }
    public static function reserveProject(PDO $db,int $project,string $table,int $id,array $body): void
    {
        if(!in_array($table,['research_requests','research_messages'],true))throw new RuntimeException('Invalid usage record.');
        $rates=self::rates($body['model']);
        if($rates===null)throw new RuntimeException('Current pricing is unavailable for this model. Choose a priced research model.');
        // UTF-8 bytes plus schema/format overhead conservatively exceed the text-token count.
        // Preview search content is free; its distinct per-call fee is reserved separately.
        $upper=(strlen(json_encode($body,JSON_THROW_ON_ERROR))+2048)*$rates['input_per_million']/1e6
            +$body['max_output_tokens']*$rates['output_per_million']/1e6+(isset($body['tools'])?$rates['preview_search_per_call']:0);
        $query=$db->prepare('SELECT spend_cap_usd FROM research_projects WHERE id=?');$query->execute([$project]);$cap=$query->fetchColumn();
        $query=$db->prepare('SELECT COALESCE(SUM(COALESCE(q.estimated_usd,0)+q.reserved_usd),0) FROM research_requests q JOIN research_runs r ON r.id=q.run_id WHERE r.project_id=?');$query->execute([$project]);$spent=(float)$query->fetchColumn();
        $query=$db->prepare("SELECT COALESCE(SUM(COALESCE(estimated_usd,0)+reserved_usd),0) FROM research_messages WHERE project_id=? AND role='assistant'");$query->execute([$project]);$spent+=(float)$query->fetchColumn();
        if($cap===false||$spent+$upper>(float)$cap)throw new RuntimeException('Project spend cap reached. Saved findings are retained; adjust the cap in Project menu to continue.');
        $db->prepare('UPDATE '.$table.' SET reserved_usd=?,model=? WHERE id=?')->execute([round($upper,9),$body['model'],$id]);
    }
    public static function summary(array $rows): array
    {
        $total=['input_tokens'=>0,'output_tokens'=>0,'cached_tokens'=>0,'estimated_usd'=>0.0,'reserved_usd'=>0.0,'unknown_requests'=>0,'pending_requests'=>0,'requests'=>count($rows)];
        foreach($rows as $row){
            $total['input_tokens']+=(int)($row['token_input']??$row['input_tokens']??0);
            $total['output_tokens']+=(int)($row['token_output']??$row['output_tokens']??0);
            $total['cached_tokens']+=(int)($row['cached_tokens']??0);
            $total['estimated_usd']+=(float)($row['estimated_usd']??0);$total['reserved_usd']+=(float)($row['reserved_usd']??0);
            if(in_array($row['status'],['pending','working'],true))$total['pending_requests']++;
            elseif(($row['estimated_usd']??null)===null)$total['unknown_requests']++;
        }
        $total['estimated_usd']=round($total['estimated_usd'],9);$total['total_tokens']=$total['input_tokens']+$total['output_tokens'];
        return $total;
    }
    public static function label(array $usage): string
    {
        return '$'.number_format($usage['estimated_usd'],$usage['estimated_usd']>0&&$usage['estimated_usd']<0.0001?6:4).' USD estimated'.($usage['unknown_requests']?' + '.$usage['unknown_requests'].' unpriced/unknown calls':'');
    }
    public static function hourlyLimit(): int
    {
        $value=getenv('AICONTROL_RESEARCH_HOURLY_LIMIT');
        return is_string($value)&&preg_match('/^(10|30)$/D',$value)?(int)$value:30;
    }
}
