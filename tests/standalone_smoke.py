"""One public file, private release, and real HTTP routing in a subdirectory.

Network archive/check integration is separately validated against GitHub.
"""

import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import http.cookiejar
import re

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BINARY", "php")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class StandaloneSmokeTest(unittest.TestCase):
    def test_one_file_bootstrap_and_private_release(self):
        with tempfile.TemporaryDirectory(prefix="aicontrol-one-file-") as temporary:
            temporary = Path(temporary)
            public = temporary / "public"
            control = public / "control"
            control.mkdir(parents=True)
            shutil.copy(ROOT / "loader.php", control / "loader.php")
            sessions = temporary / "sessions"
            sessions.mkdir(mode=0o700)
            home = temporary / "private"
            home.mkdir(mode=0o700)
            releases = home / "releases"
            releases.mkdir(mode=0o700)
            sha = "a" * 40
            release = releases / sha
            release.mkdir(mode=0o700)
            manifest = json.loads((ROOT / "release-manifest.json").read_text())
            for name in [*manifest["files"], "release-manifest.json"]:
                destination = release / name
                destination.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy(ROOT / name, destination)
            shutil.copy(ROOT / "loader.php", release / ".bootstrap-loader.php")
            password = secrets.token_urlsafe(32)
            subprocess.run([PHP, "bin/setup.php", "--access-mode=testing", f"--private-dir={home / 'runtime'}", f"--public-root={public}"], cwd=ROOT, input=password, text=True, check=True, capture_output=True)
            def private_json(path, value, prefix=""):
                path.write_text(prefix + json.dumps(value))
                path.chmod(0o600)

            import hashlib
            state = {"sha": sha, "manifest_hash": hashlib.sha256((ROOT / "release-manifest.json").read_bytes()).hexdigest()}
            private_json(home / "current.json", state)
            with socket.socket() as listener:
                listener.bind(("127.0.0.1", 0))
                port = listener.getsockname()[1]
            environment = dict(os.environ, AICONTROL_LOCAL_DEV="1")
            server = subprocess.Popen([PHP, "-d", f"session.save_path={sessions}", "-S", f"127.0.0.1:{port}", "-t", str(public)], cwd=ROOT, env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            cookies = http.cookiejar.CookieJar()
            client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies), NoRedirect())
            def request(query="", fields=None):
                data = None if fields is None else urllib.parse.urlencode(fields).encode()
                try:
                    result = client.open(f"http://127.0.0.1:{port}/control/loader.php{query}", data=data, timeout=5)
                except urllib.error.HTTPError as error:
                    result = error
                with result:
                    return result.status, result.headers, result.read().decode()

            try:
                for _ in range(100):
                    try:
                        status, _, page = request()
                        break
                    except urllib.error.URLError:
                        time.sleep(0.05)
                else:
                    self.fail("Standalone server did not start")
                self.assertEqual(status, 200)
                self.assertIn("Set your PIN", page)
                self.assertNotIn("Check GitHub", page)
                pin_csrf = re.search(r'name="pin_csrf" value="([a-f0-9]+)"', page).group(1)
                status, _, page = request(fields={"pin_action": "setup", "pin": "123456", "pin_confirm": "123456", "pin_csrf": pin_csrf})
                self.assertEqual(status, 200)
                self.assertIn("PIN saved", page)
                self.assertNotIn("Check GitHub", page)
                status, _, page = request()
                self.assertIn("Enter your PIN", page)
                pin_csrf = re.search(r'name="pin_csrf" value="([a-f0-9]+)"', page).group(1)
                self.assertEqual(request(fields={"pin_action": "login", "pin": "123456", "pin_csrf": pin_csrf})[0], 303)
                status, _, page = request()
                self.assertIn("Check GitHub", page)
                self.assertNotIn('name="home"', page)
                self.assertNotIn('name="roots"', page)
                self.assertNotIn('name="ref"', page)
                self.assertNotIn('type="password"', page)
                self.assertNotIn("Panel password", page)
                private_json(control / ".aicontrol-location.php", {"home": str(home), "roots": [str(public)]}, "<?php exit; ?>\n")
                status, _, page = request()
                self.assertEqual(status, 200)
                self.assertIn("Overview", page)
                self.assertNotIn('type="password"', page)
                self.assertIn('href="/control/loader.php?asset=panel.css"', page)
                self.assertEqual(request("?asset=panel.css")[0], 200)
                self.assertEqual(json.loads(request("?health=1")[2])["scope"], "metadata_panel_only")
                self.assertIn("Check GitHub", request("?installer=1")[2])
                self.assertNotIn('type="password"', request("?installer=1")[2])
                self.assertEqual(set(p.name for p in control.iterdir()), {"loader.php", ".aicontrol-location.php", ".aicontrol-pin.php"})
                app = release / "app.php"
                original = app.read_bytes()
                app.write_bytes(original + b"modified")
                self.assertEqual(request()[0], 503)
                app.write_bytes(original)
                self.assertEqual(request()[0], 200)
                private_json(home / "journal.json", {"phase": "activating", "previous": state})
                private_json(home / "current.json", {"sha": "b" * 40, "manifest_hash": "c" * 64})
                self.assertEqual(request()[0], 200)
                self.assertEqual(json.loads((home / "journal.json").read_text())["phase"], "recovered")
                import base64
                recovered = []
                for receipt_file in (home / "receipts").glob("*.json"):
                    receipt = json.loads(receipt_file.read_text())
                    payload = json.loads(base64.b64decode(receipt["payload"]))
                    if payload["event"] == "update.recovered":
                        recovered.append((payload["receipt_id"], receipt))
                self.assertEqual(len(recovered), 1)
                self.assertEqual(json.loads(request("?receipt=" + recovered[0][0])[2]), recovered[0][1])
                (home / "runtime/config.json").rename(home / "runtime/config.saved")
                self.assertEqual(request()[0], 503)
                self.assertFalse((home / "runtime/config.json").exists())
                (home / "runtime/config.saved").rename(home / "runtime/config.json")
                status, _, public_identity = request("?identity=1")
                self.assertEqual(status, 200)
                identity = json.loads(public_identity)
                self.assertEqual(identity["algorithm"], "Ed25519")
                self.assertNotIn("private_key", identity)
                self.assertEqual(json.loads(request("?identity=1")[2]), identity)
                key_path = home / "signing/identity.json"
                secret = json.loads(key_path.read_text())["private_key"]
                self.assertTrue(secret not in public_identity, "private key leaked")
                key_path.unlink()
                self.assertEqual(request()[0], 503)
                self.assertFalse(key_path.exists(), "lost signing key must not be regenerated")
            finally:
                server.terminate()
                server.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
