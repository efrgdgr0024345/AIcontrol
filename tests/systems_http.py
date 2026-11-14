"""Systems requires owner access even when the legacy workspace uses public testing."""
import http.cookiejar, os, pathlib, re, socket, subprocess, tempfile, time, unittest, urllib.error, urllib.parse, urllib.request
ROOT=pathlib.Path(__file__).resolve().parent.parent
class SystemsAccess(unittest.TestCase):
 def test_public_testing_cannot_read_or_enroll(self):
  with tempfile.TemporaryDirectory(prefix='aicontrol-systems-http-') as home:
   php=os.environ.get('PHP_BINARY','php');env={**os.environ,'AICONTROL_PRIVATE_DIR':home+'/private','AICONTROL_LOCAL_DEV':'1','AICONTROL_PUBLIC_TESTING':'1','AICONTROL_PIN_VERIFIED':''}
   setup=subprocess.run([php,'bin/setup.php','--access-mode=local','--private-dir='+home+'/private','--public-root='+str(ROOT)],cwd=ROOT,env=env,capture_output=True,text=True);self.assertEqual(setup.returncode,0,setup.stderr)
   with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
   server=subprocess.Popen([php,'-S',f'127.0.0.1:{port}','bin/dev-router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
   client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));url=f'http://127.0.0.1:{port}/loader.php'
   try:
    for attempt in range(50):
     try:html=client.open(url+'?view=projects',timeout=5).read().decode();break
     except OSError:time.sleep(.05)
    csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1)
    systems=client.open(url+'?view=systems&system=1',timeout=5).read().decode();self.assertIn('Systems control requires PIN',systems);self.assertNotIn('id="system-script"',systems)
    data=urllib.parse.urlencode({'csrf':csrf,'action':'system_add','name':'Unauthorized','host':'127.0.0.1','port':'22','username':'aicontrol','access':'maintain'}).encode()
    with self.assertRaises(urllib.error.HTTPError) as failed:client.open(url+'?view=systems&systems_api=1',data=data,timeout=5)
    self.assertEqual(failed.exception.code,422);self.assertIn('requires PIN',failed.exception.read().decode());self.assertFalse(pathlib.Path(home+'/private/systems.sqlite').exists())
   finally:server.terminate();server.wait(timeout=5)
if __name__=='__main__':unittest.main()
