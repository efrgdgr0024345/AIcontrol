"""Host-authentication fixture: the router supplies trusted server identity.

The synthetic HTTPS flag and test cookie policy are local test fixtures only.
No production route accepts identity headers or a request-controlled username.
"""

import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
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
                self.assertNotIn("Sign in", page)
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
