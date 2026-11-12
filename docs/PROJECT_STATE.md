# AIcontrol — current project state

This file is the repository handover for new chat sessions. Read it together with
AGENTS.md and DEPLOYMENT_DEFAULTS.md. Git history and Actions identify exact code
revisions; do not infer a live hosting revision from the latest source commit.


## Stable research Map (current UI)

This section supersedes the historical Map layout descriptions below.

The Map is a read-mostly, concept-first view of the saved run and evidence
ledger. Its only renderer now uses a deterministic structural layout with
in-memory position memory; it does not run the previous force/rectangle
simulation. Existing cards keep their world coordinates when evidence or new
questions arrive. Filter/collapse changes preserve cached positions; only
**Reset layout** clears that memory and builds a fresh arrangement. **Fit**
changes the camera, not node positions. Idle/status/token/activity polls update
text and badges without re-running layout. Resize work is debounced.

The default Concepts view shows the root, selected original claims and their
grown questions. Initial review exposes all original claims and Map checkboxes
stay synchronized with List. Excluded claims remain saved in List/history.
Process records are available through inspector history/List, not independent
boxes on the overview. `assets/map-view.js` owns the Map DOM, camera and cache;
`map-engine.js` remains pure and `panel.js` wires status/actions. Source, passage, edge, dead-end and open
filters reveal real saved records with owning concept context and clear empty
states. Missing/partial old ledgers remain safe. Synthetic evidence IDs are
presentation only and cannot become focus targets. Dashed shared-source links
mean shared citations, not independent corroboration.

The full-screen canvas keeps the compact one-sentence Idea queue and on-demand
hover/tap/keyboard controls. The sentence uses existing public text for 5–10
seconds, preserves provisional/untested labels, and fades without affecting
layout or keyboard focus. Click/tap pauses it; Idea history exposes its backlog.
Actual pending requests produce static stage badges on owning concepts, with
no continuous pulse. Reduced motion uses immediate/static changes. Failure and
review states remain discoverable with controls collapsed; Stop is in controls
and has Alt+S. Details remains an optional overlay, not a reserved column.

The inspector exposes saved history, real source URLs, literal passages with
locators, typed-edge rationale, ranking, source selection, copy link, Jump to
List and applicable Learn/focus actions. Search this direction submits the real
node ID and optional note only for a selected claim/question or linked dead
end/open assessment during idle Evidence, with a pending slot and capacity.
Blocked actions explain the reason; the server still enforces pending-request,
run/phase, selected ancestry, slot and budget guards. Focus replaces a slot and
retains its target/claim relationship in both Map history and List.

Desktop defaults to the tested Map; mobile defaults to List and can opt into
Map. Without JavaScript the complete List and phase/review/Stop/Learn/export
forms remain available. No saved data, PIN/key, model budget, billing estimate
or phase lifecycle is reset by this UI change. See RESEARCH_MAP_CONTRACT.md for
the stable projection, position-memory and steering contracts, and
RESEARCH_MAP_RELIABILITY_PLAN.md for the supplied plan and milestone checklist.

Validation on 2026-11-11 AWST: PHP 8.4.24 host/syntax checks, all twelve
application PHP suites (419 numbered assertions plus unnumbered storage, adapter
and workspace checks), all five HTTP/installer/authentication Python suites,
and the generated UI/manifest checks passed. `npm run test:ui` passed 27 pure
engine checks and the complete Chromium lifecycle, including 33 axe audits with
zero violations. Browser assertions cover exact old-card coordinates after
usage/activity updates and question growth, filter position restoration, camera-
only Fit, explicit Reset, inspector focus preservation, visible focus rejection,
new List anchors, synthetic source fallback, cyclic old parent records and empty
ledger states. Five representative snapshot fixtures are explicitly synthetic; no authenticated
live-run captures, billed provider run or cPanel activation are established by
local checks. `?map_debug=1` console diagnostics expose graph counts, layout timing
and position reuse; normal usage/token updates do not create diagnostic layout
work. The target is projection plus layout under 50 ms for typical two-to-five-
claim fixtures, not a timing guarantee for every device or large ledger. A
cloud microbenchmark of 1,000 samples each for two/five concept-only snapshots
measured median 0.015/0.014 ms and maximum 3.339/0.334 ms; this excludes DOM
rendering and is not a mid-tier device measurement.

## Current research usage, live activity and branching

New runs now use `flow_version=1`; existing runs default to zero and retain their
saved flow. The owner authorised 30 shared research requests/hour, higher model
budget for high-impact paths and verification, and bounded unconventional
exploration. `AICONTROL_RESEARCH_HOURLY_LIMIT=10` retains the former ceiling for
restricted installations and legacy regression fixtures; the default is 30.

