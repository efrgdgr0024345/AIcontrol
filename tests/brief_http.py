"""Authenticated, concurrent real HTTP + incremental SSE; synthetic provider only."""
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
ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BINARY','php')
class BriefHttpTest(unittest.TestCase):
    def test_auth_streaming_and_admission(self):
        with tempfile.TemporaryDirectory(prefix='aicontrol-brief-http-') as folder:
            home=Path(folder)/'private';marker=Path(folder)/'sent';router=Path(folder)/'router.php'
            setup=subprocess.run([PHP,'bin/setup.php','--access-mode=local',f'--private-dir={home}',f'--public-root={ROOT}'],cwd=ROOT,capture_output=True,text=True)
            self.assertEqual(setup.returncode,0,setup.stderr)
            router.write_text('''<?php
namespace AIcontrol {
 function curl_init($url){return new \\stdClass();}
 function curl_setopt_array($h,$o){$GLOBALS['brief_options']=$o;return true;}
 function curl_exec($h){
  $body=json_decode($GLOBALS['brief_options'][CURLOPT_POSTFIELDS],true);if($body['model']!=='gpt-5.4-nano')throw new \\RuntimeException('Wrong model');
  $write=$GLOBALS['brief_options'][CURLOPT_WRITEFUNCTION];$write($h,'data: '.json_encode(['type'=>'response.output_text.delta','delta'=>"Which local data could change this decision?\\n"])."\\n\\n");file_put_contents(getenv('BRIEF_MARKER'),'sent');usleep(1800000);
  $text="Which local data could change this decision?\\nWhat evidence contradicts the proposed benefit?\\nWhich household assumptions need independent data?";
  $result=['status'=>'completed','model'=>'gpt-5.4-nano','usage'=>['input_tokens'=>60,'output_tokens'=>40],'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>$text]]]]];
  $write($h,'data: '.json_encode(['type'=>'response.output_text.delta','delta'=>"What evidence contradicts the proposed benefit?\\nWhich household assumptions need independent data?"])."\\n\\n");$write($h,'data: '.json_encode(['type'=>'response.completed','response'=>$result])."\\n\\n");return true;
 }
 function curl_getinfo($h,$o){return 200;}function curl_close($h){}
}
namespace {$_SERVER['SCRIPT_NAME']='/aiadmin/loader.php';require getenv('BRIEF_ROOT').'/app.php';}
''')
            with socket.socket() as listener:listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
            env=dict(os.environ,AICONTROL_PRIVATE_DIR=str(home),AICONTROL_LOCAL_DEV='1',AICONTROL_PIN_VERIFIED='1',AICONTROL_OPENAI_API_KEY='synthetic-brief-http',PHP_CLI_SERVER_WORKERS='4',BRIEF_ROOT=str(ROOT),BRIEF_MARKER=str(marker))
            server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}',str(router)],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
            try:
                base=f'http://127.0.0.1:{port}/aiadmin/loader.php';jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
                def request(fields=None,cookie=None,query='?view=research&new=1'):
                    headers={'Cookie':cookie} if cookie else {};client=urllib.request.build_opener() if cookie else opener
                    req=urllib.request.Request(base+query,data=urllib.parse.urlencode(fields,doseq=True).encode() if fields is not None else None,headers=headers)
                    try:
                        with client.open(req,timeout=8) as response:return response.status,response.headers,response.read().decode()
                    except urllib.error.HTTPError as error:return error.code,error.headers,error.read().decode()
                for _ in range(80):
                    try:status,_,page=request();break
                    except OSError:time.sleep(.05)
                self.assertEqual(status,200);csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',page).group(1)
                fields=dict(csrf=csrf,action='research_brief_suggest',name='Solar',draft='Does solar change the bill for Perth households?')
                self.assertEqual(request(dict(fields,csrf='wrong'))[0],403)
                self.assertEqual(request(dict(fields,draft='tiny'))[0],422)
                self.assertEqual(request(dict(fields,**{'draft[]':'array'},draft=''))[0],422)
                # Create remains a normal POST and never starts research.
                status,_,page=request(dict(csrf=csrf,action='research_project',name='Existing project',brief='Does local evidence support this choice?'))
                self.assertEqual(status,200);self.assertIn('Start research',page)
                cookie='; '.join(f'{c.name}={c.value}' for c in jar)
                def consume_stream():
                    req=urllib.request.Request(base+'?view=research',data=urllib.parse.urlencode(fields).encode(),headers={'Cookie':cookie})
                    with urllib.request.urlopen(req,timeout=8) as response:
                        self.assertIn('text/event-stream',response.headers['Content-Type']);first=''
                        while 'event: delta' not in first:first+=response.readline().decode()
                        received=time.monotonic();rest=response.read().decode();return received,first+rest
                with concurrent.futures.ThreadPoolExecutor() as pool:
                    started=time.monotonic();work=pool.submit(consume_stream)
                    while not marker.exists() and time.monotonic()-started<4:time.sleep(.02)
                    self.assertTrue(marker.exists())
                    before=time.monotonic();code,_,_=request(fields,cookie);self.assertEqual(code,409);self.assertLess(time.monotonic()-before,1.5,'Session lock held during stream')
                    code,_,body=request(dict(csrf=csrf,action='research_start',project_id='1'),cookie,query='?view=research&research_api=1');self.assertEqual(code,422);self.assertIn('Another AI request',body)
                    received,text=work.result();self.assertLess(received-started,1.5,'First delta was buffered until completion');self.assertIn('event: done',text);self.assertNotIn('event: error',text)
                code,_,body=request(dict(csrf=csrf,action='research_start',project_id='1'),query='?view=research&research_api=1');self.assertEqual(code,200);run=json.loads(body)['run']['id']
                self.assertEqual(request(dict(fields,draft='A second distinct question about local evidence?'))[0],409)
                code,_,_=request(dict(csrf=csrf,action='research_stop',run_id=run),query='?view=research&research_api=1');self.assertEqual(code,200)
                journal=json.loads((home/'brief-suggestions.json').read_text());self.assertEqual(len(journal),1);self.assertEqual(journal[0]['status'],'complete')
                fresh=urllib.request.build_opener();payload=urllib.parse.urlencode(fields).encode()
                try:fresh.open(urllib.request.Request(base+'?view=research',data=payload));self.fail('Missing session accepted')
                except urllib.error.HTTPError as error:self.assertEqual(error.code,403)
            finally:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=5)
