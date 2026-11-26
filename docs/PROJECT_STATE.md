# AIcontrol — current project state

## Click-only research term definitions (2026-11-25)

Local phrase/acronym/long-word heuristics add dotted term buttons to research
briefs, open outputs and the map concept reader. Highlighting, hovering, focus,
scrolling and polling make no model request. An explicit click uses the owner's
approved fast `gpt-5.4-nano`, no tools, a 700-character saved-source excerpt and
160 output tokens. Definitions are contextual AI learning aids, saved separately
from original research and Evidence. Loaded definitions can be viewed on hover;
later clicks retrieve the private cache without another model call.

New additive research_term_explanations/research_term_usage tables support stable
source-aware caching, export, Admin inventory and rolling-hour cost reporting.
The separate workspace allowance is 30 calls/US$0.01 including reservations.
The shared provider lock remains authoritative. Unknown outcomes retain costs
and require explicit retry; cached results do not require a provider credential.
Popup dismissal is explicit, and its position follows scrolling in long mobile
readers; the stronger regression fails the previous dismissal behavior.
Owner/CSRF/project checks, changed-source rejection, literal text output and
project/reset cleanup are covered by synthetic PHP and real local HTTP/browser
tests, including desktop/mobile/dark axe and keyboard behavior. The separate
whole-output STE explanation and canceled Nextstop UI request are outside scope.
See [TERM_HELP.md](TERM_HELP.md). Exact-SHA CI and hosted evidence belong to the
release/test reports; synthetic checks are not live provider operation.

## NASA Managed project engineering workspace (2026-11-22)

NASA Managed adds a visual seven-phase dashboard, local readiness coverage and
next steps, 17-process/term explanations with software examples, eight versioned
artifact types, structured requirements/evidence, residual risks, measures,
reviews, decisions, tailoring, SHA-256 planning snapshots and Markdown/JSON/print
packages. It works manually without OpenAI. Both task and research projects are
supported with distinct source identities; research context linking is explicit.

AI drafts/reviews use bounded tool-free Responses calls, shared ProviderGate,
eight calls/workspace/hour and a separate $1/source-project/day estimated/risk
allowance. Input is capped, model output schema/scope validated, late changed-
context replies retained without overwriting owner work, and no unknown call is
retried. AI cannot approve evidence, accept risk or advance a phase. Explicit owner
actions can create ordinary draft tasks or unchecked user-origin map proposals.

Storage is additive `nasa_*` tables in private panel.sqlite. Admin inventories
them and removes project content on source deletion/workspace reset while keeping
provider allowance history and credentials. Snapshot restore appends versions;
it does not roll back code, tasks, servers or charges. Public-testing sessions
cannot read/write NASA planning. Existing PIN, vault, signing, SSH and research
controls remain authoritative.

[NASA_MANAGED.md](NASA_MANAGED.md) maps the handover features and exact contracts;
[NASA_FIRST_DAY_CHECKS.md](NASA_FIRST_DAY_CHECKS.md) records plugin-led environment
and first-use validation. The official handbook was verified over HTTPS and only
original structured explanations are shipped. The environment's live API binding
currently returns `expired_secret_key` (401); model tests remain explicitly
synthetic until the owner replaces that binding and a live request succeeds.

Local validation passed on PHP 8.4.24: generated UI/manifest and all PHP syntax,
22 PHP suites (61 NASA assertions), eleven Python integration suites including
empty/no-key/owner/public-testing/password NASA HTTP paths and real local SSH,
and the complete Chromium suite. NASA desktop/mobile light/dark axe, keyboard,
no-JS, archive/restore, scoped JSON download and actual PDF generation pass. A
final NASA browser rerun and targeted source/bridge cleanup checks cover the
last changes. GitHub planning-panel must pass on the exact published release SHA.

## SSH bootstrap and credential boundary security fixes (2026-11-22)

Both pasted Systems setup commands now run Python with `-I`: local working-directory
modules, user site packages and inherited PYTHON* configuration cannot intercept
imports before the root bootstrap. New readonly forced commands and inspector
shebangs use isolated Python too. Reapplying the generated second command replaces
all authorized-key entries for that same public key, preserving other keys; an old
unisolated or unrestricted duplicate cannot take precedence over the new entry.

The legacy public-testing profile now receives HTTP 403 for Admin, generic vault
actions and all admin-prefixed actions, including exports and data cleanup. A demo
session's CSRF token does not grant owner access. Verified PIN still takes priority
over the testing flag; ordinary owner access and private development remain usable.
The managed `ssh_system_` vault namespace rejects generic save/delete/share actions
even for owners. Systems uses explicit managed-key methods for creation, rollback
cleanup and revocation; Admin directs users there. Legacy malformed shared notes
in that namespace are excluded from model context. Existing saved data, identity
and credentials are retained, with no schema migration or key regeneration.

