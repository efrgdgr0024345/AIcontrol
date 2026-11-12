"""Exercise Admin mutations against a real local PHP server; synthetic data only."""
import fcntl
import hashlib
import html
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BINARY','php')
class AdminHttpTest(unittest.TestCase):
    def test_confirmed_authenticated_cleanup_and_inventory(self):
        with tempfile.TemporaryDirectory(prefix='aicontrol-admin-http-') as temporary:
            home=Path(temporary)/'private'
            setup=subprocess.run([PHP,'bin/setup.php','--access-mode=local',f'--private-dir={home}',f'--public-root={ROOT}'],cwd=ROOT,capture_output=True,text=True)
            self.assertEqual(setup.returncode,0,setup.stderr)
            with socket.socket() as listener:listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
            env=dict(os.environ,AICONTROL_PRIVATE_DIR=str(home),AICONTROL_LOCAL_DEV='1',AICONTROL_PIN_VERIFIED='1',AICONTROL_OPENAI_API_KEY='PRIVATE-ADMIN-HTTP-KEY')
            router=Path(temporary)/'router.php';router.write_text("<?php $_SERVER['SCRIPT_NAME']='/aiadmin/loader.php';require getenv('ADMIN_TEST_ROOT').'/app.php';")
            env['ADMIN_TEST_ROOT']=str(ROOT)
            server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}',str(router)],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            try:
                url=f'http://127.0.0.1:{port}/aiadmin/loader.php?view=admin';jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
                def request(fields=None,client=None):
                    req=urllib.request.Request(url,data=urllib.parse.urlencode(fields,doseq=True).encode() if fields is not None else None)
                    try:
                        with (client or opener).open(req,timeout=8) as response:return response.status,response.read().decode()
                    except urllib.error.HTTPError as error:return error.code,error.read().decode()
                for _ in range(80):
                    try:code,page=request();break
                    except OSError:time.sleep(.05)
                self.assertEqual(code,200);csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',page).group(1)
                report=json.loads(html.unescape(re.search(r'id="admin-technical-report"[^>]*>(.*?)</textarea>',page,re.S).group(1)))
                self.assertEqual(report['databases'][0]['driver'],'sqlite');self.assertEqual(report['databases'][0]['path'],str(home/'panel.sqlite'));self.assertNotIn('PRIVATE-ADMIN-HTTP-KEY',json.dumps(report));self.assertIn('memory_limit',report['php']['settings'])
                db=sqlite3.connect(home/'research.sqlite');db.execute("INSERT INTO research_projects(name,brief)VALUES('Delete fixture','Saved question?')");db.commit()
                db.execute("INSERT INTO research_projects(name,brief)VALUES('Other project','PRIVATE OTHER PROJECT')")
                db.execute("INSERT INTO research_runs(id,project_id,status,phase,started_at,model,busy_since)VALUES(1,1,'running','evidence',1,'synthetic',1),(2,2,'complete','reconciliation',1,'synthetic',NULL)")
                db.execute("INSERT INTO research_nodes(run_id,type,status,title,content,created_at,updated_at)VALUES(1,'dream_claim','complete','Selected claim','</textarea><script>window.BAD=true</script>',1,1)");db.commit()
                export_fields=dict(action='admin_project_json',project_id='1',run_id='1',kind='payload',csrf=csrf)
                def export(fields,client=opener):
                    req=urllib.request.Request(url,data=urllib.parse.urlencode(fields).encode())
                    try:
                        with client.open(req,timeout=8) as response:return response.status,response.headers,response.read().decode()
                    except urllib.error.HTTPError as error:return error.code,error.headers,error.read().decode()
                code,headers,body=export(export_fields);self.assertEqual(code,200);self.assertIn('attachment',headers['Content-Disposition']);self.assertIn('application/json',headers['Content-Type']);payload=json.loads(body);self.assertEqual(payload['run']['id'],1);self.assertEqual(payload['run']['status'],'running')
                code,_,body=export(dict(export_fields,kind='bundle'));bundle=json.loads(body);self.assertEqual(code,200);self.assertEqual(bundle['snapshot']['nodes'],payload['nodes']);self.assertNotIn('PRIVATE OTHER PROJECT',body);self.assertNotIn('PRIVATE-ADMIN-HTTP-KEY',body)
                self.assertEqual(export(dict(export_fields,run_id='2'))[0],422)
                self.assertEqual(export(dict(export_fields,project_id='1 OR 1=1'))[0],422)
                self.assertEqual(export(dict(export_fields,kind='unknown'))[0],422)
                self.assertEqual(export(dict(export_fields,csrf='wrong'))[0],403)
                self.assertEqual(export(export_fields,urllib.request.build_opener())[0],403)
                with opener.open(url+'&inspect_project=1&inspect_run=1') as response:inspector_html=response.read().decode()
                displayed=json.loads(html.unescape(re.search(r'id="project-map-payload"[^>]*>(.*?)</textarea>',inspector_html,re.S).group(1)));self.assertEqual(displayed['run']['id'],1);self.assertNotIn('</textarea><script>window.BAD=true</script>',inspector_html);self.assertEqual(db.execute('SELECT status FROM research_runs WHERE id=1').fetchone()[0],'running','Inspection recovered/changed stale run')
                config_hash=hashlib.sha256((home/'config.json').read_bytes()).hexdigest()
                action=dict(action='admin_data',operation='delete_research_project',record_id='1',confirmation='DELETE RESEARCH PROJECT 1',csrf=csrf)
                self.assertEqual(request(dict(action,csrf='wrong'))[0],403)
                self.assertEqual(request(dict(action,confirmation='wrong'))[0],422)
                self.assertEqual(db.execute('SELECT COUNT(*) FROM research_projects WHERE id=1').fetchone()[0],1)
                self.assertEqual(request(dict(action,operation='DROP TABLE research_projects'))[0],422)
                self.assertEqual(request(dict(action,record_id='1 OR 1=1'))[0],422)
                self.assertEqual(request(action,urllib.request.build_opener())[0],403)
                with open(home/'provider-request.lock','a') as gate:
                    fcntl.flock(gate,fcntl.LOCK_EX)
                    self.assertEqual(request(action)[0],422);self.assertEqual(db.execute('SELECT COUNT(*) FROM research_projects WHERE id=1').fetchone()[0],1)
                code,page=request(action);self.assertEqual(code,200);self.assertIn('Saved data updated',page);self.assertEqual(db.execute('SELECT COUNT(*) FROM research_projects WHERE id=1').fetchone()[0],0)
                new_csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',page).group(1);self.assertNotEqual(new_csrf,csrf)
                self.assertEqual(request(dict(action,operation='reset_workspace',record_id='0',confirmation='RESET ALL PROJECT DATA'))[0],403,'Old page can mutate after cleanup')
                self.assertEqual(hashlib.sha256((home/'config.json').read_bytes()).hexdigest(),config_hash)
                self.assertTrue((home/'diagnostics.jsonl').exists());self.assertEqual(db.execute('PRAGMA foreign_key_check').fetchall(),[])
                db.close()
            finally:server.terminate();server.wait(timeout=5)
