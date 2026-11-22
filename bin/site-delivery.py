#!/usr/bin/env python3
"""Use the existing owner PIN/forms to inspect, install and verify one website.

No credentials, cookies, CSRF values, HTML bodies or tracebacks are written to
output. The site PIN comes only from AICONTROL_SITE_PIN. No alternate site
authentication or remote command channel is created.
"""
from __future__ import annotations

import argparse
import datetime as dt
import http.cookiejar
from html.parser import HTMLParser
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

DEFAULT_URL = "https://easyai.com.au/aiadmin/loader.php"
MAX_BODY = 4 * 1024 * 1024
MAX_IMPORT = 12000
MAX_INPUT_REPORT = 512 * 1024
SHA = re.compile(r"^[a-f0-9]{40}$")
CHECK_ID = re.compile(r"^[a-z][a-z0-9_.-]{0,79}$")


class DeliveryError(Exception):
    def __init__(self, code: str, *, blocked: bool = False):
        self.code, self.blocked = code, blocked
        super().__init__(code)


class Arguments(argparse.ArgumentParser):
    def error(self, message):
        # argparse normally reflects arbitrary argv, which may contain a secret
        # mistakenly supplied instead of an environment binding.
        raise DeliveryError("invalid_arguments", blocked=True)


def origin(url: str):
    p = urllib.parse.urlsplit(url)
    try:
        port = p.port or (443 if p.scheme == "https" else 80)
    except ValueError:
        raise DeliveryError("invalid_target_url", blocked=True) from None
    return p.scheme.lower(), (p.hostname or "").lower(), port


def target_url(url: str, allow_loopback: bool) -> str:
    p = urllib.parse.urlsplit(url)
    if p.username is not None or p.password is not None or p.query or p.fragment or not re.fullmatch(r"/[A-Za-z0-9_./-]+\.php", p.path or ""):
        raise DeliveryError("invalid_target_url", blocked=True)
    scheme, host, _ = origin(url)
    if not host:
        raise DeliveryError("invalid_target_url", blocked=True)
    loopback = host == "localhost"
    try:
        loopback = loopback or ipaddress.ip_address(host).is_loopback
    except ValueError:
        pass
    if scheme != "https" and not (scheme == "http" and allow_loopback and loopback):
        raise DeliveryError("https_required", blocked=True)
    return urllib.parse.urlunsplit((p.scheme, p.netloc, p.path, "", ""))


class SameOrigin(urllib.request.HTTPRedirectHandler):
    def __init__(self, expected):
        self.expected = expected

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        p = urllib.parse.urlsplit(newurl)
        if p.username is not None or p.password is not None or origin(newurl) != self.expected:
            raise DeliveryError("cross_origin_redirect_blocked", blocked=True)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class Page(HTMLParser):
    def __init__(self, body: str):
        super().__init__(convert_charrefs=True)
        self.forms, self.current, self.technical = [], None, ""
        self._technical = False
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "form":
            self.current = {"fields": {}, "disabled": False}
            self.forms.append(self.current)
        if tag == "input" and self.current is not None and a.get("name"):
            self.current["fields"][a["name"]] = a.get("value", "")
        if tag == "button" and self.current is not None and "disabled" in a:
            self.current["disabled"] = True
        if tag == "textarea" and a.get("id") == "admin-technical-report":
            self._technical = True

    def handle_endtag(self, tag):
        if tag == "form":
            self.current = None
        if tag == "textarea":
            self._technical = False

    def handle_data(self, data):
        if self._technical:
            self.technical += data

    def form(self, field: str, value: str):
        return next((f for f in self.forms if f["fields"].get(field) == value), None)

    def pin_gate(self):
        return self.form("pin_action", "login") is not None or self.form("pin_action", "setup") is not None