Regressions execute the real command wrapper with a harmless payload from hostile
directories/PYTHONPATH/PYTHONHOME, with the vulnerable invocation as a test control.
They also verify readonly allowlists, old-key-entry replacement, demo denial with
a valid CSRF token, unchanged vault bytes, owner CRUD and managed-key revocation.
Root account changes remain simulated; real local SSH uses disposable fixtures.
See [SECURITY_FIXES.md](SECURITY_FIXES.md) for scope and installation implications.

Local validation passed on PHP 8.4.24: generated UI/manifest and syntax, all
21 PHP suites, ten Python integration suites including the targeted security
regressions and real local SSH, and the complete Chromium/accessibility suite.
The SSH protocol fixture now lets the client consume exit status before closing
its transport, removing a test-only connection-reset race; no command is retried.
Required planning-panel CI must pass on the exact published security release SHA.

## Research continuity and readable selected paths (2026-11-22)

Model exploration now sends compact coverage of every saved idea branch in the
same project, including parked ideas and earlier runs. Each entry keeps its ID,
run, parent, title, summary, uncertainty/test, layer, priority and provenance.
Detailed selected ancestry and areas of interest guide useful next steps rather
than repeating the brief. Auto-grow carries the original interest as its frontier
advances; selecting another idea steers the next request, including when a request
is already in flight. Other projects and vault contents stay outside this context.

Provider instructions explicitly reject paraphrases and rediscovery. A conservative
server check skips exact/near lexical repeats against project history and within
the reply; distinct mechanisms may share a title. This is not a guarantee of
semantic novelty. Zero useful ideas is a completed, billed-if-dispatched response:
auto-grow stops with an explanation and never retries to fill its count. No saved
idea is deleted. Context is capped at 240,000 UTF-8 JSON bytes before dispatch;
oversized histories fail preparation instead of silently dropping older lines.
Existing spend reservations include the larger body. Diagnostics retain coverage
counts, parent/interest IDs, bytes and saved/skipped counts without dumping prompts.

Selecting a star brings all ancestors to full size/light regardless of parked or
dimmed state. The selected 16px title expands beyond the old two-line clamp; very
long titles scroll and the inspector retains complete text. Other constellations
recede, and saved world coordinates remain fixed. No schema changes are needed.

Regression coverage includes prior-run/parked branches, project isolation, a
350-branch UTF-8 history, oversized-context admission, stable interests/ancestry, duplicate-only exhaustion,
live browser steering, selected-label styling and full-path focus. Provider tests
are synthetic; no billed model run or live-host activation is claimed.

Local validation passed on PHP 8.4.24: generated UI/manifest and syntax, all
21 PHP suites, ten Python integration suites including local OpenSSH, the full
Chromium suite and a final parked-interest auto-growth regression. The release
must pass required planning-panel CI on its exact published SHA before readiness.

## Pathway display and focused Start (2026-11-26)

The map reader exposes **Collapse pathway** and **Expand pathway** whenever the
selected node has map descendants, with the same labels for old and new runs.
This hides or restores descendants on the canvas without moving their saved
positions. The branch list remains available, and selecting a hidden descendant
opens its ancestors. The map-wide flow-2 Recede/Restore controls still adjust
emphasis.

For a selected flow-2 idea, the compact play button and **Expand this pathway**
each send one model-only request with the selected node as both the parent and
the area of interest. An idle or stopped investigation continues on the same run.
Stop remains separately available when the idle run also offers play. A stopped
map at the project root still offers a new investigation. Older verification
workflows keep their phase controls.

An explicit continuation retains saved nodes and prior request accounting. It
keeps an existing frozen Evidence ledger frozen and never restarts a previous
Evidence plan or automatically retries an uncertain response. A model-only map
stopped before Evidence lock-in can still lock Evidence after resuming. The same
admission, spend and hourly limits apply to manual and automatic exploration.

Regression coverage uses local PHP and synthetic provider replies to check
canvas collapse/restore, stable coordinates, selected-node continuation while
idle and stopped, the fresh root path, frozen-ledger integrity, and Stop with a
late response. Chromium checks cover the compact controls on desktop and mobile.

## Compact map controls and automatic growth (2026-11-21 AWST)

Map's top bar now has one 36px Start/Stop toggle and a separate Auto-grow/Pause
icon with a compact round count (default 5, range 1–20) and completed/total status.
The tray no longer duplicates Start/Stop. Keyboard labels and Alt+S remain usable.

Auto-grow runs exactly the requested number of confirmed model-only exploration
rounds, one request at a time, from the selected idea or active frontier, then
from newly generated ideas. It needs no review clicks between rounds and never
locks Evidence or starts web searches. Existing server spend/request/Stop guards
remain authoritative. Stop, Pause, leaving Map, hiding/closing the page, errors,
uncertain progress, changed runs and exhausted budgets prevent further dispatch.
A paused in-flight request can finish and save its ideas; no unknown request is
retried. Queue state is local to the current page and does not resume on reload.
Old workflow runs keep Start/Stop; automatic model growth is for flow version 2.

