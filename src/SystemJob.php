<?php
declare(strict_types=1);
namespace AIcontrol;
final class SystemJob
{
 public static function source():string{return <<<'PY'
import os,sys,json,base64,pathlib,subprocess,time,fcntl,stat,shutil,hashlib,pwd,ipaddress,re,tempfile,tarfile,urllib.request
# CONFIG is embedded by the trusted application, never accepted from remote output.
MODE=sys.argv[1] if len(sys.argv)>1 else CONFIG['mode']
USER_HOME=pathlib.Path(pwd.getpwuid(os.getuid()).pw_dir)
BASE=pathlib.Path('/var/lib/aicontrol/operations') if os.geteuid()==0 else USER_HOME/'.local/state/aicontrol/operations'
TOKEN=CONFIG['token']; JOB=BASE/TOKEN
if not re.fullmatch('[a-f0-9]{32}',TOKEN): raise SystemExit('Invalid operation identity')
os.umask(0o077)
def secure_dir(p):
 if not p.exists():
  if not p.parent.exists(): secure_dir(p.parent)
  p.mkdir(mode=0o700)
 s=p.lstat()
 if not stat.S_ISDIR(s.st_mode) or s.st_uid!=os.geteuid() or s.st_mode&0o022: raise RuntimeError('Unsafe operation directory')
def atomic(p,value):
 raw=json.dumps(value).encode() if not isinstance(value,bytes) else value
 fd,n=tempfile.mkstemp(prefix='.pending-',dir=p.parent)
 try:
  with os.fdopen(fd,'wb') as f:f.write(raw);f.flush();os.fsync(f.fileno());os.fchmod(f.fileno(),0o600)
  os.replace(n,p)
 finally:
  if os.path.exists(n):os.unlink(n)
def read(p):
 fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW)
 with os.fdopen(fd) as f:
  s=os.fstat(f.fileno())
  if not stat.S_ISREG(s.st_mode) or s.st_uid!=os.geteuid() or s.st_mode&0o077 or s.st_size>65536:raise RuntimeError('Unsafe operation record')
  return json.load(f)
def call(argv,limit=12,check=False):
 r=subprocess.run(argv,capture_output=True,text=True,timeout=limit,env={**os.environ,'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LC_ALL':'C','DEBIAN_FRONTEND':'noninteractive','NEEDRESTART_MODE':'l'})
 if check and r.returncode:raise RuntimeError('Command failed: '+argv[0])
 return r

def inventory():
 release={}
 for line in pathlib.Path('/etc/os-release').read_text().splitlines():
  if '=' in line:
   k,v=line.split('=',1);release[k]=v.strip('"')
 ports=[]
 try:
  for line in call(['ss','-H','-lntu']).stdout.splitlines():
   parts=line.split()
   if len(parts)<5:continue
   address,port=parts[4].rsplit(':',1);address=address.strip('[]')
   if address in ('::1','localhost') or address.startswith('127.'):continue
   if parts[0] in ('tcp','udp') and port.isdigit():ports.append({'protocol':parts[0],'port':int(port)})
 except (FileNotFoundError,ValueError):pass
 conn=os.environ.get('AICONTROL_SSH_CONNECTION',os.environ.get('SSH_CONNECTION','')).split()
 ssh_port=int(conn[3]) if len(conn)==4 and conn[3].isdigit() else None
 ufw=call(['ufw','status']).stdout if shutil.which('ufw') else 'not installed'
 existing='Status: active' in ufw
 if shutil.which('ufw'):existing=existing or bool(re.search(r'^ufw ',call(['ufw','show','added']).stdout,re.M))
 for command in (['nft','list','ruleset'],['iptables-save'],['ip6tables-save']):
  if shutil.which(command[0]):
   r=call(command)
   if r.returncode==0 and (('table ' in r.stdout) or re.search(r'^-A ',r.stdout,re.M)):existing=True
 failed=None
 if shutil.which('systemctl'):
  try:
   r=call(['systemctl','--no-pager','--plain','--no-legend','--failed','--type=service'],2)
   if r.returncode==0:failed=len([line for line in r.stdout.splitlines() if line.strip()])
  except (OSError,subprocess.TimeoutExpired):pass
 mem=re.search(r'^MemTotal:\s+(\d+)',pathlib.Path('/proc/meminfo').read_text(),re.M)
 return {'format':'AIcontrol VPS inventory v1','os':release.get('PRETTY_NAME','Linux'),'distribution':release.get('ID',''),'version':release.get('VERSION_ID',''),'architecture':os.uname().machine,'kernel':os.uname().release,'cpu_count':os.cpu_count(),'load_average':list(os.getloadavg()),'failed_service_count':failed,'memory_kib':int(mem[1]) if mem else None,'disk_free_bytes':shutil.disk_usage('/').free,'ssh_port':ssh_port,'ports':list({(p['protocol'],p['port']):p for p in ports}.values()),'existing_firewall':existing,'ufw':ufw[:4000],'root':os.geteuid()==0,'apt_available':bool(shutil.which('apt-get')),'systemd':pathlib.Path('/proc/1/comm').read_text().strip()=='systemd','reboot_required':pathlib.Path('/var/run/reboot-required').exists(),'wireguard_configured':pathlib.Path('/etc/wireguard/aicontrol0.conf').exists(),'backup_tools':[name for name in ('restic','borg','rsnapshot') if shutil.which(name)],'backup_file_count':len(list(pathlib.Path('/var/backups').iterdir())) if os.access('/var/backups',os.R_OK) else None,'observed_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())}

def result(status,summary,**extra):
 if status=='complete' and MODE=='worker':
  try:extra['inventory']=inventory()
  except Exception:extra['inventory_error']='Final inventory unavailable; run Inspect VPS to refresh.'
 v={'format':'AIcontrol VPS job v1','token':TOKEN,'recipe':CONFIG['recipe'],'status':status,'summary':summary,'updated_at':int(time.time()),**extra};atomic(JOB/'result.json',v);return v

def worker():
 steps=[];started=time.monotonic();rollback_armed=False
 def run(argv):
  if (JOB/'stop').exists():raise InterruptedError('Stopped before the next command; completed changes remain.')
  seconds=max(1,min(15 if argv[0]=='ufw' else 900,int(1800-(time.monotonic()-started))))
  try:r=call(argv,seconds)
  except subprocess.TimeoutExpired:raise TimeoutError('A command exceeded its bound; inspect package/service state before any retry.')
  steps.append({'command':argv,'exit_code':r.returncode,'stdout':r.stdout[-600:],'stderr':r.stderr[-400:],'truncated':len(r.stdout)>600 or len(r.stderr)>400})
  result('running','A reviewed operation is running.',steps=steps)
  if r.returncode:raise RuntimeError('A reviewed command failed; no retry was attempted.')
  return r
 def checkpoint():
  if (JOB/'stop').exists():raise InterruptedError('Stopped before the next change; completed changes remain.')
 def apt(*args):return run(['apt-get','-y','--no-remove','-o','DPkg::Lock::Timeout=30','-o','Dpkg::Options::=--force-confdef','-o','Dpkg::Options::=--force-confold',*args])
 def require_apt():
  v=inventory()
  if os.geteuid()!=0 or v['distribution'] not in ('ubuntu','debian') or not v['apt_available']:raise RuntimeError('This operation requires Administrator access on Debian or Ubuntu.')
  if (JOB/'stop').exists():raise InterruptedError('Stopped before changes.')
  return v
 try:
  recipe=CONFIG['recipe'];p=CONFIG.get('parameters',{})
  if recipe=='update':
   require_apt();apt('update');apt('upgrade');audit=run(['dpkg','--audit']);
   if audit.stdout.strip():raise RuntimeError('Package database needs manual review after upgrade.')
   result('complete','Package update completed. No automatic reboot or package removal.',steps=steps,reboot_required=pathlib.Path('/var/run/reboot-required').exists())
  elif recipe=='security_updates':
   require_apt();apt('update');apt('install','unattended-upgrades')
   checkpoint();target=pathlib.Path('/etc/apt/apt.conf.d/99aicontrol-periodic')
   if target.exists():raise RuntimeError('An AIcontrol automatic-update policy already exists; inspect it before replacing it.')
   atomic(target,b'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\nUnattended-Upgrade::Automatic-Reboot "false";\n');run(['systemctl','enable','--now','apt-daily.timer','apt-daily-upgrade.timer']);
   for timer in ('apt-daily.timer','apt-daily-upgrade.timer'):
    r=run(['systemctl','is-active',timer])
    if r.stdout.strip()!='active':raise RuntimeError('Automatic update schedule could not be verified')
   result('complete','Automatic distribution security updates and their timers enabled; automatic reboot disabled.',steps=steps)
  elif recipe=='firewall':
   v=require_apt()
   if v['existing_firewall'] or not v['ssh_port']:raise RuntimeError('Existing firewall rules or an unknown SSH port require manual review; no replacement was attempted.')
   expected={(x['protocol'],x['port']) for x in CONFIG['inventory']['ports']};now={(x['protocol'],x['port']) for x in v['ports']}
   if expected!=now or CONFIG['inventory']['ssh_port']!=v['ssh_port']:raise RuntimeError('Listening ports changed since review. Prepare a new plan.')
   apt('install','ufw');checkpoint();backup=JOB/'ufw-before';shutil.copytree('/etc/ufw',backup,symlinks=True)
   deadline=int(time.time())+120;atomic(JOB/'rollback-deadline.json',{'deadline':deadline});rollback=subprocess.Popen([sys.executable,'-I',str(JOB/'job.py'),'rollback_wait',sys.argv[2]],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True,pass_fds=(int(sys.argv[2]),));rollback_armed=True
   default=pathlib.Path('/etc/default/ufw');atomic(JOB/'default-ufw-before',default.read_bytes());text=default.read_text();text=re.sub(r'^IPV6=.*$', 'IPV6=yes',text,flags=re.M);atomic(default,text.encode());os.chmod(default,0o644)
   run(['ufw','default','deny','incoming']);run(['ufw','default','allow','outgoing'])
   allow={(x['protocol'],int(x['port'])) for x in p['allow_ports']};allow.add(('tcp',v['ssh_port']))
   for proto,port in sorted(allow):run(['ufw','allow',str(port)+'/'+proto])
   run(['ufw','--force','enable']);result('awaiting_confirmation','Firewall enabled provisionally. A fresh pinned SSH check must commit within two minutes, otherwise rollback disables it.',steps=steps,rollback_deadline=deadline)
  elif recipe=='vpn':
   v=require_apt()
   if v['wireguard_configured'] or pathlib.Path('/sys/class/net/aicontrol0').exists():raise RuntimeError('An AIcontrol VPN already exists. It will not be overwritten.')
   network=ipaddress.ip_network(p['network'],strict=True)
   if network.version!=4 or network.prefixlen!=24 or not network.is_private:raise RuntimeError('Use a private IPv4 /24 VPN subnet.')
   routes=json.loads(call(['ip','-j','-4','route']).stdout)
   for route in routes:
    dest=route.get('dst','default')
    if dest!='default' and network.overlaps(ipaddress.ip_network(dest,strict=False)):raise RuntimeError('VPN subnet overlaps an existing route.')
   raw=base64.b64decode(p['peer_public_key'],validate=True)
   if len(raw)!=32:raise RuntimeError('Invalid client public key')
   port=int(p['listen_port'])
   if port<1024 or port>65535 or any(x['port']==port for x in v['ports']):raise RuntimeError('VPN port is already in use or invalid.')
   apt('update');apt('install','wireguard');checkpoint();key=call(['wg','genkey'],check=True).stdout.strip()
   interface=ipaddress.ip_address(int(network.network_address)+1);peer=ipaddress.ip_address(int(network.network_address)+2)
   cfg=f'[Interface]\nAddress = {interface}/24\nListenPort = {port}\nPrivateKey = {key}\n\n[Peer]\nPublicKey = {p["peer_public_key"]}\nAllowedIPs = {peer}/32\n'
   directory=pathlib.Path('/etc/wireguard');secure_dir(directory);atomic(directory/'aicontrol0.conf',cfg.encode());
   if p.get('allow_ufw') and 'Status: active' in v.get('ufw',''):run(['ufw','allow',str(port)+'/udp'])
   run(['systemctl','enable','--now','wg-quick@aicontrol0']);run(['wg','show','aicontrol0','public-key']);server_public=steps[-1]['stdout'].strip()
   result('complete','Private-access WireGuard interface configured. Client handshake and external UDP reachability remain unverified; no default route was changed.',steps=steps,server_public_key=server_public,client_address=str(peer)+'/32',server_address=str(interface),listen_port=port,handshake_verified=False)
  elif recipe=='codex':
   arch=os.uname().machine;asset=p['asset'];name='codex-'+arch+'-unknown-linux-musl'
   if arch=='arm64':arch='aarch64';name='codex-'+arch+'-unknown-linux-musl'
   allowed='https://github.com/openai/codex/releases/download/rust-v0.171.0/'+name+'.tar.gz'
   if asset['url']!=allowed or not re.fullmatch('[a-f0-9]{64}',asset['sha256']):raise RuntimeError('Untrusted Codex release selection')
   # Always install as the selected SSH account, never run Codex as root.
   account=pwd.getpwnam(p['username']);home=pathlib.Path(account.pw_dir)
   if os.geteuid()==0 or account.pw_uid!=os.geteuid():raise RuntimeError('Codex must install as the dedicated non-root SSH account.')
   destination=home/'.local/bin/codex';secure_dir(destination.parent)
   if destination.exists() or destination.is_symlink():raise RuntimeError('Codex destination already exists; no overwrite was attempted.')
   checkpoint();archive=JOB/'codex.tar.gz';digest=hashlib.sha256();total=0
   with urllib.request.urlopen(urllib.request.Request(asset['url'],headers={'User-Agent':'AIcontrol-VPS-setup'}),timeout=20) as response,open(archive,'xb') as f:
    if not response.geturl().startswith('https://'):raise RuntimeError('Unsafe download redirect')
    while True:
     chunk=response.read(131072)
     if not chunk:break
     total+=len(chunk)
     if total>268435456 or time.monotonic()-started>600:raise RuntimeError('Codex download exceeded its size/time bound')
     digest.update(chunk);f.write(chunk)
   if digest.hexdigest()!=asset['sha256']:raise RuntimeError('Codex archive checksum did not match the reviewed official release')
   with tarfile.open(archive,'r:gz') as tar:
    members=tar.getmembers()
    if len(members)!=1 or members[0].name!=name or not members[0].isfile() or members[0].size>536870912:raise RuntimeError('Unexpected Codex archive layout')
    source=tar.extractfile(members[0]);binary=JOB/'codex';fd=os.open(binary,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o700)
    with os.fdopen(fd,'wb') as f:shutil.copyfileobj(source,f)
   checkpoint();version=run([str(binary),'--version']);
   if not re.search(r'\b0\.171\.0\b',version.stdout):raise RuntimeError('Codex version did not match the reviewed release')
   os.chmod(binary,0o755);os.rename(binary,destination);archive.unlink();result('complete','Codex 0.171.0 installed in the SSH account’s .local/bin. Codex authentication is still required; no API credential was installed.',steps=steps,authenticated=False)
  else:raise RuntimeError('Unknown operation')
 except InterruptedError as e:result('running' if rollback_armed else 'stopped',str(e),steps=steps,rollback_pending=rollback_armed)
 except (TimeoutError,subprocess.TimeoutExpired) as e:result('uncertain','Operation exceeded its bound. Inspect the server before any retry.',steps=steps)
 except Exception as e:result('running' if rollback_armed else 'failed',str(e)[:400],steps=steps,rollback_pending=rollback_armed)

def emit(state):
 state=json.loads(json.dumps(state))
 for limit in (100,40,0):
  for step in state.get('steps',[]):
   for field in ('stdout','stderr'):
    value=step.get(field,'')
    if len(value)>limit:step[field]=value[-limit:] if limit else '';step['truncated']=True
  text=json.dumps(state)
  if len(text.encode())<=14000:print(text);return
 raise RuntimeError('Result metadata exceeds the bounded response')

if MODE=='inventory':print(json.dumps(inventory()))
elif MODE=='launch':
 secure_dir(BASE);fd=os.open(BASE/'active.lock',os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
 if os.fstat(fd).st_uid!=os.geteuid():raise RuntimeError('Unsafe operation lock')
 fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
 JOB.mkdir(mode=0o700) # Existing token is never re-dispatched.
 source=base64.b64decode(CONFIG['program']);atomic(JOB/'job.py',source);result('running','Reviewed operation accepted; awaiting a confirmed result.')
 process=subprocess.Popen([sys.executable,'-I',str(JOB/'job.py'),'worker',str(fd)],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True,pass_fds=(fd,));atomic(JOB/'worker.json',{'pid':process.pid});emit(read(JOB/'result.json'))
elif MODE=='worker':worker()
elif MODE=='rollback_wait':
 deadline=read(JOB/'rollback-deadline.json')['deadline']
 while time.time()<deadline:
  if (JOB/'commit').exists():raise SystemExit(0)
  state=read(JOB/'result.json')
  if (JOB/'stop').exists() and (state.get('rollback_pending') or state['status']=='awaiting_confirmation'):break
  time.sleep(1)
 call(['ufw','--force','disable'])
 if (JOB/'ufw-before').is_dir():
  shutil.rmtree('/etc/ufw');shutil.copytree(JOB/'ufw-before','/etc/ufw',symlinks=True)
 if (JOB/'default-ufw-before').exists():atomic(pathlib.Path('/etc/default/ufw'),(JOB/'default-ufw-before').read_bytes());os.chmod('/etc/default/ufw',0o644)
 result('rolled_back','SSH confirmation was not committed; the prior inactive firewall configuration was restored.')
elif MODE in ('status','stop','commit'):
 secure_dir(BASE)
 if JOB.is_symlink() or not JOB.is_dir():raise RuntimeError('Operation state is unavailable; no launch retry is permitted')
 state=read(JOB/'result.json')
 if state['status']=='running':
  try:
   pid=read(JOB/'worker.json')['pid'];alive=str(JOB/'job.py').encode() in pathlib.Path('/proc/'+str(pid)+'/cmdline').read_bytes()
  except (OSError,KeyError,ValueError):alive=False
  if not alive or time.time()-state.get('updated_at',0)>2100:state=result('uncertain','The bounded worker is no longer confirmed. Inspect the server before another action.',steps=state.get('steps',[]))
 if state['status']=='awaiting_confirmation' and (JOB/'commit').exists():state=result('complete','Firewall commit was retained after a verified SSH connection.',steps=state.get('steps',[]))
 if state['status']=='awaiting_confirmation' and time.time()>state.get('rollback_deadline',0)+20:state=result('uncertain','Firewall rollback status is unverified. Inspect recovery access before another action.',steps=state.get('steps',[]))
 if MODE=='stop':atomic(JOB/'stop',b'pause after current command')
 if MODE=='commit':
  if state['status']!='awaiting_confirmation' or state.get('rollback_deadline',0)<=time.time():raise RuntimeError('Firewall confirmation expired')
  if (JOB/'stop').exists():raise RuntimeError('Firewall stop was requested; rollback remains armed')
  atomic(JOB/'commit',b'fresh pinned SSH verified');state=result('complete','Inbound firewall committed after a fresh pinned SSH connection succeeded.',steps=state.get('steps',[]))
 emit(state)

PY;
 }
 public static function program(array $config):string{return 'import base64,json'."\n".'CONFIG=json.loads(base64.b64decode("'.base64_encode(json_encode($config,JSON_THROW_ON_ERROR)).'"))'."\n".self::source();}
}