class Site:
    def __init__(self, url: str, allow_loopback=False, timeout=45):
        self.url = target_url(url, allow_loopback)
        self.expected_origin = origin(self.url)
        self.timeout = timeout
        self.cookies = http.cookiejar.CookieJar()  # never saved to disk
        proxy = urllib.request.ProxyHandler({}) if self.expected_origin[0] == "http" else urllib.request.ProxyHandler()
        self.http = urllib.request.build_opener(proxy, urllib.request.HTTPSHandler(context=ssl.create_default_context()), urllib.request.HTTPCookieProcessor(self.cookies), SameOrigin(self.expected_origin))
        self.checks = []

    def observe(self, check_id: str, title: str, outcome: str, summary: str, **details):
        row = {"id": check_id, "title": title, "scope": "hosted", "outcome": outcome, "summary": summary}
        if isinstance(details.get("installed_sha"), str) and SHA.fullmatch(details["installed_sha"]):
            row["claimed_tested_sha"] = details["installed_sha"]
        if details:
            row["details"] = details
        self.checks.append(row)

    def request(self, query=None, fields=None, *, multipart=False, body_limit=MAX_BODY):
        url = self.url + ("?" + urllib.parse.urlencode(query) if query else "")
        headers = {"User-Agent": "AIcontrol-Site-Delivery/1", "Accept": "text/html, application/json", "Accept-Encoding": "identity", "Cache-Control": "no-cache"}
        body = None
        if fields is not None:
            if multipart:
                boundary = "aicontrol_" + secrets.token_hex(16)
                chunks = []
                for name, value in fields.items():
                    if not re.fullmatch(r"[a-z_]+", name):
                        raise DeliveryError("invalid_form_field", blocked=True)
                    chunks.append(("--" + boundary + '\r\nContent-Disposition: form-data; name="' + name + '"\r\n\r\n').encode() + str(value).encode("utf-8") + b"\r\n")
                body = b"".join(chunks) + ("--" + boundary + "--\r\n").encode()
                headers["Content-Type"] = "multipart/form-data; boundary=" + boundary
                if len(body) > 16384:
                    raise DeliveryError("import_form_too_large", blocked=True)
            else:
                body = urllib.parse.urlencode(fields).encode("utf-8")
                headers["Content-Type"] = "application/x-www-form-urlencoded"
        request = urllib.request.Request(url, data=body, headers=headers)
        try:
            try:
                response = self.http.open(request, timeout=self.timeout)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                if origin(response.geturl()) != self.expected_origin:
                    raise DeliveryError("cross_origin_response_blocked", blocked=True)
                content_type = response.headers.get_content_type()
                data = response.read(body_limit + 1)
                if len(data) > body_limit:
                    raise DeliveryError("report_response_exceeds_512k_limit" if body_limit == MAX_INPUT_REPORT else "response_exceeds_4mib_limit")
                return response.status, content_type, data.decode("utf-8", "replace")
        except DeliveryError:
            raise
        except (urllib.error.URLError, TimeoutError, OSError, ValueError):
            raise DeliveryError("network_or_tls_request_failed") from None

    def login(self):
        pin = os.environ.get("AICONTROL_SITE_PIN", "")
        if not pin:
            raise DeliveryError("site_pin_binding_missing", blocked=True)
        if not re.fullmatch(r"[0-9]{6,12}", pin):
            raise DeliveryError("site_pin_binding_invalid", blocked=True)
        status, content_type, body = self.request()
        if status != 200 or content_type != "text/html":
            raise DeliveryError("pin_login_page_unavailable", blocked=True)
        page = Page(body)
        if page.form("pin_action", "setup"):
            raise DeliveryError("existing_pin_not_enrolled", blocked=True)
        form = page.form("pin_action", "login")
        if form is None or not form["fields"].get("pin_csrf"):
            raise DeliveryError("existing_pin_login_form_missing", blocked=True)
        status, content_type, body = self.request(fields={"pin_action": "login", "pin_csrf": form["fields"]["pin_csrf"], "pin": pin})
        del pin
        if status in (401, 403, 429) or content_type != "text/html" or Page(body).pin_gate():
            raise DeliveryError("site_pin_login_rejected", blocked=True)
        if status != 200:
            raise DeliveryError("site_pin_login_unconfirmed", blocked=True)
        self.observe("owner_login", "Existing owner PIN login", "passed", "The normal PIN form established an authenticated session; no credential was retained in this report.")

    def installed(self):
        status, content_type, body = self.request({"update_api": "status"})
        if status != 200 or content_type != "application/json":
            raise DeliveryError("installed_status_not_authenticated_json", blocked=True)
        try:
            value = json.loads(body)
        except (ValueError, TypeError):
            raise DeliveryError("installed_status_invalid_json") from None
        sha = value.get("installed_sha") if isinstance(value, dict) else None
        if not isinstance(sha, str) or SHA.fullmatch(sha) is None:
            raise DeliveryError("installed_sha_not_reported")
        return sha

    def admin(self):
        status, content_type, body = self.request({"view": "admin"})
        page = Page(body) if content_type == "text/html" else None
        form = page.form("action", "vault_save") if page else None
        if status != 200 or page is None or page.pin_gate() or form is None or not form["fields"].get("csrf"):
            raise DeliveryError("owner_admin_access_unconfirmed", blocked=True)
        sha = None
        try:
            technical = json.loads(page.technical)
            candidate = technical.get("installed_release") if isinstance(technical, dict) else None
            sha = candidate if isinstance(candidate, str) and SHA.fullmatch(candidate) else None
        except (ValueError, TypeError):
            pass
        return form["fields"]["csrf"], sha

    def inspect(self):
        sha = self.installed()
        _, admin_sha = self.admin()
        if admin_sha != sha:
            raise DeliveryError("admin_installed_sha_not_confirmed")
        self.observe("installed_release", "Installed release identity", "passed", "Authenticated loader status and Admin independently report the same installed release.", installed_sha=sha)
        return {"installed_sha": sha}

    def install(self, expected):
        before = self.installed()
        if before == expected:
            self.observe("install", "Install the checked release", "skipped", "The requested exact release is already installed; no installation POST was sent.")
            return self.inspect() | {"expected_release_sha": expected, "install_outcome": "already_installed"}
        status, content_type, body = self.request({"installer": "1"})
        page = Page(body) if content_type == "text/html" else None
        form = page.form("action", "check") if page else None
        if status != 200 or page is None or page.pin_gate() or form is None or not form["fields"].get("csrf"):
            raise DeliveryError("installer_check_form_unavailable", blocked=True)
        status, content_type, body = self.request({"installer": "1"}, {"action": "check", "csrf": form["fields"]["csrf"]})
        checked = Page(body).form("action", "install") if content_type == "text/html" else None
        if status != 200 or checked is None or checked["disabled"] or not checked["fields"].get("csrf"):
            raise DeliveryError("required_checks_or_selection_not_ready", blocked=True)
        if checked["fields"].get("sha") != expected:
            raise DeliveryError("checked_release_sha_mismatch", blocked=True)
        self.observe("checked_release", "Review the exact checked selection", "passed", "The normal installer selected the requested full commit SHA; its own required-check and expiry guards remain in force.", expected_sha=expected)
        uncertain = False
        response_failed = False
        try:
            status, _, _ = self.request({"installer": "1"}, {"action": "install", "sha": expected, "csrf": checked["fields"]["csrf"]})
            response_failed = status != 200
        except DeliveryError as error:
            if error.blocked:
                raise
            uncertain = True
        # The installer consumes its checked selection once and may keep running
        # after a lost response. Reconcile once; never repeat the install POST.
        try:
            after = self.installed()
            _, admin_sha = self.admin()
        except DeliveryError:
            self.observe("install", "Install the checked release", "uncertain", "An install request was sent, but authenticated installed-state confirmation is unavailable. No retry was attempted.")
            raise DeliveryError("install_outcome_uncertain") from None
        if after != expected or admin_sha != expected:
            self.observe("install", "Install the checked release", "uncertain" if uncertain else "failed", "The expected release was not confirmed in both loader status and Admin. No installation retry was attempted.", installed_sha=after)
            raise DeliveryError("install_outcome_uncertain" if uncertain else "installed_sha_mismatch")
        if response_failed:
            self.observe("install", "Install the checked release", "uncertain", "The expected code is observed active, but the installer returned an error. Inspect retained installation evidence before another update.", installed_sha=after)
            raise DeliveryError("installer_error_after_activation")
        self.observe("install", "Install the checked release", "passed", "The exact release is confirmed active by authenticated loader status and Admin." + (" The installation response was lost; the outcome was reconciled without retrying." if uncertain else ""), installed_sha=after, response_reconciled=uncertain)
        return {"expected_release_sha": expected, "installed_sha": after, "install_outcome": "reconciled" if uncertain else "confirmed"}

    def smoke(self):
        sha = self.installed()
        for view in ("overview", "research", "nasa"):
            status, content_type, body = self.request({"view": view})
            if status != 200 or content_type != "text/html" or Page(body).pin_gate() or Page(body).form("action", "login") is not None or re.search(r'class=["\'][^"\']*\bapp-shell\b', body) is None:
                self.observe("view_" + view, "Open " + view + " view", "failed", "The owner view did not return its normal authenticated HTML page.", http_status=status)
                raise DeliveryError("hosted_view_check_failed")
            self.observe("view_" + view, "Open " + view + " view", "passed", "The owner GET route returned authenticated HTML. This checks page availability, not model execution or project mutations.", http_status=status, installed_sha=sha)
        _, admin_sha = self.admin()
        if admin_sha != sha:
            raise DeliveryError("admin_installed_sha_not_confirmed")
        self.observe("view_admin", "Open Admin and confirm installed release", "passed", "Admin is owner-accessible and reports the same exact release as the authenticated loader status.", installed_sha=sha)
        return {"installed_sha": sha, "model_calls": 0, "project_mutations": 0}

    def upload(self, packet):
        csrf, _ = self.admin()
        compact = compact_packet(packet)
        encoded = json.dumps(compact, ensure_ascii=False, separators=(",", ":"))
        try:
            status, content_type, body = self.request({"view": "admin", "admin_test_api": "1"}, {"action": "admin_test_import", "csrf": csrf, "packet_json": encoded}, multipart=True)
        except DeliveryError as error:
            if error.blocked:
                raise
            raise DeliveryError("report_import_outcome_uncertain") from None
        if status != 200 or content_type != "application/json":
            raise DeliveryError("admin_report_import_unconfirmed")
        try:
            result = json.loads(body)
        except (ValueError, TypeError):
            raise DeliveryError("admin_report_import_invalid_json") from None
        report = result.get("report") if isinstance(result, dict) else None
        metadata = report.get("report", report) if isinstance(report, dict) else None
        report_id = metadata.get("id") if isinstance(metadata, dict) else result.get("report_id") if isinstance(result, dict) else None
        if type(report_id) is not int or report_id < 1:
            raise DeliveryError("admin_report_import_id_missing")
        self.observe("admin_report_import", "Retain the client observations in Admin", "passed", "The normal owner/CSRF Admin import retained the report. Imported observations remain client-reported evidence.", report_id=report_id, check_count=len(compact["checks"]))
        retained = {"report_id": report_id, "uploaded_checks": len(compact["checks"]), "packet_bytes": len(encoded.encode("utf-8"))}
        summary = report.get("summary", {}) if isinstance(report, dict) else {}
        if isinstance(summary, dict):
            observed = summary.get("server_observed_release_sha")
            if isinstance(observed, str) and SHA.fullmatch(observed):
                retained["server_observed_release_sha"] = observed
            if summary.get("outcome") in ("passed_as_reported", "failed", "uncertain", "incomplete", "not_run"):
                retained["retained_outcome"] = summary["outcome"]
        return retained

    def report(self, report_id):
        csrf, _ = self.admin()
        status, content_type, body = self.request({"view": "admin", "admin_test_api": "1"}, {"action": "admin_test_read", "csrf": csrf, "report_id": str(report_id)}, body_limit=MAX_INPUT_REPORT)
        if status != 200 or content_type != "application/json":
            raise DeliveryError("admin_report_read_not_authenticated_json", blocked=status in (200, 401, 403, 429))
        try:
            response = json.loads(body)
        except (ValueError, TypeError):
            raise DeliveryError("admin_report_read_invalid_json") from None
        bundle = response.get("report") if isinstance(response, dict) else None
        metadata = bundle.get("report") if isinstance(bundle, dict) else None
        if not isinstance(bundle, dict) or bundle.get("format") != "AIcontrol retained test report v1" or not isinstance(metadata, dict) or metadata.get("id") != report_id:
            raise DeliveryError("admin_report_read_identity_mismatch")
        self.observe("admin_report_read", "Read the retained Admin report", "passed", "The normal owner/CSRF read action returned the requested retained record. Client and server observation labels remain as stored.", report_id=report_id)
        return {"report_id": report_id, "report": redact(bundle), "client_redaction_applied": True}


