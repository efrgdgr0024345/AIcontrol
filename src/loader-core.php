<?php
/** Adapted from the owner's LearnPiano / DeterministicFace loader (2026-09-28). */
function ac_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ac_json(string $s): array { $v=json_decode($s,true,64,JSON_THROW_ON_ERROR); if(!is_array($v)) throw new RuntimeException('Invalid JSON object.'); return $v; }
function ac_project(string $source): array {
    if(!preg_match('~/\* AC_PROJECT_JSON_BEGIN\s*(.*?)\s*AC_PROJECT_JSON_END \*/~s',$source,$m)) throw new RuntimeException('Embedded project data missing.');
    $p=ac_json($m[1]);
    if(($p['schema']??0)!==1 || !is_string($p['title']??null) || !is_array($p['milestones']??null)) throw new RuntimeException('Unsupported project-data schema.');
    foreach(['scope','decisions','references','history'] as $k) if(!is_array($p[$k]??null)) throw new RuntimeException('Incomplete project data.');
    foreach($p['milestones'] as $row) if(!is_array($row)||!in_array($row['status']??'', ['not_started','in_review','in_progress','blocked','complete'],true)) throw new RuntimeException('Invalid milestone status.');
    return $p;
}
function ac_branch(string $s): string {
    if(!preg_match('~^[A-Za-z0-9][A-Za-z0-9_./-]{0,149}$~D',$s)||str_contains($s,'..')||str_contains($s,'//')||str_ends_with($s,'/')||str_ends_with($s,'.lock')) throw new RuntimeException('Invalid branch name.'); return $s;
}
function ac_path(string $p): string {
    if($p===''||strlen($p)>240||str_contains($p,'\\')||str_contains($p,':')||preg_match('~[\x00-\x20\x7f]~',$p)||$p[0]==='/') throw new RuntimeException('Unsafe package path.');
    foreach(explode('/',$p) as $part) if($part===''||$part==='.'||$part==='..'||$part[0]==='.') throw new RuntimeException('Unsafe package component.'); return $p;
}
function ac_target(string $p): ?string {
    ac_path($p);
    return $p === 'loader.php' ? 'loader.php' : null;
}
function ac_owned_path(string $p): string {
    if ($p !== 'loader.php') throw new RuntimeException('Only loader.php is deployment-managed.');
    return $p;
}
/** Reject symlinks anywhere in a filesystem path, including a dangling leaf. */
function ac_fs_path(string $path): string {
    if($path===''||str_contains($path,"\0")||str_contains($path,'\\')) throw new RuntimeException('Invalid filesystem path.');
    if($path[0]!=='/')$path=getcwd().'/'.$path;
    $path=preg_replace('~/+~','/',$path);
    $parts=explode('/',trim($path,'/'));$cursor='';
    foreach($parts as $i=>$part){
        if($part===''||$part==='.'||$part==='..')throw new RuntimeException('Non-canonical filesystem path.');
        $cursor.='/'.$part;clearstatcache(true,$cursor);$stat=@lstat($cursor);
        if($stat===false)continue;
        $type=$stat['mode']&0170000;
        if($type===0120000||($i<count($parts)-1&&$type!==0040000))throw new RuntimeException('Symlink or non-directory in filesystem path.');
    }
    return $path;
}
function ac_regular_file(string $path,bool $required=true): ?array {
    $path=ac_fs_path($path);clearstatcache(true,$path);$s=@lstat($path);
    if($s===false){if($required)throw new RuntimeException('Required file is missing.');return null;}
    if(($s['mode']&0170000)!==0100000||$s['nlink']!==1)throw new RuntimeException('Expected a regular file with one link.');
    return $s;
}
function ac_file_bytes(string $path,int $limit): string {
    $path=ac_fs_path($path);$before=ac_regular_file($path);
    if($limit<0||$before['size']>$limit)throw new RuntimeException('File exceeds the permitted size.');
    $f=@fopen($path,'rb');if($f===false)throw new RuntimeException('Cannot read file.');
    try{$after=fstat($f);if($after===false||$after['dev']!==$before['dev']||$after['ino']!==$before['ino'])throw new RuntimeException('File changed while opening.');
        $bytes=stream_get_contents($f,$limit+1);if($bytes===false||strlen($bytes)>$limit)throw new RuntimeException('Cannot read complete file within limit.');return $bytes;
    }finally{fclose($f);}
}
function ac_no_links(string $root,string $relative): string {
    ac_owned_path($relative);$root=ac_fs_path(rtrim($root,'/'));if(!is_dir($root))throw new RuntimeException('Deployment directory is missing.');
    return ac_fs_path($root.'/'.$relative);
}
function ac_mkdir(string $p,int $mode=0700): void {
    $p=ac_fs_path($p);if(($mode&0022)!==0||$mode<0||$mode>0777)throw new RuntimeException('Unsafe directory permissions.');
    if(file_exists($p)&&!is_dir($p))throw new RuntimeException('Directory path is occupied by a file.');
    if(!is_dir($p)&&!mkdir($p,$mode,true)&&!is_dir($p))throw new RuntimeException('Cannot create working directory.');
    ac_fs_path($p);
}
function ac_write(string $path,#[SensitiveParameter] string $bytes,int $mode=0600): void {
    $path=ac_fs_path($path);ac_regular_file($path,false);
    if(!is_dir(dirname($path))||($mode&0022)!==0||$mode<0||$mode>0777)throw new RuntimeException('Unsafe atomic-write destination.');
    $tmp=dirname($path).'/.write-'.bin2hex(random_bytes(16));$f=null;
    $mask=umask(0077);try{$f=@fopen($tmp,'x+b');}finally{umask($mask);}
    if($f===false)throw new RuntimeException('Cannot create private temporary file.');
    try{$offset=0;while($offset<strlen($bytes)){$n=fwrite($f,substr($bytes,$offset));if($n===false||$n===0)throw new RuntimeException('Cannot write complete file.');$offset+=$n;}
        if(!fflush($f)||(function_exists('fsync')&&!fsync($f)))throw new RuntimeException('Cannot persist file.');
        if(!chmod($tmp,$mode))throw new RuntimeException('Cannot set file permissions.');
        fclose($f);$f=null;ac_fs_path($path);ac_regular_file($path,false);
        if(!rename($tmp,$path))throw new RuntimeException('Cannot activate file.');
    }finally{if(is_resource($f))fclose($f);if(is_file($tmp)&&!is_link($tmp))unlink($tmp);}
}
function ac_read(string $path): array { $path=ac_fs_path($path);$s=ac_regular_file($path,false);if($s===null)return [];if(($s['mode']&0077)!==0)throw new RuntimeException('State file permissions are not private.');return ac_json(ac_file_bytes($path,16777216)); }
function ac_save(string $path,array $value): void { ac_write($path,json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n"); }
function ac_lock(string $dir): mixed {
    $path=ac_fs_path(rtrim($dir,'/').'/lock');$before=ac_regular_file($path,false);
    if($before===null){$mask=umask(0077);try{$created=@fopen($path,'x+b');}finally{umask($mask);}if($created!==false)fclose($created);$before=ac_regular_file($path);}
    if(($before['mode']&0777)!==0600||(function_exists('posix_geteuid')&&$before['uid']!==posix_geteuid()))throw new RuntimeException('Unsafe lock-file ownership or permissions.');
    $f=@fopen($path,'r+b');if($f===false)throw new RuntimeException('Cannot open operation lock.');$after=fstat($f);
    if($after===false||$after['dev']!==$before['dev']||$after['ino']!==$before['ino']||!flock($f,LOCK_EX|LOCK_NB)){
        fclose($f);throw new RuntimeException('Another operation is running or the lock changed. Try again after it completes.');}
    return $f;
}
function ac_unlock(mixed $f): void { if(is_resource($f)){flock($f,LOCK_UN);fclose($f);} }
function ac_safe_private(string $root,string $docroot): string {
    $d=realpath($docroot);if($d===false) throw new RuntimeException('Public document root is unavailable.');
    $root=ac_fs_path($root);
    if(file_exists($root)){
        if(!is_dir($root)) throw new RuntimeException('Private storage path is not a directory.');
        $r=realpath($root);if($r===false) throw new RuntimeException('Private storage path is unavailable.');
        if($r===$d||str_starts_with($r,$d.'/')) throw new RuntimeException('Private storage must remain outside the public document root.');
        clearstatcache(true,$r);
    } else {
        $parent=realpath(dirname($root));if($parent===false) throw new RuntimeException('Private storage parent directory is unavailable.');
        if($parent===$d||str_starts_with($parent,$d.'/')) throw new RuntimeException('Private storage must remain outside the public document root.');
        if(!mkdir($root,0700,false)&&!is_dir($root)) throw new RuntimeException('Cannot create private storage directory.');
        $r=realpath($root);if($r===false) throw new RuntimeException('Private storage path is unavailable after creation.');
    }
    if((fileperms($r)&0777)!==0700||(function_exists('posix_geteuid')&&fileowner($r)!==posix_geteuid()))throw new RuntimeException('Private directory must be owned by PHP and have 0700 permissions.');return $r;
}
function ac_http(string $url,#[SensitiveParameter] string $token='',int $limit=AC_MAX_ARCHIVE): array {
    $parts=parse_url($url); $host=strtolower($parts['host']??'');
    if($parts===false||($parts['scheme']??'')!=='https'||!in_array($host,['api.github.com','codeload.github.com'],true)||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||(isset($parts['port'])&&$parts['port']!==443)||preg_match('/[\x00-\x20\x7f]/',$url)) throw new RuntimeException('Unapproved GitHub download destination.');
    if($limit<1||$limit>AC_MAX_ARCHIVE||preg_match('/[\x00-\x20\x7f]/',$token))throw new RuntimeException('Invalid GitHub request configuration.');
    if($token!==''&&$host!=='api.github.com') throw new RuntimeException('Credential forwarding blocked.');
    if(!function_exists('curl_init')) throw new RuntimeException('Enable PHP cURL in cPanel.');
    $headers=['User-Agent: AIcontrol/'.AC_VERSION,'Accept: application/vnd.github+json','X-GitHub-Api-Version: 2022-11-28'];
    if($token!=='')$headers[]='Authorization: Bearer '.$token;
    $body='';$location='';$ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>90,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>static function($ch,string $s)use(&$body,$limit){if(strlen($body)+strlen($s)>$limit)return 0;$body.=$s;return strlen($s);},
        CURLOPT_HEADERFUNCTION=>static function($ch,string $s)use(&$location){if(stripos($s,'Location:')===0)$location=trim(substr($s,9));return strlen($s);}]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($ok===false)throw new RuntimeException('GitHub transfer failed or exceeded the download limit. No update was activated.');
    return ['status'=>$status,'body'=>$body,'location'=>$location];
}
function ac_api(string $endpoint,string $token): array {
    $r=ac_http('https://api.github.com/repos/'.AC_REPO.'/'.$endpoint,$token,AC_MAX_FILE);
    if($r['status']!==200)throw new RuntimeException('GitHub HTTP '.$r['status'].'. Check repository read access, branch and token permissions.');return ac_json($r['body']);
}
function ac_check_result(array $a,string $sha): array {
    $states=[];
    if(!preg_match('/\A[a-f0-9]{40}\z/D',$sha))throw new RuntimeException('Invalid checked commit identity.');
    if(!is_int($a['total_count']??null)||$a['total_count']<0||$a['total_count']>100||
        !is_array($a['check_runs']??null)||count($a['check_runs'])!==$a['total_count'])throw new RuntimeException('Incomplete check-run response. Update blocked.');
    foreach($a['check_runs'] as $r){if(!is_array($r)||!is_string($r['name']??null))throw new RuntimeException('Invalid check-run response.');$name=$r['name'];if(!in_array($name,AC_REQUIRED_CHECKS,true))continue;
        $pass=($r['head_sha']??'')===$sha&&($r['app']['slug']??'')==='github-actions'&&($r['status']??'')==='completed'&&($r['conclusion']??'')==='success';
        $states[$name]=isset($states[$name])?($states[$name]&&$pass):$pass;
    }
    $ok=true;foreach(AC_REQUIRED_CHECKS as $n)if(empty($states[$n]))$ok=false;
    return ['ok'=>$ok,'required'=>AC_REQUIRED_CHECKS,'states'=>$states];
}
function ac_checks(string $sha,string $token): array { return ac_check_result(ac_api('commits/'.$sha.'/check-runs?per_page=100&filter=latest',$token),$sha); }
function ac_remote(string $branch,string $token): array {
    $branch=ac_branch($branch);$c=ac_api('commits/'.rawurlencode($branch),$token);$sha=$c['sha']??'';
    if(!is_string($sha)||!preg_match('/^[a-f0-9]{40}$/D',$sha))throw new RuntimeException('Invalid GitHub commit identity.');
    $f=ac_api('contents/loader.php?ref='.$sha,$token);
    if(($f['encoding']??'')!=='base64'||($f['type']??'')!=='file'||($f['path']??'')!=='loader.php'||!is_string($f['content']??null))throw new RuntimeException('This commit has no supported loader.php. Use the console development branch until it is merged.');
    $source=base64_decode(str_replace(["\r","\n"],'',$f['content']),true);
    if($source===false||!hash_equals((string)($f['sha']??''),sha1('blob '.strlen($source)."\0".$source)))throw new RuntimeException('GitHub file identity check failed.');
    $p=ac_project($source);$checks=ac_checks($sha,$token);
    return ['sha'=>$sha,'branch'=>$branch,'message'=>substr((string)($c['commit']['message']??''),0,600),'commit_date'=>$c['commit']['committer']['date']??'', 'checked_at'=>gmdate(DATE_ATOM),'loader_sha256'=>hash('sha256',$source),'project'=>$p,'checks'=>$checks];
}
function ac_package(string $zipfile): array {
    if(!class_exists('ZipArchive'))throw new RuntimeException('Enable PHP ZipArchive in cPanel.');
    $zipfile=ac_fs_path($zipfile);$zipstat=ac_regular_file($zipfile);if($zipstat['size']>AC_MAX_ARCHIVE)throw new RuntimeException('Archive exceeds download limits.');
    $z=new ZipArchive();if($z->open($zipfile,ZipArchive::RDONLY)!==true)throw new RuntimeException('Invalid ZIP archive.');$files=[];$seen=[];$top=null;$expanded=0;
    try {
        if($z->numFiles>3000)throw new RuntimeException('Archive contains too many entries.');
        for($i=0;$i<$z->numFiles;$i++){
            $s=$z->statIndex($i);if(!$s||!is_string($s['name']??null)||!is_int($s['size']??null)||$s['size']<0)throw new RuntimeException('Unreadable archive entry.');$name=$s['name'];
            if($name===''||strlen($name)>1024||str_contains($name,'\\')||str_starts_with($name,'/')||preg_match('~[\x00-\x1f\x7f]~',$name))throw new RuntimeException('Unsafe archive entry.');
            $parts=explode('/',rtrim($name,'/'));foreach($parts as $part)if($part===''||$part==='.'||$part==='..'||str_contains($part,':'))throw new RuntimeException('Unsafe archive entry.');
            $key=strtolower(rtrim($name,'/'));if(isset($seen[$key]))throw new RuntimeException('Duplicate or case-colliding archive entry.');$seen[$key]=true;
            if($top===null)$top=$parts[0];if($parts[0]!==$top)throw new RuntimeException('Multiple archive roots.');
            $opsys=0;$attr=0;if(!$z->getExternalAttributesIndex($i,$opsys,$attr))throw new RuntimeException('Archive file attributes unavailable.');$type=($attr>>16)&0170000;
            if($type!==0&&$type!==0100000&&$type!==0040000)throw new RuntimeException('Links/special files are not accepted in packages.');
            $directory=str_ends_with($name,'/');if(($directory&&$type===0100000)||(!$directory&&$type===0040000)||($directory&&$s['size']!==0))throw new RuntimeException('Archive entry type mismatch.');
            if(($s['encryption_method']??0)!==0)throw new RuntimeException('Encrypted archive entries are not accepted.');
            if($directory)continue;
            if(count($parts)<2)throw new RuntimeException('Archive files must be under a single root directory.');
            $expanded+=(int)$s['size'];if($expanded>AC_MAX_EXPANDED||(int)$s['size']>AC_MAX_FILE)throw new RuntimeException('Package exceeds extraction limits.');
            $rel=implode('/',array_slice($parts,1));
            // Repository-only dotfiles, docs and tests are NOT deployed into the website.
            if($rel!=='loader.php')continue;
            $target=ac_target($rel);if($target===null)continue;
            $bytes=$z->getFromIndex($i,AC_MAX_FILE+1);if($bytes===false||strlen($bytes)!==(int)$s['size'])throw new RuntimeException('Incomplete archive entry.');
            if(!str_starts_with($bytes,"<?php\n"))throw new RuntimeException('Package loader is not a supported PHP source file.');
            token_get_all($bytes,TOKEN_PARSE);
            $files[$target]=$bytes;
        }
    } finally {$z->close();}
    if(!isset($files['loader.php']))throw new RuntimeException('This snapshot has no loader.php; nothing was installed.');ac_project($files['loader.php']);
    if(count($files)>500)throw new RuntimeException('Too many deployable files.');ksort($files,SORT_STRING);return $files;
}
function ac_assert_existing(string $root,array $managed,array $incoming): void {
    foreach(array_unique(array_merge(array_keys($managed),array_keys($incoming))) as $rel){$p=ac_no_links($root,(string)$rel);
        if(is_dir($p))throw new RuntimeException('File/directory conflict: '.$rel);
        if(isset($managed[$rel])){if(!is_file($p)||!hash_equals((string)$managed[$rel],hash_file('sha256',$p)))throw new RuntimeException('A managed file changed locally; preserving it: '.$rel);}
        elseif(file_exists($p)&&$rel!=='loader.php')throw new RuntimeException('Unmanaged file would be overwritten; preserving it: '.$rel);
    }
}
function ac_replace(string $root,string $dir,string $rel,?string $bytes): void {
    $target=ac_no_links($root,$rel);
    if($bytes===null){if(is_file($target)&&!unlink($target))throw new RuntimeException('Cannot remove obsolete managed file.');return;}
    ac_mkdir(dirname($target),0755);ac_no_links($root,$rel);
    $tmp=$dir.'/activate-'.bin2hex(random_bytes(8));
    try {ac_write($tmp,$bytes);chmod($tmp,0644);if(!rename($tmp,$target))throw new RuntimeException('Activation failed; private storage and website must share a filesystem.');if(function_exists('opcache_invalidate'))@opcache_invalidate($target,true);} finally {if(is_file($tmp))unlink($tmp);}
}
function ac_restore(string $root,string $dir,array $journal): void {
    if(($journal['root']??'')!==realpath($root)||!preg_match('/^[a-f0-9]{24}$/D',(string)($journal['id']??'')))throw new RuntimeException('Invalid recovery journal.');
    foreach($journal['before'] as $rel=>$hash){ac_owned_path((string)$rel);$bytes=null;
        if($hash!==null){$path=$dir.'/backups/'.$journal['id'].'/'.hash('sha256',$rel);$bytes=@file_get_contents($path);if($bytes===false||!hash_equals($hash,hash('sha256',$bytes)))throw new RuntimeException('Backup identity mismatch.');}
        ac_replace($root,$dir,(string)$rel,$bytes);
    }
    ac_save($dir.'/state.json',$journal['old_state']);
}
function ac_activate(string $root,string $dir,array $files,array $meta,?callable $hook=null): array {
    $state=ac_read($dir.'/state.json');$managed=$state['managed']??[];
    if(is_file($dir.'/journal.json'))throw new RuntimeException('Interrupted update requires recovery first.');
    ac_assert_existing($root,$managed,$files);$id=bin2hex(random_bytes(12));$backup=$dir.'/backups/'.$id;ac_mkdir($backup);$before=[];
    foreach(array_unique(array_merge(array_keys($managed),array_keys($files))) as $rel){$p=ac_no_links($root,$rel);$before[$rel]=is_file($p)?hash_file('sha256',$p):null;if($before[$rel]!==null)ac_write($backup.'/'.hash('sha256',$rel),(string)file_get_contents($p));}
    $j=['id'=>$id,'root'=>realpath($root),'old_state'=>$state,'before'=>$before];ac_save($dir.'/journal.json',$j);
    try {
        // Activate the console last. Multi-file app deployment is recoverable, not globally atomic.
        $order=array_values(array_diff(array_keys($before),['loader.php']));$order[]='loader.php';
        foreach($order as $i=>$rel){ac_replace($root,$dir,$rel,$files[$rel]??null);if($hook!==null)$hook($rel,$i);}
        $new=['repository'=>AC_REPO,'sha'=>$meta['sha'],'branch'=>$meta['branch'],'deployed_at'=>gmdate(DATE_ATOM),'archive_sha256'=>$meta['archive_sha256']??'', 'managed'=>array_map(static fn($b)=>hash('sha256',$b),$files),'previous_backup'=>$id];
        ac_save($dir.'/state.json',$new);ac_save($dir.'/rollback.json',$j);unlink($dir.'/journal.json');return $new;
    } catch(Throwable $e){try{ac_restore($root,$dir,$j);unlink($dir.'/journal.json');}catch(Throwable $r){throw new RuntimeException('Update interrupted; recovery required. Backups are retained.');}throw new RuntimeException('Update failed and the previous files were restored: '.$e->getMessage());}
}
function ac_install(string $root,string $dir,array $pending,string $token,bool $preview): array {
    if(!preg_match('/^[a-f0-9]{40}$/D',(string)($pending['sha']??''))||!strtotime((string)($pending['checked_at']??''))||time()-strtotime((string)$pending['checked_at'])>600||strtotime((string)$pending['checked_at'])>time()+30)throw new RuntimeException('Check GitHub again; the selection expired.');
    if(($pending['branch']??'')!=='main'&&!$preview)throw new RuntimeException('Explicitly acknowledge the experimental branch before installation.');
    if(!ac_checks($pending['sha'],$token)['ok'])throw new RuntimeException('Required checks are missing, pending or failing on this exact commit.');
    $r=ac_http('https://api.github.com/repos/'.AC_REPO.'/zipball/'.$pending['sha'],$token);
    if($r['status']===302){$r=ac_http($r['location']);} // Never forward the API token to codeload.
    if($r['status']!==200||!str_starts_with($r['body'],'PK'))throw new RuntimeException('GitHub did not return a valid archive.');
    $tmp=$dir.'/archive-'.bin2hex(random_bytes(8)).'.zip';
    try{ac_write($tmp,$r['body']);$files=ac_package($tmp);if(!hash_equals($pending['loader_sha256'],hash('sha256',$files['loader.php'])))throw new RuntimeException('Archive loader differs from the checked commit.');$pending['archive_sha256']=hash('sha256',$r['body']);return ac_activate($root,$dir,$files,$pending);}finally{if(is_file($tmp))unlink($tmp);}
}
function ac_gate(array &$session,string $method,array $post): string {
    if($method!=='POST')return '';
    if(!is_string($post['csrf']??null)||!hash_equals((string)($session['csrf']??''),$post['csrf'])||empty($session['csrf']))throw new RuntimeException('The form expired. Refresh the page and retry.');
    if(!is_string($post['action']??null))throw new RuntimeException('Invalid action.');return $post['action'];
}
function ac_https(): bool {return (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');}

