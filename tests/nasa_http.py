"""First-day manual workflow and security boundaries against real PHP, without a provider key."""
import contextlib, http.cookiejar, json, os, pathlib, re, socket, sqlite3, subprocess, tempfile, time, unittest, urllib.error, urllib.parse, urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BINARY','php')
class NasaHttp(unittest.TestCase):
 @contextlib.contextmanager
 def workspace(self,mode='owner',provider=False):
  with tempfile.TemporaryDirectory(prefix='aicontrol-nasa-http-') as temporary:
   home=pathlib.Path(temporary)/'private';env={**os.environ,'AICONTROL_PRIVATE_DIR':str(home),'AICONTROL_LOCAL_DEV':'1','AICONTROL_PIN_VERIFIED':'1' if mode=='owner' else '','AICONTROL_PUBLIC_TESTING':'1' if mode=='testing' else '','AICONTROL_OPENAI_API_KEY':'sk-synthetic-no-network' if provider else ''}
   access='password' if mode=='password' else 'local';setup=subprocess.run([PHP,'bin/setup.php','--access-mode='+access,'--private-dir='+str(home),'--public-root='+str(ROOT)],input='disposable-first-day-password',cwd=ROOT,env=env,capture_output=True,text=True);self.assertEqual(setup.returncode,0,setup.stderr)
   seed=subprocess.run([PHP,'-r',"require 'src/PanelStore.php';require 'src/Vault.php';require 'src/AgentRunner.php';require 'src/Research.php';$p=new AIcontrol\\PanelStore(getenv('AICONTROL_PRIVATE_DIR').'/panel.sqlite');$p->createProject('First-day project','A clear operating scenario.');$p->createProject('Second project','PRIVATE-OTHER-CONTEXT');$r=new AIcontrol\\Research(getenv('AICONTROL_PRIVATE_DIR'));$r->create('Research source','Keep model ideas provisional.');(new AIcontrol\\Vault(getenv('AICONTROL_PRIVATE_DIR')))->saveSystemKey(7,'PRIVATE-SSH-CANARY');"],cwd=ROOT,env=env,capture_output=True,text=True);self.assertEqual(seed.returncode,0,seed.stderr)
   with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
   server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','bin/dev-router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);url=f'http://127.0.0.1:{port}/loader.php';client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
   def request(fields=None,query='?view=nasa&source=project:1'):
    try:
     with client.open(url+query,data=urllib.parse.urlencode(fields,doseq=True).encode() if fields is not None else None,timeout=10) as r:return r.status,r.headers,r.read().decode()
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
   self.assertEqual(request(dict(csrf=csrf,action='nasa_start',source_kind='project',source_id='1',promote_run='1 OR 1',promote_node='1'))[0],422)
   self.assertIn('Start managing this project',request()[2])
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
   self.assertEqual(action('nasa_baseline',title='Unreviewed baseline')[0],422)
   self.assertEqual(action('nasa_review')[0],200)
   self.assertEqual(action('nasa_baseline',title='First-day baseline')[0],200)
   for name,value in protected.items():self.assertEqual((home/name).read_bytes(),value)
 def test_structured_tailoring_native_form_review_and_export(self):
  with self.workspace() as (home,csrf,request):
   code,_,page=request(dict(csrf=csrf,action='nasa_start',source_kind='project',source_id='1'));self.assertEqual(code,200)
   self.assertIn('name="criteria[]"',page);self.assertIn('Type of mission',page);self.assertIn('Launch constraints',page)
   def action(name,**fields):return request(dict(csrf=csrf,action=name,nasa_project='1',**fields))
   fields=dict(kind='tailoring',item_key='',version='0',title='Private pilot planning',content='Use a compact operating story for the private pilot.',status='recorded',phase='pre',artifact='conops',residual_risk='The recovery journey has not yet been observed by a second user.',revisit='Before inviting a second user, or 2026-12-01.')
   for criteria in [[],['unknown'],['mission_type','unknown']]:
    with self.subTest(criteria=criteria):self.assertEqual(action('nasa_save_record',**fields,**{'criteria[]':criteria})[0],422)
   self.assertEqual(action('nasa_save_record',**fields,**{'criteria[][]':['mission_type']})[0],422)
   self.assertEqual(action('nasa_save_record',**fields,**{'criteria[named]':'mission_type'})[0],422)
   self.assertEqual(action('nasa_save_record',**{**fields,'residual_risk':''},**{'criteria[]':['mission_type']})[0],422)
   self.assertEqual(action('nasa_save_record',**{**fields,'revisit':''},**{'criteria[]':['mission_type']})[0],422)
   code,_,page=action('nasa_save_record',**fields,**{'criteria[]':['mission_type','complexity']});self.assertEqual(code,200);self.assertIn('Tailored',page);self.assertIn(fields['residual_risk'],page);self.assertIn(fields['revisit'],page)
   code,_,text=action('nasa_export',format='json');self.assertEqual(code,200);bundle=json.loads(text)
   records=[record for record in bundle['workspace']['items'] if record['kind']=='tailoring'];self.assertEqual(len(records),1);record=records[0]
   self.assertEqual(record['data']['criteria'],['mission_type','complexity']);self.assertEqual(record['data']['residual_risk'],fields['residual_risk']);self.assertEqual(record['data']['revisit'],fields['revisit']);self.assertEqual(record['data']['knowledge_version'],bundle['reference']['knowledge_version'])
   check=next(check for check in bundle['workspace']['readiness']['checks'] if check['key']=='conops');self.assertTrue(check['passed']);self.assertTrue(check['waived']);self.assertEqual(check['tailoring']['version'],1)
   self.assertEqual(action('nasa_review')[0],200);code,_,text=action('nasa_export',format='json');self.assertEqual(code,200);bundle=json.loads(text);report=json.loads(bundle['workspace']['reviews'][0]['report_json']);check=next(check for check in report['recorded_checks'] if check['key']=='conops');self.assertEqual(check['tailoring']['residual_risk'],fields['residual_risk']);self.assertEqual(check['tailoring']['revisit'],fields['revisit'])
   updated={**fields,'item_key':record['item_key'],'version':'1','residual_risk':'The pilot restore path remains unobserved on the target host.'}
   self.assertEqual(action('nasa_save_record',**updated,**{'criteria[]':['complexity']})[0],200);self.assertEqual(action('nasa_save_record',**updated,**{'criteria[]':['complexity']})[0],422)
   code,headers,text=action('nasa_export',format='markdown');self.assertEqual(code,200);self.assertIn('text/markdown',headers['Content-Type']);self.assertIn(updated['residual_risk'],text);self.assertIn(fields['revisit'],text);self.assertIn('complexity',text)
   code,_,text=action('nasa_export',format='json');self.assertEqual(code,200);bundle=json.loads(text);history=[entry for entry in bundle['workspace']['artifact_history'] if entry['kind']=='tailoring'];self.assertEqual([entry['version'] for entry in history],[1,2]);self.assertEqual(json.loads(history[0]['data_json'])['residual_risk'],fields['residual_risk']);self.assertFalse(bundle['workspace']['ai_results'])
   with sqlite3.connect(home/'panel.sqlite') as database:self.assertEqual(database.execute('SELECT COUNT(*) FROM nasa_ai_requests').fetchone()[0],0)
 def test_legacy_tailoring_is_readable_without_silently_waiving_readiness(self):
  with self.workspace() as (home,csrf,request):
   self.assertEqual(request(dict(csrf=csrf,action='nasa_start',source_kind='project',source_id='1'))[0],200)
   with sqlite3.connect(home/'panel.sqlite') as database:
    database.execute('INSERT INTO nasa_items(project_id,kind,item_key,version,title,content,status,data_json,created_by,created_at)VALUES(?,?,?,?,?,?,?,?,?,?)',(1,'tailoring','TAIL-001',1,'Earlier pilot exception','Retain the earlier rationale while its residual risk is completed.','recorded',json.dumps({'phase':'pre','artifact':'conops'}),'owner',int(time.time())))
   code,_,page=request(query='?view=nasa&nasa_project=1');self.assertEqual(code,200);self.assertIn('Earlier pilot exception',page);self.assertIn('Retain the earlier rationale',page)
   code,_,text=request(dict(csrf=csrf,action='nasa_export',nasa_project='1',format='json'));self.assertEqual(code,200);bundle=json.loads(text);check=next(check for check in bundle['workspace']['readiness']['checks'] if check['key']=='conops');self.assertFalse(check['waived']);self.assertFalse(check['passed']);record=next(record for record in bundle['workspace']['items'] if record['kind']=='tailoring');self.assertEqual(record['version'],1);self.assertEqual(record['data'],{'phase':'pre','artifact':'conops'})
 def test_public_testing_cannot_read_or_write_engineering_state(self):
  with self.workspace('testing') as (home,csrf,request):
   self.assertEqual(request()[0],403)
   self.assertEqual(request(dict(csrf=csrf,action='nasa_start',source_kind='project',source_id='1'),query='?view=projects')[0],403)
 def test_native_research_promotion_evidence_confirmation_and_context_pack(self):
  import sqlite3
  with self.workspace() as (home,csrf,request):
   with sqlite3.connect(home/'research.sqlite') as db:
    run=db.execute("INSERT INTO research_runs(project_id,status,phase,started_at,model,flow_version)VALUES(1,'complete','exploring',1,'fixture-no-provider',2)").lastrowid
    node=db.execute("INSERT INTO research_nodes(run_id,type,status,title,content,created_at,updated_at)VALUES(?,'model_idea','complete','Session isolation','The system shall reject an expired session.',1,1)",(run,)).lastrowid
   self.assertEqual(request(dict(csrf=csrf,action='nasa_start',source_kind='research',source_id='1'))[0],200)
   def action(name,**fields):return request(dict(csrf=csrf,action=name,nasa_project='1',**fields))
   fields=dict(research_source=f'research_node:{run}:{node}',target_type='requirement',target_version='0',target_key='',title='Reject expired sessions',content='The system shall reject an expired session.',method='test',note='Explicitly selected model idea; needs actual evidence.')
   self.assertEqual(action('nasa_promote_research',**fields)[0],200)
   def bundle():return json.loads(action('nasa_export',format='json')[2])['workspace']
   saved=bundle();req=next(i for i in saved['items'] if i['kind']=='requirement');self.assertEqual(req['status'],'unverified');self.assertEqual(saved['provenance'][0]['source_kind'],'research_node')
   self.assertEqual(action('nasa_attach_provenance',kind='requirement',item_key=req['item_key'],version='1',research_source='manual_evidence:0:0',note='Disposable synthetic test output',manual_title='Session rejection test',manual_content='Synthetic test: expired session rejected; write count remained zero.',manual_reference='fixture://session-test/1',manual_method='test')[0],200)
   link=next(l for l in bundle()['provenance'] if l['source_kind']=='manual_evidence')
   update=dict(kind='requirement',item_key=req['item_key'],version='1',title=req['title'],content=req['content'],status='verified',source=req['data']['source'],method=req['data']['method'],evidence='fixture://session-test/1')
   self.assertEqual(action('nasa_save_record',verification_confirmed='1',**update)[0],422)
   self.assertEqual(action('nasa_review_provenance',link_id=str(link['id']),link_version=str(link['version']),version='1',review_note='I reviewed this synthetic test record for the exact requirement and method.')[0],200)
   self.assertEqual(action('nasa_save_record',**update)[0],422)
   self.assertEqual(action('nasa_save_record',verification_confirmed='1',**update)[0],200)
   saved=bundle();req=next(i for i in saved['items'] if i['kind']=='requirement');self.assertTrue(req['verification_confirmed']);self.assertIn('owner_confirmation',req['data'])
   update['version']='2';update['content']='The system shall reject a session older than 10 minutes.'
   self.assertEqual(action('nasa_save_record',verification_confirmed='1',**update)[0],422)
   update['status']='unverified';self.assertEqual(action('nasa_save_record',**update)[0],200)
   self.assertEqual(action('nasa_save_record',kind='interface',item_key='',version='0',title='Browser to PHP',content='Authenticated planning request boundary.',status='draft',side_a='Browser',side_b='PHP',payload='JSON fields and CSRF token',errors='422 with a bounded error',auth='Owner PIN and CSRF',owner='Owner',change_rule='Version contract before incompatible changes')[0],200)
   code,headers,text=action('nasa_export',format='context_json');self.assertEqual(code,200);pack=json.loads(text);self.assertIn('context',pack['format'].lower());self.assertIn('owner_confirmation',json.dumps(saved));self.assertNotIn('PRIVATE-SSH-CANARY',text);self.assertNotIn('PRIVATE-OTHER-CONTEXT',text)
   code,headers,text=action('nasa_export',format='context_markdown');self.assertEqual(code,200);self.assertIn('text/markdown',headers['Content-Type']);self.assertIn('The system shall reject a session older than 10 minutes.',text)
   code,_,page=request(query='?view=nasa&nasa_project=1&density=practitioner');self.assertEqual(code,200);self.assertIn('data-nasa-density="practitioner"',page);self.assertIn('Phase entrance and success',page)
   code,_,page=request(query='?view=research&project=1');self.assertEqual(code,200);self.assertIn('Promote this idea into NASA planning',page)
   saved=bundle();revision=str(saved['project']['revision']);self.assertEqual(action('nasa_focused_research',focus_key='conops',focus_version='0',revision=revision,run_id='0')[0],200)
   self.assertEqual(len(bundle()['research_origins']),1)
   with sqlite3.connect(home/'panel.sqlite') as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM nasa_ai_requests').fetchone()[0],0)
 def test_focused_research_prepares_model_only_run_without_provider_call(self):
  import sqlite3
  with self.workspace(provider=True) as (home,csrf,request):
   self.assertEqual(request(dict(csrf=csrf,action='nasa_start',source_kind='project',source_id='1'))[0],200)
   code,_,page=request(dict(csrf=csrf,action='nasa_focused_research',nasa_project='1',focus_key='conops',focus_version='0',revision='0',run_id='0'));self.assertEqual(code,200)
   with sqlite3.connect(home/'research.sqlite') as db:
    run=db.execute('SELECT status,phase,flow_version,auto_search FROM research_runs WHERE project_id=2').fetchone();self.assertEqual(run,('running','dream',2,0));self.assertEqual(db.execute('SELECT COUNT(*) FROM research_requests').fetchone()[0],0)
   with sqlite3.connect(home/'panel.sqlite') as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM nasa_ai_requests').fetchone()[0],0)
 def test_password_session_cannot_bypass_signin(self):
  with self.workspace('password') as (home,csrf,request):
   code,_,page=request();self.assertEqual(code,200);self.assertIn('Sign in',page);self.assertNotIn('Start managing this project',page)
   self.assertEqual(request(dict(csrf=csrf,action='nasa_start',source_kind='project',source_id='1'))[0],401)
 def test_empty_workspace_has_a_useful_first_step(self):
  with self.workspace() as (home,csrf,request):
   code,_,_=request(dict(csrf=csrf,action='admin_data',operation='reset_workspace',record_id='0',confirmation='RESET ALL PROJECT DATA'),query='?view=admin');self.assertEqual(code,200)
   code,_,page=request();self.assertEqual(code,200);self.assertIn('Create task project',page);self.assertIn('Create research project',page);self.assertIn('A handbook you can use without reading the book',page);self.assertNotIn('Start managing this project',page)
if __name__=='__main__':unittest.main()
