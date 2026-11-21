<?php
declare(strict_types=1);
namespace AIcontrol;

/** Self-contained, reviewable Python bootstrap. No downloaded executable or private key. */
final class SystemBootstrap
{
    public static function command(string $script): string
    {
        $python='import base64;exec(compile(base64.b64decode("'.base64_encode($script).'"),"aicontrol-setup","exec"))';
        // Both stages run with root authority: exclude cwd, user site and PYTHON* imports.
        $args='env PATH=/usr/sbin:/usr/bin:/sbin:/bin AICONTROL_SSH_CONNECTION="${SSH_CONNECTION:-}" /usr/bin/python3 -I -c '.SystemRisk::quote($python);
        return 'if [ "$(id -u)" -eq 0 ]; then '.$args.'; else sudo '.$args.'; fi';
    }
    public static function inspector(): string
    {
        return "#!/usr/bin/python3 -I\n".'import os, subprocess, sys, json' ."\n".
            'allowed=json.loads('.json_encode(json_encode(SystemRisk::commands(),JSON_UNESCAPED_SLASHES)).")\n".
            'request=os.environ.get("SSH_ORIGINAL_COMMAND", "")'."\n".
            'prefix="aicontrol-inspect "'."\n".
            'name=request[len(prefix):] if request.startswith(prefix) else ""'."\n".
            'if name not in allowed: print("Read-only key: command is outside the diagnostic allowlist.",file=sys.stderr);sys.exit(126)'."\n".
            'os.execvpe("timeout",["timeout","-k","2","15","/bin/sh","-c",allowed[name]],{"PATH":"/usr/sbin:/usr/bin:/sbin:/bin","LC_ALL":"C","HOME":os.path.expanduser("~")})'."\n";
    }
    public static function script(array $enrollment,string $stage,?array $system=null): string
    {
        $config=['id'=>$enrollment['id'],'challenge'=>$enrollment['challenge'],'receipt_challenge'=>$enrollment['receipt_challenge'],'risk'=>$enrollment['risk_level'],'username'=>$enrollment['username'],'stage'=>$stage];
        if($system){$config['public_key']=$system['public_key'];$config['host_fingerprint']=$system['fingerprint'];$config['machine_tag']=json_decode($enrollment['discovery_json'],true)['machine_tag'];$config['inspector']=self::inspector();$config['system_id']=(int)$system['id'];}
        $prefix='import base64, json' ."\n".'CONFIG=json.loads(base64.b64decode("'.base64_encode(json_encode($config,JSON_THROW_ON_ERROR)).'"))'."\n";
        $common= <<<'PY'
import os, sys, pwd, grp, stat, pathlib, subprocess, tempfile, hashlib, socket, ipaddress, fcntl, shutil, shlex
ROOT=pathlib.Path('/var/lib/aicontrol')
IDENTITY=CONFIG['id']
USER=CONFIG['username']
RISK=CONFIG['risk']
HOME=ROOT/'homes'/USER
def run(args, required=True):
    r=subprocess.run(args,capture_output=True,text=True,timeout=12)
    if required and r.returncode: raise RuntimeError(args[0]+' failed: '+r.stderr.strip()[:300])
    return r
def secure_dir(path, mode=0o700):
    created=False
    try: path.mkdir(mode=mode);created=True
    except FileExistsError: pass
    s=path.lstat()
    if not stat.S_ISDIR(s.st_mode) or s.st_uid!=0 or s.st_mode & 0o022: raise RuntimeError('Unsafe directory: '+str(path))
    if created: os.chmod(path,mode)
def secure_json(path):
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    with os.fdopen(fd) as f:
        s=os.fstat(f.fileno())
        if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_nlink!=1 or s.st_mode & 0o077: raise RuntimeError('Unsafe enrollment marker')
        return json.load(f)
def atomic(path, content, mode=0o600, uid=0, gid=0):
    parent=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    name='.aicontrol-'+os.urandom(12).hex()
    try:
        fd=os.open(name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,mode,dir_fd=parent)
        with os.fdopen(fd,'w') as f: f.write(content);f.flush();os.fsync(f.fileno());os.fchmod(f.fileno(),mode);os.fchown(f.fileno(),uid,gid)
        os.replace(name,path.name,src_dir_fd=parent,dst_dir_fd=parent)
    finally:
        try: os.unlink(name,dir_fd=parent)
        except FileNotFoundError: pass
        os.close(parent)
def host_identity():
    path=pathlib.Path('/etc/ssh/ssh_host_ed25519_key.pub')
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    with os.fdopen(fd) as f:
        s=os.fstat(f.fileno())
        if s.st_uid!=0 or s.st_mode & 0o022: raise RuntimeError('Unsafe SSH host public key permissions')
        parts=f.read(2048).split()
    if len(parts)<2 or parts[0]!='ssh-ed25519': raise RuntimeError('An ED25519 SSH host key is required')
    raw=base64.b64decode(parts[1],validate=True)
    return 'ssh-ed25519 '+parts[1], 'SHA256:'+base64.b64encode(hashlib.sha256(raw).digest()).decode().rstrip('=')
def machine_tag():
    value=pathlib.Path('/etc/machine-id').read_text().strip()
    if not value: raise RuntimeError('Machine identity is unavailable')
    return hashlib.sha256((value+IDENTITY).encode()).hexdigest()
def check_account(marker, configured=False):
    account=pwd.getpwnam(USER)
    if account.pw_uid==0 or account.pw_uid!=marker['uid'] or account.pw_dir!=str(HOME) or account.pw_shell!='/bin/sh': raise RuntimeError('Dedicated account identity changed')
    if marker['risk']!=RISK or marker['challenge']!=CONFIG['challenge'] or marker['username']!=USER: raise RuntimeError('Risk or enrollment mismatch')
    if HOME.is_symlink() or not HOME.is_dir(): raise RuntimeError('Unsafe account home')
    h=HOME.lstat()
    if configured and (h.st_uid!=(0 if RISK=='readonly' else account.pw_uid) or h.st_gid!=account.pw_gid or stat.S_IMODE(h.st_mode)!=0o750): raise RuntimeError('Account home permissions changed; rerun command 1')
    shadow=run(['getent','shadow',USER]).stdout.split(':')
    if len(shadow)<2 or not (shadow[1]=='*' or shadow[1].startswith('!')): raise RuntimeError('Dedicated account password must remain locked')
    if RISK!='admin':
        groups=run(['id','-Gn',USER]).stdout.split()
        if set(groups)&{'root','sudo','wheel'}: raise RuntimeError('Unexpected privileged group')
        if shutil.which('sudo') and run(['sudo','-n','-l','-U',USER],False).returncode==0: raise RuntimeError('Non-administrator account unexpectedly has sudo access')
    return account
def emit(kind, body):
    body.update(format='AIcontrol SSH '+kind+' v1', enrollment_id=IDENTITY, risk_level=RISK, username=USER)
    print('---BEGIN AICONTROL '+kind.upper()+' V1---')
    print(json.dumps(body,indent=2,sort_keys=True))
    print('---END AICONTROL '+kind.upper()+' V1---')
if os.geteuid()!=0: raise SystemExit('Run this command with sudo or as root.')
os.umask(0o077)
for tool in ['useradd','getent','ssh-keygen','timeout']:
    if not shutil.which(tool): raise SystemExit('Required Linux tool is missing: '+tool)
host_key,host_fingerprint=host_identity()
tag=machine_tag()
secure_dir(ROOT,0o755);secure_dir(ROOT/'homes',0o755);secure_dir(ROOT/'enrollments')
lock_fd=os.open(ROOT/'enrollments'/'bootstrap.lock',os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
if os.fstat(lock_fd).st_uid!=0 or os.fstat(lock_fd).st_nlink!=1: raise SystemExit('Unsafe bootstrap lock')
fcntl.flock(lock_fd,fcntl.LOCK_EX)
marker_path=ROOT/'enrollments'/(IDENTITY+'.json')
PY;
        $discovery= <<<'PY'
if RISK=='admin' and (not shutil.which('sudo') or not shutil.which('visudo')): raise SystemExit('Administrator setup requires sudo and visudo.')
if marker_path.exists():
    marker=secure_json(marker_path)
else:
    try: pwd.getpwnam(USER)
    except KeyError: pass
    else: raise SystemExit('An unrecognised account already uses this name; prepare a new enrollment.')
    if HOME.exists() or HOME.is_symlink(): raise SystemExit('Account home already exists; refusing to replace it.')
    run(['useradd','--user-group','--password','*','--create-home','--home-dir',str(HOME),'--shell','/bin/sh',USER])
    account=pwd.getpwnam(USER)
    marker={'risk':RISK,'challenge':CONFIG['challenge'],'username':USER,'uid':account.pw_uid,'machine_tag':tag}
    atomic(marker_path,json.dumps(marker))
account=check_account(marker)
if marker['machine_tag']!=tag: raise SystemExit('Enrollment belongs to another machine.')
os.chmod(HOME,0o750);os.chown(HOME,0 if RISK=='readonly' else account.pw_uid,account.pw_gid)
if RISK=='admin':
    directory=pathlib.Path('/etc/sudoers.d');secure_dir(directory,0o755)
    policy=USER+' ALL=(ALL:ALL) NOPASSWD: ALL\n'
    fd,candidate=tempfile.mkstemp(prefix='aicontrol-',dir=ROOT/'enrollments')
    try:
        with os.fdopen(fd,'w') as f: f.write(policy);f.flush();os.fsync(f.fileno())
        run(['visudo','-cf',candidate])
        atomic(directory/('aicontrol-'+IDENTITY),policy,0o440)
        if run(['sudo','-n','-u',USER,'sudo','-n','/usr/bin/id','-u']).stdout.strip()!='0': raise RuntimeError('Full sudo access could not be verified')
    finally: os.unlink(candidate)
connection=os.environ.get('AICONTROL_SSH_CONNECTION','').split()
addresses=[]
try:
    for interface in json.loads(run(['ip','-j','address']).stdout):
        for item in interface.get('addr_info',[]):
            address=item.get('local','')
            try:
                parsed=ipaddress.ip_address(address)
                if not parsed.is_loopback and not parsed.is_link_local: addresses.append(address)
            except ValueError: pass
except (FileNotFoundError,ValueError,RuntimeError): pass
ports=[]
if len(connection)==4:
    try:
        ipaddress.ip_address(connection[2]);ports=[int(connection[3])];addresses.insert(0,connection[2])
    except ValueError: pass
if not ports:
    sshd=shutil.which('sshd') or '/usr/sbin/sshd'
    for line in run([sshd,'-T']).stdout.splitlines():
        if line.startswith('port '): ports.append(int(line.split()[1]))
if not ports or not all(0<p<65536 for p in ports): raise SystemExit('Could not determine the SSH listening port.')
addresses=list(dict.fromkeys(addresses))[:32]
public_address=None
try:
    import urllib.request
    value=urllib.request.urlopen('https://api.ipify.org',timeout=3).read(80).decode().strip()
    if ipaddress.ip_address(value).is_global: public_address=value
except Exception: pass
if not addresses: raise SystemExit('Could not determine a connection address.')
emit('discovery',{'challenge':CONFIG['challenge'],'machine_tag':tag,'hostname':socket.gethostname(),'addresses':addresses,'connection_host':public_address or addresses[0],'public_address':public_address,'ssh_port':ports[0],'ssh_ports':ports,'host_key':host_key,'host_fingerprint':host_fingerprint,'uid':account.pw_uid,'home_dir':str(HOME),'sudo_granted':RISK=='admin'})
PY;
        $configure= <<<'PY'
marker=secure_json(marker_path);account=check_account(marker,True)
if tag!=CONFIG['machine_tag'] or marker['machine_tag']!=tag or host_fingerprint!=CONFIG['host_fingerprint']: raise SystemExit('This command belongs to another server or a changed host key.')
if RISK=='admin':
    policy=pathlib.Path('/etc/sudoers.d')/('aicontrol-'+IDENTITY)
    s=policy.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode & 0o022 or policy.read_text()!=USER+' ALL=(ALL:ALL) NOPASSWD: ALL\n': raise SystemExit('Administrator policy is missing or changed; rerun command 1.')
    run(['visudo','-cf',str(policy)])
    if run(['sudo','-n','-u',USER,'sudo','-n','/usr/bin/id','-u']).stdout.strip()!='0': raise RuntimeError('Full sudo access could not be verified')
directory=HOME/'.ssh'
try: directory.mkdir(mode=0o750)
except FileExistsError: pass
fd=os.open(directory,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
try:
    s=os.fstat(fd)
    if s.st_uid not in (0,account.pw_uid): raise SystemExit('Unsafe .ssh ownership')
    os.fchmod(fd,0o750);os.fchown(fd,0,account.pw_gid)
finally: os.close(fd)
options='restrict'
if RISK=='readonly':
    secure_dir(ROOT/'inspectors',0o755)
    inspector=ROOT/'inspectors'/(IDENTITY+'.py')
    atomic(inspector,CONFIG['inspector'],0o755)
    options+=',command="/usr/bin/python3 -I '+str(inspector)+'"'
line=options+' '+CONFIG['public_key']+' aicontrol-system-'+str(CONFIG['system_id'])
target=directory/'authorized_keys'
try:
    key_fd=os.open(target,os.O_RDONLY|os.O_NOFOLLOW)
    with os.fdopen(key_fd) as f:
        s=os.fstat(f.fileno())
        if not stat.S_ISREG(s.st_mode) or s.st_nlink!=1 or s.st_uid not in (0,account.pw_uid): raise SystemExit('Unsafe authorized_keys file')
        existing=f.read(262145)
        if len(existing)>262144: raise SystemExit('authorized_keys is unexpectedly large')
except FileNotFoundError: existing=''
# Replace every entry for this key, including older non-isolated forced commands.
# Otherwise sshd can accept the first old entry and ignore the hardened one.
kind,blob=CONFIG['public_key'].split()[:2]
def same_key(entry):
    if entry.lstrip().startswith('#'): return False
    fields=shlex.shlex(entry,posix=True);fields.whitespace_split=True;fields.commenters=''
    try:
        first=next(fields,'')
        if first!=kind: first=next(fields,'')  # Skip an optional quoted options field.
        return first==kind and next(fields,'')==blob
    except ValueError: return False  # Invalid key lines are not authorizations.
lines=[entry for entry in existing.splitlines() if not same_key(entry)]
lines.append(line)
atomic(target,'\n'.join(lines)+'\n',0o640,0,account.pw_gid)
raw=base64.b64decode(CONFIG['public_key'].split()[1],validate=True)
emit('receipt',{'challenge':CONFIG['receipt_challenge'],'machine_tag':tag,'host_fingerprint':host_fingerprint,'authorized_key_sha256':'SHA256:'+base64.b64encode(hashlib.sha256(raw).digest()).decode().rstrip('='),'uid':account.pw_uid,'home_dir':str(HOME),'sudo_granted':RISK=='admin','read_only_enforced':RISK=='readonly'})
PY;
        // Nowdocs keep Python literal backslashes; use Python's newline escapes once.
        $body=$prefix.$common."\n".($stage==='discovery'?$discovery:$configure)."\n";
        return str_replace('\\\\n','\\n',$body);
    }
}
