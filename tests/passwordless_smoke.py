"""Host-authentication fixture: the router supplies trusted server identity.

The synthetic HTTPS flag and test cookie policy are local test fixtures only.
No production route accepts identity headers or a request-controlled username.
"""

import http.cookiejar
import concurrent.futures
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import socket
import sqlite3
import subprocess
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BINARY", "php")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class PasswordlessSmokeTest(unittest.TestCase):
    @unittest.skipUnless(os.name == "posix", "PHP multiworker regression requires a POSIX loopback fixture")
    def test_legacy_password_admission_is_atomic_across_eight_sessions(self):
        with tempfile.TemporaryDirectory(prefix="aicontrol-password-admission-") as directory:
            temporary=Path(directory);private=temporary/"private"
            password="disposable-owner-password-for-admission-test"
            environment=dict(os.environ,AICONTROL_PRIVATE_DIR=str(private),AICONTROL_LOCAL_DEV="1",PHP_CLI_SERVER_WORKERS="8")
            environment.pop("AICONTROL_PIN_VERIFIED",None);environment.pop("AICONTROL_PUBLIC_TESTING",None)
            subprocess.run([PHP,"bin/setup.php","--access-mode=password",f"--private-dir={private}",f"--public-root={ROOT}"],cwd=ROOT,env=environment,input=password,text=True,check=True,capture_output=True)
            router=temporary/"router.php"
            router.write_text("<?php\nheader('X-Fixture-Worker: '.getmypid());\nrequire "+json.dumps(str(ROOT/"bin/dev-router.php"))+";\n")
            with socket.socket() as listener:
                listener.bind(("127.0.0.1",0));port=listener.getsockname()[1]
            server=subprocess.Popen([PHP,"-S",f"127.0.0.1:{port}",str(router)],cwd=ROOT,env=environment,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
            url=f"http://127.0.0.1:{port}/loader.php"
            def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
            def request(browser,fields=None,query=""):
                data=None if fields is None else urllib.parse.urlencode(fields).encode()
                try:response=browser.open(url+query,data=data,timeout=15)
                except urllib.error.HTTPError as error:response=error
                with response:return response.status,response.headers,response.read().decode()
            def token(page):return re.search(r'name="csrf" value="([a-f0-9]+)"',page).group(1)
            def failures():
                with sqlite3.connect(private/"panel.sqlite") as database:return database.execute("SELECT COUNT(*) FROM login_failures").fetchone()[0]
            try:
                for _ in range(100):
                    try:
                        request(client());break
                    except urllib.error.URLError:time.sleep(.03)
                else:self.fail("Disposable multiworker password fixture did not start")
                browsers=[]
                for _ in range(8):
                    browser=client();_,_,page=request(browser);browsers.append((browser,token(page)))
                barrier=threading.Barrier(8)
                def concurrent_failure(item):
                    browser,csrf=item;barrier.wait(timeout=10)
                    status,headers,_=request(browser,{"action":"login","csrf":csrf,"password":"incorrect-synthetic-test-value"})
                    return status,headers.get("X-Fixture-Worker")
                with concurrent.futures.ThreadPoolExecutor(max_workers=8) as workers:results=list(workers.map(concurrent_failure,browsers))
                codes=[result[0] for result in results]
                self.assertGreaterEqual(len({result[1] for result in results}),2,"The concurrency regression did not reach independent PHP workers")
                self.assertEqual(codes.count(401),5,"More or fewer than five password checks passed atomic admission")
                self.assertEqual(codes.count(429),3,"Excess concurrent sessions did not receive the shared throttle")
                self.assertEqual(failures(),5,"Failed or pending attempts were not retained exactly once")
                with sqlite3.connect(private/"panel.sqlite") as database:
                    self.assertEqual(database.execute("SELECT COUNT(DISTINCT attempt_key) FROM login_failures").fetchone()[0],5)
                owner=client();_,_,page=request(owner);old_csrf=token(page)
                self.assertEqual(request(owner,{"action":"login","csrf":old_csrf,"password":password})[0],429,"A correct password unexpectedly bypassed the exhausted admission window")
                # Age only this disposable database, then exercise a normal owner
                # success and its own reservation release/session rotation.
                with sqlite3.connect(private/"panel.sqlite") as database:database.execute("UPDATE login_failures SET attempted_at=?",(int(time.time())-601,))
                status,_,_=request(owner,{"action":"login","csrf":old_csrf,"password":password})
                self.assertEqual(status,303);self.assertEqual(failures(),0,"Successful owner login left its own reservation charged")
                _,_,page=request(owner,query="?view=projects")
                self.assertTrue('name="action" value="project"' in page,"Successful owner login did not open the authenticated project form")
                self.assertTrue(token(page)!=old_csrf,"Successful owner login did not rotate its session CSRF")
                self.assertEqual(request(owner,{"action":"project","csrf":old_csrf,"name":"Rejected stale form","brief":"This must not create a project."})[0],403)
                # A successful reservation must not erase another session's
                # failures; legacy rows without keys must still count too.
                with sqlite3.connect(private/"panel.sqlite") as database:
                    database.executemany("INSERT INTO login_failures(client,attempted_at)VALUES(?,?)",[(hashlib.sha256(b'127.0.0.1').hexdigest(),int(time.time()))]*4)
                another=client();_,_,page=request(another)
                self.assertEqual(request(another,{"action":"login","csrf":token(page),"password":password})[0],303)
                self.assertEqual(failures(),4,"Successful admission erased unrelated legacy failure rows")
                wrong=client();_,_,page=request(wrong);csrf=token(page)
                self.assertEqual(request(wrong,{"action":"login","csrf":csrf,"password":"incorrect-synthetic-test-value"})[0],401)
                self.assertEqual(request(wrong,{"action":"login","csrf":csrf,"password":"incorrect-synthetic-test-value"})[0],429)
                self.assertEqual(failures(),5)
            finally:
                os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=5)

    def test_host_identity_required_on_every_request(self):
        with tempfile.TemporaryDirectory(prefix="aicontrol-host-auth-") as directory:
            temporary = Path(directory)
            private = temporary / "private"
            subprocess.run([PHP, "bin/setup.php", f"--private-dir={private}", f"--public-root={ROOT}"], cwd=ROOT, check=True, capture_output=True)
            config = json.loads((private / "config.json").read_text())
            self.assertEqual(config["access_mode"], "host")
            self.assertNotIn("password_hash", config)
            identity = temporary / "identity"
            identity.write_text("")
            router = temporary / "router.php"
            router.write_text("<?php\n$_SERVER['HTTPS']='on';\n$_SERVER['REMOTE_ADDR']='203.0.113.10';\n$_SERVER['REMOTE_USER']=trim(file_get_contents(getenv('TEST_IDENTITY_FILE')));\nif (isset($_GET['fixture_public_installer'])) { $_SERVER['SCRIPT_NAME']='/loader.php'; require getenv('TEST_SOURCE_ROOT').'/loader.php'; return; }\nif (isset($_GET['fixture_installer'])) { define('AICONTROL_TESTING_MODE', false); $_SERVER['SCRIPT_NAME']='/loader.php'; require getenv('TEST_SOURCE_ROOT').'/loader.php'; return; }\nrequire getenv('TEST_SOURCE_ROOT').'/bin/dev-router.php';\n")
            with socket.socket() as listener:
                listener.bind(("127.0.0.1", 0))
                port = listener.getsockname()[1]
            environment = dict(os.environ, AICONTROL_PRIVATE_DIR=str(private), TEST_IDENTITY_FILE=str(identity), TEST_SOURCE_ROOT=str(ROOT))
            environment.pop("AICONTROL_LOCAL_DEV", None)
            server = subprocess.Popen([PHP, "-d", f"session.save_path={private / 'sessions'}", "-S", f"127.0.0.1:{port}", str(router)], cwd=ROOT, env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            # Send synthetic Secure cookies over this loopback HTTP fixture only.
            policy = http.cookiejar.DefaultCookiePolicy(secure_protocols=("http", "https", "wss"))
            cookies = http.cookiejar.CookieJar(policy)
            client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies), NoRedirect())
            def request(fields=None, headers=None, query=""):
                data = None if fields is None else urllib.parse.urlencode(fields).encode()
                req = urllib.request.Request(f"http://127.0.0.1:{port}/loader.php{query}", data=data, headers=headers or {})
                try:
                    response = client.open(req, timeout=5)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    return response.status, response.headers, response.read().decode()
            try:
                for _ in range(100):
                    try:
                        status, _, _ = request()
                        break
                    except urllib.error.URLError:
                        time.sleep(0.05)
                else:
                    self.fail("Host authentication fixture did not start")
                self.assertEqual(status, 403)
                self.assertEqual(request(headers={"Remote-User": "forged", "X-Remote-User": "forged", "Authorization": "Basic Zm9yZ2VkOnBhc3N3b3Jk"})[0], 403)
                identity.write_text("hosting-user")
                status, headers, page = request()
                self.assertEqual(status, 200)
                self.assertIn("Overview", page)
                self.assertNotIn('type="password"', page)
                self.assertNotIn("<h1>Sign in</h1>", page)
                self.assertNotIn("Sign out", page)
                self.assertIn("secure", headers["Set-Cookie"].lower())
                _, _, page = request(query="?view=projects")
                token = re.search(r'name="csrf" value="([a-f0-9]+)"', page).group(1)
                self.assertEqual(request(fields={"action": "project", "name": "Host-protected project", "brief": "No app password", "csrf": "wrong"})[0], 403)
                self.assertEqual(request(fields={"action": "project", "name": "Host-protected project", "brief": "No app password", "csrf": token})[0], 303)
                self.assertIn("Host-protected project", request(query="?view=projects")[2])
                self.assertEqual(request(fields={"action": "install", "csrf": token})[0], 422)
                identity.write_text("")
                self.assertEqual(request()[0], 403, "existing app cookie cannot bypass removed host identity")
                identity.write_text("different-user")
                status, _, page = request(query="?view=projects")
                self.assertEqual(status, 200)
                new_token = re.search(r'name="csrf" value="([a-f0-9]+)"', page).group(1)
                self.assertNotEqual(new_token, token, "host identity change rotates application session")
                self.assertEqual(request(fields={"action": "project", "name": "Old session", "brief": "Denied", "csrf": token})[0], 403)
            finally:
                server.terminate()
                server.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