`tests/map_auto_growth_ui.cjs` uses real local PHP actions with synthetic provider
responses to verify five sequential frontier rounds, model-only scope, invalid
counts, Stop with a held/stale response, fresh-run reset, failure/no-retry and
compact desktop/mobile axe. Existing map coordinates/camera behavior is unchanged.

Local release checks passed on PHP 8.4.24: generated UI/manifest and syntax,
twenty PHP suites, eight HTTP suites and the complete Chromium regression suite.
The new five-round/Stop/failure test uses synthetic provider replies and actual
local research actions; no billed call or live-host installation is claimed.
Required planning-panel CI is verified on the published commit before readiness.

## Guided command transcripts in the SSH console

Verified server pages put the console above the setup, protection and server
list sections. The action buttons are directly below the console. Mutating
actions keep the existing review and approval. Once dispatched, the console
shows the active recipe's command, returned output excerpts and exit status
while manual input is paused. Polling updates the same operation, including
after reopening the server directly. Completion restores the manual transcript
and entry, with the guided result still visible on that operation.

The browser fixture verifies the review, running stdout/stderr, masked
protected value, server reload and completed VPN transcript. Python tests
verify pending command records and clipped output. Local SSH transport and
desktop/mobile accessibility checks also ran. Live VPS activation and a VPN
handshake were outside these checks.

## Guided controls and setup checks beside the SSH console

Shell output distinguishes an empty command result from an omitted result in
both live and saved transcripts. Server actions sit directly below the shell.
**Check system setup** is a read-only SSH action. It saves a per-connection
observation, identifies existing setup or configuration and disables affected
one-time actions in the UI. Repeatable updates remain available. VPN interface
state and client handshake are distinct observations.

**Learn more** shows the recipe outline and available commands, exit codes and
purposes from the latest recorded run. These explanations are deterministic and
contain no raw remote output. Local tests cover empty and nonempty shell output,
check status before and after a fixture VPN job, operation history, read-only
probe logic and desktop/mobile accessibility. Deployment and VPN connectivity
require separate authenticated verification.

## Website console during guided server actions

An open SSH command line stays on the selected server page while reviewing and
reconciling guided actions and when opening an agent task. The console remains
scoped to the original sign-in, selected server and active Systems unlock.
When a guided action owns the server operation lock, the transcript stays
visible and command entry is disabled.

The Chromium workflow test uses real protected website forms and synthetic SSH
and OpenAI replies. It covers the bound setup receipt, pinned host confirmation,
manual shell output, VPN review and reconciliation, protected key use in the
agent’s HTTPS Authorization header, an agent-selected shell command, and a final
summary based on the returned output. Separate local OpenSSH protocol and VPN
worker tests cover transport and recipe behavior. These checks do not establish
a live VPS installation, VPN client handshake or billed provider response.

## Resumable server setup and SSH diagnostics

Saved Linux server links reopen their original enrollment. An existing first
reply can also select its matching setup without preparing another command.
AIcontrol checks the same enrollment challenge, account, risk and host
identity. Accepted discoveries remain resumable after the 24-hour window for
unused first commands.

Systems shows the next setup step per account and reports the SSH verification
failure when it can classify it. Failure references correlate with stage,
reason and available exit status and timing in the private Admin log. These
failure records omit pasted replies and raw SSH output.

PHP and Chromium fixtures check resume, key reuse, rejection, diagnostics and
the two-command flow. Live cPanel and VPS verification are independent of these
synthetic checks.

## Two-command server setup, risk accounts and web console (2026-11-15 AWST)

Systems now offers a risk dropdown, one discovery/account-setup command, a first
paste, one key-install command, a second paste and Connect. Low creates a unique
non-sudo account with a server-enforced diagnostic key; Medium creates a normal
maintenance account; High explicitly creates an administrator account with full
passwordless sudo. Commands/replies bind the role, machine, UID, host key and
public key to one enrollment. Actual pinned SSH authentication precedes connection.

A browser command console runs noninteractive commands, retains history and last
confirmed cwd, records stdout/stderr/exits, and supports disconnect/interrupt.
Session binding, CSRF, time/output limits and single-command guards apply. It is
a PHP command console, not a persistent PTY service. Existing systems/manual
registration, provider settings and research/map behavior remain compatible.

[SYSTEM_SETUP.md](SYSTEM_SETUP.md) is the current setup/console contract and exact
risk/remote-account lifecycle. It supersedes the older no-sudo default description
for the explicitly selected Administrator level. Added tables/columns are additive.