Research projects have a tracked USD spend cap (default $5 for the project, not
per hour), primary model, autoplay preference and 0–3 additional growth searches.
Project menu edits these settings; model/pace applies to new runs, cap immediately.
New runs retain human claim review, then can advance ranked searches automatically.
Up to six searches and two creative passes create at most twelve untested question
nodes. Typical expanded runs use twelve calls before optional repair/Learn.
All work remains one active specialist request at a time on PHP; no daemon, queue
or fabricated parallel agents. Closing the page stops advancing subsequent calls.
Stop still rejects late results and never retries an unknown outcome.

High-priority paths and verification default to GPT-4.1; planning, mapping and
lower-priority searches use GPT-4.1 mini; divergent passes use GPT-4.1 nano without
search tools. A server model override still controls the primary model. Priority
weights are attention/model-routing heuristics, not truth probabilities; ranking
changes have their own history. Failed searches and verifier outcomes can reduce
rank without deleting a path. New unconventional questions include falsification
tests, stay visibly untested and never become verified claim assessments merely
by being generated. Creative passes use current provisional observations/findings.

Research and task requests retain API token usage, cached counts, search counts,
model and immutable pricing provenance. USD estimates use official standard
pricing checked 2026-11-10; they are not invoice totals. Old unpriced rows and
missing usage remain unknown, not zero. Search-preview fees are included; cached
tokens and reasoning tokens are not counted twice. Provider-reported model names
are priced only when recognised. Unknown models/tiers are not guessed.

Before each new research call, the engine reserves a conservative maximum cost
from request bytes, output cap and tool limit. An uncertain outcome keeps that
reservation; confirmed usage replaces it with the estimate. Cap exhaustion stops
before dispatch and retains findings. Unpriced historical calls, taxes and account
credits/discounts are outside the local tracked cap. Requests and legacy chat share
the same project accounting. Task projects also display accumulated estimates.

The top of Research is a findings bulletin, activity and spend; project controls
are collapsed into Project menu for existing investigations. Full-screen Map has
its own findings/activity/spend and access to that menu. Recent search observations
are provisional until verification. Usage expands into per-area, phase, model and
request breakdowns; shared work stays separate from path totals. Streamed Responses
events update provider activity/visible response-character counts; the browser
shows elapsed time and last successful poll. Token totals arrive only when the API
returns usage. No fake token ticker, progress percentage or hidden reasoning trace.

Validation adds 45 checks in `php tests/research_flow.php` to all prior checks: pricing/caching,
stream chunks/truncation, bounded growth, cap-before-dispatch, attribution, model
routing and Stop races use synthetic transport. Concurrent HTTP covers actual SSE
activity, session-free polling/Stop and settings/ranking. Chromium covers the
findings-first menu, usage, path priorities and live activity on desktop/mobile
with 29 axe audits and zero violations.
No live billed provider run or authenticated cPanel activation is established.
The older rigour section below describes the previous release; this section
supersedes its ten-call/1600-token default for new flow-enabled runs. New specialist
calls permit 3,200 output tokens; Learn and legacy runs retain 1,600.

## Current research rigour upgrade

New runs use an additive passage/typed-edge/assessment-version ledger. The flow
is Dream → human claim review → Plan (1–2 normal searches + reserved Challenge)
→ Evidence → extraction/provisional reconciliation → grouped verification/final
reconciliation → review/freeze. Optional high-impact repair is one tool-free call.
The minimum six-call capacity is checked at Start; all phases and Learn share the
existing ten-call/hour ceiling. Old runs retain the legacy workflow.

Passages are literal provider source snippets/abstracts with locator/scope/hash,
never generated quotes. URL-only provider results remain citation-only, with
final unsupported claims left open. No full document fetch exists; actual source
snippet availability needs host-side provider validation. Frozen selected-source
Learn retains the existing privacy boundary and excludes Dream and vault notes.

Stop/error freezes partial evidence with verification skipped; late replies cannot
write passages, edges or assessments. Assessment versions and verifier findings
survive repair. Map/List, relation/passages matrix, filters, no-JS forms and export
expose the record. Map header includes Finish/Review gaps at verification review.
See ADMIN_RESEARCH.md and RESEARCH_MAP_CONTRACT.md for exact behavior and limits.

Validation: all ten PHP suites, including 105 legacy investigation checks and
58 new rigour checks; five HTTP suites (including concurrent verifier Stop,
repair/freeze); desktop/mobile Chromium with 27 axe audits and zero violations. Tests use synthetic provider transport;
no live billed provider run or authenticated cPanel activation is established.
Earlier implementation sections below describe the evolution; this section
supersedes their older two-search/five-call descriptions for new runs.

## Where the implementation lives

