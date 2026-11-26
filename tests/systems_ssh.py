"""Real OpenSSH client/keyscan against a local SSH protocol server; synthetic model decisions."""
import base64, hashlib, io, json, logging, os, pathlib, socket, sqlite3, subprocess, tempfile, threading, time, unittest
import paramiko
from cryptography.hazmat.primitives.asymmetric import ed25519
from cryptography.hazmat.primitives import serialization
logging.getLogger('paramiko').setLevel(logging.CRITICAL)
ROOT=pathlib.Path(__file__).resolve().parent.parent
PHP=os.environ.get('PHP_BINARY','php')

class Server(paramiko.ServerInterface):
    def __init__(self, harness): self.harness=harness; self.event=threading.Event(); self.command=None;self.risk='legacy'
    def get_allowed_auths(self, username): return 'publickey'
    def get_banner(self): return 'OpenSSH protocol welcome fixture\n', 'en'
    def check_auth_publickey(self, username, key):
        db=sqlite3.connect(self.harness.home+'/systems.sqlite')
        rows=list(db.execute('SELECT public_key,username,risk_level FROM systems WHERE enabled=1'));db.close()
        for public,user,risk in rows:
            if username==user and key.get_base64()==public.split()[1]:self.risk=risk;return paramiko.AUTH_SUCCESSFUL
        return paramiko.AUTH_FAILED
    def check_channel_request(self, kind, chanid): return paramiko.OPEN_SUCCEEDED if kind=='session' else paramiko.OPEN_FAILED_ADMINISTRATIVELY_PROHIBITED
    def check_channel_exec_request(self, channel, command): self.command=command.decode(); self.event.set(); return True

