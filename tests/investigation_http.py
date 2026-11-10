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
  $stream=file_exists(getenv('TEST_STREAM_MARKER'));
  if($stream)($GLOBALS['investigation_options'][CURLOPT_WRITEFUNCTION])($h,"event: response.created\n".'data: '.json_encode(['type'=>'response.created'])."\n\n");
  file_put_contents(getenv('TEST_WAIT_MARKER'),'waiting'); if(!$fast)usleep(2500000);
  $text=json_encode(['claims'=>[['claim'=>'Late unchecked fixture','uncertainty'=>'Unknown','confidence'=>'low','verify'=>'Verify externally']]]);
  if($fast){
   $claims=[['claim'=>'Fixture solar savings depend on tariffs','confidence'=>'medium','uncertainty'=>'Local tariff','verify'=>'Find local data','priority'=>'high'],['claim'=>'Fixture payback depends on cost','confidence'=>'low','uncertainty'=>'Capital costs','verify'=>'Find measured costs','priority'=>'normal']];
   $text=json_encode(['core_claims'=>$claims,'competing_angles'=>['Export value may be small'],'least_confident'=>'Local costs','background_assumptions'=>['Suitable roof']]);
   if(str_contains($body['instructions'],'Plan phase')){
    $nodes=json_decode(explode('Dream nodes: ',$body['input'])[1],true);
    $text=json_encode(['steps'=>[['claim_id'=>(int)$nodes[0]['id'],'query'=>'Verify only the selected claim']],'challenge'=>['claim_id'=>(int)$nodes[0]['id'],'query'=>'Seek counter evidence']]);
   }
  }
  if(str_contains($body['instructions'],'Grouped verification')){
   $ledger=json_decode(explode('Evidence ledger: ',$body['input'])[1],true);
   $text=json_encode(['assessments'=>array_map(fn($claim)=>['claim_id'=>(int)$claim['id'],'status'=>'open','rationale'=>'No passage-backed relation','strength'=>null],$ledger['claims']),'defects'=>[],'calculations'=>[]]);
  }
  $data=json_encode(['status'=>'completed','usage'=>['input_tokens'=>12,'output_tokens'=>18],'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>$text]]]]]);
  if($stream)$data="event: response.completed\n".'data: '.json_encode(['type'=>'response.completed','response'=>json_decode($data,true)])."\n\n";
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
            env = dict(os.environ, AICONTROL_RESEARCH_HOURLY_LIMIT='10', AICONTROL_PRIVATE_DIR=str(private), AICONTROL_LOCAL_DEV='1', AICONTROL_PIN_VERIFIED='1', AICONTROL_OPENAI_API_KEY='synthetic-http-only', PHP_CLI_SERVER_WORKERS='4', TEST_WAIT_MARKER=str(ready), TEST_APP_ROOT=str(ROOT), TEST_FAST_MARKER=str(Path(directory)/'fast'), TEST_BODY_FILE=str(Path(directory)/'body.json'),TEST_STREAM_MARKER=str(Path(directory)/'stream'))
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
                self.assertEqual(request('?research_api=1',dict(csrf='bad',action='research_settings',project_id='1'))[0],403)
                status, _, body=request('?research_api=1',dict(csrf=csrf,action='research_settings',project_id='1',primary_model='gpt-4.1',spend_cap_usd='5',auto_search='1',growth_searches='3'))
                self.assertEqual(status,200,body)
                self.assertEqual(request('?research_api=1',dict(csrf=csrf,action='research_settings',project_id='1',primary_model='invented-model',spend_cap_usd='5',growth_searches='3'))[0],422)
                status, _, body = request('?view=research&research_api=1', dict(csrf=csrf, action='research_start', project_id='1'))
                self.assertEqual(status, 200, body[:200])
                run = json.loads(body)['run']['id']
                (Path(directory)/'stream').touch()
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
                    self.assertEqual(json.loads(body)['requests'][-1]['activity'],'Provider accepted request')
                    self.assertIsNone(json.loads(body)['requests'][-1]['estimated_usd'])
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
                self.assertEqual([n['parent_id'] for n in steps], [chosen, chosen])
                self.assertTrue(json.loads(steps[-1]['data_json'])['challenge'])
                before_usage=snapshot['usage']['project']['total_tokens']
                status, _, ranked=request('?research_api=1',dict(csrf=csrf,action='research_rank',run_id=reviewed_run,node_id=chosen,path_weight='20',model_tier='economy'))
                self.assertEqual(status,200,ranked)
                self.assertEqual(json.loads(ranked)['usage']['project']['total_tokens'],before_usage,'Ranking spent tokens')
                self.assertTrue(any(n['id']==chosen and n['path_weight']==20 for n in json.loads(ranked)['nodes']))
                self.assertEqual(request('?research_api=1',dict(csrf=csrf,action='research_rank',run_id=reviewed_run,node_id=chosen,path_weight='101',model_tier='economy'))[0],422)
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
                cards=json.loads(learned['input'].split('Collected evidence: ')[1].split('\nUser question/understanding: ')[0].split('\nFrozen passage material: ')[0])
                self.assertEqual([card['url'] for card in cards],['https://example.com/chosen-http'])
                self.assertNotIn('tools',learned)
                status, _, body = request('?research_api=1', dict(csrf=csrf, action='research_start', project_id='999'))
                self.assertEqual(status, 422)
                self.assertNotIn('synthetic-http-only', body)
                # Verification I/O must release the session and reject late ledger writes after Stop.
                self.assertEqual(request('?research_api=1',dict(csrf='bad',action='research_finish',run_id=fallback_run))[0],403)
                self.assertEqual(request('?research_api=1',dict(csrf=csrf,action='research_finish',run_id=fallback_run))[0],422)
                with sqlite3.connect(private/'research.sqlite') as db:
                    db.execute("UPDATE research_runs SET status='running',phase='verification',evidence_frozen=0,verification_status='pending',busy=NULL,busy_since=NULL WHERE id=?",(fallback_run,))
                    claim_ids=[row[0] for row in db.execute("SELECT id FROM research_nodes WHERE run_id=? AND type='dream_claim' AND selected=1",(fallback_run,))]
                    for claim_id in claim_ids:
                        db.execute("INSERT INTO research_claim_versions(claim_id,run_id,status,rationale,created_at,created_by) VALUES(?,?,'open','Provisional fixture',?,'system')",(claim_id,fallback_run,int(time.time())))
                _, _, body=request(f'?research_api=status&run_id={fallback_run}',headers={'X-CSRF-Token':csrf})
                before=json.loads(body)['ledger']
                (Path(directory)/'fast').unlink()
                ready.unlink(missing_ok=True)
                with concurrent.futures.ThreadPoolExecutor() as pool:
                    verifying=pool.submit(request,'?research_api=1',dict(csrf=csrf,action='research_step',run_id=fallback_run),None,cookie)
                    deadline=time.monotonic()+6
                    while not ready.exists() and time.monotonic()<deadline:
                        time.sleep(.02)
                    self.assertTrue(ready.exists(),'Verifier did not enter provider I/O')
                    started=time.monotonic()
                    status, _, body=request(f'?research_api=status&run_id={fallback_run}',headers={'X-CSRF-Token':csrf},cookie=cookie)
                    self.assertEqual(status,200)
                    self.assertLess(time.monotonic()-started,1.5,'Verifier blocked status polling')
                    started=time.monotonic()
                    status, _, body=request('?research_api=1',dict(csrf=csrf,action='research_stop',run_id=fallback_run),cookie=cookie)
                    self.assertEqual(status,200)
                    self.assertLess(time.monotonic()-started,1.5,'Verifier blocked Stop')
                    snapshot=json.loads(verifying.result()[2])
                    self.assertEqual(snapshot['run']['status'],'interrupted')
                    self.assertEqual(snapshot['run']['verification_status'],'skipped')
                    self.assertEqual(snapshot['ledger'],before,'Late verification changed the ledger')
                # Seed an eligible review fixture, then exercise bounded repair and freeze through HTTP.
                (Path(directory)/'fast').touch()
                with sqlite3.connect(private/'research.sqlite') as db:
                    db.execute("UPDATE research_runs SET status='running',phase='verification_review',evidence_frozen=0,verification_status='complete',repair_count=0 WHERE id=?",(fallback_run,))
                    db.execute("UPDATE research_nodes SET status='complete',data_json=? WHERE run_id=? AND type='phase' AND title='Verification'",(json.dumps({'defects':[{'claim_id':claim_ids[0],'severity':'high','kind':'missing_passage','description':'Missing captured passage'}]}),fallback_run))
                status, _, body=request('?research_api=1',dict(csrf=csrf,action='research_repair',run_id=fallback_run))
                self.assertEqual(status,200,body)
                snapshot=json.loads(body)
                self.assertEqual(snapshot['run']['repair_count'],1)
                self.assertEqual(snapshot['run']['phase'],'verification_review')
                self.assertEqual(request('?research_api=1',dict(csrf=csrf,action='research_repair',run_id=fallback_run))[0],422)
                status, _, body=request('?research_api=1',dict(csrf=csrf,action='research_finish',run_id=fallback_run))
                self.assertEqual(status,200,body)
                snapshot=json.loads(body)
                self.assertEqual(snapshot['run']['status'],'complete')
                self.assertEqual(snapshot['run']['evidence_frozen'],1)
                self.assertTrue(any(n['title']=='Verification' and 'Missing captured passage' in n['data_json'] for n in snapshot['nodes']),'Earlier findings disappeared')

            finally:
                os.killpg(server.pid, signal.SIGTERM)
                server.wait(timeout=5)
