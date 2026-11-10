"""Actual one-file HTTP PIN enrollment/login; no provider or GitHub calls."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
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


class PinSmokeTest(unittest.TestCase):
    def test_first_visit_enrolls_second_visit_logs_in(self):
        with tempfile.TemporaryDirectory(prefix="aicontrol-pin-http-") as temporary:
            temporary = Path(temporary)
            public = temporary / "public"
            public.mkdir()
            shutil.copy(ROOT / "loader.php", public / "loader.php")
            sessions = temporary / "sessions"
            sessions.mkdir(mode=0o700)
            with socket.socket() as listener:
                listener.bind(("127.0.0.1", 0))
                port = listener.getsockname()[1]
            server = subprocess.Popen([PHP, "-d", f"session.save_path={sessions}", "-S", f"127.0.0.1:{port}", "-t", str(public)], env=dict(os.environ, AICONTROL_LOCAL_DEV="1"), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            def client():
                return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())
            owner = client()
            visitor = client()
            def request(browser=owner, query="", fields=None):
                data = None if fields is None else urllib.parse.urlencode(fields).encode()
                try:
                    response = browser.open(f"http://127.0.0.1:{port}/loader.php{query}", data=data, timeout=5)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    return response.status, response.headers, response.read().decode()
            def csrf(page):
                return re.search(r'name="pin_csrf" value="([a-f0-9]+)"', page).group(1)
            auth_directory = None
            pin = "731956"
            try:
                for _ in range(100):
                    try:
                        status, _, page = request()
                        break
                    except urllib.error.URLError:
                        time.sleep(0.05)
                else:
                    self.fail("PIN test server failed to start")
                self.assertEqual(status, 200)
                self.assertIn("Set your PIN", page)
                self.assertNotIn("Check GitHub", page)
                token = csrf(page)
                self.assertEqual(request(fields={"pin_action": "setup", "pin": pin, "pin_confirm": "000000", "pin_csrf": token})[0], 422)
                status, _, saved = request(fields={"pin_action": "setup", "pin": pin, "pin_confirm": pin, "pin_csrf": token})
                self.assertEqual(status, 200)
                self.assertIn("PIN saved", saved)
                self.assertNotIn("Check GitHub", saved)
                self.assertTrue(pin not in saved, "PIN reflected in response")
                marker = public / ".aicontrol-pin.php"
                pointer = json.loads(marker.read_text().split("\n", 1)[1])
                auth_directory = Path(pointer["directory"])
                auth_file = auth_directory / "auth.json"
                state = json.loads(auth_file.read_text())
                self.assertTrue(state["hash"] != pin and pin not in auth_file.read_text(), "PIN stored in plaintext")
                _, _, page = request()
                self.assertIn("Enter your PIN", page)
                token = csrf(page)
                self.assertEqual(request(fields={"pin_action": "login", "pin": pin, "pin_csrf": "wrong"})[0], 403)
                self.assertEqual(request(fields={"pin_action": "login", "pin": pin, "pin_csrf": token})[0], 303)
                _, _, installer = request()
                self.assertIn("Check GitHub", installer)
                installer_csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', installer).group(1)
                probe = request(fields={"action": "fixture_probe", "csrf": installer_csrf})[2]
                self.assertIn("Unknown installer operation", probe)
                self.assertNotIn("Request verification failed", probe)
                _, _, anonymous = request(visitor, query="?installer=1")
                self.assertIn("Enter your PIN", anonymous)
                self.assertNotIn("Check GitHub", anonymous)
                self.assertEqual(request(visitor, fields={"pin_action": "setup", "pin": "999999", "pin_confirm": "999999", "pin_csrf": csrf(anonymous)})[0], 409)
                _, _, menu = request(query="?pin=manage")
                self.assertIn("Lock panel", menu)
                _, _, locked = request(fields={"pin_action": "logout", "pin_csrf": csrf(menu)})
                self.assertIn("Enter your PIN", locked)
                self.assertNotIn("Check GitHub", request()[2])
                token = csrf(request(visitor)[2])
                for _ in range(5):
                    self.assertEqual(request(visitor, fields={"pin_action": "login", "pin": "000000", "pin_csrf": token})[0], 401)
                self.assertEqual(request(visitor, fields={"pin_action": "login", "pin": pin, "pin_csrf": token})[0], 429)
                state = json.loads(auth_file.read_text())
                state["window"] = int(time.time()) - 601
                auth_file.write_text(json.dumps(state))
                self.assertEqual(request(visitor, fields={"pin_action": "login", "pin": pin, "pin_csrf": token})[0], 303)
                self.assertIn("Check GitHub", request(visitor)[2])
                for session in sessions.glob("sess_*"):
                    raw = session.read_text()
                    if "pin_until|i:" in raw:
                        session.write_text(re.sub(r"pin_until\|i:\d+;", "pin_until|i:1;", raw))
                self.assertIn("Enter your PIN", request(visitor)[2])
                auth_file.unlink()
                status, _, damaged = request()
                self.assertEqual(status, 503)
                self.assertNotIn("Set your PIN", damaged)
                self.assertFalse(auth_file.exists())
            finally:
                server.terminate()
                server.wait(timeout=5)
                if auth_directory and auth_directory.is_dir():
                    shutil.rmtree(auth_directory)


if __name__ == "__main__":
    unittest.main()