Local verification passed: twenty PHP suites, eight HTTP suites, generated
bootstrap tests for all three risk levels, real OpenSSH console/allowlist/cwd/
interrupt tests, PHP syntax, generated UI/manifest and complete Chromium tests.
Desktop/mobile setup and console axe pass; the workspace audit covers 34 views
with zero violations. Root/account changes in bootstrap tests are simulated;
SSH commands run in the disposable local protocol fixture. No owner-server
setup, live-host installation or billed administration task is established.

## Linux systems and SSH agent tasks (2026-11-14 AWST)

Systems now supports per-server encrypted SSH keys, copyable account setup,
explicit host-fingerprint verification, connection/resource inspection and bounded
model-directed SSH tasks. Inspection uses fixed tools; optional Maintenance permits
commands within the account's Linux permissions. One command per step, eight
model decisions/task and ten tasks/hour. Stop, timeout and uncertain-worker guards
retain actual command evidence; reports copy/download, disconnect and typed
record deletion work alongside an additive private systems.sqlite database.

[SYSTEMS.md](SYSTEMS.md) records exact host prerequisites, access/runner behavior,
storage, bounds, logs, testing and limitations. This supersedes historical claims
that no remote execution exists. The older generic task adapter remains tool-free.
Direct SSH requires proc_open/OpenSSH on the PHP host and outbound server access;
an external worker for restricted hosting is not implemented. No owner server or
live-host activation is established by local test fixtures.

Validation: nineteen PHP suites, eight HTTP suites, actual OpenSSH/keyscan against
a local SSH protocol fixture (real commands, timeout, Stop, output capture and
key cleanup), generated UI/manifest/syntax checks and full Chromium regressions
passed locally. Systems desktop/mobile axe, sequential auto steps, Stop between
steps, report copy/download and no-JS cleanup are covered. Model decisions are
synthetic; no billed server task or owner-server connection is established.

## Three-dimensional orbit and flight (2026-11-14 AWST)

Map now uses stable X/Y/Z constellations and a perspective camera. Drag changes
both yaw and pitch; Orbit focused idea centres on the selected star, while Fly
freely permits fixed-eye looking and held WASD/RF movement through space. Nearby
and distant ideas show real parallax. Touch steering, two-finger pan/pinch,
full-size recovery of behind-camera ideas and upright labels are supported.
World coordinates stay fixed; clipping and pointer-target occlusion affect only
the view. Family colours, common-idea links and verified work markers remain.

[MAP_3D_FLIGHT.md](MAP_3D_FLIGHT.md) records controls, geometry, camera state,
compatibility, regression scope and performance limits. It supersedes the flat
rotation/pan controls below. No provider call or database migration is added.

Local validation passed on PHP 8.4.24: host/syntax, generated UI/manifest, all
eighteen PHP suites, all seven HTTP suites, 32 projection groups, 14 legacy
camera groups, 14 spatial camera groups and the complete Chromium suite. The
34-view workspace axe report has zero violations; the new 241-star 3D desktop
and mobile audits also pass. Fixtures are synthetic, with no billed provider
call or live-host activation. Verify required CI on the published release SHA.


## Rotation, common-idea links and current model work (2026-11-14 AWST)

Map rotation now supports buttons, Shift-drag, a drag-mode toggle, keyboard Q/E/0
and two-finger twist/pinch/pan. Labels stay upright and saved positions stay fixed.
Related branches share family colours; labelled display-only links expose common
family, matching wording and shared citations without creating evidence. Current
model work has a visible banner, per-node static rings/badges and request details.
Markers track actual pending context, clear on Stop/unverified progress and recover
after confirmed status, including unchanged snapshots. No extra model calls occur.

Local validation passed: all eighteen PHP suites, all seven Python HTTP suites,
32 map projection groups, 14 camera groups and all Chromium suites, including
rotation/native touch, common-link inspection, pending-context attribution,
identical-status recovery, Stop and dark desktop/mobile axe. Provider states are
synthetic fixtures; no billed live call or host activation is established. The
release still requires its exact-SHA planning-panel check after publication.

[MAP_ROTATION_LINKS_ACTIVITY.md](MAP_ROTATION_LINKS_ACTIVITY.md) records controls,
link semantics, camera math, activity honesty, tests and remaining performance
limits. Its family-colour rules supersede the earlier node-type colour key.


## Living star-system Map (2026-11-14 AWST)

The Map now uses a pure black canvas, continuous size/light attention, stable
knowledge constellations and one smooth, interruptible CameraController. Current
focus stays prominent; older ideas recede and remain reachable. Dim/recede controls
retain ideas, and camera movement never changes their cached world coordinates.
Only explicit Reset clears position memory. Model-only exploration and final
user-selected Evidence lock-in retain their existing provider, budget and Stop
contracts; saved flow-version 0/1 investigations remain readable and functional.

[STAR_SYSTEM_MAP.md](STAR_SYSTEM_MAP.md) records all five milestones, public camera
API, additive fields, controls, no-JS/legacy compatibility and exact validation
limits. It supersedes older scroll-camera, white/grid canvas and layer-hide
presentation descriptions below. There is no continuous physics or idle camera loop.


