<?php
declare(strict_types=1);
namespace AIcontrol;

use RuntimeException;
require_once __DIR__.'/SystemBootstrap.php';

final class SystemEnrollment
{
    public function __construct(private Systems $systems){}
    public function prepare(string $risk): array
    {
        $level=SystemRisk::level($risk);
        $this->systems->write("DELETE FROM system_enrollments WHERE system_id IS NULL AND created_at<?",[time()-86400]);
        if(count($this->systems->query('SELECT id FROM system_enrollments WHERE created_at>?',[time()-3600]))>=30)throw new RuntimeException('Setup limit reached. Reuse an existing setup or wait before preparing another.');
        $id=bin2hex(random_bytes(16));$username=$level['prefix'].substr($id,0,12);
        $this->systems->write("INSERT INTO system_enrollments(id,challenge,receipt_challenge,risk_level,username,state,created_at)VALUES(?,?,?,?,?,'prepared',?)",[$id,bin2hex(random_bytes(24)),bin2hex(random_bytes(24)),$risk,$username,time()]);
        return $this->get($id);
    }
    public function get(string $id): array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Choose a valid setup.');
        $e=$this->systems->query('SELECT * FROM system_enrollments WHERE id=?',[$id])[0]??throw new RuntimeException('This setup is unavailable. Prepare a new command.');
        if($e['created_at']<time()-86400&&$e['state']!=='connected')throw new RuntimeException('This setup expired. Prepare a new command.');
        return $e;
    }
    public static function parse(string $text,string $kind): array
    {
        if(strlen($text)>12000||!mb_check_encoding($text,'UTF-8'))throw new RuntimeException('Paste a valid report up to 12 KB.');
        $begin='---BEGIN AICONTROL '.strtoupper($kind).' V1---';$end='---END AICONTROL '.strtoupper($kind).' V1---';
        if(substr_count($text,$begin)!==1||substr_count($text,$end)!==1)throw new RuntimeException('Paste the complete formatted '.$kind.' report, including its BEGIN and END lines.');
        $start=strpos($text,$begin)+strlen($begin);$finish=strpos($text,$end);
        if($finish<$start)throw new RuntimeException('Report markers are out of order.');
        try{$data=json_decode(trim(substr($text,$start,$finish-$start)),true,12,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new RuntimeException('The pasted report is not valid JSON. Copy the whole formatted output again.');}
        if(!is_array($data)||($data['format']??null)!=='AIcontrol SSH '.$kind.' v1')throw new RuntimeException('Unexpected setup report format.');
        return $data;
    }
    private function bound(array $e,array $data,string $challenge): void
    {
        foreach(['enrollment_id'=>$e['id'],'risk_level'=>$e['risk_level'],'username'=>$e['username'],'challenge'=>$challenge] as $key=>$value)if(!is_string($data[$key]??null)||!hash_equals($value,$data[$key]))throw new RuntimeException('The reply belongs to a different setup, account or risk level. Use the commands shown for this setup.');
    }
    public static function fingerprint(string $key): string
    {
        if(!preg_match('~^ssh-ed25519 ([A-Za-z0-9+/]+={0,2})$~D',$key,$match))throw new RuntimeException('An ED25519 public key is required.');
        $raw=base64_decode($match[1],true);
        if($raw===false||strlen($raw)!==51||substr($raw,0,19)!==pack('N',11).'ssh-ed25519'.pack('N',32))throw new RuntimeException('Invalid ED25519 public key.');
        return 'SHA256:'.rtrim(base64_encode(hash('sha256',$raw,true)),'=');
    }
    public function importDiscovery(string $id,string $text): array
    {
        $e=$this->get($id);$d=self::parse($text,'discovery');$this->bound($e,$d,$e['challenge']);
        if(!is_string($d['host_key']??null)||self::fingerprint($d['host_key'])!==($d['host_fingerprint']??null)||!preg_match('/^[a-f0-9]{64}$/D',$d['machine_tag']??'')||!is_int($d['uid']??null)||$d['uid']<1||($d['home_dir']??'')!=='/var/lib/aicontrol/homes/'.$e['username']||($d['sudo_granted']??null)!==($e['risk_level']==='admin'))throw new RuntimeException('The discovered identity or risk permissions are invalid. Rerun command 1 on the selected server.');
        if(!is_string($d['connection_host']??null)||!filter_var($d['connection_host'],FILTER_VALIDATE_IP)||!is_int($d['ssh_port']??null)||$d['ssh_port']<1||$d['ssh_port']>65535||!is_string($d['hostname']??null)||strlen($d['hostname'])>120)throw new RuntimeException('The report must contain a valid connection IP address, SSH port and hostname.');
        $safe=array_intersect_key($d,array_flip(['format','enrollment_id','risk_level','username','challenge','machine_tag','hostname','connection_host','ssh_port','host_key','host_fingerprint','uid','home_dir','sudo_granted']));
        $safe['addresses']=array_values(array_slice(array_filter(is_array($d['addresses']??null)?$d['addresses']:[],static fn($v)=>is_string($v)&&filter_var($v,FILTER_VALIDATE_IP)),0,32));
        $json=json_encode($safe,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $lockPath=$this->systems->directory.'/systems-enrollment.lock';if(is_link($lockPath))throw new RuntimeException('Setup lock unavailable.');$lock=fopen($lockPath,'c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new RuntimeException('Another setup is being saved. Wait before pasting again.');}
        try{
            $e=$this->get($id);
            if($e['system_id']){if($e['discovery_json']!==$json)throw new RuntimeException('This setup already belongs to a different saved discovery. Prepare a new setup.');return $e;}
            $level=SystemRisk::level($e['risk_level']);$system=null;
            $this->systems->db->beginTransaction();
            try {
                $system=$this->systems->add($safe['hostname']?:'Linux server',$safe['connection_host'],(string)$safe['ssh_port'],$e['username'],$level['access'],$safe['host_fingerprint']);
                $this->systems->write('UPDATE systems SET risk_level=?,enrollment_id=?,home_dir=? WHERE id=?',[$e['risk_level'],$id,$safe['home_dir'],$system]);
                $this->systems->write("UPDATE system_enrollments SET state='discovered',system_id=?,discovery_json=? WHERE id=?",[$system,$json,$id]);
                $this->systems->db->commit();
            }catch(\Throwable $failure){
                if($this->systems->db->inTransaction())$this->systems->db->rollBack();
                if($system&&!$this->systems->query('SELECT id FROM systems WHERE id=?',[$system]))try{(new Vault($this->systems->directory))->change('ssh_system_'.$system,'key','',false,true);}catch(\Throwable $cleanup){Diagnostics::record('systems.enrollment.cleanup_failed',['enrollment_id'=>$id],$cleanup);}
                throw $failure;
            }
            return $this->get($id);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function receipt(string $id,string $text): array
    {
        $e=$this->get($id);if(!$e['system_id'])throw new RuntimeException('Paste discovery before the key setup reply.');
        $s=$this->systems->system((int)$e['system_id']);if(!$s['enabled'])throw new RuntimeException('This system is disconnected. Prepare a new setup.');
        $d=self::parse($text,'receipt');$this->bound($e,$d,$e['receipt_challenge']);$first=json_decode($e['discovery_json'],true,12,JSON_THROW_ON_ERROR);
        foreach(['machine_tag'=>$first['machine_tag'],'host_fingerprint'=>$s['fingerprint'],'authorized_key_sha256'=>self::fingerprint($s['public_key']),'uid'=>$first['uid'],'home_dir'=>$first['home_dir'],'sudo_granted'=>$e['risk_level']==='admin','read_only_enforced'=>$e['risk_level']==='readonly'] as $key=>$value)if(($d[$key]??null)!==$value)throw new RuntimeException('Key setup reply does not match the discovered server, generated key or selected permissions.');
        $safe=array_intersect_key($d,array_flip(['format','enrollment_id','risk_level','username','challenge','machine_tag','host_fingerprint','authorized_key_sha256','uid','home_dir','sudo_granted','read_only_enforced']));
        $this->systems->write("UPDATE system_enrollments SET state=CASE WHEN state='connected' THEN state ELSE 'configured' END,receipt_json=? WHERE id=?",[json_encode($safe,JSON_THROW_ON_ERROR),$id]);
        return $s;
    }
    public function view(?string $id): ?array
    {
        if(!$id)return null;$e=$this->get($id);$e['script1']=SystemBootstrap::script($e,'discovery');$e['command1']=SystemBootstrap::command($e['script1']);
        if($e['system_id']){$s=$this->systems->system((int)$e['system_id']);$e['script2']=SystemBootstrap::script($e,'receipt',$s);$e['command2']=SystemBootstrap::command($e['script2']);$e['system']=$s;}
        return $e;
    }
}
