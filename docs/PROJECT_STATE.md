# AIcontrol — current project state

This file is the repository handover for new chat sessions. Read it together with
AGENTS.md and DEPLOYMENT_DEFAULTS.md. Git history and Actions identify exact code
revisions; do not infer a live hosting revision from the latest source commit.

## Where the implementation lives

- Repository: `efrgdgr0024345/AIcontrol` on GitHub.
- Implementation/release branch: `feature/control-panel-foundation`.
- Required check on the selected full SHA: `planning-panel` from GitHub Actions.
- `main` keeps the original production specification and continuity documents;
  do not assume the implementation has already been merged there.
- Public entry/download: `loader.php` on the implementation branch. Download
  that file's actual contents, not the GitHub HTML file-view page.

## Owner decisions and current behavior

1. Shared cPanel is the testing host; independent phone/Android authorization and
   stronger production isolation are deferred. No Android image is built yet.
2. The owner wants one uploaded loader, not a source-file transfer or laptop relay.
3. First visit sets and confirms a 6–12 digit PIN; next visit signs in and opens
   setup. The same PIN gates the panel, installer, identity and receipt endpoints.
4. No Directory Privacy, REMOTE_USER, manually generated hash or copied private
   key is required. Do not reinstate those setup instructions.
5. Installation paths, detected web roots and release channel are chosen in code,
   not requested in browser forms. Existing installations retain their recorded
   paths. The selection and detection rule persist on the server.
6. cPanel generates an Ed25519 identity; private key stays outside the website.
   GitHub requests/results, checked selections and update outcomes are signed.
7. The owner wants useful agents before further security design discussions.
   Avoid fabricated agents or success statuses; show missing connections plainly.

## Implemented

- PIN enrollment/login, CSRF, shared attempt throttling, expiry and lock control.
- Automatic private storage selection, persisted bootstrap/runtime configuration.
- Exact-commit GitHub archive staging, manifest/file hashes, PHP syntax checks,
  deployment lock, activation journal and recovery of the previous code pointer.
- Automatic checked-release detection, panel update button, verified loader
  self-replacement, streamed stages and signed changed-file summaries (UPDATES.md).
- Server-generated signing identity, public-key pinning and signed receipts.
- SQLite project/task proposals, acceptance criteria and attributable events.
- Bounded OpenAI Responses API task adapter: one active request, three runs/hour,
  up to 1,000 output tokens; results need review, costs remain unknown.
- Explicit handling of missing connection, provider refusal and uncertain timeout;
  no automatic retry of an uncertain provider operation.
- Locally bundled Tabler 1.7.4 workspace/installer/PIN UI, including project/task
  search, agent-result views, connection states and activity search; see UI.md.

## Not established or not built

- The user's actual cPanel account, domain, account-home path, addon-domain root
  inventory and installed SHA are not available to this cloud machine. The user
  reached the installer after PIN login; that is not a completed live deployment.
- No real OpenAI key/connection was supplied in this cloud task. The adapter has
  test-transport validation, not a validated live/billed provider run. Configure
  `AICONTROL_OPENAI_API_KEY` directly on cPanel, never in chat or GitHub.
- No repository checkout/tool-using coding agent, disposable code runner,
  coding-to-PR pipeline, production deployment or trusted-device approval.
- Provider-owned log/backup trust investigation was explicitly deferred.
- SQLite is the testing model; the production transaction/grant/budget engine
  specified in TEAM_HANDOVER remains future work.

## Reproducible validation

Run from the repository root (set PHP_BINARY when PHP is not on PATH):

```sh
php bin/build-ui.php --check
php bin/build-manifest.php --check
php tests/run.php
php tests/loader.php
php tests/signing.php
php tests/storage.php
php tests/updates.php
php tests/agent.php
php tests/agent_adapter.php
python3 -B -m unittest -v tests/http_smoke.py tests/standalone_smoke.py tests/passwordless_smoke.py tests/pin_smoke.py
git diff --check
npm ci --ignore-scripts
PHP_BINARY=php npm run test:ui
```

For UI source changes, run `php bin/build-ui.php` before `php bin/build-manifest.php`.
The latter refreshes the manifest after application changes.
The standalone loader has a separate bootstrap hash in the release manifest.
After one upload of the updater-capable loader, checked releases can replace it
alongside the workspace while preserving generated markers and private state.

Prior cloud browser validation exercised actual GitHub download/install, a second
update, retained project/key state and independently verified Ed25519 receipts.
Check the current Actions result and test the current change before making new
readiness claims. Actual hosting access and live provider use remain separate.

## Next useful work

Get the automatic-configuration loader installed on the user's host, then verify
projects/tasks persist there. Connect the server-side provider and exercise one
bounded real task response. Next add authenticated worker/tool execution and
linked code/test/PR evidence. Keep access and custody limitations explicit while
following the owner's current testing scope.
