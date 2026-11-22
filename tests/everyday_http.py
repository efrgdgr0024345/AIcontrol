"""Everyday owner workflows on disposable PHP/SQLite; no model/provider request."""
import json, os, re, sqlite3, subprocess, unittest
from tests import nasa_http as fixtures

class EverydayHttp(unittest.TestCase):
 def fixture(self,mode='owner'):return fixtures.NasaHttp('test_empty_workspace_has_a_useful_first_step').workspace(mode)
 def test_new_everyday_default_owner_intent_mode_history_and_no_key(self):
  with self.fixture() as (home,csrf,request):
   code,_,page=request(query='?view=nasa&source=project:1');self.assertEqual(code,200);self.assertIn('Start everyday workspace',page);self.assertIn('nasa_mode=rigorous',page);self.assertNotIn('<nav class="nasa-timeline"',page)
   protected={p:(home/p).read_bytes() for p in ['vault.key','vault.json','config.json']}
   def action(name,**fields):return request(dict(csrf=csrf,action=name,nasa_project='1',**fields),query='?view=nasa')
   code,_,page=action('nasa_everyday_start',source_kind='project',source_id='1');self.assertEqual(code,200,page[:500]);self.assertTrue(bool(re.search(r'<(?:div|section)\b[^>]*\bdata-everyday-workspace(?:\s|=|>)',page)),'Everyday view did not render')
   def export():return json.loads(action('nasa_export',format='json')[2])['workspace']
   state=export();self.assertEqual(state['operating_mode'],'everyday');self.assertFalse(state['items']);self.assertEqual(state['project']['phase'],'pre')
   objects=state['everyday']['objects'] if 'objects' in state['everyday'] else state['everyday']['state']['objects']
   intent=next(o for o in objects if o['kind']=='intent');revision=str(state['project']['revision'])
   self.assertEqual(action('nasa_everyday_object',object_key=intent.get('key',intent.get('object_key')),version=str(intent['version']),revision=revision,title='Owner clarified intent',content='Retain useful research and distinguish an unknown request from completed work.')[0],200)
   self.assertEqual(action('nasa_everyday_object',object_key=intent.get('key',intent.get('object_key')),version=str(intent['version']),revision=revision,title='Stale intent',content='This must not overwrite the owner correction.')[0],422)
   self.assertEqual(action('nasa_everyday_ai',operation='pathway')[0],422)
   state=export();revision=str(state['project']['revision'])
   code,_,page=action('nasa_everyday_mode',mode='rigorous',reason='Review the formal release controls.',exposure='Existing uncertainty remains visible.',revisit='After the bounded review.',revision=revision);self.assertEqual(code,200);self.assertTrue(bool(re.search(r'<(?:div|section)\b[^>]*\bdata-nasa-workspace(?:\s|=|>)',page)),'Managed view did not render')
   state=export();self.assertEqual(state['operating_mode'],'rigorous');self.assertEqual(state['project']['phase'],'pre')
   self.assertEqual(action('nasa_everyday_start',source_kind='project',source_id='1')[0],422)
   code,_,page=action('nasa_everyday_mode',mode='everyday',reason='Use short cycles for this software project.',exposure='No evidence is upgraded.',revisit='Before a consequential release.',revision=str(state['project']['revision']));self.assertEqual(code,200);self.assertTrue(bool(re.search(r'<(?:div|section)\b[^>]*\bdata-everyday-workspace(?:\s|=|>)',page)),'Everyday view did not render')
   with sqlite3.connect(home/'panel.sqlite') as db:
    self.assertEqual(db.execute('SELECT COUNT(*) FROM nasa_ai_requests').fetchone()[0],0)
    self.assertGreater(db.execute("SELECT COUNT(*) FROM engineering_object_versions WHERE kind='intent'").fetchone()[0],1)
   for p,data in protected.items():self.assertEqual((home/p).read_bytes(),data)
 def test_research_map_selection_survives_new_everyday_start(self):
  with self.fixture() as (home,csrf,request):
   env={**os.environ,'AICONTROL_PRIVATE_DIR':str(home),'AICONTROL_OPENAI_API_KEY':'sk-synthetic-no-network'}
   seed=subprocess.run([fixtures.PHP,'-r',"require 'src/AgentRunner.php';require 'src/Research.php';require_once 'src/Investigation.php';$e=new AIcontrol\\Investigation(getenv('AICONTROL_PRIVATE_DIR'));$run=$e->createRun(1);$node=$e->appendNode($run,null,'model_idea','complete','Selected provisional idea','Keep recovery behaviour understandable.');echo json_encode([$run,$node]);"],cwd=fixtures.ROOT,env=env,capture_output=True,text=True)
   self.assertEqual(seed.returncode,0,seed.stderr);run,node=json.loads(seed.stdout)
   code,_,page=request(query=f'?view=nasa&source=research:1&promote_run={run}&promote_node={node}');self.assertEqual(code,200)
   self.assertTrue(f'name="promote_run" value="{run}"' in page,"Selected research run was lost");self.assertTrue(f'name="promote_node" value="{node}"' in page,"Selected research node was lost")
   code,_,page=request(dict(csrf=csrf,action='nasa_everyday_start',source_kind='research',source_id='1',promote_run=str(run),promote_node=str(node)),query='?view=nasa');self.assertEqual(code,200);self.assertTrue('Promote research as draft' in page,'Selected research promotion form is unavailable')
   code,_,page=request(dict(csrf=csrf,action='nasa_promote_research',nasa_project='1',research_source=f'research_node:{run}:{node}',target_type='requirement',target_key='',target_version='0',method='test',note='Owner selected a provisional research idea.'),query='?view=nasa');self.assertEqual(code,200,page[:200])
   bundle=json.loads(request(dict(csrf=csrf,action='nasa_export',nasa_project='1',format='json'),query='?view=nasa')[2])
   item=next(r for r in bundle['workspace']['items'] if r['kind']=='requirement');self.assertEqual(item['status'],'unverified');self.assertTrue(item['data']['configuration_required']);self.assertEqual(item['data']['required_scope'],'unknown')
   self.assertEqual(request(dict(csrf=csrf,action='nasa_everyday_start',source_kind='research',source_id='1',promote_run='1 OR 1',promote_node=str(node)),query='?view=nasa')[0],422)
 def test_legacy_rigorous_workspace_keeps_its_mode(self):
  with self.fixture() as (home,csrf,request):
   self.assertEqual(request(dict(csrf=csrf,action='nasa_start',source_kind='project',source_id='1'),query='?view=nasa')[0],200)
   code,_,page=request(query='?view=nasa&nasa_project=1');self.assertEqual(code,200);self.assertTrue(bool(re.search(r'<(?:div|section)\b[^>]*\bdata-nasa-workspace(?:\s|=|>)',page)),'Managed view did not render');self.assertFalse(bool(re.search(r'<(?:div|section)\b[^>]*\bdata-everyday-workspace(?:\s|=|>)',page)),'Legacy workspace changed operating mode')
 def test_everyday_owner_boundary_and_csrf(self):
  with self.fixture('testing') as (home,csrf,request):
   for name in ['nasa_everyday_start','nasa_everyday_ai','nasa_everyday_proposal_accept','nasa_everyday_cycle','nasa_everyday_observation']:
    self.assertEqual(request(dict(csrf=csrf,action=name,nasa_project='1',source_kind='project',source_id='1'),query='?view=projects')[0],403)
  with self.fixture() as (home,csrf,request):
   self.assertEqual(request(dict(csrf='wrong',action='nasa_everyday_start',source_kind='project',source_id='1'),query='?view=nasa')[0],403)

if __name__=='__main__':unittest.main()
