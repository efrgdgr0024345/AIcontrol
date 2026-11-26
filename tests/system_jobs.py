"""Execute real recipe logic against a temporary filesystem and simulated Linux tools."""
import base64,io,json,os,pathlib,subprocess,tarfile,tempfile,types,unittest,hashlib
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BINARY','php')
class JobRecipes(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.source=subprocess.check_output([PHP,'-r',"require 'src/SystemJob.php';echo AIcontrol\\SystemJob::source();"],cwd=ROOT,text=True)
 def fixture(self,recipe,params=None):
  temp=tempfile.TemporaryDirectory(prefix='aicontrol-job-test-');self.addCleanup(temp.cleanup);root=pathlib.Path(temp.name);job=root/'job';job.mkdir();fake=root/'root';(fake/'etc/ufw').mkdir(parents=True);(fake/'etc/default').mkdir();(fake/'etc/default/ufw').write_text('IPV6=no\n');(fake/'etc/ufw/user.rules').write_text('# Original inactive rules\n')
  config={'mode':'inventory','token':'a'*32,'recipe':recipe,'parameters':params or {},'inventory':{'ssh_port':2222,'ports':[{'protocol':'tcp','port':2222},{'protocol':'tcp','port':80}]}}
  ns={'CONFIG':config};exec(self.source.split("\nif MODE=='inventory':")[0],ns);ns['JOB']=job;ns['BASE']=root/'operations';events=[];snapshots=[]
  def mapped(p,*args):
   p=pathlib.Path(p,*args)
   return fake/str(p).lstrip('/') if str(p).startswith(('/etc/','/var/','/sys/')) else p
  ns['pathlib']=types.SimpleNamespace(Path=mapped)
  ns['os']=types.SimpleNamespace(**{k:getattr(os,k) for k in dir(os)});ns['os'].geteuid=lambda:0
  ns['sys']=types.SimpleNamespace(executable=__import__('sys').executable,argv=['job.py','worker','123'])
  ns['secure_dir']=lambda p:p.mkdir(mode=0o700,parents=True,exist_ok=True)
  import shutil
  ns['shutil']=types.SimpleNamespace(**{k:getattr(shutil,k) for k in dir(shutil)})
  ns['shutil'].copytree=lambda a,b,**kw:shutil.copytree(mapped(a),mapped(b),**kw)
  ns['subprocess']=types.SimpleNamespace(**{k:getattr(subprocess,k) for k in dir(subprocess)})
  ns['subprocess'].Popen=lambda *a,**kw:events.append(['WATCHDOG']) or types.SimpleNamespace(pid=123)
  inv={'root':True,'distribution':'ubuntu','apt_available':True,'existing_firewall':False,'ssh_port':2222,'ports':config['inventory']['ports'],'wireguard_configured':False};ns['inventory']=lambda:dict(inv)
  def call(argv,limit=12,check=False):
   events.append(argv);text=''
   if argv[:2]==['ip','-j']:text='[]'
   if argv==['wg','genkey']:text='PRIVATE_KEY_MUST_NOT_LEAK'
   if argv==['wg','show','aicontrol0','public-key']:text=base64.b64encode(b'p'*32).decode()
   return types.SimpleNamespace(returncode=0,stdout=text,stderr='')
  ns['call']=call;original=ns['result']
  def result(*a,**kw):
   value=original(*a,**kw);snapshots.append(json.dumps(value));return value
  ns['result']=result
  return ns,root,fake,events,snapshots,inv
 def final(self,ns):return json.loads((ns['JOB']/'result.json').read_text())
 def test_firewall_preserves_actual_ssh_and_arms_rollback_before_enable(self):
  ns,root,fake,events,snapshots,inv=self.fixture('firewall',{'allow_ports':[{'protocol':'tcp','port':80}]});ns['worker']();v=self.final(ns)
  self.assertEqual(v['status'],'awaiting_confirmation');self.assertLess(events.index(['WATCHDOG']),events.index(['ufw','--force','enable']));self.assertLess(events.index(['ufw','allow','2222/tcp']),events.index(['ufw','--force','enable']));self.assertIn('IPV6=yes',(fake/'etc/default/ufw').read_text());self.assertEqual((ns['JOB']/'ufw-before/user.rules').read_text(),'# Original inactive rules\n');self.assertNotIn('sshd_config',str(events))
 def test_existing_rules_and_changed_ports_fail_before_changes(self):
  for change in ('existing','ssh'):
   ns,root,fake,events,snapshots,inv=self.fixture('firewall',{'allow_ports':[]})
   if change=='existing':inv['existing_firewall']=True
   else:inv['ssh_port']=2200
   ns['worker']();self.assertEqual(self.final(ns)['status'],'failed');self.assertEqual(events,[])
 def test_stop_during_firewall_keeps_lock_until_watchdog_rollback(self):
  ns,root,fake,events,snapshots,inv=self.fixture('firewall',{'allow_ports':[]});call=ns['call']
  def stop(argv,*a,**kw):
   r=call(argv,*a,**kw)
   if argv==['ufw','default','deny','incoming']:(ns['JOB']/'stop').write_text('pause')
   return r
  ns['call']=stop;ns['worker']();v=self.final(ns);self.assertEqual(v['status'],'running');self.assertTrue(v['rollback_pending']);self.assertNotIn(['ufw','--force','enable'],events)
 def test_update_preserves_configs_no_removal_no_reboot(self):
  ns,root,fake,events,snapshots,inv=self.fixture('update');ns['worker']();self.assertEqual(self.final(ns)['status'],'complete');self.assertIn('upgrade',str(events));self.assertIn('--no-remove',str(events));self.assertIn('--force-confold',str(events));self.assertFalse(any('reboot' in a or 'autoremove' in a or 'full-upgrade' in a for a in events))
 def test_update_timeout_is_uncertain_and_never_retries(self):
  ns,root,fake,events,snapshots,inv=self.fixture('update');call=ns['call']
  def timeout(argv,*a,**kw):
   if argv[-1]=='upgrade':events.append(argv);raise subprocess.TimeoutExpired(argv,1)
   return call(argv,*a,**kw)
  ns['call']=timeout;ns['worker']();self.assertEqual(self.final(ns)['status'],'uncertain');self.assertEqual(sum(a[-1]=='upgrade' for a in events),1);self.assertNotIn(['dpkg','--audit'],events)
 def test_security_updates_require_active_timers_and_disable_reboot(self):
  for active in (True,False):
   ns,root,fake,events,snapshots,inv=self.fixture('security_updates');call=ns['call'];(fake/'etc/apt/apt.conf.d').mkdir(parents=True)
   def timer(argv,*a,**kw):
    r=call(argv,*a,**kw)
    if argv[:2]==['systemctl','is-active']:r.stdout='active' if active else 'inactive'
    return r
   ns['call']=timer;ns['worker']();self.assertEqual(self.final(ns)['status'],'complete' if active else 'failed');policy=(fake/'etc/apt/apt.conf.d/99aicontrol-periodic').read_text();self.assertIn('Automatic-Reboot "false"',policy);self.assertIn(['systemctl','enable','--now','apt-daily.timer','apt-daily-upgrade.timer'],events)
 def test_wireguard_private_key_never_enters_returned_evidence(self):
  ns,root,fake,events,snapshots,inv=self.fixture('vpn',{'network':'10.78.0.0/24','listen_port':51820,'peer_public_key':base64.b64encode(b'c'*32).decode()});ns['worker']();v=self.final(ns);self.assertEqual(v['status'],'complete');self.assertFalse(v['handshake_verified']);self.assertNotIn('PRIVATE_KEY_MUST_NOT_LEAK',''.join(snapshots));cfg=fake/'etc/wireguard/aicontrol0.conf';self.assertIn('PRIVATE_KEY_MUST_NOT_LEAK',cfg.read_text());self.assertEqual(cfg.stat().st_mode&0o777,0o600);self.assertNotIn('PostUp',cfg.read_text());self.assertNotIn('ip_forward',str(events))
 def test_wireguard_overlap_fails_before_install(self):
  ns,root,fake,events,snapshots,inv=self.fixture('vpn',{'network':'10.78.0.0/24','listen_port':51820,'peer_public_key':base64.b64encode(b'c'*32).decode()});call=ns['call']
  def route(argv,*a,**kw):
   if argv[:2]==['ip','-j']:return types.SimpleNamespace(stdout='[{"dst":"10.78.0.0/16"}]',returncode=0,stderr='')
   return call(argv,*a,**kw)
  ns['call']=route;ns['worker']();self.assertEqual(self.final(ns)['status'],'failed');self.assertFalse(any(a[0]=='apt-get' for a in events))
 def test_setup_check_distinguishes_presence_from_verified_service(self):
  ns,root,fake,events,_,inv=self.fixture('inspect')
  ns['CONFIG']['username']='fixture'
  ns['pwd']=types.SimpleNamespace(getpwnam=lambda name:types.SimpleNamespace(pw_dir=str(root/'account')))
  binary=root/'account/.local/bin/codex';binary.parent.mkdir(parents=True);binary.touch();binary.chmod(0o755)
  policy=fake/'etc/apt/apt.conf.d/99aicontrol-periodic';policy.parent.mkdir(parents=True);policy.write_bytes(ns['UPDATE_SETTINGS'])
  def probe(argv,*a,**kw):
   events.append(argv)
   if argv[0]=='ip':return types.SimpleNamespace(returncode=0,stdout='[{"linkinfo":{"info_kind":"wireguard"},"flags":["UP"]}]',stderr='')
   return types.SimpleNamespace(returncode=0,stdout='active\nactive\n' if argv[1]=='is-active' else 'enabled\nenabled\n',stderr='')
  ns['call']=probe;ns['setup_checks'](inv)
  self.assertEqual(inv['setup_checks'],{'vpn':'active','firewall':'unknown','codex':'installed','security_updates':'active'})
  self.assertEqual([step['command'] for step in inv['setup_steps']],events)
  self.assertTrue(all(argv[0] in ('ip','systemctl') for argv in events))
  policy.write_text('local settings');binary.chmod(0o600)
  ns['call']=lambda *a,**kw:types.SimpleNamespace(returncode=1,stdout='',stderr='Device "aicontrol0" does not exist.')
  ns['setup_checks'](inv)
  self.assertEqual(inv['setup_checks'],{'vpn':'absent','firewall':'unknown','codex':'configured','security_updates':'configured'})
 def codex_fixture(self,unsafe=False,bad_digest=False):
  name='codex-x86_64-unknown-linux-musl';out=io.BytesIO()
  with tarfile.open(fileobj=out,mode='w:gz') as tar:
   info=tarfile.TarInfo('../escape' if unsafe else name);raw=b'synthetic binary';info.size=len(raw);tar.addfile(info,io.BytesIO(raw))
  data=out.getvalue();params={'username':'fixture','asset':{'url':'https://github.com/openai/codex/releases/download/rust-v0.171.0/'+name+'.tar.gz','sha256':'0'*64 if bad_digest else hashlib.sha256(data).hexdigest()}}
  ns,root,fake,events,snapshots,inv=self.fixture('codex',params);ns['os'].geteuid=lambda:1000;ns['os'].uname=lambda:types.SimpleNamespace(machine='x86_64');ns['pwd']=types.SimpleNamespace(getpwnam=lambda _:types.SimpleNamespace(pw_uid=1000,pw_dir=str(root/'home')))
  class Response(io.BytesIO):
   def geturl(self):return 'https://release-assets.githubusercontent.com/fixture'
  original_call=ns['call']
  def version(argv,*a,**kw):
   r=original_call(argv,*a,**kw)
   if argv[-1]=='--version':r.stdout='codex-cli 0.171.0'
   return r
  ns['call']=version
  ns['urllib']=types.SimpleNamespace(request=types.SimpleNamespace(Request=lambda *a,**kw:None,urlopen=lambda *a,**kw:Response(data)))
  return ns,root,events
 def test_codex_verified_archive_installs_per_account_without_auth(self):
  ns,root,events=self.codex_fixture();ns['worker']();v=self.final(ns);self.assertEqual(v['status'],'complete');self.assertFalse(v['authenticated']);self.assertEqual((root/'home/.local/bin/codex').read_bytes(),b'synthetic binary');self.assertNotIn('login',str(events));self.assertNotIn('sudo',str(events))
 def test_codex_bad_hash_and_traversal_never_install(self):
  for unsafe,bad in ((False,True),(True,False)):
   ns,root,events=self.codex_fixture(unsafe,bad);ns['worker']();self.assertEqual(self.final(ns)['status'],'failed');self.assertFalse((root/'home/.local/bin/codex').exists());self.assertFalse((root/'escape').exists())
if __name__=='__main__':unittest.main()