Local validation: PHP 8.4.24 host/syntax and all eighteen PHP suites, all seven
Python HTTP suites, 29 engine checks, 11 new camera/layout groups and the full
Chromium UI suite pass. New fixtures use synthetic provider/data only. The new
240-star browser case includes dark desktop/mobile axe, interruption, full-size
old focus, real deferred growth/manual cancellation, stable coordinates and
optional focus HTTP scope. Camera frame timing is recorded with outliers in
STAR_SYSTEM_MAP.md; sustained 60 fps, billed live research and live-host activation
are not established by these checks. Required CI is verified on the exact release
SHA after publication; do not infer hosting activation from a passing release.

## Exploration-first living map (2026-11-13 AWST)

New runs use flow version 2: tool-free provisional seed → user-steered model
exploration → explicit selected-branch Evidence lock-in → verification/review.
This supersedes earlier new-run defaults in this document; existing runs keep
their persisted workflow. Active/Secondary/Tertiary metadata never deletes ideas.
Map and List provide model exploration, layer/park controls and scoped lock-in;
Admin JSON and Markdown expose the stored provenance. See
[EXPLORATION_FIRST.md](EXPLORATION_FIRST.md) for exact behavior, milestones,
limitations and tests, and [the supplied plan](EXPLORATION_FIRST_PLAN.md).


This file is the repository handover for new chat sessions. Read it together with
AGENTS.md and DEPLOYMENT_DEFAULTS.md. Git history and Actions identify exact code
revisions; do not infer a live hosting revision from the latest source commit.


Validation for this release: PHP 8.4.24 host/syntax checks, all seventeen PHP
application/installer suites (including 42 new exploration assertions), all seven
Python HTTP suites, 29 pure map-engine checks and the complete Chromium UI suite
pass locally. New Chromium coverage uses real local PHP actions and synthetic
provider replies for model growth, layer/reason controls, exact position memory,
visibility, scoped Evidence through verification, disabled lock explanations,
CSRF rejection, keyboard/no-JS actions and desktop/mobile axe. Prior legacy flow,
Admin JSON, installation, brief-writing and workspace UI checks remain covered.
A 300-node, three-layer pure projection/layout plus growth cloud check measured
11.2 ms in one local run; DOM rendering is excluded and this is not a host/device
performance guarantee. No billed AI call or live-host activation was performed;
live billed validation remains host-side. Exact release CI must still be checked
on the published commit.

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

## Admin saved-data controls and database/PHP inventory

See ADMIN_DATA.md for the exact allowlisted controls, reset scope and validation.
Admin can now delete either project type, clear histories, delete investigations
or tasks, reset task history, clear activity/diagnostics/writing-help usage, or
reset all project data. Destructive forms require typed confirmation and the
existing authenticated CSRF POST flow. ProviderGate blocks active workers;
attached SQLite transactions delete children first and check relationships.
PIN, vault/key, signing identity, configuration, sessions, login throttling and
installed release files are retained. No live user data is cleared by deployment.

The expandable technical section reads actual SQLite paths, engine/driver,
health, tables, row counts and column/schema metadata, plus effective PHP runtime
limits, extension versions and error-output settings. Copy technical report
excludes credentials, project contents, raw environment and phpinfo dumps.
Mutation events go to the existing private diagnostic log. Current-session CSRF
rotation rejects stale forms after cleanup; open pages should be refreshed.
Add admin_data.php, admin_http.py and admin_ui.cjs to required validation.

## Admin selected-project map JSON inspector

Admin now exposes a selected research project's saved JSON and investigation
history, defaulting to its newest run. See PROJECT_JSON_INSPECTION.md. It shows
stored database rows, the normal map payload and the exact browser map projection
under selectable filters, with copy/download controls and ID/reference checks.
With JS, the downloaded/copied debug bundle includes the displayed projection;
no-JS users retain authenticated server JSON downloads. No public files are saved.

Inspection uses a consistent read transaction with timeout reconciliation off;
regular polling's recovery behavior remains unchanged. A broken payload builder
leaves raw rows and a diagnostic error in the bundle. Other projects' contents,
private worker tokens, vault/config/session files are not exported. Tests cover
scope, read-only pending/stale state, malformed data, no-JS/subfolder downloads,
source/node IDs and parity with AIcontrolMap.project. This adds diagnosis tools;
it does not by itself establish the cause of a hosted map disconnect.

## NASA workspace evolution — Slice 1 (2026-11-22)

Knowledge version 2026-11-22.2 adds the actual eight tailoring criteria from
§3.11.2, software-adapted phase entrance/success prompts, the purpose and common
failure of all 17 processes, and recursive/SEMP/product-maturity guidance. See
NASA_KNOWLEDGE_PROVENANCE.md for the verified handbook source and adaptation scope.
The searchable helper and tool-free AI context include this guidance.

