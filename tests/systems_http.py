"""Systems requires owner access even when the legacy workspace uses public testing."""
import contextlib, http.cookiejar, os, pathlib, re, socket, subprocess, tempfile, time, unittest, urllib.error, urllib.parse, urllib.request
ROOT=pathlib.Path(__file__).resolve().parent.parent
class SystemsAccess(unittest.TestCase):
 @contextlib.contextmanager
 def credential_server(self,owner=False):
  with tempfile.TemporaryDirectory(prefix='aicontrol-credential-http-') as home:
   php=os.environ.get('PHP_BINARY','php');private=pathlib.Path(home)/'private';env={**os.environ,'AICONTROL_PRIVATE_DIR':str(private),'AICONTROL_LOCAL_DEV':'1','AICONTROL_PUBLIC_TESTING':'1','AICONTROL_PIN_VERIFIED':'1' if owner else ''}
   setup=subprocess.run([php,'bin/setup.php','--access-mode=local','--private-dir='+str(private),'--public-root='+str(ROOT)],cwd=ROOT,env=env,capture_output=True,text=True);self.assertEqual(setup.returncode,0,setup.stderr)
   seed=subprocess.run([php,'-r',"require 'src/Vault.php';$v=new AIcontrol\\Vault(getenv('AICONTROL_PRIVATE_DIR'));$v->saveSystemKey(7,'disposable-managed-ssh-key');$v->change('openai_api_key','key','disposable-provider-key',false);$v->change('owner_note','note','disposable-private-note',false);"],cwd=ROOT,env=env,capture_output=True,text=True);self.assertEqual(seed.returncode,0,seed.stderr)
   with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
   server=subprocess.Popen([php,'-S',f'127.0.0.1:{port}','bin/dev-router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
   client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));url=f'http://127.0.0.1:{port}/loader.php'
   def request(fields=None,query='?view=projects'):
    try:
     with client.open(url+query,data=urllib.parse.urlencode(fields).encode() if fields is not None else None,timeout=8) as reply:return reply.status,reply.read().decode()
    except urllib.error.HTTPError as error:return error.code,error.read().decode()
   try:
    for _ in range(80):
     try:code,page=request();break
     except OSError:time.sleep(.05)
    self.assertEqual(code,200);csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',page).group(1)
    yield private,csrf,request
   finally:server.terminate();server.wait(timeout=5)
 def test_separate_systems_password_masks_notes_and_blocks_pin_only_control(self):
  import sqlite3,json
  with self.credential_server(owner=True) as (private,csrf,request):
   code,page=request(query='?view=systems');self.assertEqual(code,200);self.assertIn('Set Systems password',page)
   fields=dict(csrf=csrf,action='system_add',name='Fixture VPS',host='192.0.2.22',port='22',username='fixture_login',access='inspect')
   code,body=request(fields,query='?view=systems&systems_api=1');self.assertEqual(code,422);self.assertIn('Unlock Systems',body)
   with sqlite3.connect(private/'systems.sqlite') as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM systems').fetchone()[0],0)
   password='synthetic separate systems password'
   code,page=request(dict(csrf=csrf,action='system_guard_setup',systems_password=password,systems_password_confirm=password),query='?view=systems');self.assertEqual(code,200);self.assertIn('Remote control unlocked',page)
   code,page=request(fields,query='?view=systems');self.assertEqual(code,200);self.assertIn('fixture_login',page)
   code,page=request(dict(csrf=csrf,action='system_notes_save',system_id='1',notes='Web service notes. username=fixture_login password=fixture-secret-value',secret_label='database_password',secret_value='fixture-secret-value'),query='?view=systems');self.assertEqual(code,200);self.assertIn('Web service notes.',page);self.assertNotIn('fixture-secret-value',page)
   with sqlite3.connect(private/'systems.sqlite') as db:
    saved_hash=db.execute('SELECT password_hash FROM system_guard').fetchone()[0];self.assertNotIn(password,saved_hash)
    self.assertNotIn('fixture-secret-value',str(db.execute('SELECT * FROM system_notebooks').fetchall()))
    db.execute("INSERT INTO system_runs(system_id,task,status,result,created_at)VALUES(1,'Private fixture task','complete','fixture-private-raw-output',0)")
   code,page=request(dict(csrf=csrf,action='system_notes_reveal',system_id='1'),query='?view=systems');self.assertEqual(code,200);self.assertIn('fixture-secret-value',page)
   code,body=request(dict(csrf=csrf,action='system_guard_lock',system_id='1',run_id='1'),query='?view=systems&systems_api=1');self.assertEqual(code,200);self.assertIsNone(json.loads(body)['run']);self.assertNotIn('fixture-private-raw-output',body)
   code,page=request(query='?view=systems&system=1&system_run=1');self.assertEqual(code,200);self.assertIn('Web service notes.',page);self.assertNotIn('fixture_login',page);self.assertNotIn('192.0.2.22',page);self.assertNotIn('fixture-private-raw-output',page);self.assertNotIn('fixture-secret-value',page)
   for action in ('system_notes_reveal','system_console_connect','system_start','system_playbook_prepare'):
    code,body=request(dict(csrf=csrf,action=action,system_id='1',task='Inspect',recipe='inspect'),query='?view=systems&systems_api=1');self.assertEqual(code,422);self.assertIn('Unlock Systems',body)
   code,body=request(dict(csrf=csrf,action='system_guard_setup',systems_password='replacement password',systems_password_confirm='replacement password'),query='?view=systems&systems_api=1');self.assertEqual(code,422)
   with sqlite3.connect(private/'systems.sqlite') as db:self.assertEqual(db.execute('SELECT password_hash FROM system_guard').fetchone()[0],saved_hash)
   code,page=request(dict(csrf=csrf,action='system_guard_unlock',systems_password=password),query='?view=systems');self.assertEqual(code,200);self.assertIn('Remote control unlocked',page)
 def test_public_testing_cannot_mutate_vault_or_use_admin(self):
  with self.credential_server() as (private,csrf,request):
   before=(private/'vault.json').read_bytes();key=(private/'vault.key').read_bytes()
   code,page=request(query='?view=admin');self.assertEqual(code,403);self.assertIn('owner-authenticated',page);self.assertNotIn('owner_note',page);self.assertNotIn('disposable-',page)
   for name in ['ssh_system_7','openai_api_key','owner_note']:
    for action in ['vault_save','vault_delete']:
     with self.subTest(name=name,action=action):
      code,body=request(dict(csrf=csrf,action=action,name=name,type='note',value='replacement',share='1'));self.assertEqual(code,403);self.assertIn('owner-authenticated',body)
   for action in ['admin_data','admin_project_json']:
    code,_=request(dict(csrf=csrf,action=action,operation='clear_diagnostics',record_id='0',confirmation='CLEAR DIAGNOSTICS',project_id='1',run_id='0',kind='bundle'),query='?view=research');self.assertEqual(code,403)
   self.assertEqual((private/'vault.json').read_bytes(),before);self.assertEqual((private/'vault.key').read_bytes(),key)
 def test_owner_keeps_normal_vault_control_but_managed_keys_require_systems(self):
  with self.credential_server(owner=True) as (private,csrf,request):
   code,page=request(query='?view=admin');self.assertEqual(code,200);self.assertIn('managed in Systems',page);self.assertNotIn('Delete ssh_system_7',page);self.assertNotIn('disposable-managed-ssh-key',page)
   before=(private/'vault.json').read_bytes()
   for action in ['vault_save','vault_delete']:
    code,body=request(dict(csrf=csrf,action=action,name='ssh_system_7',type='note',value='replacement',share='1'));self.assertEqual(code,422);self.assertIn('SSH credentials are managed in Systems.',body)
   self.assertEqual((private/'vault.json').read_bytes(),before)
   for action in ['vault_save','vault_delete']:
    code,page=request(dict(csrf=csrf,action=action,name='owner_note',type='note',value='owner replacement',share='0'));self.assertEqual(code,200);self.assertIn('Private vault updated.',page)
 def test_public_testing_cannot_read_or_enroll(self):
  with tempfile.TemporaryDirectory(prefix='aicontrol-systems-http-') as home:
   php=os.environ.get('PHP_BINARY','php');env={**os.environ,'AICONTROL_PRIVATE_DIR':home+'/private','AICONTROL_LOCAL_DEV':'1','AICONTROL_PUBLIC_TESTING':'1','AICONTROL_PIN_VERIFIED':''}
   setup=subprocess.run([php,'bin/setup.php','--access-mode=local','--private-dir='+home+'/private','--public-root='+str(ROOT)],cwd=ROOT,env=env,capture_output=True,text=True);self.assertEqual(setup.returncode,0,setup.stderr)
   with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
   server=subprocess.Popen([php,'-S',f'127.0.0.1:{port}','bin/dev-router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
   client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));url=f'http://127.0.0.1:{port}/loader.php'
   try:
    for attempt in range(50):
     try:html=client.open(url+'?view=projects',timeout=5).read().decode();break
     except OSError:time.sleep(.05)
    csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1)
    systems=client.open(url+'?view=systems&system=1',timeout=5).read().decode();self.assertIn('Systems control requires PIN',systems);self.assertNotIn('id="system-script"',systems)
    data=urllib.parse.urlencode({'csrf':csrf,'action':'system_add','name':'Unauthorized','host':'127.0.0.1','port':'22','username':'aicontrol','access':'maintain'}).encode()
    with self.assertRaises(urllib.error.HTTPError) as failed:client.open(url+'?view=systems&systems_api=1',data=data,timeout=5)
    self.assertEqual(failed.exception.code,422);self.assertIn('requires PIN',failed.exception.read().decode());self.assertFalse(pathlib.Path(home+'/private/systems.sqlite').exists())
   finally:server.terminate();server.wait(timeout=5)
if __name__=='__main__':unittest.main()
