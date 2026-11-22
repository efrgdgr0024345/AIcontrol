"""Disposable normal PIN/CSRF/update HTTP flow; no live host or provider calls."""
import contextlib
from email import policy
from email.parser import BytesParser
import html
import http.server
import importlib.util
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import threading
import unittest
from unittest import mock
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "bin" / "site-delivery.py"
PIN = "582147"
PIN_CSRF = "synthetic-pin-csrf-value"
CSRF = "synthetic-owner-csrf-value"
COOKIE = "synthetic-memory-only-cookie"
OLD = "a" * 40
NEW = "b" * 40


class QuietServer(http.server.ThreadingHTTPServer):
    daemon_threads = True

    def handle_error(self, request, client_address):
        pass


@contextlib.contextmanager
def website(**settings):
    retained = {"format": "AIcontrol retained test report v1", "report": {"id": 1, "title": "Retained client failures", "expected_release_sha": NEW}, "summary": {"outcome": "failed", "server_observed_release_sha": OLD, "client_claimed_tested_shas": [NEW], "counts": {"passed": 1, "failed": 1}}, "events": [{"origin": "owner_reported", "kind": "check.result", "fields": {"check_id": "retained_failure", "outcome": "failed", "summary": "The failed assertion remains available for the next assistant review.", "output": "Useful failure detail. Authorization: Bearer sk-fixture-report-secret-123456789 pin=" + PIN, "details": {"cookie": COOKIE, "csrf": CSRF}}}], "integrity": {"verified": True, "scope": "Stored report chain, not external attestation."}}
    state = {"installed": OLD, "selected": NEW, "requests": [], "login_attempts": 0, "install_attempts": 0, "imports": [], "read_ids": [], "reports": {1: retained}, **settings}

    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self, *_):
            pass

        def send(self, body="", status=200, content_type="text/html", headers=None):
            data = body.encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", content_type + "; charset=utf-8")
            self.send_header("Content-Length", str(len(data)))
            for key, value in (headers or {}).items():
                self.send_header(key, value)
            self.end_headers()
            self.wfile.write(data)

        def login_page(self, setup=False):
            padding = "x" * (5 * 1024 * 1024 if state.get("oversize") else 450000 if state.get("padded_login") else 0)
            return '<html><head><style>' + padding + '</style></head><body><form method="post"><input name="pin_action" value="' + ("setup" if setup else "login") + '"><input name="pin_csrf" value="' + PIN_CSRF + '"><input name="pin" type="password"></form></body></html>'

        def authorized(self):
            return "aicontrol_pin=" + COOKIE in self.headers.get("Cookie", "")

        def read_fields(self):
            raw = self.rfile.read(int(self.headers.get("Content-Length", "0")))
            if self.headers.get("Content-Type", "").startswith("multipart/form-data"):
                message = BytesParser(policy=policy.default).parsebytes(("Content-Type: " + self.headers["Content-Type"] + "\r\nMIME-Version: 1.0\r\n\r\n").encode() + raw)
                return {part.get_param("name", header="Content-Disposition"): part.get_payload(decode=True).decode("utf-8") for part in message.iter_parts()}
            return {key: value[-1] for key, value in urllib.parse.parse_qs(raw.decode()).items()}

        def installer_page(self, selected=False):
            install = '<form method="post"><input name="action" value="install"><input name="csrf" value="' + CSRF + '"><input name="sha" value="' + state["selected"] + '"><button' + (' disabled' if state.get("checks_pending") else '') + '>Install this checked commit</button></form>' if selected else ""
            return '<form method="post"><input name="action" value="check"><input name="csrf" value="' + CSRF + '"></form>' + install

        def admin_page(self):
            technical = html.escape(json.dumps({"installed_release": state["installed"]}))
            return '<div class="app-shell"><form method="post"><input name="action" value="vault_save"><input name="csrf" value="' + CSRF + '"></form><textarea id="admin-technical-report">' + technical + '</textarea></div>'

        def do_GET(self):
            q = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query)
            state["requests"].append(("GET", q, None))
            if state.get("redirect"):
                return self.send(status=302, headers={"Location": state["redirect"]})
            if not self.authorized():
                return self.send(self.login_page(state.get("setup", False)))
            if "update_api" in q:
                if state.get("status_login_html"):
                    return self.send(self.login_page())
                return self.send(json.dumps({"state": "up_to_date", "installed_sha": state["installed"]}), content_type="application/json")
            if "installer" in q:
                return self.send(self.installer_page())
            view = q.get("view", ["overview"])[0]
            if view == "admin":
                return self.send(self.admin_page())
            if state.get("view_login") == view:
                return self.send(self.login_page())
            return self.send('<div class="app-shell"><h1>' + html.escape(view) + '</h1></div>')

        def do_POST(self):
            q = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query)
            fields = self.read_fields()
            state["requests"].append(("POST", q, fields.get("action") or fields.get("pin_action")))
            if "pin_action" in fields:
                state["login_attempts"] += 1
                if fields.get("pin_action") != "login" or fields.get("pin_csrf") != PIN_CSRF or fields.get("pin") != PIN or state.get("reject_pin"):
                    return self.send(self.login_page(), status=401)
                return self.send(status=303, headers={"Location": "/loader.php", "Set-Cookie": "aicontrol_pin=" + COOKIE + "; Path=/loader.php; HttpOnly; SameSite=Strict"})
            if not self.authorized() or fields.get("csrf") != CSRF:
                return self.send("Rejected", status=403)
            if fields.get("action") == "check":
                return self.send(self.installer_page(True))
            if fields.get("action") == "install":
                state["install_attempts"] += 1
                if fields.get("sha") != state["selected"]:
                    return self.send("Wrong commit", status=422)
                if not state.get("no_activation"):
                    state["installed"] = state["selected"]
                if state.get("disconnect_install"):
                    self.close_connection = True
                    self.connection.shutdown(socket.SHUT_RDWR)
                    self.connection.close()
                    return
                return self.send("Installed checked release")
            if fields.get("action") == "admin_test_import":
                packet = json.loads(fields.get("packet_json", "null"))
                state["imports"].append({"packet": packet, "content_type": self.headers.get("Content-Type"), "content_length": int(self.headers.get("Content-Length", "0"))})
                return self.send(json.dumps({"report": {"format": "AIcontrol retained test report v1", "report": {"id": len(state["imports"])}, "summary": {"server_observed_release_sha": state["installed"], "outcome": "failed" if any(check["outcome"] == "failed" for check in packet["checks"]) else "passed_as_reported"}}, "location": "/loader.php?view=admin"}), content_type="application/json")
            if fields.get("action") == "admin_test_read":
                state["read_ids"].append(fields.get("report_id"))
                if state.get("reject_read_csrf"):
                    return self.send("Expired CSRF", status=403)
                if state.get("read_login_html"):
                    return self.send(self.login_page())
                report = state["reports"].get(int(fields.get("report_id", "0")))
                if report is None:
                    return self.send(json.dumps({"error": "Choose a retained report."}), status=422, content_type="application/json")
                if state.get("oversize_report"):
                    report = {**report, "large_output": "x" * (512 * 1024)}
                if state.get("wrong_report_identity"):
                    report = {**report, "report": {**report["report"], "id": 99}}
                return self.send(json.dumps({"report": report}), content_type="application/json")
            return self.send("Unsupported action", status=422)

    server = QuietServer(("127.0.0.1", 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield "http://127.0.0.1:" + str(server.server_port) + "/loader.php", state
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=5)


class SiteDelivery(unittest.TestCase):
    def test_helper_redacts_opaque_environment_values_without_token_prefixes(self):
        spec = importlib.util.spec_from_file_location("aicontrol_site_delivery_redaction_test", SCRIPT)
        helper = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(helper)
        protected = {"GH_TOKEN": "opaque fixture gamma 73.7/r", "GITHUB_TOKEN": "opaque fixture delta 41.9/s", "AICONTROL_OPENAI_API_KEY": "opaque fixture epsilon 12.5/t", "openai_api_key": "opaque fixture zeta 06.3/u", "AICONTROL_SITE_PIN": PIN}
        values = list(protected.values())
        original = {"summary": "Observed values: " + " | ".join(values), "details": {"trace": ["Nested observation: " + value for value in values], "safe": "Useful failure fact remains."}, "installed_sha": NEW}
        with mock.patch.dict(os.environ, protected):
            cleaned = helper.redact(original)
        serialized = json.dumps(cleaned)
        # Fixed assertion messages do not reflect a protected value on failure.
        self.assertTrue(all(value not in serialized for value in values), "An opaque protected environment value remained in helper output")
        self.assertTrue(serialized.count("[redacted]") >= len(values) * 2, "Expected recursive redaction markers were missing")
        self.assertEqual(cleaned["details"]["safe"], "Useful failure fact remains.")
        self.assertEqual(cleaned["installed_sha"], NEW)

    def cli(self, url, *command, pin=PIN, allow=True):
        env = {**os.environ}
        if pin is None:
            env.pop("AICONTROL_SITE_PIN", None)
        else:
            env["AICONTROL_SITE_PIN"] = pin
        args = [sys.executable, "-B", str(SCRIPT), "--url", url]
        if allow:
            args.append("--allow-loopback")
        args += list(command)
        result = subprocess.run(args, cwd=ROOT, env=env, capture_output=True, text=True, timeout=20)
        self.assertEqual(result.stderr, "")
        self.assertNotIn(PIN, result.stdout)
        self.assertNotIn(PIN_CSRF, result.stdout)
        self.assertNotIn(CSRF, result.stdout)
        self.assertNotIn(COOKIE, result.stdout)
        self.assertNotIn("<html", result.stdout)
        self.assertNotIn("Traceback", result.stdout)
        return result.returncode, json.loads(result.stdout)

    def test_inspect_uses_existing_pin_and_full_padded_html(self):
        with website(padded_login=True) as (url, state):
            code, report = self.cli(url, "inspect")
            self.assertEqual(code, 0)
            self.assertEqual(report["installed_sha"], OLD)
            self.assertEqual(report["status"], "completed")
            self.assertEqual(state["login_attempts"], 1)
            self.assertEqual(state["install_attempts"], 0)

    def test_install_exact_checked_sha_then_confirms_admin_and_loader(self):
        with website() as (url, state):
            code, report = self.cli(url, "install", "--sha", NEW)
            self.assertEqual(code, 0)
            self.assertEqual(report["installed_sha"], NEW)
            self.assertEqual(report["install_outcome"], "confirmed")
            self.assertEqual(state["install_attempts"], 1)
            self.assertEqual([r[2] for r in state["requests"] if r[0] == "POST"], ["login", "check", "install"])

    def test_wrong_checked_sha_never_sends_install(self):
        with website(selected="c" * 40) as (url, state):
            code, report = self.cli(url, "install", "--sha", NEW)
            self.assertEqual(code, 2)
            self.assertEqual(report["error"], "checked_release_sha_mismatch")
            self.assertEqual(state["install_attempts"], 0)
            self.assertEqual(state["installed"], OLD)

    def test_required_check_pending_never_sends_install(self):
        with website(checks_pending=True) as (url, state):
            code, report = self.cli(url, "install", "--sha", NEW)
            self.assertEqual(code, 2)
            self.assertEqual(report["error"], "required_checks_or_selection_not_ready")
            self.assertEqual(state["install_attempts"], 0)

    def test_already_installed_release_does_not_post_install_or_check(self):
        with website(installed=NEW) as (url, state):
            code, report = self.cli(url, "install", "--sha", NEW)
            self.assertEqual(code, 0)
            self.assertEqual(report["install_outcome"], "already_installed")
            self.assertEqual([r[2] for r in state["requests"] if r[0] == "POST"], ["login"])

    def test_lost_install_response_reconciles_without_retry(self):
        with website(disconnect_install=True) as (url, state):
            code, report = self.cli(url, "install", "--sha", NEW)
            self.assertEqual(code, 0)
            self.assertEqual(report["installed_sha"], NEW)
            self.assertEqual(report["install_outcome"], "reconciled")
            self.assertEqual(state["install_attempts"], 1)

    def test_unknown_install_outcome_is_retained_without_retry(self):
        with website(disconnect_install=True, no_activation=True) as (url, state):
            code, report = self.cli(url, "install", "--sha", NEW)
            self.assertEqual(code, 3)
            self.assertEqual(report["status"], "uncertain")
            self.assertEqual(report["error"], "install_outcome_uncertain")
            self.assertEqual(state["install_attempts"], 1)

    def test_smoke_only_posts_login_and_never_runs_models_or_mutations(self):
        with website() as (url, state):
            code, report = self.cli(url, "smoke")
            self.assertEqual(code, 0)
            self.assertEqual(report["model_calls"], 0)
            self.assertEqual(report["project_mutations"], 0)
            self.assertEqual([r[2] for r in state["requests"] if r[0] == "POST"], ["login"])
            self.assertTrue(all(any(r[1].get("view") == [view] for r in state["requests"] if r[0] == "GET") for view in ("overview", "research", "nasa", "admin")))

    def test_login_html_200_is_not_installed_sha_evidence(self):
        with website(status_login_html=True) as (url, state):
            code, report = self.cli(url, "inspect")
            self.assertEqual(code, 2)
            self.assertEqual(report["error"], "installed_status_not_authenticated_json")
            self.assertNotIn("installed_sha", report)

    def test_login_page_in_smoke_does_not_pass_as_owner_view(self):
        with website(view_login="research") as (url, state):
            code, report = self.cli(url, "smoke")
            self.assertEqual(code, 1)
            self.assertEqual(report["error"], "hosted_view_check_failed")
            self.assertEqual(report["checks"][-1]["outcome"], "failed")

    def test_missing_pin_blocks_without_network_and_wrong_pin_is_not_retried(self):
        with website() as (url, state):
            code, report = self.cli(url, "inspect", pin=None)
            self.assertEqual(code, 2)
            self.assertEqual(report["error"], "site_pin_binding_missing")
            self.assertEqual(state["requests"], [])
        with website(reject_pin=True) as (url, state):
            code, report = self.cli(url, "inspect")
            self.assertEqual(code, 2)
            self.assertEqual(report["error"], "site_pin_login_rejected")
            self.assertEqual(state["login_attempts"], 1)

    def test_setup_is_refused_and_http_needs_explicit_loopback_flag(self):
        with website(setup=True) as (url, state):
            code, report = self.cli(url, "inspect")
            self.assertEqual(code, 2)
            self.assertEqual(report["error"], "existing_pin_not_enrolled")
            self.assertEqual(state["login_attempts"], 0)
        with website() as (url, state):
            code, report = self.cli(url, "inspect", allow=False)
            self.assertEqual(code, 2)
            self.assertEqual(report["error"], "https_required")
            self.assertEqual(state["requests"], [])

    def test_cross_origin_redirect_is_blocked_before_credentials_or_external_request(self):
        with website() as (sink_url, sink):
            with website(redirect=sink_url) as (url, state):
                code, report = self.cli(url, "inspect")
                self.assertEqual(code, 2)
                self.assertEqual(report["error"], "cross_origin_redirect_blocked")
                self.assertEqual(state["login_attempts"], 0)
                self.assertEqual(sink["requests"], [])

    def test_oversize_html_fails_instead_of_silently_missing_the_login_form(self):
        with website(oversize=True) as (url, state):
            code, report = self.cli(url, "inspect")
            self.assertEqual(code, 1)
            self.assertEqual(report["error"], "response_exceeds_4mib_limit")
            self.assertEqual(state["login_attempts"], 0)

    def test_upload_compacts_output_preserves_failed_checks_and_uses_owner_csrf_multipart(self):
        packet = {"format": "AIcontrol test import v1", "title": "Disposable QA import", "expected_release_sha": NEW, "checks": [{"id": "failure_example", "title": "Retain a useful failed observation", "scope": "hosted", "outcome": "failed", "summary": "The failed fact remains visible after large output is shortened.", "output": "Useful first line\n" + "bulk text " * 5000 + "\nUseful final line\nAuthorization: Bearer sk-fixture-secret-key-123456789\npin=" + PIN, "claimed_tested_sha": NEW}], "finished": True}
        with tempfile.TemporaryDirectory() as directory:
            reportfile = Path(directory) / "report.json"
            reportfile.write_text(json.dumps(packet), encoding="utf-8")
            with website() as (url, state):
                code, report = self.cli(url, "upload", "--reportfile", str(reportfile))
                self.assertEqual(code, 0)
                self.assertEqual(report["report_id"], 1)
                self.assertEqual(report["retained_outcome"], "failed")
                self.assertEqual(report["server_observed_release_sha"], OLD)
                imported = state["imports"][0]
                self.assertTrue(imported["content_type"].startswith("multipart/form-data; boundary="))
                self.assertLessEqual(imported["content_length"], 16384)
                self.assertLessEqual(report["packet_bytes"], 12000)
                check = imported["packet"]["checks"][0]
                self.assertEqual(check["outcome"], "failed")
                self.assertEqual(check["summary"], packet["checks"][0]["summary"])
                self.assertEqual(check["claimed_tested_sha"], NEW)
                self.assertGreater(check["details"]["output_omitted_bytes"], 0)
                self.assertIn("Useful first line", check["output"])
                self.assertIn("Useful final line", check["output"])
                self.assertNotIn(PIN, json.dumps(imported))
                self.assertNotIn("sk-fixture-secret", json.dumps(imported))
                self.assertNotIn("source", imported["packet"])

    def test_invalid_import_and_secret_argv_mistakes_are_not_reflected(self):
        with tempfile.TemporaryDirectory() as directory:
            reportfile = Path(directory) / "bad.json"
            reportfile.write_text(json.dumps({"format": "AIcontrol test import v1", "title": "Bad authority", "source": "server", "checks": []}))
            with website() as (url, state):
                code, report = self.cli(url, "upload", "--reportfile", str(reportfile))
                self.assertEqual(code, 2)
                self.assertEqual(report["error"], "invalid_import_packet")
                self.assertEqual(state["requests"], [])
                code, report = self.cli(url, "inspect", "--pin", PIN)
                self.assertEqual(code, 2)
                self.assertEqual(report["error"], "invalid_arguments")
                self.assertEqual(state["requests"], [])

    def test_report_reads_requested_retained_failures_and_redacts_without_reclassifying(self):
        with website() as (url, state):
            code, result = self.cli(url, "report", "--id", "1")
            self.assertEqual(code, 0)
            self.assertEqual(result["report_id"], 1)
            self.assertTrue(result["client_redaction_applied"])
            report = result["report"]
            self.assertEqual(report["report"]["expected_release_sha"], NEW)
            self.assertEqual(report["summary"]["server_observed_release_sha"], OLD)
            self.assertEqual(report["summary"]["counts"]["failed"], 1)
            self.assertEqual(report["events"][0]["origin"], "owner_reported")
            self.assertEqual(report["events"][0]["fields"]["outcome"], "failed")
            self.assertIn("Useful failure detail", report["events"][0]["fields"]["output"])
            self.assertIn("[redacted]", report["events"][0]["fields"]["output"])
            self.assertNotIn("sk-fixture-report-secret", json.dumps(result))
            self.assertEqual(report["events"][0]["fields"]["details"]["cookie"], "[redacted]")
            self.assertEqual(state["read_ids"], ["1"])
            self.assertEqual([r[2] for r in state["requests"] if r[0] == "POST"], ["login", "admin_test_read"])
            self.assertEqual(state["imports"], [])

    def test_report_uses_normal_owner_login_and_rejects_expired_csrf(self):
        with website(reject_pin=True) as (url, state):
            code, result = self.cli(url, "report", "--id", "1")
            self.assertEqual(code, 2)
            self.assertEqual(result["error"], "site_pin_login_rejected")
            self.assertEqual(state["read_ids"], [])
        with website(reject_read_csrf=True) as (url, state):
            code, result = self.cli(url, "report", "--id", "1")
            self.assertEqual(code, 2)
            self.assertEqual(result["error"], "admin_report_read_not_authenticated_json")
            self.assertNotIn("report", result)
            self.assertEqual(state["read_ids"], ["1"])

    def test_report_login_html_and_wrong_identity_are_never_returned_as_a_report(self):
        with website(read_login_html=True) as (url, state):
            code, result = self.cli(url, "report", "--id", "1")
            self.assertEqual(code, 2)
            self.assertEqual(result["error"], "admin_report_read_not_authenticated_json")
            self.assertNotIn("report", result)
        with website(wrong_report_identity=True) as (url, state):
            code, result = self.cli(url, "report", "--id", "1")
            self.assertEqual(code, 1)
            self.assertEqual(result["error"], "admin_report_read_identity_mismatch")
            self.assertNotIn("report", result)

    def test_report_has_an_explicit_size_bound_and_validates_id_before_network(self):
        with website(oversize_report=True) as (url, state):
            code, result = self.cli(url, "report", "--id", "1")
            self.assertEqual(code, 1)
            self.assertEqual(result["error"], "report_response_exceeds_512k_limit")
            self.assertNotIn("report", result)
        with website() as (url, state):
            code, result = self.cli(url, "report", "--id", "1 OR 1=1")
            self.assertEqual(code, 2)
            self.assertEqual(result["error"], "invalid_report_id")
            self.assertEqual(state["requests"], [])


if __name__ == "__main__":
    unittest.main()