Tailoring is an append-only structured record: phase/artifact, criterion IDs,
rationale, residual risk, and revisit trigger. Current readiness and saved local
review packages retain the exact exception version and rationale. Historical
exceptions remain readable but must gain the required fields before counting as
a current waiver. JSON/Markdown exports include the fields and review snapshot.
All ordinary POST forms remain usable without JavaScript.

Validation: PHP NASA storage/provider boundaries; HTTP no-JS form validation,
legacy tailoring, readiness/version/export; Chromium tailoring, process search,
existing first-day workflow and accessibility; full required regression suite.
No new live-provider success is claimed: the unchanged environment binding was
previously rejected as expired_secret_key. No deployment changes credentials.

## NASA workspace evolution — integrated slices 2–5 (2026-11-22)

Research ideas, sources, captured passages and ledger records can now seed
versioned NASA drafts or attach as immutable provenance. The map inspector and
native node tree link into the promotion form. Scope comes from the linked
research project or an explicit owner-created focused project, with immutable
project/run identities protecting Admin reset and numeric-ID reuse. Gaps and
open high risks can seed a linked idle run or create focused research. A new
focused project prepares a model-only run when a connection and admission allow;
otherwise it remains ready for explicit Start. No Evidence stage starts here.

Requirements need a declared method, suitable owner-reviewed linked evidence,
and an explicit confirmation POST for the exact subject/method to become
verified. The saved owner-confirmation marker is hash-bound. Legacy verified
rows without this confirmation remain historical claims, not effective verified
readiness. Edits and baseline restores remove effective verification. Model
ideas, citation cards and model claim verdicts cannot become verification evidence.

The tool-free SE collaborator proposes one primary move, 2–3 alternatives,
assumptions/unknowns and an optional exact-scope 1–3-document bundle. Missing
artifacts use these bundles. Proposals, edits and owner dispositions are append-only;
nothing materialises until explicit acceptance, which writes all members in one
transaction as draft/unverified/open. Existing per-document generation/review URLs
remain. All modes share eight workspace calls/hour and $1/source-project/rolling
day, including unknown-outcome reservations and no automatic retry.

Freshness includes full selected research/task fingerprints, beyond the bounded
prompt summaries. Planning decisions acquire SQLite write reservations on panel
and research storage together before checking context and writing drafts,
verification or baselines. Concurrency tests prove stale contexts supersede and
mid-bundle failures roll back. An unchecked idea added to an existing research
run uses the research engine's own transaction/idempotence key; an interrupted
origin write is safely repairable on retry and never approves planning work.

Baselines require an explicit local review of the current revision and capture
planning, provenance, deterministic linked/focused research hashes and the installed
release SHA when available. Comparisons and restore audit show planning/research
changes; restores never roll research/code/servers back. All saved baselines remain accessible in the interactive view, with comparison
details before restore. Structured IF records version both sides, payload/errors/
auth, owner and change rule.

Beginner guidance remains. Practitioner mode adds phase entrance/success and
recursive detail and works through a native GET form. Current posture combines
phase, risk, readiness, top-three blockers and one next move. SE Context Pack
JSON/Markdown exports current scoped planning/provenance without full histories;
excerpts/omissions are explicit. Full review-package and print/PDF exports remain.
See NASA_MANAGED.md, NASA_FIRST_DAY_CHECKS.md and NASA_EVOLUTION_RELEASE.md.

Validation adds nasa_integration.php, nasa_collaborator.php, nasa_discipline.php,
nasa_context_pack.php and nasa_atomicity.php to required CI, alongside nasa.php,
eight real PHP/SQLite HTTP cases and Chromium first-day/accessibility/no-JS flows.
The environment plugin confirms the runtime is connected/running. AI responses in
regression tests are synthetic; the unchanged live key previously returned
expired_secret_key, so no successful new live-model run or cPanel activation is
claimed. PIN, Vault keys, signing identity, provider limits and update checks remain.

## Everyday engineering and owner-authenticated delivery loop (2026-11-22)

The owner approved both the Everyday NASA redesign and publish/install/test/Admin
reporting implementation. See EVERYDAY_ENGINEERING.md and DELIVERY_AND_TEST_REPORTS.md.
New Engineering workspaces start from a pathway/cycle model; existing NASA Managed
workspaces remain rigorous. AI proposes with bounded dedicated prompts; owner
adoption, cycle control, evidence review and verification are distinct. Every
significant recommendation retains project and handbook references. Observations,
versions, omissions and unknown exposure remain visible. Unadopted legacy AI
records are read-only context, never silently upgraded to commitments.

New constrained promises require scope and tested-configuration compatible owner
reviewed evidence. Unknown risks remain unassessed/null. Full context/manifest
compilation and adoption share the protected panel/research transaction. Replans
cannot drop obligations via a mere rationale citation; dependency ordering and
active-cycle selection are tested. New baseline comparisons retain operating
context and label control-panel SHA separately from managed project configuration.

