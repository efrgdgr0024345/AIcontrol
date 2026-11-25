<?php
declare(strict_types=1);

namespace AIcontrol {
    // Exercises the real HTTP forms with synthetic SSH and OpenAI transports.
    function proc_open(array $argv, array $descriptors, &$pipes, $cwd=null, $env=null, array $options=[])
    {
        $directory=getenv('AICONTROL_PRIVATE_DIR');
        $output='';
        $exit=0;
        if($argv[0]==='/usr/bin/ssh-keyscan'){
            $output='[192.0.2.31]:2222 '.trim(file_get_contents($directory.'/fixture-host-key'))."\n";
        }elseif($argv[0]==='/usr/bin/ssh'){
            $command=end($argv);
            if(preg_match('/CONFIG=json.loads\(base64.b64decode\("([A-Za-z0-9+\/=]+)"\)\)/',$command,$match)){
                $config=json_decode(base64_decode($match[1],true),true,32,JSON_THROW_ON_ERROR);
                $running=['token'=>$config['token'],'recipe'=>$config['recipe'],'status'=>'running'];
                $result=match($config['mode']){
                    'inventory'=>['format'=>'AIcontrol VPS inventory v1','root'=>true,'distribution'=>'ubuntu','apt_available'=>true,'systemd'=>true,'ssh_port'=>2222,'ports'=>[]],
                    'launch'=> $config['recipe']==='vpn'&&$config['parameters']['network']==='10.78.0.0/24'
                        && $config['parameters']['listen_port']===51820
                        && $config['parameters']['peer_public_key']===base64_encode(str_repeat('c',32))
                        ?$running:throw new \RuntimeException('Unexpected VPN launch'),
                    'status'=>is_file($directory.'/fixture-vpn-finished')
                        ?array_replace($running,['status'=>'complete','summary'=>'Fixture WireGuard interface configured.','client_address'=>'10.78.0.2/24','server_public_key'=>base64_encode(str_repeat('s',32)),'handshake_verified'=>false])
                        :$running,
                    default=>throw new \RuntimeException('Unexpected SSH worker request'),
                };
                $output=json_encode($result,JSON_THROW_ON_ERROR)."\n";
            }elseif(str_contains($command,'getconf _NPROCESSORS_ONLN')){
                $output="Linux verification fixture\n";
            }elseif(str_contains($command,'manual shell fixture')){
                $output="manual shell fixture\n";
            }elseif(str_contains($command,'agent shell fixture')){
                $output="agent shell fixture\n";
            }else{
                $exit=127;
            }
        }else{
            throw new \RuntimeException('Unexpected process in Systems fixture');
        }
        return \proc_open([PHP_BINARY,'-r','echo $argv[1]; exit((int)$argv[2]);',$output,(string)$exit],$descriptors,$pipes,$cwd,$env,$options);
    }

    function curl_init(string $url): object
    {
        if($url!=='https://api.openai.com/v1/responses')throw new \RuntimeException('Unexpected provider URL');
        return new \stdClass();
    }
    function curl_setopt_array(object $handle,array $options): bool
    {
        $handle->options=$options;
        return true;
    }
    function curl_exec(object $handle): bool
    {
        $options=$handle->options;
        $body=json_decode($options[CURLOPT_POSTFIELDS],true,32,JSON_THROW_ON_ERROR);
        $input=json_decode($body['input'],true,32,JSON_THROW_ON_ERROR);
        if(!in_array('Authorization: Bearer fixture-saved-openai-key',$options[CURLOPT_HTTPHEADER],true)
            || $body['model']!=='gpt-4.1-mini'||$input['task']!=='Print a disposable shell test marker'
            ||str_contains($options[CURLOPT_POSTFIELDS],'fixture-saved-openai-key')
            ||str_contains($options[CURLOPT_POSTFIELDS],'synthetic-protected-value')){
            throw new \RuntimeException('Unexpected saved-key request or private data in prompt');
        }
        $decision=match($input['step']){
            0=>['tool'=>'shell','command'=>"printf 'agent shell fixture'"],
            1=>str_contains($input['executed_commands'][0]['stdout']??'','agent shell fixture')
                ?['tool'=>'finish','summary'=>'Verified the fixture shell output.']:throw new \RuntimeException('Missing shell evidence'),
            default=>throw new \RuntimeException('Unexpected provider step'),
        };
        file_put_contents(getenv('AICONTROL_PRIVATE_DIR').'/fixture-provider-calls',"step\n",FILE_APPEND|LOCK_EX);
        $response=['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($decision,JSON_THROW_ON_ERROR)]]]]];
        ($options[CURLOPT_WRITEFUNCTION])($handle,json_encode($response,JSON_THROW_ON_ERROR));
        return true;
    }
    function curl_getinfo(object $handle,int $option): int {return 200;}
    function curl_close(object $handle): void {}
}

namespace {
    if(PHP_SAPI!=='cli-server'||getenv('AICONTROL_LOCAL_DEV')!=='1'){
        http_response_code(404);
        exit;
    }
    require __DIR__.'/../../bin/dev-router.php';
}