- Repository: `efrgdgr0024345/AIcontrol` on GitHub.
- Implementation/release branch: `main`.
- Required check on the selected full SHA: `planning-panel` from GitHub Actions.
- `main` contains the full implementation, tests and continuity documents.
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
php tests/workspace.php
php tests/investigation.php
php tests/rigour.php
php tests/research_flow.php
php tests/map_steering.php
python3 -B -m unittest -v tests/investigation_http.py tests/http_smoke.py tests/standalone_smoke.py tests/passwordless_smoke.py tests/pin_smoke.py
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

## Research notebook flow polish

The next UI pass adds first-project guidance, review selection shortcuts, explicit
irreversible-selection copy, Dream loading placeholders, search progress, completed
phase navigation, source-domain markers, visible dead-end labels and a completion
matrix ahead of the board. Mobile uses a compact current-stage rail and reachable
Stop controls. Interrupted/uncertain runs retain their evidence and offer a new
investigation on the same project without retrying an unknown request.

The visible rolling-hour budget counts both investigation requests and legacy
assistant chat, including pending/failed calls; it shows the next availability
estimate when exhausted. Existing enforcement remains authoritative. Learn
follow-ups reuse the bounded evidence-only actions. Markdown export now groups
claims, verification/evidence, outcomes and open questions, with every collected
source URL retained. Optional mid-run brief editing, previous-question seeding
and SSE are deferred. Validation uses synthetic transport and local browsers;
no new live provider, hosting deployment or publication is implied.

## Research mind map

Research now has a desktop-first spatial Map with a full List fallback. The map
shares the persisted node tree and source records, supports inspection, filters,
zoom, collapse, keyboard navigation, claim selection and source-specific Learn.
Mobile starts in List. Status adds derived claim/source links, confidence and
dead-end reasons without schema changes. See RESEARCH_MAP_CONTRACT.md.

JavaScript pauses before each Evidence request for explicit direction. Optional
focus replaces a remaining planned search, stays within two searches per run,
and records its note/claim/node on the request phase and resulting search. The
replaced plan is explicitly incomplete; its original query was not executed.
Only selected claims or their linked open/dead-end nodes can be focused, during
an idle Evidence phase. No completed or uncertain run is revived. Source-selected
Learn validates run ownership and sends only the chosen cards and project brief.
Markdown export includes the node-to-parent outline and real source URLs.
Validation uses synthetic PHP transport, concurrent HTTP Stop tests and Chromium
with keyboard/mobile/accessibility checks; live billed/hosting validation remains
separate. Optional drawn comparison edges and vault pinning are not introduced.

The owner wants tested application updates released automatically without another
deployment-confirmation question. Use the existing implementation branch and
required exact-SHA checks. Live-host activation still requires an authenticated
hosting/panel connection; never label a GitHub push as verified host activation.

## Main branch consolidation

The full implementation and documentation are consolidated on main, preserving
both histories. New loaders check main. The previous feature branch is retained
as a fast-forward compatibility pointer so older installed loaders can receive
the main-channel loader through their existing updater. It is not a separate
development line. Do not delete it until the hosting installations have migrated.

## Full-screen map layout

The owner requested a map that uses the screen and puts Start/Stop at the top.
Map now opens as a full-viewport, keyboard-contained workspace with a compact
header and on-demand Details. List/Escape restore the ordinary page. Node packing
uses actual card/subtree heights rather than the previous uniform 176px rows;
columns have short connectors and the canvas uses remaining screen height.
Starting from the map preserves that view through the new-run navigation,
including on mobile. Existing human review, bounded Evidence steering and Stop
semantics are preserved. Browser coverage verifies viewport dimensions, canvas
height, sibling spacing, header controls, restart, and fallback behavior.
## Admin diagnostics and live research validation (12 November 2026 AWST)

Admin now shows a private bounded diagnostic journal and Copy contents button
(with a selection fallback when clipboard access fails). Events include UTC
and request correlation IDs, research actions/phases, provider HTTP status and
request ID, timing, transport failures, usage, citation counts, schema rejection
locations and traces without arguments, PHP warnings/fatal errors, and bounded
browser research failures. Task and legacy research-chat provider errors are
also recorded. The journal lives in runtime/diagnostics.jsonl, mode 0600, under
an exclusive write lock; it rotates from approximately 1 MB to 512 KB. It
persists across application updates. Prompt/response bodies, authorization
headers, cookies and request form bodies are not recorded. Environment provider
credentials and vault values are redacted before storage and again on export.
Admin requires the existing authenticated PIN session; client reports require
CSRF/authentication and are limited to one per session per five seconds.

The configured cloud OpenAI credential was used for real Responses API calls
with gpt-4.1-mini through the Research tab in Chromium. An initial investigation
reached web search but failed reconciliation with `Unrelated source.` The new
log identified the exact rejection. Reconciliation now sends a claim-specific
strict response schema: only linked source IDs are allowed, and claims without
collected sources must remain open. Existing server validation remains in place.