The external coding helper uses ordinary owner PIN/CSRF/check/review/install,
exact-SHA checks and read-only smoke routes. It creates no deployment token,
alternate login, command API or secret export. Admin retains bounded append-only
release/test records, actual serving-release observations and separately labelled
client/browser results with copy/download/readback. Project reset retains these
records; typed report cleanup is explicit. The in-app planning model remains
tool-free and cannot deploy or approve itself.

Required checks include Everyday storage/provider/evidence/export/HTTP/Chromium,
Admin report storage/HTTP/UI and site-delivery HTTP fixtures alongside all heritage
suites. Local fixture/browser results and real hosted/provider outcomes must be
reported separately. The live target currently presents its existing PIN gate;
AICONTROL_SITE_PIN was not configured during implementation, so no authenticated
cPanel install or hosted feature test is claimed without subsequent evidence.


## Engineering visual refinement (2026-11-22)

The live target was reconfirmed as easyai.com.au/aiadmin/loader.php. The separately
mentioned /bus URL serves Nextstop and is not an AIcontrol deployment destination.
Engineering now uses a contrasting outcome/current-action panel, connected adopted
cycle route, explicit proof trail and timestamped retained history. Other cycles,
real lifecycle states, exact promise versions and unconfirmed evidence remain
visible rather than implying completed progress. Existing Managed projects have
a clear native entry into the owner mode decision; GET never changes authority.
See EVERYDAY_ENGINEERING.md for presentation semantics.

Validation covers the owner cycle/evidence/no-JS flow, saved route order and
retained cycles, paused/in-review/incomplete labels, source-order timestamp ties,
mobile/dark-mode contrast and the native mode-entry GET boundary. Tests remain
local and synthetic where declared. Installation/login on the live host requires
the protected AICONTROL_SITE_PIN credential; the runtime and saved environment
configuration did not expose that binding during this refinement.

## Branch-first map reading and conversation (2026-11-22)

The owner requested a wider, quiet left panel focused on Branches. Saved concepts
now have concise searchable titles with family colours/nesting. A reading window
opens full text, explicit model-only pathway expansion and a chat icon. Context
and advanced controls are folded away; Back restores list focus. Scoped chat
retains each concept's thread and drafts, uses the shared provider/budget controls,
and stays separate from Evidence and authority. See RESEARCH_MAP_CONTRACT.md.

The pending security fixes for public-testing AI note disclosure and concurrent
legacy password admission are included, with focused regression coverage; normal
standalone PIN/updater controls are unchanged. A valid owner login is now available
for the confirmed AIadmin host. Published source, live activation and actual
provider outcomes must still be reported separately and supported by checks.

Hosted page smoke checks now use the actual `app-layout` wrapper. The previous
`app-shell` expectation falsely rejected healthy owner pages; PIN/login rejection,
exact-release identity and read-only checks remain enforced. The delivery fixture
uses the same page structure. Live installation and interaction outcomes are
retained in Admin test reports rather than inferred from fixture passes.

## Nested map branches and canvas dismissal (2026-11-23)

The Branches panel now uses actual nested lists in parent-first order instead of
creation order with a shallow indent. New children and grandchildren remain under
their source branch; search includes ancestors. Missing/cyclic parent records
stay visible once and deep indentation is bounded. No saved data is rewritten.
Clicking exposed map background dismisses the reading window and controls while
node selection and 3D navigation keep their existing behavior. Chromium regression
coverage grows two separate families, checks grandchild search/ancestry and verifies
that an orbit drag keeps details open while a background click closes them.

## Guided Systems operations and research handover (2026-11-23)

Systems adds eight reviewed actions for inspection, SSH-preserving firewall,
package updates, private WireGuard, pinned official Codex, automatic security
updates, health and backup readiness. Deterministic recipes use bounded remote
jobs and reconciliation rather than running package installs inside the short
console timeout. Unsupported permissions/platforms/custom firewalls fail closed.
See SYSTEM_ACTIONS.md for precise change/rollback/partial-result boundaries.

Readable server notes mask protected values. A separate Systems passphrase gates
remote control and explicit reveal, and wraps the key that encrypts SSH credentials
and private records. Existing SSH identities transfer without regeneration;
legacy usable key copies are removed only after protected copies are committed.
Configured protection blocks release activation that removes SystemGuard. Owner
must choose this separate password in Systems; code installation does not choose
one or execute a VPS change. Project resets retain independent Systems protection.

Research Export for chatbot review opens a compact workflow explanation, topic,
run/node/source outline and complete project-scoped saved data, ending in questions
for another chatbot to assess method and usefulness. All runs/chat are included;
no provider call or hidden chain-of-thought is claimed. See RESEARCH_HANDOVER.md.
Exact validation results and live activation remain recorded separately in Admin.

