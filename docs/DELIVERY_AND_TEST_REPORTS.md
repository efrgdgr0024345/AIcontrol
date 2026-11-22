# Checked website delivery and retained Admin reports

The coding assistant can publish through the existing GitHub connection and use
`bin/site-delivery.py` for the website's existing owner-login/updater flow.
This does not give the in-app planning model tools, deployment authority, a new
credential or an authentication bypass.

The live target remains the explicitly supplied HTTPS loader URL, currently
`https://easyai.com.au/aiadmin/loader.php`. Supply its existing PIN only through
protected environment configuration as `AICONTROL_SITE_PIN`. Never put it in
chat, command arguments, source, test output or GitHub. The website retains its
existing PIN, CSRF, update checks, private storage and signing identity.

## Delivery loop

1. Implement and test the change in the existing checkout. Build the embedded UI,
   regenerate the manifest, publish main and fast-forward the compatibility ref.
2. Verify the required `planning-panel` job on the exact full commit SHA.
3. Use normal PIN sign-in and the installer’s separate CSRF token. Check GitHub,
   require the selected commit to match the tested commit, then install that exact
   checked selection through the existing installer.
4. Confirm the loader status JSON and authenticated Admin agree on installed SHA.
   HTTP 200 containing a PIN page does not establish authentication or success.
5. Perform bounded checks. Keep synthetic/local checks separate from actual
   hosted and live-provider observations. A successful response is not proof of
   every feature, executed code or application requirement.
6. Upload the report through the normal owner/CSRF Admin action. Read it back,
   inspect retained failures/limits, fix issues and repeat the same checked loop.

The first installation of these new reports still uses the existing updater.
A missing PIN means installation and authenticated checks remain blocked; a
reporting feature cannot grant itself access to an older installation.

If an install response is lost, the helper reconciles the installed state once.
It never posts another install automatically. Unknown outcomes remain uncertain.
Cookies stay in memory; redirects are restricted to the selected HTTPS origin;
setup/PIN reset is refused. No alternate deployment token or command channel is
created. Current testing-stage same-PIN authorization is retained; the stronger
future trusted-device architecture remains a separate project.

## Helper commands

The PIN is read from protected environment settings, not a CLI flag. Run from the
repository checkout after required exact-SHA checks pass:

```sh
python3 bin/site-delivery.py inspect
python3 bin/site-delivery.py install --sha "$(git rev-parse HEAD)"
python3 bin/site-delivery.py smoke > /tmp/aicontrol-site-smoke.json
python3 bin/site-delivery.py upload --reportfile /tmp/aicontrol-site-smoke.json
python3 bin/site-delivery.py report --id 1
```

`--url` selects an explicitly authorized HTTPS loader. HTTP loopback is accepted
only with `--allow-loopback` for disposable tests. Read-only smoke checks visit
the owner views; they never create/delete projects, call an AI model, run SSH or
execute arbitrary commands. More involved feature checks need their own explicit
bounded test actions and truthful output records.

The helper emits redacted JSON for success, failure, blocked access and uncertainty.
`upload` accepts that output or an `AIcontrol test import v1` packet. Compaction
preserves every check's outcome, summary and SHA, trimming large output with
omitted-byte counts to fit the 12,000-byte packet/16 KiB normal POST limit.
`report --id` retrieves the retained record through normal login and CSRF, with
a 512 KiB response bound; failures keep their original labels.

## Admin interface

**Delivery and test records** is under Admin, beside application updates. It has:

- A named record and expected release, followed by actual server SHA observations.
- Planned checks, latest results, source/scope labels and retained earlier failures.
- Three allowlisted read-only checks: PHP/extensions, SQLite health, release match.
- Owner/browser observations, errors, output and claimed tested SHA, all labelled.
- Finish-with-limits, retained history, copy contents and JSON/Markdown downloads.
- A bounded packet import for the helper’s results.

A client/imported “passed” remains a reported result. Server runtime observations
do not attest GitHub publication, browser behaviour or model quality. Finishing a
record does not turn missing, skipped, failed or uncertain checks into passes.
A stored hash chain checks internal record consistency; it is not independent
approval or external attestation.

Reports use additive `admin_test_reports` / `admin_test_events` tables in private
panel.sqlite. Events have sequence CAS, timestamps, redacted content hashes and
server metadata. They persist separately from rotating diagnostics, across normal
updates and project reset. Admin's typed **Clear release and test reports** action
removes them explicitly, retaining PIN, Vault, projects and installed code.
Quotas: 200 records, 200 events/record, 6,000 bytes/output, 512 KiB/record and
16 MiB retained payload globally. Nothing silently rotates away. Older records
remain available through history pages and authenticated report IDs.

The report collector has no login/session issuance, secret retrieval, arbitrary
URL fetch, shell, SQL, deployment, GitHub webhook or agent authority endpoint.
All `admin_test_*` routes stay behind existing owner authentication and CSRF;
public-testing sessions are denied. Outputs are redacted before storage and on
read, escaped in HTML, and excluded from model context by default.

## Validation scope

`admin_test_reports.php`, `admin_test_http.py`, `admin_test_ui.cjs` and
`site_delivery.py` exercise real disposable PHP/SQLite/browser/HTTP fixtures.
They prove the implemented protocol and its guards, not a cPanel activation.
Report actual hosted install, login, feature and provider outcomes separately.
