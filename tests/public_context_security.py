"""Synthetic local checks: public demo actors cannot receive owner Vault context."""
import contextlib
import http.cookiejar
import json
import os
import pathlib
import re
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
SHARED = 'AUDIT-OWNER-OPTED-IN-NOTE'
SECRETS = ['AUDIT-UNSHARED-NOTE', 'AUDIT-VAULT-API-KEY', 'AUDIT-MANAGED-SSH-KEY', 'synthetic-public-context-key']

ROUTER = r'''<?php
namespace AIcontrol {
 function curl_init($url){if($url!=='https://api.openai.com/v1/responses')throw new \RuntimeException('Unexpected fixture endpoint');return new \stdClass();}
 function curl_setopt_array($client,$options){$GLOBALS['public_context_options']=$options;return true;}
 function curl_exec($client){
  $options=$GLOBALS['public_context_options'];$body=json_decode($options[CURLOPT_POSTFIELDS],true,64,JSON_THROW_ON_ERROR);
  file_put_contents(getenv('PUBLIC_CONTEXT_CAPTURE'),json_encode($body,JSON_THROW_ON_ERROR)."\n",FILE_APPEND);
  $text='Synthetic local provider result; no code, external search or live provider was used.';
  if(str_contains($body['instructions'],'Dream phase'))$text=json_encode(['core_claims'=>[
    ['claim'=>'A disposable recovery path can be checked','confidence'=>'low','uncertainty'=>'Synthetic fixture only','verify'=>'Record a bounded observation','priority'=>'normal'],
    ['claim'=>'Recovery scope needs a concrete configuration','confidence'=>'low','uncertainty'=>'Hosted behavior remains unknown','verify'=>'Record the tested build','priority'=>'normal']],
    'competing_angles'=>[],'least_confident'=>'No live host was tested','background_assumptions'=>[]],JSON_THROW_ON_ERROR);
  $response=['status'=>'completed','usage'=>['input_tokens'=>10,'output_tokens'=>20],'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>$text]]]]];
  $options[CURLOPT_WRITEFUNCTION]($client,json_encode($response,JSON_THROW_ON_ERROR));return true;
 }
 function curl_getinfo($client,$option){return 200;}
 function curl_close($client){}
}
namespace {$_SERVER['SCRIPT_NAME']='/loader.php';require 'APP_PATH';}
'''