Local validation: 48 separate-factor/playbook assertions, 11 complete-handover
assertions, 34 installer and 53 core checks; ten Python recipe simulations and
four Systems HTTP cases pass. Native SSH transport also passes using disposable
fixtures. Existing backend regressions and Chromium desktop/mobile accessibility
checks passed, including explicit reveal/hide/lock, eight actions, map export,
no-JS Markdown download and existing research/3D navigation. No owner VPS was
changed and no model request was made for this addition. Required exact-SHA CI
and authenticated hosted activation must still be confirmed for each release.

## Research export copy choices and token estimates (2026-11-25)

Handover previews add independent complete, explanation-only and data-only copy
choices, each with a token estimate for its actual clipboard payload. Data-only
JSON retains all selected-project records and excludes derived method text/count;
explanation includes the compact method and reviewer questions. Counts use the
explicit UTF-8-byte/4 heuristic, not model tokenization, API usage or billing.
Collapsible previews support no-JS manual copying; refused clipboard access opens
and selects the requested text. No saved research or provider workflow changes.
See RESEARCH_HANDOVER.md for scope and estimation limits.

Targeted validation: 19 PHP handover assertions and Chromium checks for all three
exact clipboard payloads/counts, data retention, refused-clipboard selection,
map entry, no-JS JSON preview/Markdown and mobile accessibility pass. Core and
installer checks pass. Exact-SHA CI and hosted verification are recorded in Admin.

## Visible DigitalOcean VPS connection (2026-11-25)

Systems keeps a visible Connect a DigitalOcean VPS section while locked, with
clear instructions and a link to set/unlock the separate Systems password. When
unlocked, the original two-command risk/account/key setup is always shown, even
when another server is selected. Existing Linux Droplets and other Linux VPS
providers use the same SSH enrollment; no provider API integration or extra
credential is introduced. Commands and connection secrets remain locked.

## Activity-driven brief help and save-to-map (2026-11-25)

The owner selected automatic updates: two-second typing idle, one-second mouse or
scroll intent, combined with an eight-second automatic dispatch gap. Selection
keeps other alternatives and buys one replacement; Refresh buys a fresh full set.
Dispatched calls finish instead of being cancelled on every edit. Stale output
cannot overwrite the latest brief; failures pause automatic dispatch. The UI shows
returned-usage estimates separately from unverified reservations and the rolling
workspace allowance. Matching logged completed provider usage can reconcile an
orphaned reservation; truly unknown calls remain reserved. No provider call or
allowance reset is needed for this recovery. See BRIEF_WRITING_HELP.md.

Create and start research now saves the project and opens a newly started map;
its existing polling advances initial model exploration. Failed start admission
retains the saved project with an explanation. Explicit Evidence selection and
Stop/budget protections remain. Legacy save-only POSTs are compatible. The CI job
allows 15 minutes for the expanded gesture, clipboard, map and accessibility suite.

Local validation: 38 writing-help assertions, authenticated concurrent HTTP/SSE
with read-only usage and create/start/busy retention, 19 export assertions and the
workspace smoke suite pass. Chromium checks two-second typing, one-second mouse
and scroll bursts, retained peer cards/one replacement, refresh, stale responses,
map start/Stop, no-JS creation and mobile accessibility. Provider responses remain
synthetic in these tests; live diagnostics were read without buying another call.

Term explanations now request Responses streaming and forward text deltas as SSE.
The popup paints each delta before completion, labels partial text unfinished,
and only caches a definition after server validation/persistence. Truncated streams
retain partial text with an error and never retry automatically. Cached results
remain immediate. A delayed synthetic provider regression asserts partial text is
visible while final output is still unavailable.

## A/B/C map reader and click-only STE rewrite (2026-11-25)

A uses a short local note shared by map labels and the reader header; B retains
the original high-level model output. Explain, immediately before Expand this
pathway, streams C using gpt-5.4-nano and ASD-STE100 writing principles. C is
separate AI guidance, not certified standards compliance or verified Evidence.
Saved source-aware rewrites use purpose ste100 in the existing private reading
help tables and a separate 10-call/US$0.05 rolling-hour allowance, including
unverified reservations. Admin reports usage; export/reset/delete include C.
Delayed synthetic HTTP/browser tests cover early rendering, branch/source
isolation, reuse, unchanged originals and desktop/mobile/dark accessibility.
See TERM_HELP.md for complete bounds. Exact-SHA CI and hosted observations must
be distinguished from synthetic validation in the retained release reports.

### OpenAI API rejection messages

AIcontrol identifies explicit API billing or quota rejections separately from
temporary rate limits, bad credentials, project permissions and model access.
Systems labels a configured key and links to API billing and Admin diagnostics.
The private diagnostic journal records the HTTP status, provider error code
and provider request ID when available to the caller. The diagnostic fixture
exercises synthetic quota and rate-limit responses. Live API billing and
cPanel activation require independent verification.
