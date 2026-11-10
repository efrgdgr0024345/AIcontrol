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

Current updater release validation: commit `ca114e2945c98480f655458495f23956dfcafca9`
passed required Actions run `42704888597`. A fresh single-file browser install
actually replaced a deliberately different initial loader with checked GitHub
bytes. A second installation retained the matching loader. Both emitted all nine
progress stages; PIN, signing key and project/task data persisted. Independent
Node Ed25519 verification passed for 37 receipts; authenticated automatic status
reported up_to_date. UI/accessibility fixtures passed across eleven states.
This was cloud PHP/Chromium validation, not a test on the owner’s cPanel account.
Check the current Actions result and test the current change before making new
readiness claims. Actual hosting access and live provider use remain separate.

## Next useful work

Get the automatic-configuration loader installed on the user's host, then verify
projects/tasks persist there. Connect the server-side provider and exercise one
bounded real task response. Next add authenticated worker/tool execution and
linked code/test/PR evidence. Keep access and custody limitations explicit while
following the owner's current testing scope.

## Admin and research workspace

Admin stores encrypted private keys and notes outside the public directory.
`openai_api_key` connects research and task agents; opted-in notes become shared
AI context, while keys stay out of prompts. Research projects have persistent
project-specific chats and optional provider web search. See ADMIN_RESEARCH.md
for storage, bounds and exact validation scope. Run `php tests/workspace.php`
alongside existing checks. Live provider operation still needs a host-side key.

## Workspace asset delivery fix

The owner reported unstyled overview/admin/runs at easyai.com.au under /aiadmin/.
Unauthenticated live asset requests return PIN HTML, not CSS; the authenticated
host response was not accessible in this session. Workspace pages now embed the
verified release CSS and JS with per-response CSP nonces, like the standalone
sign-in/installer. They no longer depend on additional authenticated asset loads.
Browser tests deliberately block separate asset requests and verify layout,
forms and accessibility. Existing asset endpoints remain compatible.

## Investigation plan implementation

The supplied plan is saved as docs/RESEARCH_IMPLEMENTATION_PLAN.md. Research now
adds a persisted Dream/Plan/Evidence/Reconciliation phase machine, progressive
polling tree, Stop, first-class provider-cited source cards and dead ends,
evidence-only Learn, history and Markdown export. Legacy project chat/data stays.
Investigation.php handles schema/state/provider bounds; ResearchApi.php releases
session locks before I/O; ResearchView.php renders the progressively enhanced UI.
Limits and uncertainty semantics are documented in ADMIN_RESEARCH.md. Add
`php tests/investigation.php` and `python3 -B -m unittest -v tests/investigation_http.py`
to the reproducible checks. Provider tests use synthetic transport; live billed
provider/shared-host validation remains separate.

## Claim review and research output redesign

Dream now requests 2–5 bounded core hypotheses with prior confidence, uncertainty,
verification angle and priority, plus competing angles, assumptions and the least
confident area. These remain explicitly unchecked. New runs pause in
`dream_review` with status `running`, retaining the single-active-run guard.
`research_step` cannot bypass review or spend another request there.
`research_continue` validates selected claim IDs in a transaction before Plan;
without JavaScript it selects all claims. Existing in-progress runs keep their
saved phase and plain-text claims remain readable. Additive node columns preserve
structured display data and selection; nothing resets project or private state.

The research output is now a claim board with a stage rail, review controls,
claim-linked verification/evidence/dead ends, a compact final outcome matrix,
and separate prior context. The full expandable tree and source library are
available as details. Shared source URLs retain relevance to multiple claims.
Plan accepts selected claims only; reconciliation evaluates selected claims only.
Selection polling preserves checkboxes and keyboard focus. Learn still requires
sources and an idle investigation. Stop, request bounds, and uncertain outcomes
retain their existing behavior.

Validation for this change uses synthetic provider transport, HTTP requests,
and desktop/mobile Chromium with accessibility checks. It does not establish
live provider quality, a checked GitHub release, or deployment to cPanel.
