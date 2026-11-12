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
            server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','bin/dev-router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            try:
                url=f'http://127.0.0.1:{port}/loader.php?view=admin';jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
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
                config_hash=hashlib.sha256((home/'config.json').read_bytes()).hexdigest()
                action=dict(action='admin_data',operation='delete_research_project',record_id='1',confirmation='DELETE RESEARCH PROJECT 1',csrf=csrf)
                self.assertEqual(request(dict(action,csrf='wrong'))[0],403)
                self.assertEqual(request(dict(action,confirmation='wrong'))[0],422)
                self.assertEqual(db.execute('SELECT COUNT(*) FROM research_projects').fetchone()[0],1)
                self.assertEqual(request(dict(action,operation='DROP TABLE research_projects'))[0],422)
                self.assertEqual(request(dict(action,record_id='1 OR 1=1'))[0],422)
                self.assertEqual(request(action,urllib.request.build_opener())[0],403)
                with open(home/'provider-request.lock','a') as gate:
                    fcntl.flock(gate,fcntl.LOCK_EX)
                    self.assertEqual(request(action)[0],422);self.assertEqual(db.execute('SELECT COUNT(*) FROM research_projects').fetchone()[0],1)
                code,page=request(action);self.assertEqual(code,200);self.assertIn('Saved data updated',page);self.assertEqual(db.execute('SELECT COUNT(*) FROM research_projects').fetchone()[0],0)
                new_csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',page).group(1);self.assertNotEqual(new_csrf,csrf)
                self.assertEqual(request(dict(action,operation='reset_workspace',record_id='0',confirmation='RESET ALL PROJECT DATA'))[0],403,'Old page can mutate after cleanup')
                self.assertEqual(hashlib.sha256((home/'config.json').read_bytes()).hexdigest(),config_hash)
                self.assertTrue((home/'diagnostics.jsonl').exists());self.assertEqual(db.execute('PRAGMA foreign_key_check').fetchall(),[])
                db.close()
            finally:server.terminate();server.wait(timeout=5)
