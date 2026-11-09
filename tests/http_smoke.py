"""Exercise the real PHP HTTP entry point using only Python's standard library.

Run: PHP_BINARY=php python3 -B -m unittest -v tests/http_smoke.py
"""

import html
import http.cookiejar
import os
from pathlib import Path
import re
import secrets
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


class PanelHttpTest(unittest.TestCase):
    def test_panel_flow_and_boundaries(self):
        with tempfile.TemporaryDirectory(prefix="aicontrol-http-") as directory:
            private = Path(directory) / "private"
            password = secrets.token_urlsafe(32)
            setup = [PHP, "bin/setup.php", "--access-mode=password", f"--private-dir={private}", f"--public-root={ROOT}"]
            result = subprocess.run(setup, cwd=ROOT, input=password, text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            duplicate = subprocess.run(setup, cwd=ROOT, input=password, text=True, capture_output=True)
            self.assertNotEqual(duplicate.returncode, 0, "setup must not overwrite state")
            self.assertNotIn(password, (private / "config.json").read_text())
            with socket.socket() as listener:
                listener.bind(("127.0.0.1", 0))
                port = listener.getsockname()[1]
            environment = dict(os.environ, AICONTROL_PRIVATE_DIR=str(private), AICONTROL_LOCAL_DEV="1")
            server = subprocess.Popen(
                [PHP, "-S", f"127.0.0.1:{port}", "bin/dev-router.php"],
                cwd=ROOT, env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            try:
                base = f"http://127.0.0.1:{port}"
                cookies = http.cookiejar.CookieJar()
                client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies), NoRedirect())

                def request(path="/loader.php", fields=None, method=None):
                    data = None if fields is None else urllib.parse.urlencode(fields).encode()
                    req = urllib.request.Request(base + path, data=data, method=method)
                    try:
                        response = client.open(req, timeout=5)
                    except urllib.error.HTTPError as error:
                        response = error
                    with response:
                        return response.status, response.headers, response.read().decode()

                for _ in range(100):
                    try:
                        if request("/health")[0] == 200:
                            break
                    except urllib.error.URLError:
                        time.sleep(0.05)
                else:
                    self.fail("PHP server did not become ready")

                status, headers, page = request()
                self.assertEqual(status, 200)
                self.assertIn("Sign in", page)
                self.assertIn("no-store", headers["Cache-Control"])
                self.assertIn("HttpOnly", headers["Set-Cookie"])
                self.assertIn("SameSite=Strict", headers["Set-Cookie"])
                self.assertIn("frame-ancestors 'none'", headers["Content-Security-Policy"])
                csrf = lambda body: re.search(r'name="csrf" value="([a-f0-9]+)"', body).group(1)
                token = csrf(page)
                self.assertEqual(request(fields={"action": "login", "password": password, "csrf": "wrong"})[0], 403)
                self.assertEqual(request(fields={"action": "project", "name": "Denied", "brief": "Denied", "csrf": token})[0], 401)
                self.assertEqual(request(fields={"action": "login", "password": "incorrect", "csrf": token})[0], 401)
                self.assertEqual(request(fields={"action": "login", "password": password, "csrf": token})[0], 303)
                status, _, page = request("/loader.php?view=projects")
                token = csrf(page)
                name = '<script>alert("unsafe")</script>'
                self.assertEqual(request(fields={"action": "project", "name": name, "brief": "Acceptance brief", "csrf": token})[0], 303)
                self.assertEqual(request(fields={"action": "task", "project_id": "1", "title": "Example task", "criteria": "Evidence required", "csrf": token})[0], 303)
                self.assertEqual(request(fields={"action": "task", "project_id": "999", "title": "Bad task", "criteria": "Missing project", "csrf": token})[0], 422)
                _, _, page = request("/loader.php?view=projects")
                self.assertIn(html.escape(name, quote=True), page)
                self.assertNotIn(name, page)
                self.assertIn("Example task", page)
                self.assertIn("Draft · not dispatched", page)
                self.assertNotIn("Bad task", page)
                self.assertNotIn(password, page)
                filtered = request("/loader.php?view=projects&q=no-such-task")[2]
                self.assertIn("No matching tasks", filtered)
                self.assertNotIn('id="task-1"', filtered)
                self.assertIn("No agent runs yet", request("/loader.php?view=runs")[2])
                _, _, history = request("/loader.php?view=history")
                self.assertIn("project.proposed", history)
                self.assertIn("task.proposed", history)
                self.assertIn("view=projects#task-1", history)
                _, _, systems = request("/loader.php?view=systems")
                for system in ["OpenAI", "GitHub Actions", "Hosting"]:
                    self.assertIn(system, systems)
                self.assertIn("Not configured", systems)
                self.assertNotIn("Configured · not verified", systems)
                self.assertEqual(request("/assets/panel.css")[0], 200)
                for path in ["/vault", "/install", "/approve", "/src/PanelStore.php", "/bin/setup.php", "/tests/run.php"]:
                    with self.subTest(path=path):
                        self.assertEqual(request(path)[0], 404)
                self.assertEqual(request(fields={"action": "install", "csrf": token})[0], 422)
                self.assertEqual(request(method="DELETE")[0], 405)
                self.assertEqual(request(method="HEAD")[2], "")
                self.assertEqual(request(fields={"action": "logout", "csrf": token})[0], 303)
                _, _, page = request("/loader.php?view=projects")
                self.assertNotIn("Example task", page)
                self.assertIn("Sign in", page)
                token = csrf(page)
                for _ in range(4):
                    self.assertEqual(request(fields={"action": "login", "password": "incorrect", "csrf": token})[0], 401)
                self.assertEqual(request(fields={"action": "login", "password": password, "csrf": token})[0], 429)
                config = private / "config.json"
                config.rename(private / "config.saved")
                self.assertEqual(request("/health")[0], 503)
                (private / "config.saved").rename(config)
                self.assertEqual(request("/health")[0], 200)
            finally:
                server.terminate()
                server.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