class PublicContextSecurity(unittest.TestCase):
    @contextlib.contextmanager
    def private_workspace(self, stored='local', public=False, pin=False):
        with tempfile.TemporaryDirectory(prefix='aicontrol-public-context-') as temporary:
            base = pathlib.Path(temporary)
            home, public_root = base / 'private', base / 'public'
            public_root.mkdir()
            env = {**os.environ, 'AICONTROL_PRIVATE_DIR': str(home), 'AICONTROL_LOCAL_DEV': '1',
                   'AICONTROL_PIN_VERIFIED': '1' if pin else '', 'AICONTROL_PUBLIC_TESTING': '1' if public else '',
                   'AICONTROL_OPENAI_API_KEY': 'synthetic-public-context-key', 'openai_api_key': '',
                   'PUBLIC_CONTEXT_CAPTURE': str(base / 'requests.jsonl')}
            setup_mode = 'password' if stored == 'password' else 'local'
            result = subprocess.run([PHP, 'bin/setup.php', '--access-mode=' + setup_mode,
                                     '--private-dir=' + str(home), '--public-root=' + str(public_root)],
                                    cwd=ROOT, env=env, input='disposable-context-fixture-password',
                                    text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            config_file = home / 'config.json'
            config = json.loads(config_file.read_text())
            config['access_mode'] = stored
            config_file.write_text(json.dumps(config))
            seed = r"""require 'src/PanelStore.php';require 'src/Vault.php';require 'src/AgentRunner.php';require 'src/Research.php';
                $directory=getenv('AICONTROL_PRIVATE_DIR');$p=new AIcontrol\PanelStore($directory.'/panel.sqlite');
                $p->createProject('Synthetic context task','A bounded proposed task.');$p->createTask(1,'Review fixture','No execution or deployment is requested.');
                $r=new AIcontrol\Research($directory);$r->create('Synthetic context research','A bounded recovery question with no external requests.');
                $v=new AIcontrol\Vault($directory);$v->change('shared_note','note','AUDIT-OWNER-OPTED-IN-NOTE',true);
                $v->change('unshared_note','note','AUDIT-UNSHARED-NOTE',false);$v->change('openai_api_key','key','AUDIT-VAULT-API-KEY',false);
                $v->saveSystemKey(1,'AUDIT-MANAGED-SSH-KEY');"""
            result = subprocess.run([PHP, '-r', seed], cwd=ROOT, env=env, text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            yield base, home, env

    def contexts(self, env):
        code = r"""require 'src/Vault.php';require 'src/AgentRunner.php';$v=new AIcontrol\Vault(getenv('AICONTROL_PRIVATE_DIR'));
            echo json_encode(['vault'=>$v->context(),'agent'=>AIcontrol\AgentRunner::sharedContext()],JSON_THROW_ON_ERROR);"""
        result = subprocess.run([PHP, '-r', code], cwd=ROOT, env=env, text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_effective_profile_precedence_and_owner_notes(self):
        for stored, public, pin, allowed in [('testing', False, False, False), ('local', True, False, False),
                                             ('password', True, False, False), ('testing', True, True, True),
                                             ('testing', False, True, True), ('local', False, False, True),
                                             ('password', False, False, True)]:
            with self.subTest(stored=stored, public=public, pin=pin):
                with self.private_workspace(stored, public, pin) as (_, home, env):
                    protected = {name: (home / name).read_bytes() for name in ['config.json', 'vault.key', 'vault.json']}
                    result = self.contexts(env)
                    for value in result.values():
                        self.assertEqual(SHARED in value, allowed)
                        for secret in SECRETS:
                            self.assertNotIn(secret, value)
                    for name, value in protected.items():
                        self.assertEqual((home / name).read_bytes(), value)

    def test_indeterminate_profile_fails_closed_even_with_pin_flag(self):
        for fault in ['missing', 'invalid_json', 'unknown_mode', 'unsafe_permissions']:
            with self.subTest(fault=fault):
                with self.private_workspace('local', False, True) as (_, home, env):
                    config_file = home / 'config.json'
                    if fault == 'missing':
                        config_file.unlink()
                    elif fault == 'invalid_json':
                        config_file.write_text('{')
                    elif fault == 'unknown_mode':
                        config = json.loads(config_file.read_text()); config['access_mode'] = 'unknown'
                        config_file.write_text(json.dumps(config))
                    else:
                        config_file.chmod(0o644)
                    self.assertEqual(self.contexts(env), {'vault': '', 'agent': ''})

    def test_legacy_mislabelled_keys_and_non_boolean_opt_in_never_share(self):
        with self.private_workspace() as (_, home, env):
            code = r"""$d=getenv('AICONTROL_PRIVATE_DIR');$key=file_get_contents($d.'/vault.key');$raw=base64_decode(file_get_contents($d.'/vault.json'),true);
                $items=json_decode(sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$key),true,32,JSON_THROW_ON_ERROR);
                $items['openai_api_key']['type']='note';$items['openai_api_key']['share']=true;$items['ssh_system_1']['type']='note';$items['ssh_system_1']['share']=true;
                $items['false_flag']=['type'=>'note','value'=>'AUDIT-FALSE-OPT-IN','share'=>'false'];$nonce=random_bytes(24);
                file_put_contents($d.'/vault.json',base64_encode($nonce.sodium_crypto_secretbox(json_encode($items,JSON_THROW_ON_ERROR),$nonce,$key)));"""
            changed = subprocess.run([PHP, '-r', code], cwd=ROOT, env=env, capture_output=True, text=True)
            self.assertEqual(changed.returncode, 0, changed.stderr)
            for value in self.contexts(env).values():
                self.assertIn(SHARED, value)
                for secret in SECRETS + ['AUDIT-FALSE-OPT-IN']:
                    self.assertNotIn(secret, value)

    def assert_http_context(self, stored, public=False, pin=False, allowed=False):
        with self.private_workspace(stored, public, pin) as (base, home, env):
            router = base / 'router.php'
            router.write_text(ROUTER.replace('APP_PATH', str(ROOT / 'app.php')))
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
            server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', str(router)], cwd=ROOT, env=env,
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            url = f'http://127.0.0.1:{port}/loader.php'
            def request(query='', fields=None):
                try:
                    with client.open(url + query, data=urllib.parse.urlencode(fields).encode() if fields is not None else None,
                                     timeout=10) as response:
                        return response.status, response.read().decode()
                except urllib.error.HTTPError as failure:
                    return failure.code, failure.read().decode()
            try:
                for _ in range(100):
                    try:
                        code, page = request('?view=research&project=1'); break
                    except OSError:
                        time.sleep(.03)
                self.assertEqual(code, 200)
                csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', page).group(1)
                self.assertEqual(request('?view=admin')[0], 200 if pin else 403)
                code, page = request('?view=research', dict(action='research_chat', csrf=csrf, project_id='1',
                                                          message='Inspect the supplied shared context.'))
                self.assertEqual(code, 200, page[:400])
                self.assertEqual(request('?view=projects', dict(action='run_agent', csrf=csrf, task_id='1'))[0], 200)
                code, raw = request('?research_api=1', dict(action='research_start', csrf=csrf, project_id='1'))
                self.assertEqual(code, 200, raw[:400]); run = json.loads(raw)['run']['id']
                code, raw = request('?research_api=1', dict(action='research_step', csrf=csrf, run_id=str(run)))
                self.assertEqual(code, 200, raw[:400])
                requests = [json.loads(line) for line in pathlib.Path(env['PUBLIC_CONTEXT_CAPTURE']).read_text().splitlines()]
                self.assertEqual(len(requests), 3)
                for body in requests:
                    encoded = json.dumps(body)
                    self.assertEqual(SHARED in encoded, allowed)
                    for secret in SECRETS:
                        self.assertNotIn(secret, encoded)
            finally:
                server.terminate(); server.wait(timeout=5)

    def test_http_stored_public_testing_omits_notes_from_chat_agent_and_phase(self):
        self.assert_http_context('testing')

    def test_http_public_flag_overrides_password_profile_for_all_provider_paths(self):
        self.assert_http_context('password', public=True)

    def test_http_pin_owner_precedence_preserves_opted_in_context(self):
        self.assert_http_context('testing', public=True, pin=True, allowed=True)


if __name__ == '__main__':
    unittest.main()
