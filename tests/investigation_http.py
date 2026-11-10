"""Real concurrent PHP requests with a local synthetic provider; no network AI use."""
import concurrent.futures
import http.cookiejar
import json
import os
from pathlib import Path
import re
import signal
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')

class InvestigationHttpTest(unittest.TestCase):
    def test_session_unlock_stop_poll_and_export(self):
        with tempfile.TemporaryDirectory(prefix='aicontrol-investigation-http-') as directory:
            private = Path(directory) / 'private'
            setup = subprocess.run([PHP, 'bin/setup.php', '--access-mode=local', f'--private-dir={private}', f'--public-root={ROOT}'], cwd=ROOT, capture_output=True, text=True)
            self.assertEqual(setup.returncode, 0, setup.stderr)
            ready = Path(directory) / 'provider-waiting'
            fixture = Path(directory) / 'router.php'
            fixture.write_text('''<?php
namespace AIcontrol {
 function curl_init($url) { return new \\stdClass(); }
 function curl_setopt_array($h,$options) { $GLOBALS['investigation_options']=$options; return true; }
 function curl_exec($h) {
  $body=json_decode($GLOBALS['investigation_options'][CURLOPT_POSTFIELDS],true);
  file_put_contents(getenv('TEST_BODY_FILE'),json_encode($body));
  $fast=file_exists(getenv('TEST_FAST_MARKER'));
  file_put_contents(getenv('TEST_WAIT_MARKER'),'waiting'); if(!$fast)usleep(2500000);
  $text=json_encode(['claims'=>[['claim'=>'Late unchecked fixture','uncertainty'=>'Unknown','confidence'=>'low','verify'=>'Verify externally']]]);
  if($fast){
   $claims=[['claim'=>'Fixture solar savings depend on tariffs','confidence'=>'medium','uncertainty'=>'Local tariff','verify'=>'Find local data','priority'=>'high'],['claim'=>'Fixture payback depends on cost','confidence'=>'low','uncertainty'=>'Capital costs','verify'=>'Find measured costs','priority'=>'normal']];
   $text=json_encode(['core_claims'=>$claims,'competing_angles'=>['Export value may be small'],'least_confident'=>'Local costs','background_assumptions'=>['Suitable roof']]);
   if(str_contains($body['instructions'],'Plan phase')){
    $nodes=json_decode(explode('Dream nodes: ',$body['input'])[1],true);
    $text=json_encode(['steps'=>[['claim_id'=>(int)$nodes[0]['id'],'query'=>'Verify only the selected claim']]]);
   }
  }
  $data=json_encode(['status'=>'completed','usage'=>['input_tokens'=>12,'output_tokens'=>18],'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>$text]]]]]);
  ($GLOBALS['investigation_options'][CURLOPT_WRITEFUNCTION])($h,$data);return true;
 }
 function curl_getinfo($h,$option) { return 200; }
 function curl_close($h) {}
}
namespace { $_SERVER['SCRIPT_NAME']='/aiadmin/loader.php';require getenv('TEST_APP_ROOT').'/app.php'; }
''')
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                port = listener.getsockname()[1]
            env = dict(os.environ, AICONTROL_PRIVATE_DIR=str(private), AICONTROL_LOCAL_DEV='1', AICONTROL_PIN_VERIFIED='1', AICONTROL_OPENAI_API_KEY='synthetic-http-only', PHP_CLI_SERVER_WORKERS='4', TEST_WAIT_MARKER=str(ready), TEST_APP_ROOT=str(ROOT), TEST_FAST_MARKER=str(Path(directory)/'fast'), TEST_BODY_FILE=str(Path(directory)/'body.json'))
            server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', str(fixture)], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
            try:
                base = f'http://127.0.0.1:{port}/aiadmin/loader.php'
                jar = http.cookiejar.CookieJar()
                opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
                def request(query='', fields=None, headers=None, cookie=None):
                    payload = urllib.parse.urlencode(fields, doseq=True).encode() if fields is not None else None
                    h = dict(headers or {})
                    if cookie:
                        h['Cookie'] = cookie
                    client = urllib.request.build_opener() if cookie else opener
                    req = urllib.request.Request(base + query, data=payload, headers=h)
                    try:
                        with client.open(req, timeout=12) as r:
                            return r.status, r.headers, r.read().decode()
                    except urllib.error.HTTPError as e:
                        return e.code, e.headers, e.read().decode()
                for _ in range(80):
                    try:
                        status, _, page = request('?view=research')
                        break
                    except OSError:
                        time.sleep(.05)
                else:
                    self.fail('PHP server did not start')
                self.assertEqual(status, 200)
                csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', page).group(1)
                request('?view=research', dict(csrf=csrf, action='research_project', name='Concurrent research', brief='Stop while waiting'))
                status, _, body = request('?view=research&research_api=1', dict(csrf=csrf, action='research_start', project_id='1'))
                self.assertEqual(status, 200, body[:200])
                run = json.loads(body)['run']['id']
                self.assertEqual(request(f'?research_api=status&run_id={run}')[0], 403)
                self.assertEqual(request('?research_api=1', dict(csrf='bad', action='research_stop', run_id=run))[0], 403)
                cookie = '; '.join(f'{c.name}={c.value}' for c in jar)
                with concurrent.futures.ThreadPoolExecutor() as pool:
                    work = pool.submit(request, '?research_api=1', dict(csrf=csrf, action='research_step', run_id=run), None, cookie)
                    deadline = time.monotonic()+6
                    while not ready.exists() and time.monotonic()<deadline:
                        time.sleep(.02)
                    self.assertTrue(ready.exists(), 'Provider phase was not entered')
                    start = time.monotonic()
                    status, _, body = request(f'?research_api=status&run_id={run}', headers={'X-CSRF-Token': csrf}, cookie=cookie)
                    self.assertEqual(status, 200, body[:200])
                    self.assertEqual(json.loads(body)['run']['status'], 'running')
                    self.assertLess(time.monotonic()-start, 1.5, 'Poll blocked by the phase session lock')
                    start = time.monotonic()
                    status, _, body = request('?research_api=1', dict(csrf=csrf, action='research_stop', run_id=run), cookie=cookie)
                    self.assertEqual(status, 200, body[:200])
                    self.assertLess(time.monotonic()-start, 1.5, 'Stop blocked by the phase session lock')
                    self.assertEqual(json.loads(body)['run']['status'], 'interrupted')
                    status, _, body = work.result()
                    snapshot = json.loads(body)
                    self.assertEqual(snapshot['run']['status'], 'interrupted')
                    self.assertFalse(any(n['type']=='dream_claim' for n in snapshot['nodes']), 'Late result overwrote Stop')
                status, headers, body = request('', dict(csrf=csrf, action='research_export', run_id=run))
                self.assertEqual(status, 200)
                self.assertTrue(headers['Content-Type'].startswith('text/markdown'))
                self.assertIn('interrupted', body)
                # A real Dream request pauses without issuing Plan; review is persisted across reloads.
                (Path(directory)/'fast').touch()
                status, _, body = request('?research_api=1', dict(csrf=csrf, action='research_start', project_id='1'))
                reviewed_run = json.loads(body)['run']['id']
                status, _, body = request('?research_api=1', dict(csrf=csrf, action='research_step', run_id=reviewed_run))
                snapshot = json.loads(body)
                self.assertEqual(snapshot['run']['phase'], 'dream_review')
                claims = [n for n in snapshot['nodes'] if n['type']=='dream_claim']
                self.assertEqual(len(claims), 2)
                _, _, html = request(f'?view=research&run={reviewed_run}')
                self.assertIn('Model’s prior — not yet checked', html)
                self.assertIn('Continue with selected claims', html)
                status, _, body = request('?research_api=1', dict(csrf=csrf, action='research_step', run_id=reviewed_run))
                self.assertEqual(len(json.loads(body)['requests']), 1, 'Review step spent budget')
                self.assertEqual(request('?research_api=1', dict(csrf=csrf, action='research_continue', run_id=reviewed_run, selection_explicit='1'))[0], 422)
                self.assertEqual(request('?research_api=1', dict(csrf='bad', action='research_continue', run_id=reviewed_run))[0], 403)
                chosen = claims[1]['id']
                status, _, body = request('?research_api=1', dict(csrf=csrf, action='research_continue', run_id=reviewed_run, selection_explicit='1', **{'claim_ids[]':[chosen]}))
                self.assertEqual(status, 200, body)
                self.assertEqual(json.loads(body)['run']['phase'], 'plan')
                status, _, body = request('?research_api=1', dict(csrf=csrf, action='research_step', run_id=reviewed_run))
                snapshot = json.loads(body)
                self.assertEqual(snapshot['run']['phase'], 'evidence')
                steps = [n for n in snapshot['nodes'] if n['type']=='plan_step']
                self.assertEqual([n['parent_id'] for n in steps], [chosen])
                plan_body = json.loads((Path(directory)/'body.json').read_text())
                sent_claims = json.loads(plan_body['input'].split('Dream nodes: ')[1])
                self.assertEqual([n['id'] for n in sent_claims], [chosen])
                self.assertEqual(request('?research_api=1', dict(csrf=csrf, action='research_step', run_id=reviewed_run, focus_node_id='999999'))[0], 422)
                self.assertEqual(request('?research_api=1', dict(csrf='bad', action='research_step', run_id=reviewed_run, focus_node_id=chosen))[0], 403)
                self.assertTrue(all('linked_claim_ids' in n and 'source_ids' in n for n in snapshot['nodes']))
                # Focused I/O must release the session exactly like ordinary phases.
                (Path(directory)/'fast').unlink()
                ready.unlink(missing_ok=True)
                cookie = '; '.join(f'{c.name}={c.value}' for c in jar)
                with concurrent.futures.ThreadPoolExecutor() as pool:
                    focused = pool.submit(request, '?research_api=1', dict(csrf=csrf, action='research_step', run_id=reviewed_run, focus_node_id=chosen, focus_note='Find primary sources'), None, cookie)
                    deadline = time.monotonic()+6
                    while not ready.exists() and time.monotonic()<deadline:
                        time.sleep(.02)
                    self.assertTrue(ready.exists())
                    start = time.monotonic()
                    status, _, body = request('?research_api=1', dict(csrf=csrf, action='research_stop', run_id=reviewed_run), cookie=cookie)
                    self.assertLess(time.monotonic()-start, 1.5, 'Focused Stop blocked by session')
                    self.assertEqual(json.loads(body)['run']['status'], 'interrupted')
                    snapshot = json.loads(focused.result()[2])
                    self.assertEqual(snapshot['run']['status'], 'interrupted')
                    self.assertFalse(any(n['type']=='search' for n in snapshot['nodes']), 'Late focus applied')
                (Path(directory)/'fast').touch()

                # A form without the JS marker continues with all claims, preserving no-JS operation.
                _, _, body = request('?research_api=1', dict(csrf=csrf, action='research_start', project_id='1'))
                fallback_run = json.loads(body)['run']['id']
                request('?research_api=1', dict(csrf=csrf, action='research_step', run_id=fallback_run))
                _, _, body = request('?research_api=1', dict(csrf=csrf, action='research_continue', run_id=fallback_run))
                snapshot = json.loads(body)
                self.assertEqual(snapshot['run']['phase'], 'plan')
                self.assertTrue(all(n['selected']==1 for n in snapshot['nodes'] if n['type']=='dream_claim'))
                request('?research_api=1', dict(csrf=csrf, action='research_stop', run_id=fallback_run))
                # Source-specific Learn uses the same authenticated endpoint and no broader context.
                with sqlite3.connect(private/'research.sqlite') as db:
                    source_ids=[]
                    for target, title, url in [(reviewed_run,'Chosen fixture','https://example.com/chosen-http'),(reviewed_run,'Other fixture','https://example.com/other-http'),(fallback_run,'Foreign fixture','https://example.com/foreign-http')]:
                        cursor=db.execute("INSERT INTO research_nodes(run_id,parent_id,type,status,title,content,created_at,updated_at) VALUES(?,NULL,'source','complete',?,'Synthetic source',?,?)",(target,title,int(time.time()),int(time.time())))
                        cursor=db.execute("INSERT INTO research_sources(run_id,node_id,url,title,extract,domain,retrieved_at,meta_json) VALUES(?,?,?,?,?,'example.com',?,'{}')",(target,cursor.lastrowid,url,title,'Synthetic extract',int(time.time())))
                        source_ids.append(cursor.lastrowid)
                self.assertEqual(request('?research_api=1',dict(csrf=csrf,action='research_learn',run_id=reviewed_run,learn_action='explain',source_selection_explicit='1'))[0],422)
                self.assertEqual(request('?research_api=1',dict(csrf=csrf,action='research_learn',run_id=reviewed_run,learn_action='explain',**{'source_ids[]':[source_ids[2]]}))[0],422)
                status, _, body=request('?research_api=1',dict(csrf=csrf,action='research_learn',run_id=reviewed_run,learn_action='explain',source_selection_explicit='1',**{'source_ids[]':[source_ids[0]]}))
                self.assertEqual(status,200,body)
                learned=json.loads((Path(directory)/'body.json').read_text())
                cards=json.loads(learned['input'].split('Collected evidence: ')[1].split('\nUser question/understanding: ')[0])
                self.assertEqual([card['url'] for card in cards],['https://example.com/chosen-http'])
                self.assertNotIn('tools',learned)
                status, _, body = request('?research_api=1', dict(csrf=csrf, action='research_start', project_id='999'))
                self.assertEqual(status, 422)
                self.assertNotIn('synthetic-http-only', body)
            finally:
                os.killpg(server.pid, signal.SIGTERM)
                server.wait(timeout=5)
