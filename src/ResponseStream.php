<?php
declare(strict_types=1);
namespace AIcontrol;

use RuntimeException;

/** Incremental Responses SSE reader, with JSON fallback for fixtures/older transports. */
final class ResponseStream
{
    private string $buffer='';
    private string $raw='';
    private ?array $response=null;
    private int $characters=0;
    private bool $sse=false;
    public function __construct(private mixed $progress) {}
    public function push(string $chunk): int
    {
        if(strlen($this->raw)+strlen($chunk)>256000)return 0;
        $this->raw.=$chunk;$this->buffer.=$chunk;
        if(preg_match('/^(event:|data:|:)/',ltrim($this->raw)))$this->sse=true;
        if(!$this->sse)return strlen($chunk);
        while(($position=strpos($this->buffer,"\n"))!==false){
            $line=rtrim(substr($this->buffer,0,$position),"\r");$this->buffer=substr($this->buffer,$position+1);
            if(!str_starts_with($line,'data:'))continue;
            $payload=trim(substr($line,5));if($payload===''||$payload==='[DONE]')continue;
            $event=json_decode($payload,true,64,JSON_THROW_ON_ERROR);$type=$event['type']??'';
            $activity=match($type){
                'response.created','response.in_progress'=>'Provider accepted request',
                'response.web_search_call.in_progress','response.web_search_call.searching'=>'Searching external sources',
                'response.web_search_call.completed'=>'Search returned; checking citations',
                'response.output_text.delta'=>'Receiving response text',
                'response.completed'=>'Provider finished; validating response',
                default=>null,
            };
            if($type==='response.output_text.delta'&&is_string($event['delta']??null))$this->characters+=mb_strlen($event['delta'],'UTF-8');
            if($activity!==null)($this->progress)($activity,$this->characters);
            if(in_array($type,['response.completed','response.incomplete','response.failed'],true)&&is_array($event['response']??null))$this->response=$event['response'];
        }
        return strlen($chunk);
    }
    public function diagnostics(): array
    {
        $result=$this->response??json_decode($this->raw,true,64);
        return ['response_bytes'=>strlen($this->raw),'streaming'=>$this->sse,
            'provider_error'=>$result['error']??null,'provider_status'=>$result['status']??null,
            'incomplete_details'=>$result['incomplete_details']??null];
    }
    public function result(): array
    {
        if($this->sse&&str_starts_with(trim($this->buffer),'data:'))$this->push("\n");
        if($this->sse)return $this->response??throw new RuntimeException('Stream ended without a final provider response.');
        $data=json_decode($this->raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Invalid provider response.');
        return $data;
    }
}
