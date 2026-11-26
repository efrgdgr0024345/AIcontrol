<?php
declare(strict_types=1);

namespace AIcontrol {
    // Exercises the real HTTP forms with synthetic SSH and OpenAI transports.
    function proc_open(array $argv, array $descriptors, &$pipes, $cwd=null, $env=null, array $options=[])
    {
        $directory=getenv('AICONTROL_PRIVATE_DIR');
        $output='';
        $delay=0;
        $exit=0;
        if($argv[0]==='/usr/bin/ssh-keyscan'){
            $output='[192.0.2.31]:2222 '.trim(file_get_contents($directory.'/fixture-host-key'))."\n";
        }elseif($argv[0]==='/usr/bin/ssh'){
            $command=end($argv);
            if(preg_match('/CONFIG=json.loads\(base64.b64decode\("([A-Za-z0-9+\/=]+)"\)\)/',$command,$match)){
                $config=json_decode(base64_decode($match[1],true),true,32,JSON_THROW_ON_ERROR);
                $running=['token'=>$config['token'],'recipe'=>$config['recipe'],'status'=>'running'];
                $finished=is_file($directory.'/fixture-vpn-finished');
                $inventory=['format'=>'AIcontrol VPS inventory v1','root'=>true,'distribution'=>'ubuntu','apt_available'=>true,'systemd'=>true,'ssh_port'=>2222,'ports'=>[]];
                if($config['recipe']==='inspect')$inventory+=['setup_checks'=>['vpn'=>$finished?'active':'absent','codex'=>'installed','security_updates'=>'unknown'],
                    'setup_steps'=>[['command'=>['ip','-j','-details','link','show','dev','aicontrol0'],'exit_code'=>$finished?0:1]]];
                $result=match($config['mode']){
                    'inventory'=>$inventory,
                    'launch'=> $config['recipe']==='vpn'&&$config['parameters']['network']==='10.78.0.0/24'
                        && $config['parameters']['listen_port']===51820
                        && $config['parameters']['peer_public_key']===base64_encode(str_repeat('c',32))
                        ?$running:throw new \RuntimeException('Unexpected VPN launch'),
                    'status'=>$finished
                        ?array_replace($running,['status'=>'complete','summary'=>'Fixture WireGuard interface configured.','client_address'=>'10.78.0.2/24','server_public_key'=>base64_encode(str_repeat('s',32)),'handshake_verified'=>false,
                            'steps'=>[['command'=>['systemctl','enable','--now','wg-quick@aicontrol0'],'exit_code'=>0,'stdout'=>'Fixture WireGuard service enabled.']]])
                        :$running+['steps'=>[
                            ['command'=>['apt-get','update'],'exit_code'=>0,'stdout'=>'Fixture package lists refreshed.','stderr'=>'Fixture stderr with synthetic-protected-value'],
                            ['command'=>['apt-get','install','wireguard'],'exit_code'=>null],
                        ]],
                    default=>throw new \RuntimeException('Unexpected SSH worker request'),
                };
                $output=json_encode($result,JSON_THROW_ON_ERROR)."\n";
            }elseif(str_contains($command,'getconf _NPROCESSORS_ONLN')){
                $output="Linux verification fixture\n";
                if(preg_match('/AICTRL_WELCOME_[a-f0-9]{24}/',$command,$welcome))$output="Welcome to the fixture VPS\n\n".$welcome[0]."\n".$output;
            }elseif(str_contains($command,'manual shell fixture')){
                $output="manual shell fixture\n";
            }elseif(str_contains($command,'{ ls -la'."\n")){
                $output="total 4\n-rw-r--r-- 1 fixture fixture 20 Nov 26 12:00 .profile\n";
            }elseif(str_contains($command,'wg show all latest-handshakes')){
                $delay=2400000;
                $exit=1;
            }elseif(str_contains($command,'ss -lnt; ip -brief address')){
                $output="aicontrol0 UP 10.78.0.1/24\n";
            }elseif(!str_contains($command,'{ ls'."\n")){
                $exit=127;
            }
            if(preg_match('/AICTRL_CWD_[a-f0-9]{32}:/',$command,$marker)){
                $username=$argv[array_search('-l',$argv,true)+1];
                $output.="\n".$marker[0].base64_encode('/var/lib/aicontrol/homes/'.$username)."\n";
            }
        }else{
            throw new \RuntimeException('Unexpected process in Systems fixture');
        }
        return \proc_open([PHP_BINARY,'-r','usleep((int)$argv[3]); echo $argv[1]; exit((int)$argv[2]);',$output,(string)$exit,(string)$delay],$descriptors,$pipes,$cwd,$env,$options);
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
            || $body['model']!=='gpt-4.1-mini'
            ||str_contains($options[CURLOPT_POSTFIELDS],'fixture-saved-openai-key')
            ||str_contains($options[CURLOPT_POSTFIELDS],'synthetic-protected-value')){
            throw new \RuntimeException('Unexpected saved-key request or private data in prompt');
        }
        $format=$body['text']['format'];
        if(($format['name']??'')==='guided_action_choice'){
            if($input['goal']!=='Set up private VPN access'||!isset($input['actions']['vpn'])||isset($input['actions']['codex'])
                ||$format['strict']!==true||$format['schema']['additionalProperties']!==false){
                throw new \RuntimeException('Unexpected guided action choices');
            }
            $decision=['recipe'=>'vpn','reason'=>'WireGuard provides private access for this server.'];
            $call="choice\n";
        }else{
            if($input['task']!=='Check why VPN traffic is not getting through.')throw new \RuntimeException('Unexpected fixture task');
            $decision=match($input['step']){
                0=>['tool'=>'vpn_status','update'=>'I will check local WireGuard handshakes and transfer counters.'],
                1=>($input['executed_commands'][0]['exit_code']??null)===1
                    ?['tool'=>'network','update'=>'The WireGuard read failed. I will check whether the tunnel interface is up.']:throw new \RuntimeException('Missing WireGuard check evidence'),
                2=>str_contains($input['executed_commands'][1]['stdout']??'','aicontrol0 UP')
                    ?['tool'=>'finish','summary'=>'The interface is up. Handshakes and end-to-end traffic remain unverified.']:throw new \RuntimeException('Missing interface evidence'),
                default=>throw new \RuntimeException('Unexpected provider step'),
            };
            $call="step\n";
        }
        file_put_contents(getenv('AICONTROL_PRIVATE_DIR').'/fixture-provider-calls',$call,FILE_APPEND|LOCK_EX);
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