class SshIntegration(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='aicontrol-real-ssh-'); cls.home=cls.temp.name
        cls.env={**os.environ,'AICONTROL_PRIVATE_DIR':cls.home}
        key=ed25519.Ed25519PrivateKey.generate().private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.OpenSSH,serialization.NoEncryption())
        cls.hostkey=paramiko.Ed25519Key.from_private_key(io.StringIO(key.decode()))
        cls.fingerprint='SHA256:'+base64.b64encode(hashlib.sha256(cls.hostkey.asbytes()).digest()).decode().rstrip('=')
        cls.socket=socket.socket();cls.socket.bind(('127.0.0.1',0));cls.port=cls.socket.getsockname()[1];cls.socket.listen(20);cls.socket.settimeout(.2);cls.done=False
        threading.Thread(target=cls.listen,daemon=True).start()
    @classmethod
    def listen(cls):
        while not cls.done:
            try: connection,_=cls.socket.accept()
            except socket.timeout: continue
            except OSError: return
            threading.Thread(target=cls.handle,args=(connection,),daemon=True).start()
    @classmethod
    def handle(cls,connection):
        transport=paramiko.Transport(connection);transport.add_server_key(cls.hostkey);server=Server(cls)
        try:
            transport.start_server(server=server);channel=transport.accept(8)
            if channel is None or not server.event.wait(5): return
            # The fixture executes real bounded commands in the disposable local workspace.
            if server.risk=='readonly':
                result=subprocess.run(['python3','-I',cls.inspector],env={**os.environ,'SSH_ORIGINAL_COMMAND':server.command,'HOME':cls.home},cwd=cls.home,capture_output=True,timeout=20)
            else:result=subprocess.run(server.command,shell=True,executable='/bin/sh',capture_output=True,timeout=20)
            channel.sendall(result.stdout);channel.sendall_stderr(result.stderr);channel.send_exit_status(result.returncode);channel.shutdown_write();channel.close()
            # Let OpenSSH consume the exit status and close the connection before
            # tearing down the transport; an immediate close can reset unread data.
            deadline=time.monotonic()+2
            while transport.is_active() and time.monotonic()<deadline:time.sleep(.01)
        except (EOFError,OSError,paramiko.SSHException,subprocess.TimeoutExpired): pass
        finally: transport.close()
    @classmethod
    def driver(cls,action,ok=True,**fields):
        p=subprocess.run([PHP,'tests/fixtures/systems_driver.php'],input=json.dumps({'action':action,**fields}),text=True,capture_output=True,cwd=ROOT,env=cls.env)
        if ok and p.returncode: raise AssertionError(p.stderr)
        if not ok: return p
        return json.loads(p.stdout)
    @classmethod
    def tearDownClass(cls): cls.done=True;cls.socket.close();cls.temp.cleanup()
    def test_real_ssh_and_execution(self):
        bad=self.driver('add',port=self.port,fingerprint='SHA256:'+'a'*43)
        failed=self.driver('verify',ok=False,id=bad['id']);self.assertNotEqual(failed.returncode,0);self.assertIn('identity',failed.stderr)
        system=self.driver('add',port=self.port,fingerprint=self.fingerprint,access='maintain');sid=system['id']
        verified=self.driver('verify',id=sid);self.assertTrue(verified['verified_at']);self.assertIn('Linux',verified['facts'])
        run=self.driver('start',id=sid,task='Write a disposable integration marker and verify it')['id'];marker=self.home+'/executed-marker'
        disabled=subprocess.run([PHP,'-d','disable_functions=proc_open','tests/fixtures/systems_driver.php'],input=json.dumps({'action':'step','id':sid,'run':run,'decision':{'tool':'resources'}}),text=True,capture_output=True,cwd=ROOT,env=self.env)
        self.assertNotEqual(disabled.returncode,0);self.assertIn('SSH execution is unavailable',disabled.stderr)
        snapshot=self.driver('step',id=sid,run=run,decision={'tool':'shell','command':"printf 'executed over SSH' > '"+marker+"'; cat '"+marker+"'; printf 'stderr marker' >&2"})
        self.assertEqual(pathlib.Path(marker).read_text(),'executed over SSH');command=snapshot['commands'][0]
        self.assertEqual(command['stdout'],'executed over SSH');self.assertEqual(command['stderr'],'stderr marker');self.assertEqual(command['exit_code'],0)
        snapshot=self.driver('step',id=sid,run=run,decision={'tool':'shell','command':"head -c 20000 /dev/zero | tr '\\000' x; exit 7"})
        self.assertEqual(snapshot['commands'][-1]['exit_code'],7);self.assertTrue(snapshot['commands'][-1]['truncated']);self.assertLessEqual(len(snapshot['commands'][-1]['stdout']),16384)
        snapshot=self.driver('step',id=sid,run=run,decision={'tool':'finish','summary':'Verified actual SSH execution; the second command returned exit 7.'});self.assertEqual(snapshot['run']['status'],'complete')
        timeout_run=self.driver('start',id=sid,task='Exercise the remote timeout bound')['id']
        timeout_result=self.driver('step',id=sid,run=timeout_run,decision={'tool':'shell','command':'sleep 30'})
        self.assertEqual(timeout_result['run']['status'],'outcome_uncertain');self.assertEqual(timeout_result['commands'][-1]['exit_code'],124)
        stop_run=self.driver('start',id=sid,task='Exercise Stop while SSH is active')['id'];results=[]
        worker=threading.Thread(target=lambda:results.append(self.driver('step',id=sid,run=stop_run,decision={'tool':'shell','command':'sleep 4; printf late'})))
        worker.start();deadline=time.monotonic()+5
        while time.monotonic()<deadline:
            db=sqlite3.connect(self.home+'/systems.sqlite');pending=db.execute("SELECT COUNT(*) FROM system_commands WHERE run_id=? AND status='running'",[stop_run]).fetchone()[0];db.close()
            if pending: break
            time.sleep(.02)
        self.assertTrue(pending);self.driver('stop',run=stop_run);worker.join(5);self.assertFalse(worker.is_alive());self.assertEqual(results[0]['run']['status'],'stopped');self.assertEqual(results[0]['commands'][-1]['status'],'outcome_uncertain')
        terminal=self.driver('console_connect',id=sid)
        self.assertIn('OpenSSH protocol welcome fixture', terminal['welcome'])
        command=self.driver('console_command',terminal=terminal['id'],command="cd '"+self.home+"' && printf 'café'")
        self.assertEqual(command['command']['stdout'],'café');self.assertEqual(command['cwd'],self.home)
        command=self.driver('console_command',terminal=terminal['id'],command='pwd');self.assertEqual(command['command']['stdout'].strip(),'[private]')
        listing=pathlib.Path(self.home)/'listing'
        listing.mkdir()
        (listing/'.profile').write_text('fixture shell settings\n')
        self.driver('console_command',terminal=terminal['id'],command='cd listing')
        command=self.driver('console_command',terminal=terminal['id'],command='ls')
        self.assertEqual(command['command']['exit_code'],0)
        self.assertEqual(command['command']['stdout'],'')
        self.assertIn('Run ls -la', command['entry']['hint'])
        command=self.driver('console_command',terminal=terminal['id'],command='ls -la')
        self.assertIn('.profile',command['entry']['output'])
        self.assertEqual(command['entry']['hint'],'')
        (listing/'visible.txt').touch()
        command=self.driver('console_command',terminal=terminal['id'],command='ls')
        self.assertEqual(command['entry']['output'],'visible.txt')
        self.assertEqual(command['entry']['hint'],'')
        command=self.driver('console_command',terminal=terminal['id'],command='ls missing-file')
        self.assertTrue(command['entry']['failed'])
        self.assertIn('missing-file',command['entry']['output'])
        self.assertEqual(command['entry']['hint'],'')
        self.driver('console_disconnect',terminal=terminal['id']);self.assertNotEqual(self.driver('console_command',ok=False,terminal=terminal['id'],command='pwd').returncode,0)
        db=sqlite3.connect(self.home+'/systems.sqlite');db.execute("UPDATE systems SET risk_level='readonly',access='inspect',home_dir=? WHERE id=?",[self.home,sid]);db.commit();db.close()
        type(self).inspector=self.home+'/readonly-inspector.py';pathlib.Path(self.inspector).write_text(self.driver('inspector')['script'])
        terminal=self.driver('console_connect',id=sid);self.assertEqual(self.driver('console_command',terminal=terminal['id'],command='pwd')['command']['stdout'].strip(),'[private]')
        self.assertNotEqual(self.driver('console_command',ok=False,terminal=terminal['id'],command='touch /tmp/forbidden').returncode,0)
        bypass=self.driver('raw_probe',id=sid,command='touch '+self.home+'/must-not-exist');self.assertEqual(bypass['exit_code'],126,bypass);self.assertFalse(pathlib.Path(self.home+'/must-not-exist').exists())
        self.driver('console_disconnect',terminal=terminal['id'])
        self.driver('revoke',id=sid);self.assertNotEqual(self.driver('verify',ok=False,id=sid).returncode,0)
        self.assertFalse(list(pathlib.Path(self.home).glob('.ssh-key-*')));self.assertFalse(list(pathlib.Path(self.home).glob('.ssh-host-*')))

if __name__=='__main__': unittest.main()