A fresh live run then completed Dream, reviewed claims, Plan, one web search and
Reconciliation: four provider requests, three cited source cards, 5,731 input and
944 output tokens confirmed, no unknown usage. Admin clipboard contents matched
the complete report. This proves live provider operation in this cloud runtime,
not deployment or authentication against the owner's cPanel installation.
The previous no-live-key statements describe earlier validation only. These live
tests preceded integration with the newer concept-map/streaming research changes;
the integrated release is covered by the current full automated checks.

Validation: all nine existing PHP suites, workspace and investigation suites,
five Python HTTP suites, Chromium desktop/mobile/accessibility suite, new
`php tests/diagnostics.php` redaction/retention checks, and build/manifest checks.
The investigation suite adds constraints preventing cross-claim source citation
and unsupported verdicts in the provider schema. At this validation stage no
application release or cloud environment publication had been performed.

The owner subsequently authorized publishing this logging update for host testing.
Release preparation integrates the latest main research map, streaming, ledger
and usage changes and adds diagnostics to the required CI workflow.

## Hosted research progress failure (12 November 2026 AWST)

The owner's diagnostic report shows runs 18/19 were created, then progress
verification failed before any research_step/provider event, and Stop worked.
The report did not include HTTP status or content type, so it does not establish
whether the host rejected the custom header, returned HTML, redirected, or
failed for another reason. Do not describe this as an OpenAI API failure.

Browser status polling now uses a CSRF-protected POST research_status action via
the same endpoint/form channel as Start and Stop, with no-store caching. It is
read-only and releases the session lock before SQLite snapshot access; existing
GET/header status remains compatible. Polling selects the investigation's own
CSRF field. Response failures now retain HTTP status, content type, redirect
flag, final URL pathname (no query), and error category; raw HTML is excluded.
Server CSRF/status rejections are also recorded without token values. A failed
poll still halts automatic phase dispatch, preserving uncertain-call semantics.

Regression coverage: POST status rejects wrong CSRF, returns a run with zero
provider requests, and works during a blocked provider phase while Stop remains
responsive. Browser fixtures reject legacy header polling and use POST; a host
returning HTML produces detailed diagnostics without logging its body or sending
a provider phase. Cloud validation does not establish the precise hosted cause;
the owner must install the checked release and retest on cPanel.

The owner also requested API-return facts without large text blocks. Diagnostics
now includes bounded JSON response summaries for investigation, task and legacy
chat calls; streaming returns include event counts and stream error metadata.
Long/generated text uses length/hash markers; private prompt fields are excluded.
New regression checks retain error codes, usage details, citations and stream
errors while proving large prose and private instructions are not logged.

## Guided research creation and live writing-help draft

The current local change implements the owner's RESEARCH_CREATE_PLAN.md. See
RESEARCH_CREATE_DELIVERY.md for milestone-by-milestone coverage, baseline, product
choices and exact validation scope. Create is now a first-class guided card;
New research opens it while preserving existing projects/runs. Titles/briefs use
the existing POST contract and schema. Applying a suggestion never starts research.

The new helper streams short alternatives, with abort/stale-response protection,
copyable examples, soft quality hints, undo, no-JS creation and mobile/accessibility
coverage. Private metadata-only usage is bounded to 20/hour and $0.01/hour for
pre-project help. No suggestion tables or dummy project/run rows are added.
ProviderGate serializes provider calls and creation admission without blocking
Stop/status. Existing research/map/ledger behavior and project caps are preserved.

Local PHP suites, six HTTP suites, the new brief UI/accessibility/no-JS suite and
existing workspace/map UI suite pass. A real call to the original mandated
`gpt-4.1-nano` failed with provider HTTP 404/model_not_found (retired). The owner
then explicitly approved `gpt-5.4-nano`. It is now forced for this feature with
reasoning effort none and known alias/snapshot pricing. A fresh live browser test
successfully streamed three suggestions, applied one and created a project.
First text appeared at 1,175 ms including debounce; confirmed usage was 100 input
and 162 output tokens, estimated $0.0002225 USD, no tools/reasoning tokens. No
research auto-started. See delivery evidence for scope and source rates. Publish
only after required planning-panel checks pass on the exact release SHA; cloud
success is separate from host activation.

## Workspace chrome simplification

The owner requested update status/checking only under Admin. The update-status
root, banner, refresh control and installer link now live in Admin's Application
updates card. Other views do not poll or display update status; sidebar/footer
update links are removed. The sidebar “One step at a time” box and redundant
YOUR WORKSPACE heading eyebrow are removed. PIN locking, update verification,
installation paths and private state are unchanged. Browser coverage checks
Admin-only status placement and existing update success/failure flows.
