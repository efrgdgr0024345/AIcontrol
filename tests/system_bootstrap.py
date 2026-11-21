"""Execute generated bootstrap under a simulated root/account API and real temp files.
No real accounts, sudo policies or SSH configuration are changed."""
import base64, contextlib, io, json, os, pathlib, pwd, shlex, stat, subprocess, tempfile, types, unittest
from unittest.mock import patch
ROOT=pathlib.Path(__file__).resolve().parent.parent
PHP=os.environ.get('PHP_BINARY','php')
class Bootstrap(unittest.TestCase):
 def scripts(self,risk):
  r=subprocess.run([PHP,'tests/fixtures/setup_scripts.php',risk],cwd=ROOT,capture_output=True,text=True);self.assertEqual(r.returncode,0,r.stderr);return json.loads(r.stdout)
 def exercise(self,risk):
  data=self.scripts(risk)
  for command in [data['command1'],data['command2']]:
   self.assertNotIn('\n',command);r=subprocess.run(['/bin/sh','-n'],input=command,text=True,capture_output=True);self.assertEqual(r.returncode,0,r.stderr)
   tokens=shlex.split(command)
   for i,token in enumerate(tokens):
    if token=='/usr/bin/python3':self.assertEqual(tokens[i+1:i+3],['-I','-c'])
  with tempfile.TemporaryDirectory(prefix='aicontrol-bootstrap-') as temp:
   root=pathlib.Path(temp)/'root';username=data['enrollment']['username'];home=root/'homes'/username;accounts={};owners={}
   host=pathlib.Path(temp)/'host.pub';host.write_text(data['host_key']+' fixture\n');machine=pathlib.Path(temp)/'machine';machine.write_text('fixture-machine')
   realstat=os.stat;reallstat=os.lstat;realfstat=os.fstat
   def amended(s):
    values=list(s);uid,gid=owners.get((s.st_dev,s.st_ino),(0,0));values[4]=uid;values[5]=gid;return os.stat_result(values)
   def chown(path,uid,gid):
    s=realstat(path);owners[(s.st_dev,s.st_ino)]=(uid,gid)
   def fchown(fd,uid,gid):
    s=realfstat(fd);owners[(s.st_dev,s.st_ino)]=(uid,gid)
   def run(args,**kwargs):
    output='';code=0
    if args[0]=='useradd':
     self.assertNotIn(username,accounts);home.mkdir(mode=0o750);accounts[username]=types.SimpleNamespace(pw_uid=1001,pw_gid=1001,pw_dir=str(home),pw_shell='/bin/sh');chown(home,1001,1001)
    elif args[:2]==['getent','shadow']:output=username+':*:'
    elif args[:2]==['id','-Gn']:output=username
    elif args[0]=='sudo':
     if '-l' in args:code=0 if risk=='admin' else 1
     else:output='0\n'
    elif args[0]=='visudo':self.assertIn('NOPASSWD: ALL',pathlib.Path(args[-1]).read_text())
    elif args[:2]==['ip','-j']:output=json.dumps([{'addr_info':[{'local':'192.0.2.10'}]}])
    else:raise AssertionError(args)
    return subprocess.CompletedProcess(args,code,output,'')
   def execute(script):
    script=script.replace("pathlib.Path('/var/lib/aicontrol')",'pathlib.Path('+repr(str(root))+')').replace("pathlib.Path('/etc/sudoers.d')",'pathlib.Path('+repr(str(root/'sudoers'))+')').replace("'/etc/ssh/ssh_host_ed25519_key.pub'",repr(str(host))).replace("'/etc/machine-id'",repr(str(machine)))
    namespace={};stdout=io.StringIO();mask=os.umask(0o077)
    try:
     with patch('os.geteuid',return_value=0),patch('os.stat',side_effect=lambda *a,**k:amended(realstat(*a,**k))),patch('os.lstat',side_effect=lambda *a,**k:amended(reallstat(*a,**k))),patch('os.fstat',side_effect=lambda fd:amended(realfstat(fd))),patch('os.chown',side_effect=chown),patch('os.fchown',side_effect=fchown),patch('pwd.getpwnam',side_effect=lambda u:accounts[u]),patch('subprocess.run',side_effect=run),patch('shutil.which',side_effect=lambda tool:'/usr/bin/'+tool),patch.dict(os.environ,{'AICONTROL_SSH_CONNECTION':'198.51.100.1 40000 192.0.2.10 2222'}),patch('urllib.request.urlopen',side_effect=OSError('offline fixture')),contextlib.redirect_stdout(stdout):exec(compile(script,'generated-bootstrap','exec'),namespace)
    finally:
     if 'lock_fd' in namespace:os.close(namespace['lock_fd'])
     os.umask(mask)
    text=stdout.getvalue();return json.loads(text[text.index('{'):text.rindex('}')+1])
   first=execute(data['first']);again=execute(data['first']);self.assertEqual(first,again);self.assertEqual(first['risk_level'],risk);self.assertEqual(first['ssh_port'],2222);self.assertEqual(stat.S_IMODE(realstat(root).st_mode),0o755)
   receipt=execute(data['second']);keys=(home/'.ssh/authorized_keys').read_text();execute(data['second']);self.assertEqual((home/'.ssh/authorized_keys').read_text(),keys);self.assertEqual(receipt['sudo_granted'],risk=='admin');self.assertEqual(receipt['read_only_enforced'],risk=='readonly');self.assertEqual(stat.S_IMODE(realstat(home/'.ssh/authorized_keys').st_mode),0o640)
   if risk=='readonly':
    inspector=root/'inspectors'/(data['enrollment']['id']+'.py');env={**os.environ,'SSH_ORIGINAL_COMMAND':'aicontrol-inspect console_pwd','HOME':str(home)}
    self.assertIn('command="/usr/bin/python3 -I '+str(inspector)+'"',keys)
    self.assertTrue(inspector.read_text().startswith('#!/usr/bin/python3 -I\n'))
    # Emulate an old setup plus an unrestricted duplicate for the same public key.
    public=keys.split('ssh-ed25519 ',1)[1].split()[0];other=data['host_key']+' independent-fixture-key refers to ssh-ed25519 '+public
    (home/'.ssh/authorized_keys').write_text(keys.replace('/usr/bin/python3 -I ','/usr/bin/python3 ')+'ssh-ed25519 '+public+' stale-duplicate\n'+other+'\n')
    execute(data['second']);upgraded=(home/'.ssh/authorized_keys').read_text();self.assertEqual(upgraded.count(public),2);self.assertIn('command="/usr/bin/python3 -I ',upgraded);self.assertNotIn('stale-duplicate',upgraded);self.assertIn(other,upgraded)
    poison=home/'poison';poison.mkdir();marker=home/'import-hijack';(poison/'json.py').write_text('from pathlib import Path\nPath('+repr(str(marker))+').write_text("unsafe import")\nraise SystemExit(73)\n');env['PYTHONPATH']=str(poison)
    allowed=subprocess.run(['/usr/bin/python3','-I',str(inspector)],cwd=home,env=env,capture_output=True,text=True);self.assertEqual(allowed.returncode,0,allowed.stderr);self.assertEqual(allowed.stdout.strip(),str(home));self.assertFalse(marker.exists())
    denied=subprocess.run(['/usr/bin/python3','-I',str(inspector)],cwd=home,env={**env,'SSH_ORIGINAL_COMMAND':'touch /tmp/not-allowed'},capture_output=True,text=True);self.assertEqual(denied.returncode,126)
   target=home/'.ssh/authorized_keys';target.unlink();canary=pathlib.Path(temp)/'canary';canary.write_text('untouched');target.symlink_to(canary)
   with self.assertRaises(OSError):execute(data['second'])
   self.assertEqual(canary.read_text(),'untouched')
 def test_readonly(self):self.exercise('readonly')
 def test_account(self):self.exercise('account')
 def test_admin(self):self.exercise('admin')
 def test_command_ignores_hostile_working_directory_and_python_environment(self):
  # Run the real command generator with a harmless payload; never run account setup as root.
  r=subprocess.run([PHP,'-r',"require 'src/SystemRisk.php';require 'src/SystemBootstrap.php';echo AIcontrol\\SystemBootstrap::command(\"print('ISOLATED_BOOTSTRAP_OK')\");"],cwd=ROOT,capture_output=True,text=True);self.assertEqual(r.returncode,0,r.stderr)
  tokens=shlex.split(r.stdout);i=tokens.index('/usr/bin/python3');self.assertEqual(tokens[i+1:i+3],['-I','-c']);args=tokens[i:i+4]
  with tempfile.TemporaryDirectory(prefix='aicontrol-hostile-import-') as temporary:
   home=pathlib.Path(temporary);cwd=home/'cwd';inherited=home/'inherited';cwd.mkdir();inherited.mkdir();marker=home/'unsafe-import'
   poison='from pathlib import Path\nPath('+repr(str(marker))+').write_text("unsafe import")\nraise SystemExit(73)\n'
   (cwd/'base64.py').write_text(poison);(inherited/'base64.py').write_text(poison);(inherited/'sitecustomize.py').write_text(poison)
   for directory in [cwd,home]:
    with self.subTest(directory=directory.name):
     result=subprocess.run(args,cwd=directory,env={**os.environ,'PYTHONPATH':str(inherited),'PYTHONUSERBASE':str(home/'user-site'),'PYTHONHOME':str(home/'invalid-python-home')},capture_output=True,text=True)
     self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(result.stdout.strip(),'ISOLATED_BOOTSTRAP_OK');self.assertFalse(marker.exists())
   # A control proves the malicious fixture actually intercepts the old invocation.
   control=subprocess.run([args[0],'-c',args[-1]],cwd=cwd,env={**os.environ,'PYTHONPATH':''},capture_output=True,text=True);self.assertEqual(control.returncode,73);self.assertTrue(marker.exists())
if __name__=='__main__':unittest.main()