def clip(text, limit):
    raw = text.encode("utf-8")
    if len(raw) <= limit:
        return text, 0
    marker = "\n[large output omitted]\n"
    room = max(0, limit - len(marker.encode()))
    head = raw[:room * 2 // 3].decode("utf-8", "ignore")
    tail = raw[-(room // 3):].decode("utf-8", "ignore") if room >= 3 else ""
    kept = len(head.encode()) + len(tail.encode())
    return head + marker + tail, len(raw) - kept


def redact(value):
    if isinstance(value, dict):
        return {k: "[redacted]" if re.search(r"(?:password|secret|api.?key|authorization|cookie|csrf|(?:^|_)pin(?:$|_))", k, re.I) else v if re.search(r"(?:^|_)sha(?:256)?$", k) and isinstance(v, str) and re.fullmatch(r"[a-f0-9]{40}|[a-f0-9]{64}", v) else redact(v) for k, v in value.items()}
    if isinstance(value, list):
        return [redact(v) for v in value]
    if isinstance(value, str):
        for secret_name in ("GH_TOKEN", "GITHUB_TOKEN", "AICONTROL_OPENAI_API_KEY", "openai_api_key"):
            secret = os.environ.get(secret_name, "")
            if secret:
                value = value.replace(secret, "[redacted]")
        pin = os.environ.get("AICONTROL_SITE_PIN", "")
        if re.fullmatch(r"[0-9]{6,12}", pin):
            value = value.replace(pin, "[redacted]")
        value = re.sub(r"\b(?:sk-[A-Za-z0-9_-]{12,}|gh[pousr]_[A-Za-z0-9_]{12,}|github_pat_[A-Za-z0-9_]{12,})", "[redacted]", value)
        return re.sub(r"(?i)\b(authorization|cookie|set-cookie|password|api[_-]?key|pin|csrf)\s*[:=]\s*[^\s,;]+(?:\s+[^\s,;]+)?", r"\1: [redacted]", value)
    return value


def import_packet(value):
    if isinstance(value, dict) and value.get("format") == "AIcontrol site delivery v1":
        expected = value.get("expected_release_sha") or value.get("installed_sha")
        value = {"format": "AIcontrol test import v1", "title": "Site delivery: " + str(value.get("command", "verification")), "expected_release_sha": expected, "checks": value.get("checks", []), "summary": "Client automation observations; site checks remain separate from local fixtures and model verification.", "finished": True}
    if not isinstance(value, dict) or value.get("format") != "AIcontrol test import v1" or set(value) - {"format", "title", "expected_release_sha", "checks", "summary", "finished"}:
        raise DeliveryError("invalid_import_packet", blocked=True)
    if not isinstance(value.get("title"), str) or not value["title"].strip() or len(value["title"].encode()) > 160:
        raise DeliveryError("invalid_import_title", blocked=True)
    expected = value.get("expected_release_sha")
    if expected is not None and (not isinstance(expected, str) or SHA.fullmatch(expected) is None):
        raise DeliveryError("invalid_import_sha", blocked=True)
    if "summary" in value and (not isinstance(value["summary"], str) or len(value["summary"].encode()) > 1200) or "finished" in value and type(value["finished"]) is not bool:
        raise DeliveryError("invalid_import_metadata", blocked=True)
    checks = value.get("checks")
    if not isinstance(checks, list) or not 1 <= len(checks) <= 30:
        raise DeliveryError("invalid_import_checks", blocked=True)
    seen = set()
    for check in checks:
        if not isinstance(check, dict) or set(check) - {"id", "title", "scope", "outcome", "summary", "output", "claimed_tested_sha", "duration_ms", "client_utc", "details"}:
            raise DeliveryError("invalid_import_check_fields", blocked=True)
        if not isinstance(check.get("id"), str) or CHECK_ID.fullmatch(check["id"]) is None or check["id"] in seen:
            raise DeliveryError("invalid_import_check_id", blocked=True)
        seen.add(check["id"])
        if check.get("scope") not in ("fixture", "local", "live_provider", "hosted", "manual") or check.get("outcome") not in ("passed", "failed", "skipped", "uncertain"):
            raise DeliveryError("invalid_import_check_state", blocked=True)
        for key, limit in (("title", 160), ("summary", 1200), ("output", 65536)):
            text = check.get(key, "")
            if not isinstance(text, str) or len(text.encode()) > limit or key != "output" and not text.strip():
                raise DeliveryError("invalid_import_check_text", blocked=True)
        claim = check.get("claimed_tested_sha")
        if claim is not None and (not isinstance(claim, str) or SHA.fullmatch(claim) is None):
            raise DeliveryError("invalid_import_claimed_sha", blocked=True)
        if "details" in check and not isinstance(check["details"], dict) or "duration_ms" in check and (type(check["duration_ms"]) is not int or not 0 <= check["duration_ms"] <= 86400000) or "client_utc" in check and (not isinstance(check["client_utc"], str) or re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+(?:Z|[+-][0-9:]+)", check["client_utc"]) is None):
            raise DeliveryError("invalid_import_observation_metadata", blocked=True)
    return redact(value)


def compact_packet(value):
    packet = import_packet(value)
    # Retain every outcome/ID/SHA and every summary. Only voluminous output and
    # detail payloads are shortened; omission counts stay explicit per check.
    for limit in (2000, 800, 300, 0):
        candidate = json.loads(json.dumps(packet))
        for check in candidate["checks"]:
            output = check.get("output", "")
            shortened, omitted = clip(output, limit) if limit else ("", len(output.encode()))
            original_details = check.get("details")
            details_bytes = len(json.dumps(original_details, ensure_ascii=False).encode()) if original_details is not None else 0
            if shortened:
                check["output"] = shortened
            else:
                check.pop("output", None)
            if details_bytes > limit:
                check["details"] = {"detail_payload_omitted_bytes": details_bytes}
            elif original_details is not None:
                check["details"] = original_details
            if omitted:
                if not isinstance(check.get("details"), dict):
                    check["details"] = {}
                check["details"].update({"output_original_bytes": len(output.encode()), "output_omitted_bytes": omitted})
        if len(json.dumps(candidate, ensure_ascii=False, separators=(",", ":")).encode()) <= MAX_IMPORT:
            return candidate
    raise DeliveryError("report_summaries_exceed_import_limit", blocked=True)


def arguments(argv):
    parser = Arguments(description=__doc__)
    parser.add_argument("--url", default=DEFAULT_URL, help="Existing HTTPS loader URL")
    parser.add_argument("--allow-loopback", action="store_true", help="Allow HTTP loopback targets for disposable tests only")
    parser.add_argument("--timeout", type=int, default=45)
    sub = parser.add_subparsers(dest="command", required=True)
    inspect = sub.add_parser("inspect")
    install = sub.add_parser("install")
    install.add_argument("--sha", required=True)
    smoke = sub.add_parser("smoke")
    upload = sub.add_parser("upload")
    upload.add_argument("--reportfile", required=True)
    report = sub.add_parser("report")
    report.add_argument("--id", required=True)
    # Also accept normal command-first usage, e.g. inspect --url https://… .
    for command in (inspect, install, smoke, upload, report):
        command.add_argument("--url", default=argparse.SUPPRESS)
        command.add_argument("--allow-loopback", action="store_true", default=argparse.SUPPRESS)
        command.add_argument("--timeout", type=int, default=argparse.SUPPRESS)
    return parser.parse_args(argv)


def main(argv=None):
    started = time.monotonic()
    result = {"format": "AIcontrol site delivery v1", "generated_utc": dt.datetime.now(dt.timezone.utc).isoformat(), "source": "client", "status": "failed", "checks": []}
    site = None
    try:
        args = arguments(argv)
        result["command"] = args.command
        if not 1 <= args.timeout <= 180:
            raise DeliveryError("invalid_timeout", blocked=True)
        if args.command == "install" and SHA.fullmatch(args.sha) is None:
            raise DeliveryError("invalid_release_sha", blocked=True)
        if args.command == "report" and re.fullmatch(r"[1-9][0-9]{0,9}", args.id) is None:
            raise DeliveryError("invalid_report_id", blocked=True)
        packet = None
        if args.command == "upload":
            try:
                with Path(args.reportfile).open("rb") as source:
                    raw = source.read(MAX_INPUT_REPORT + 1)
                if len(raw) > MAX_INPUT_REPORT:
                    raise DeliveryError("input_report_exceeds_limit", blocked=True)
                packet = compact_packet(json.loads(raw))
            except DeliveryError:
                raise
            except (OSError, ValueError, TypeError):
                raise DeliveryError("input_report_unreadable", blocked=True) from None
        site = Site(args.url, args.allow_loopback, args.timeout)
        result["target"] = site.url
        result["checks"] = site.checks
        site.login()
        if args.command == "inspect":
            result.update(site.inspect())
        elif args.command == "install":
            result["expected_release_sha"] = args.sha
            result.update(site.install(args.sha))
        elif args.command == "smoke":
            result.update(site.smoke())
        elif args.command == "report":
            result.update(site.report(int(args.id)))
        else:
            result.update(site.upload(packet))
        result["status"] = "completed"
        code = 0
    except DeliveryError as error:
        result["status"] = "blocked" if error.blocked else "uncertain" if error.code in ("install_outcome_uncertain", "installer_error_after_activation", "report_import_outcome_uncertain") else "failed"
        result["error"] = error.code
        result["checks"].append({"id": "delivery_result", "title": "Client delivery outcome", "scope": "local", "outcome": "skipped" if error.blocked else "uncertain" if result["status"] == "uncertain" else "failed", "summary": "Client observation: " + error.code + ". No additional installation attempt was made."})
        code = 2 if error.blocked else 3 if result["status"] == "uncertain" else 1
    except Exception:
        result["error"] = "unexpected_client_failure"
        result["checks"].append({"id": "delivery_result", "title": "Client delivery outcome", "scope": "local", "outcome": "failed", "summary": "The client could not complete verification. No credential or traceback was included in this report."})
        code = 1
    result["duration_ms"] = round((time.monotonic() - started) * 1000)
    print(json.dumps(redact(result), ensure_ascii=False, separators=(",", ":")))
    return code


if __name__ == "__main__":
    raise SystemExit(main())
