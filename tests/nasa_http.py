"""First-day manual workflow and security boundaries against real PHP, without a provider key."""
import contextlib, http.cookiejar, json, os, pathlib, re, socket, subprocess, tempfile, time, unittest, urllib.error, urllib.parse, urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BINARY','php')
class NasaHttp(unittest.TestCase):
 @contextlib.contextmanager
 def workspace(self,mode='owner'):
  with tempfile.TemporaryDirectory(prefix='aicontrol-nasa-http-') as temporary:
   home=pathlib.Path(temporary)/'private';env={**os.environ,'AICONTROL_PRIVATE_DIR':str(home),'AICONTROL_LOCAL_DEV':'1','AICONTROL_PIN_VERIFIED':'1' if mode=='owner' else '','AICONTROL_PUBLIC_TESTING':'1' if mode=='testing' else '','AICONTROL_OPENAI_API_KEY':''}
   access='password' if mode=='password' else 'local';setup=subprocess.run([PHP,'bin/setup.php','--access-mode='+access,'--private-dir='+str(home),'--public-root='+str(ROOT)],input='disposable-first-day-password',cwd=ROOT,env=env,capture_output=True,text=True);self.assertEqual(setup.returncode,0,setup.stderr)
   seed=subprocess.run([PHP,'-r',"require 'src/PanelStore.php';require 'src/Vault.php';require 'src/AgentRunner.php';require 'src/Research.php';$p=new AIcontrol\\PanelStore(getenv('AICONTROL_PRIVATE_DIR').'/panel.sqlite');$p->createProject('First-day project','A clear operating scenario.');$p->createProject('Second project','PRIVATE-OTHER-CONTEXT');$r=new AIcontrol\\Research(getenv('AICONTROL_PRIVATE_DIR'));$r->create('Research source','Keep model ideas provisional.');(new AIcontrol\\Vault(getenv('AICONTROL_PRIVATE_DIR')))->saveSystemKey(7,'PRIVATE-SSH-CANARY');"],cwd=ROOT,env=env,capture_output=True,text=True);self.assertEqual(seed.returncode,0,seed.stderr)
   with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
   server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','bin/dev-router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);url=f'http://127.0.0.1:{port}/loader.php';client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
   def request(fields=None,query='?view=nasa&source=project:1'):
    try:
     with client.open(url+query,data=urllib.parse.urlencode(fields).encode() if fields is not None else None,timeout=10) as r:return r.status,r.headers,r.read().decode()
    except urllib.error.HTTPError as e:return e.code,e.headers,e.read().decode()
   try:
    for _ in range(80):
     try:code,_,page=request(query='?view=projects');break
     except OSError:time.sleep(.05)
    self.assertEqual(code,200);csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',page).group(1);yield home,csrf,request
   finally:server.terminate();server.wait(timeout=5)
 def test_manual_first_day_without_openai(self):
  with self.workspace() as (home,csrf,request):
   protected={name:(home/name).read_bytes() for name in ['vault.key','vault.json','config.json']}
   code,_,page=request();self.assertEqual(code,200);self.assertIn('Start managing this project',page)
   self.assertEqual(request(dict(csrf='wrong',action='nasa_start',source_kind='project',source_id='1'))[0],403)
   self.assertEqual(request(dict(csrf=csrf,action='nasa_start',source_kind='project',source_id='1'))[0],200)
   def action(name,**fields):return request(dict(csrf=csrf,action=name,nasa_project='1',**fields))
   self.assertEqual(action('nasa_template',artifact='conops')[0],200)
   code,_,page=action('nasa_save_artifact',artifact='conops',version='1',status='reviewed',content='Owner text </textarea><script>window.BAD=true</script>');self.assertEqual(code,200);self.assertNotIn('Owner text </textarea><script>',page);self.assertIn('&lt;script&gt;',page)
   self.assertEqual(action('nasa_save_artifact',artifact='conops',version='1',status='draft',content='Lost update')[0],422)
   self.assertEqual(action('nasa_save_record',kind='requirement',item_key='',version='0',title='Reject expired sessions',content='The system shall reject an expired session.',status='verified',source='ConOps',method='test',evidence='')[0],422)
   self.assertEqual(action('nasa_save_record',kind='requirement',item_key='',version='0',title='Reject expired sessions',content='The system shall reject an expired session.',status='unverified',source='ConOps',method='test',evidence='')[0],200)
   self.assertEqual(action('nasa_archive_record',kind='requirement',item_key='REQ-001',version='1')[0],200)
   self.assertEqual(action('nasa_restore_record',kind='requirement',item_key='REQ-001',version='2')[0],200)
   self.assertEqual(action('nasa_ai_review')[0],422)
   code,headers,text=action('nasa_export',format='json');self.assertEqual(code,200);self.assertIn('attachment',headers['Content-Disposition']);bundle=json.loads(text);self.assertEqual(bundle['format'],'AIcontrol NASA Managed v1');self.assertNotIn('PRIVATE-SSH-CANARY',text);self.assertNotIn('PRIVATE-OTHER-CONTEXT',text);self.assertFalse(bundle['workspace']['ai_results'])
   code,headers,text=action('nasa_export',format='markdown');self.assertEqual(code,200);self.assertIn('text/markdown',headers['Content-Type']);self.assertIn('Owner text',text)
   self.assertEqual(request(dict(csrf=csrf,action='nasa_export',nasa_project='1 OR 1=1',format='json'))[0],422)
   self.assertEqual(action('nasa_baseline',title='First-day baseline')[0],200)
   for name,value in protected.items():self.assertEqual((home/name).read_bytes(),value)
 def test_public_testing_cannot_read_or_write_engineering_state(self):
  with self.workspace('testing') as (home,csrf,request):
   self.assertEqual(request()[0],403)
   self.assertEqual(request(dict(csrf=csrf,action='nasa_start',source_kind='project',source_id='1'),query='?view=projects')[0],403)
 def test_password_session_cannot_bypass_signin(self):
  with self.workspace('password') as (home,csrf,request):
   code,_,page=request();self.assertEqual(code,200);self.assertIn('Sign in',page);self.assertNotIn('Start managing this project',page)
   self.assertEqual(request(dict(csrf=csrf,action='nasa_start',source_kind='project',source_id='1'))[0],401)
 def test_empty_workspace_has_a_useful_first_step(self):
  with self.workspace() as (home,csrf,request):
   code,_,_=request(dict(csrf=csrf,action='admin_data',operation='reset_workspace',record_id='0',confirmation='RESET ALL PROJECT DATA'),query='?view=admin');self.assertEqual(code,200)
   code,_,page=request();self.assertEqual(code,200);self.assertIn('Create task project',page);self.assertIn('Create research project',page);self.assertIn('A handbook you can use without reading the book',page);self.assertNotIn('Start managing this project',page)
if __name__=='__main__':unittest.main()
