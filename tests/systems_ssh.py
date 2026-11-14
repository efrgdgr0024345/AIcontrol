"""Real OpenSSH client/keyscan against a local SSH protocol server; synthetic model decisions."""
import base64, hashlib, io, json, logging, os, pathlib, socket, sqlite3, subprocess, tempfile, threading, time, unittest
import paramiko
from cryptography.hazmat.primitives.asymmetric import ed25519
from cryptography.hazmat.primitives import serialization
logging.getLogger('paramiko').setLevel(logging.CRITICAL)
ROOT=pathlib.Path(__file__).resolve().parent.parent
PHP=os.environ.get('PHP_BINARY','php')

class Server(paramiko.ServerInterface):
    def __init__(self, harness): self.harness=harness; self.event=threading.Event(); self.command=None
    def get_allowed_auths(self, username): return 'publickey'
    def check_auth_publickey(self, username, key):
        db=sqlite3.connect(self.harness.home+'/systems.sqlite')
        keys=[row[0].split()[1] for row in db.execute('SELECT public_key FROM systems WHERE enabled=1')]; db.close()
        return paramiko.AUTH_SUCCESSFUL if username=='aicontrol' and key.get_base64() in keys else paramiko.AUTH_FAILED
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
            result=subprocess.run(server.command,shell=True,executable='/bin/sh',capture_output=True,timeout=20)
            channel.sendall(result.stdout);channel.sendall_stderr(result.stderr);channel.send_exit_status(result.returncode);channel.shutdown_write();channel.close()
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
        self.driver('revoke',id=sid);self.assertNotEqual(self.driver('verify',ok=False,id=sid).returncode,0)
        self.assertFalse(list(pathlib.Path(self.home).glob('.ssh-key-*')));self.assertFalse(list(pathlib.Path(self.home).glob('.ssh-host-*')))

if __name__=='__main__': unittest.main()
