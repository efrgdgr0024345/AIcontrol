<?php
declare(strict_types=1);
namespace AIcontrol;

use PDO;
use RuntimeException;
require_once __DIR__.'/SshTransport.php';

final class Systems
{
    public PDO $db;
    public function __construct(public string $directory)
    {
        $path=$directory.'/systems.sqlite';if(is_link($path))throw new RuntimeException('Systems storage unavailable.');
        $this->db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);chmod($path,0600);
        $this->db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;
            CREATE TABLE IF NOT EXISTS systems(id INTEGER PRIMARY KEY,name TEXT NOT NULL,host TEXT NOT NULL,port INTEGER NOT NULL,username TEXT NOT NULL,access TEXT NOT NULL,public_key TEXT NOT NULL,fingerprint TEXT NOT NULL,host_key TEXT,key_deleted_at INTEGER,enabled INTEGER NOT NULL DEFAULT 1,verified_at INTEGER,facts TEXT,error TEXT,created_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS system_runs(id INTEGER PRIMARY KEY,system_id INTEGER NOT NULL REFERENCES systems(id),project_id INTEGER,task TEXT NOT NULL,status TEXT NOT NULL,rounds INTEGER NOT NULL DEFAULT 0,result TEXT,error TEXT,created_at INTEGER NOT NULL,finished_at INTEGER);
            CREATE TABLE IF NOT EXISTS system_commands(id INTEGER PRIMARY KEY,run_id INTEGER NOT NULL REFERENCES system_runs(id),command TEXT NOT NULL,status TEXT NOT NULL,stdout TEXT,stderr TEXT,exit_code INTEGER,timed_out INTEGER,truncated INTEGER,elapsed_ms INTEGER,created_at INTEGER NOT NULL,finished_at INTEGER);
            CREATE TABLE IF NOT EXISTS system_messages(id INTEGER PRIMARY KEY,run_id INTEGER NOT NULL REFERENCES system_runs(id),payload TEXT NOT NULL);');
    }
    public function query(string $sql,array $params=[]): array {$q=$this->db->prepare($sql);$q->execute($params);return $q->fetchAll();}
    public function write(string $sql,array $params=[]): void {$q=$this->db->prepare($sql);$q->execute($params);}
    public function system(int $id): array {return $this->query('SELECT * FROM systems WHERE id=?',[$id])[0]??throw new RuntimeException('System not found.');}
    public function run(int $id): array {return $this->query('SELECT * FROM system_runs WHERE id=?',[$id])[0]??throw new RuntimeException('System task not found.');}
    public static function keypair(): array
    {
        $pair=sodium_crypto_sign_keypair();$public=sodium_crypto_sign_publickey($pair);$secret=sodium_crypto_sign_secretkey($pair);$field=static fn(string $s):string=>pack('N',strlen($s)).$s;
        $pub=$field('ssh-ed25519').$field($public);$check=random_bytes(4);$body=$check.$check.$field('ssh-ed25519').$field($public).$field($secret).$field('AIcontrol');$padding=8-strlen($body)%8;for($i=1;$i<=$padding;$i++)$body.=chr($i);
        $raw="openssh-key-v1\0".$field('none').$field('none').$field('').pack('N',1).$field($pub).$field($body);
        return ['public'=>'ssh-ed25519 '.base64_encode($pub),'private'=>"-----BEGIN OPENSSH PRIVATE KEY-----\n".chunk_split(base64_encode($raw),70,"\n")."-----END OPENSSH PRIVATE KEY-----\n"];
    }
    public function add(string $name,string $host,string $port,string $user,string $access,string $fingerprint): int
    {
        $name=trim($name);$host=strtolower(trim($host));
        if($name===''||strlen($name)>120||!mb_check_encoding($name,'UTF-8')||(!filter_var($host,FILTER_VALIDATE_IP)&&!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host))||!ctype_digit($port)||(int)$port<1||(int)$port>65535||!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D',$user)||!in_array($access,['inspect','maintain'],true)||(trim($fingerprint)!==''&&!preg_match('~^SHA256:[A-Za-z0-9+/]{43}$~D',trim($fingerprint))))throw new RuntimeException('Enter a name, valid host, port, Linux username, access level and SHA256 SSH fingerprint.');
        if(count($this->query('SELECT id FROM systems'))>=30)throw new RuntimeException('This workspace supports up to 30 saved systems.');
        $key=self::keypair();$this->write('INSERT INTO systems(name,host,port,username,access,public_key,fingerprint,created_at)VALUES(?,?,?,?,?,?,?,?)',[$name,$host,(int)$port,$user,$access,$key['public'],trim($fingerprint),time()]);$id=(int)$this->db->lastInsertId();
        try{(new Vault($this->directory))->change('ssh_system_'.$id,'key',$key['private'],false);}catch(\Throwable $e){$this->write('DELETE FROM systems WHERE id=?',[$id]);throw $e;}
        return $id;
    }
    public function script(int $id): string
    {
        $s=$this->system($id);$u=escapeshellarg($s['username']);$key=escapeshellarg('restrict '.$s['public_key'].' aicontrol-system-'.$id);
        return "#!/bin/sh\nset -eu\n# Run as root (or with sudo) on your selected Linux server.\n# Adds a dedicated account if missing; grants no sudo privileges.\nuser=$u\nif ! id \"\$user\" >/dev/null 2>&1; then useradd --password '*' --create-home --shell /bin/sh \"\$user\"; fi\nhome_dir=\$(getent passwd \"\$user\" | cut -d: -f6)\ntest -n \"\$home_dir\"\ntest ! -L \"\$home_dir/.ssh\"\ntest ! -L \"\$home_dir/.ssh/authorized_keys\"\ninstall -d -m 700 -o \"\$user\" \"\$home_dir/.ssh\"\ntouch \"\$home_dir/.ssh/authorized_keys\"\nkey=$key\ngrep -qxF \"\$key\" \"\$home_dir/.ssh/authorized_keys\" || printf '%s\\n' \"\$key\" >> \"\$home_dir/.ssh/authorized_keys\"\nchown \"\$user\" \"\$home_dir/.ssh/authorized_keys\"\nchmod 600 \"\$home_dir/.ssh/authorized_keys\"\nprintf '%s\\n' 'SSH host fingerprint (compare with the saved fingerprint in AIcontrol):'\nssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256\nprintf '%s\\n' 'To revoke on this server, remove the authorized_keys line ending aicontrol-system-$id.'\n";
    }
    public function snapshot(int $id=0,int $run=0): array
    {
        $systems=$this->query('SELECT * FROM systems ORDER BY id DESC');$selected=$id?$this->system($id):null;
        $runs=$id?$this->query('SELECT * FROM system_runs WHERE system_id=? ORDER BY id DESC LIMIT 50',[$id]):[];
        $current=$run?$this->run($run):($runs[0]??null);if($current&&(!$selected||(int)$current['system_id']!==$id))throw new RuntimeException('Task does not belong to this system.');
        return ['systems'=>$systems,'selected'=>$selected,'runs'=>$runs,'run'=>$current,'messages'=>$current?$this->query('SELECT * FROM system_messages WHERE run_id=? ORDER BY id',[$current['id']]):[],'commands'=>$current?$this->query('SELECT * FROM system_commands WHERE run_id=? ORDER BY id',[$current['id']]):[],'script'=>$selected&&$selected['enabled']?$this->script($id):'','runner_available'=>SshTransport::available()];
    }
    public function revoke(int $id): void
    {
        $this->system($id);$this->write('UPDATE systems SET enabled=0,verified_at=NULL WHERE id=?',[$id]);$this->write("UPDATE system_runs SET status='stopped',finished_at=? WHERE system_id=? AND status IN ('ready','working')",[time(),$id]);(new Vault($this->directory))->change('ssh_system_'.$id,'key','',false,true);$this->write('UPDATE systems SET key_deleted_at=? WHERE id=?',[time(),$id]);
    }
    public function purge(int $id,string $confirmation): void
    {
        $s=$this->system($id);
        if($s['enabled']||!$s['key_deleted_at']||$confirmation!=='DELETE SYSTEM '.$id)throw new RuntimeException('Disconnect first, then type DELETE SYSTEM '.$id.' to delete this system and its saved task logs.');
        $path=$this->directory.'/systems-run.lock';if(is_link($path))throw new RuntimeException('Systems execution lock unavailable.');$lock=fopen($path,'c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new RuntimeException('Wait for the interrupted system step to finish before deleting its records.');}
        try{$this->db->beginTransaction();$this->write('DELETE FROM system_messages WHERE run_id IN (SELECT id FROM system_runs WHERE system_id=?)',[$id]);$this->write('DELETE FROM system_commands WHERE run_id IN (SELECT id FROM system_runs WHERE system_id=?)',[$id]);$this->write('DELETE FROM system_runs WHERE system_id=?',[$id]);$this->write('DELETE FROM systems WHERE id=?',[$id]);$this->db->commit();}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function stop(int $run): void {$this->run($run);$this->write("UPDATE system_runs SET status='stopped',finished_at=? WHERE id=? AND status IN ('ready','working')",[time(),$run]);}
    public function privateKey(int $id): string {$key=(new Vault($this->directory))->value('ssh_system_'.$id);if($key==='')throw new RuntimeException('SSH credential unavailable.');return $key;}
    public function pin(int $id,string $fingerprint): void
    {
        $s=$this->system($id);$fingerprint=trim($fingerprint);
        if(!preg_match('~^SHA256:[A-Za-z0-9+/]{43}$~D',$fingerprint))throw new RuntimeException('Paste the SHA256 fingerprint printed by the server setup script.');
        if($s['host_key']&&$s['fingerprint']!==$fingerprint)throw new RuntimeException('A verified server identity cannot be replaced. Disconnect and add the server again after checking the change.');
        $this->write('UPDATE systems SET fingerprint=? WHERE id=?',[$fingerprint,$id]);
    }
    public function verify(int $id,?object $transport=null): void
    {
        $s=$this->system($id);if(!$s['enabled'])throw new RuntimeException('This system is disconnected. Add it again to issue a new key.');$transport??=new SshTransport();
        try{$s['host_key']=$transport->probe($s);$r=$transport->execute($s,$this->privateKey($id),SystemsAgent::INSPECT['system_info'],$this->directory,fn()=>!(bool)$this->system($id)['enabled']);if($r['exit_code']!==0||$r['timed_out']||$r['cancelled'])throw new RuntimeException('SSH authentication or inspection failed. Check the setup script, account permissions and timeout utility.');
            $this->write('UPDATE systems SET host_key=?,verified_at=?,facts=?,error=NULL WHERE id=? AND enabled=1',[$s['host_key'],time(),Diagnostics::clean($r['stdout']),$id]);
        }catch(\Throwable $e){$this->write('UPDATE systems SET verified_at=NULL,error=? WHERE id=?',[Diagnostics::clean($e->getMessage()),$id]);throw $e;}
    }
}
