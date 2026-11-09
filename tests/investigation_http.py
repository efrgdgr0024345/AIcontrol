"""Real concurrent PHP requests with a local synthetic provider; no network AI use."""
import concurrent.futures
import http.cookiejar
import json
import os
from pathlib import Path
import re
import signal
import socket
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
  file_put_contents(getenv('TEST_WAIT_MARKER'),'waiting'); usleep(2500000);
  $text=json_encode(['claims'=>[['claim'=>'Late unchecked fixture','uncertainty'=>'Unknown','confidence'=>'low','verify'=>'Verify externally']]]);
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
            env = dict(os.environ, AICONTROL_PRIVATE_DIR=str(private), AICONTROL_LOCAL_DEV='1', AICONTROL_PIN_VERIFIED='1', AICONTROL_OPENAI_API_KEY='synthetic-http-only', PHP_CLI_SERVER_WORKERS='4', TEST_WAIT_MARKER=str(ready), TEST_APP_ROOT=str(ROOT))
            server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', str(fixture)], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
            try:
                base = f'http://127.0.0.1:{port}/aiadmin/loader.php'
                jar = http.cookiejar.CookieJar()
                opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
                def request(query='', fields=None, headers=None, cookie=None):
                    payload = urllib.parse.urlencode(fields).encode() if fields is not None else None
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
                status, _, body = request('?research_api=1', dict(csrf=csrf, action='research_start', project_id='999'))
                self.assertEqual(status, 422)
                self.assertNotIn('synthetic-http-only', body)
            finally:
                os.killpg(server.pid, signal.SIGTERM)
                server.wait(timeout=5)
