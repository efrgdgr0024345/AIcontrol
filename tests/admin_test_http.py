"""Owner-session HTTP coverage for retained release/test reports; disposable data only."""
import json, os, sqlite3, unittest
from unittest import mock
from tests import nasa_http as nasa_fixtures

class AdminTestHttp(unittest.TestCase):
 def fixture(self,mode='owner'):
  return nasa_fixtures.NasaHttp('test_empty_workspace_has_a_useful_first_step').workspace(mode)
 def test_owner_reports_local_observations_exports_and_cleanup(self):
  with mock.patch.dict(os.environ,{'AICONTROL_INSTALLED_SHA':'a'*40,'AICONTROL_SITE_PIN':'654321','GH_TOKEN':'SYNTHETIC_GH_SECRET'}):
   with self.fixture() as (home,csrf,request):
    protected={p:(home/p).read_bytes() for p in ['vault.key','vault.json','config.json']}
    def action(name,**fields):
     return request(dict(csrf=csrf,action=name,**fields),query='?view=admin&admin_test_api=1')
    code,_,page=request(query='?view=admin');self.assertEqual(code,200);self.assertIn('Delivery and test records',page)
    code,_,body=action('admin_test_start',title='Release <script>window.BAD=true</script>',expected_sha='a'*40,plan_json='');self.assertEqual(code,200,body)
    bundle=json.loads(body)['report'];rid=str(bundle['report']['id']);sequence=bundle['sequence']
    self.assertEqual(bundle['summary']['outcome'],'not_run')
    code,_,body=action('admin_test_check',report_id=rid,sequence=str(sequence),check_id='php_runtime');self.assertEqual(code,200,body);bundle=json.loads(body)['report']
    self.assertTrue(any(e['origin']=='server_observed' for e in bundle['events']))
    self.assertEqual(action('admin_test_check',report_id=rid,sequence=str(sequence),check_id='sqlite_health')[0],422)
    sequence=bundle['sequence']
    code,_,body=action('admin_test_append',report_id=rid,sequence=str(sequence),event_kind='browser.observation',check_id='research_flow',outcome='failed',summary='The page reached an uncertain result.',output='pin=654321 Cookie: session=PRIVATE_COOKIE\nAuthorization: Bearer SYNTHETIC_GH_SECRET\n<script>window.BAD=true</script>',claimed_tested_sha='b'*40);self.assertEqual(code,200,body);bundle=json.loads(body)['report']
    text=json.dumps(bundle);self.assertNotIn('654321',text);self.assertNotIn('SYNTHETIC_GH_SECRET',text);self.assertNotIn('PRIVATE_COOKIE',text)
    self.assertEqual(bundle['summary']['outcome'],'failed')
    code,_,page=request(query='?view=admin&test_report='+rid);self.assertEqual(code,200);self.assertNotIn('<script>window.BAD=true</script>',page);self.assertIn('&lt;script&gt;',page)
    code,headers,text=action('admin_test_export',report_id=rid,format='json');self.assertEqual(code,200);self.assertIn('attachment',headers['Content-Disposition']);self.assertEqual(json.loads(text)['report']['id'],int(rid))
    code,headers,text=action('admin_test_export',report_id=rid,format='markdown');self.assertEqual(code,200);self.assertIn('text/markdown',headers['Content-Type']);self.assertIn('reported',text.lower())
    code,_,body=action('admin_test_finish',report_id=rid,sequence=str(bundle['sequence']),owner_summary='A failed observation is retained; this report does not establish success.');self.assertEqual(code,200,body);finished=json.loads(body)['report'];self.assertEqual(finished['summary']['lifecycle'],'finished');self.assertEqual(finished['summary']['outcome'],'failed')
    self.assertEqual(action('admin_test_append',report_id=rid,sequence=str(finished['sequence']),event_kind='check.result',check_id='research_flow',outcome='passed',summary='Cannot modify a finished record.')[0],422)
    self.assertEqual(action('admin_test_read',report_id=rid)[0],200)
    self.assertEqual(action('admin_test_read',report_id='1 OR 1=1')[0],422)
    self.assertEqual(action('admin_test_read',report_id='999999')[0],422)
    code,_,_=request(dict(csrf=csrf,action='admin_data',operation='clear_test_reports',record_id='0',confirmation='WRONG'),query='?view=admin');self.assertEqual(code,422)
    self.assertEqual(action('admin_test_read',report_id=rid)[0],200)
    self.assertEqual(request(dict(csrf=csrf,action='admin_data',operation='clear_test_reports',record_id='0',confirmation='CLEAR RELEASE TEST REPORTS'),query='?view=admin')[0],200)
    with sqlite3.connect(home/'panel.sqlite') as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM admin_test_reports').fetchone()[0],0);self.assertEqual(db.execute('SELECT COUNT(*) FROM admin_test_events').fetchone()[0],0)
    for p,data in protected.items():self.assertEqual((home/p).read_bytes(),data)
 def test_owner_import_cannot_forge_server_origin_and_native_flow(self):
  with self.fixture() as (home,csrf,request):
   packet={'format':'AIcontrol test import v1','title':'Synthetic release check','expected_release_sha':'c'*40,'checks':[{'id':'login','title':'Sign-in view','scope':'fixture','outcome':'passed','summary':'Synthetic fixture only; no live website tested.'}],'summary':'Fixture import','finished':True}
   def action(name,**fields):return request(dict(csrf=csrf,action=name,**fields),query='?view=admin&admin_test_api=1')
   forged={**packet,'origin':'server_observed'};self.assertEqual(action('admin_test_import',packet_json=json.dumps(forged))[0],422)
   code,_,body=action('admin_test_import',packet_json=json.dumps(packet));self.assertEqual(code,200,body);bundle=json.loads(body)['report']
   self.assertTrue(all(e['origin']!='server_observed' for e in bundle['events'] if e['kind']=='check.result'))
   self.assertEqual(bundle['summary']['release_state'],'unknown')
   rid=str(bundle['report']['id']);code,_,page=request(query='?view=admin&test_report='+rid);self.assertEqual(code,200);self.assertIn('Synthetic fixture',page)
   code,_,page=request(dict(csrf=csrf,action='admin_test_start',title='Native report',expected_sha='',plan_json=''),query='?view=admin');self.assertEqual(code,200);self.assertIn('Native report',page)
   self.assertEqual(request(dict(csrf='wrong',action='admin_test_import',packet_json=json.dumps(packet)),query='?view=admin')[0],403)
 def test_testing_session_cannot_use_reports(self):
  with self.fixture('testing') as (home,csrf,request):
   for name in ['admin_test_start','admin_test_read','admin_test_export','admin_test_import']:
    self.assertEqual(request(dict(csrf=csrf,action=name,report_id='1',title='Denied',format='json',packet_json='{}'),query='?view=projects')[0],403)
 def test_password_session_needs_signin(self):
  with self.fixture('password') as (home,csrf,request):
   self.assertEqual(request(dict(csrf=csrf,action='admin_test_start',title='Denied'),query='?view=admin')[0],401)

if __name__=='__main__':unittest.main()
