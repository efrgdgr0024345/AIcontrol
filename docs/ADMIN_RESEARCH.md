# Private admin vault and research projects

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


## Exploration-first living map (2026-11-13 AWST)

New runs use flow version 2: tool-free provisional seed → user-steered model
exploration → explicit selected-branch Evidence lock-in → verification/review.
This supersedes earlier new-run defaults in this document; existing runs keep
their persisted workflow. Active/Secondary/Tertiary metadata never deletes ideas.
Map and List provide model exploration, layer/park controls and scoped lock-in;
Admin JSON and Markdown expose the stored provenance. See
[EXPLORATION_FIRST.md](EXPLORATION_FIRST.md) for exact behavior, milestones,
limitations and tests, and [the supplied plan](EXPLORATION_FIRST_PLAN.md).


Open **Admin** using the existing workspace PIN session. No separate account or
password is introduced. Everyone who can enter this testing workspace can manage
its vault. Add or replace named entries (lowercase letters, digits, underscores).
Entries can be a key or private text. Existing values are never returned in page
HTML; replace an entry by saving its name again. Delete removes the current vault
entry, not copies already present in hosting backups or past provider requests.

The special key `openai_api_key` connects both task agents and research chat.
An existing server `AICONTROL_OPENAI_API_KEY` takes precedence. Other keys can be
stored and retrieved server-side through `Vault::value` for future integrations;
there is no generic arbitrary-endpoint or automatic tool execution facility.

Private text is excluded from AI by default. Marking **Share this note with AI**
explicitly includes it in agent/research provider requests, up to 12000 bytes of
shared context. Key entries are never included in model prompts. Do not put
credentials in shared notes or research messages.

Vault files live in the existing private runtime directory: `vault.json` is
Sodium secretbox authenticated ciphertext; `vault.key` is a separately stored
0600 random key. Updates use a lock and atomic replacement. Corruption, missing
keys or wrong keys fail without silently overwriting the stored vault. The key
is protected by the same hosting account as the ciphertext: encryption does not
isolate secrets from an attacker who controls that account. Back up both files
privately together. Neither is part of a release, manifest or GitHub commit.

**Research projects** maintains separate project briefs and persistent message
threads in private `research.sqlite`, independent of the coding-project tables.
AI responses use OpenAI Responses, the stored/environment key and the server
model (default `gpt-4.1-mini`). Web search can be selected per message, with one
tool call and 1600 output tokens maximum. This is provider-billed use, not free
local search. Provider citation URLs are retained with answers. Responses and
URLs are escaped as text; model-generated HTML is never executed.

Each request includes its project brief, the last twelve completed/user messages
from that project (each capped at 6000 bytes), and opted-in shared notes. Up to
ten requests per hour and one pending research request globally are allowed.
Requests time out at sixty seconds. Errors/timeouts retain the user message and
record a failed/incomplete/uncertain outcome without automatic retries. A pending
request left by a killed PHP worker can be marked uncertain after two minutes.
There is no claim that this cancels or reverses provider billing. Normal submits
show a researching state; research answers are not streamed token by token.

Existing releases/PINs/keys/tasks remain intact. Install the checked GitHub
release through Application updates; uploading only the bootstrap does not
replace the workspace. New private tables/files are created as needed without
resetting the existing database. Code rollback leaves private data intact.

Validation includes `php tests/workspace.php` with a synthetic provider transport:
encryption/no-plaintext, metadata masking, key-loss/tamper refusal, sharing bounds,
key retrieval, project isolation, web configuration, citations, uncertain outcome
and deletion. Browser tests save private guidance and a research project, assert
no saved plaintext is returned, check disconnected chat, both tabs/accessibility
and mobile layout. No live provider key or billed research request was supplied
in this cloud session; actual provider/host validation follows on the user's host.

## Investigation workspace (plan implementation)

The original supplied specification is retained in RESEARCH_IMPLEMENTATION_PLAN.md.
The Research view now selects a project and offers **Start research**, an
expandable Investigate tree, **Learn**, history and **Export Markdown**. Earlier
project-scoped chats remain under Simple chat. Existing tables and conversations
are preserved. New runs/nodes/sources/requests tables are added without resets.

New investigations run Dream → claim review → Plan → Evidence → extraction /
provisional reconciliation → grouped verification → verification review / freeze.
Dream retains 2–5 unchecked hypotheses and human selection. Plan reserves 1–2
normal searches and one distinct Challenge search targeting a strong or
high-priority selected claim. A redirected Challenge still seeks counter-evidence.
Each search uses one web tool call. Unattempted challenge slots retain a skipped
reason after Stop/failure; searches without usable citations retain dead ends.
Old investigations keep their saved phase machine and remain readable.

Extraction creates typed relations and provisional assessments against existing
source/passage IDs. Grouped verification also performs final reconciliation;
it requires one assessment per selected claim and reports wording, citation,
scope, dependence and arithmetic issues. Every non-open assessment requires a
matching, same-run edge. Final non-open assessments additionally need a matching
passage-backed edge and no reported high-impact defect. These are model
assessments, not independent proof. Deterministic arithmetic checks validate the
reported calculation on supplied operands, not the operands' source accuracy.

Only HTTP(S) citation URLs from a **completed web-search tool event** create
source cards. URLs merely written in generated prose cannot become cards.
Cards preserve provider title, URL/domain, capture time and linked claim/search
metadata. Search summaries stay on search nodes; new cards do not duplicate that
summary as if it were a source-specific extract. They are provider citations, not independent server-side
page verification. Summaries are labelled as paraphrases, never verified extracts.
Missing tools, absent/invalid citations and empty searches leave visible dead-end
branches. Later synthesis does not remove them. Source URLs open safely as links;
node text/model HTML remains escaped. Markdown export includes actual card URLs,
statuses, questions, dead ends and confirmed token counts.

### Shared-host execution and Stop

No shell worker, daemon, cron or queue service is required. Start only creates a
persisted run. JavaScript then POSTs one phase at a time while polling an
authenticated status endpoint every 1.5 seconds. Nodes appear progressively at
phase boundaries; output is not a private reasoning trace or token-by-token
stream. Token counts are provider-reported totals updated after each response,
not a simulated live estimate. Unknown usage stays explicitly marked.

Start/step/stop/learn/export POSTs require the existing session and CSRF token.
Status GET requires the same session and an X-CSRF-Token header. Session locks
are released before phase/provider work, so Stop and polling can proceed on
another PHP worker. A single-worker local PHP server cannot demonstrate that
concurrency; the HTTP test runs four workers. Actual cPanel worker availability,
request buffering and timeouts still require host-side validation.

The database atomically claims each phase with a private token. **Stop** marks
the run/open nodes interrupted and in-flight requests uncertain. A late provider
reply may contribute confirmed usage, but cannot add claims/sources or revive
the run. It cannot guarantee cancellation of a request already accepted by the
provider or reverse billing. Transport errors and stale workers (after 120s)
become uncertain; malformed or truncated structured output becomes incomplete.
No stopped/uncertain phase is retried automatically. New investigations are an
explicit user action. Leaving/reloading a running investigation can resume its
next unclaimed phase, but never retries a claimed or uncertain request.

Without JavaScript, forms remain usable: Start creates the run, **Continue next
phase** advances it once, Stop works from another page/tab, and reload shows
saved progress. Automatic advancement requires the page to remain open.

### Limits and Learn

The existing ten-provider-requests/hour research limit now covers legacy chat,
all investigation phases and Learn together. Start validates capacity but does
not consume a provider request. A one-normal-search plus Challenge run consumes six calls; two normal searches
plus Challenge consume seven (Dream, Plan, three searches, extraction, verifier).
Start requires at least six remaining calls, reducing normal search capacity to
one if only six remain. Review/freeze costs no provider request. One optional
repair review and an on-demand Learn bring the normal maximum to nine; every
request permits at
most 1600 output tokens and has a sixty-second transport timeout. One running
investigation or pending research/Learn request is allowed per workspace. Budget
exhaustion mid-run leaves an incomplete saved tree rather than silently retrying.
Key/model presence and input/source/output size caps are checked server-side.

Learn appears only once source cards exist and is available after active work
stops. Explain, caveats, compare, go deeper and check-understanding actions send
only the selected run's frozen collected cards/passages, its project brief and
the optional user question. No web tool, other project's chat, unchecked Dream content or
shared Admin notes is sent. Learn replies are saved separately in the Learn
section. Opted-in Admin notes remain available to Dream/Plan/Evidence and legacy
chat, while credential keys never enter prompts.

### Validation

Run `php tests/investigation.php` for migration preservation, phase lifecycle,
claim/source binding, citations, dead ends, combined budgets, key checks, Stop
races, stale workers and uncertain outcomes. Provider traffic is synthetic.
`tests/investigation_http.py` exercises real concurrent PHP HTTP requests and
session/CSRF checks, confirms responsive poll/Stop during provider I/O, rejects
a late answer and tests Markdown export. Browser fixtures exercise Start,
progress through polling, Stop, source links, Learn, accessibility and mobile
layout. Existing vault, tasks, PIN and updater suites remain required.
No live billed OpenAI investigation was performed in cloud; the real model,
web-search entitlement and shared-host behavior remain host-side validation.

### Review the prior before verification

After Dream finishes, the claim board pauses for review. Prior confidence is the
model's own confidence, not a measure of source support. Each card shows the
hypothesis, uncertainty and verification angle; competing angles, assumptions and
the weakest area appear separately. Select at least one claim and choose
**Continue with selected claims**. Plan receives only those selected IDs. Up to
two normal searches and one Challenge follow; claims without useful evidence remain open.
With JavaScript unavailable, Continue uses all claims. Stop works during review
and never resumes a stopped run. An active review still blocks another run.

Evidence cards stay beside the claim they test. They contain real HTTP(S)
citation URLs, model summaries, domain and captured time; they are not verified
quotations. Repeated citation URLs can belong to multiple claims. Final outcomes
are summarized in the claim matrix; excluded claims stay visible, and dead ends
remain in the claim's history. The full tree, source library and Markdown export
retain the detailed record. Old runs remain readable after additive migration.

### Steerable research Map

Desktop investigations open in the stable Map; List remains available and is the
default on mobile and without JavaScript. Map card buttons open saved details
and real citation URLs in an optional inspector. Scroll to pan, zoom or Fit.
**Fit** changes only the camera; **Reset layout** deliberately clears cached
positions. Filters and branch collapse change the local projection and never
delete notebook or ledger records. See the current reliability section below.

Map claim checkboxes mirror the List review selection; Continue still freezes
it before planning. Before an Evidence search, choose a selected claim, its
grown question or linked dead end/open assessment and use **Search this
direction**, optionally with a short note. This replaces the next planned slot
and does not add searches. The redirected plan records that its original query
was not executed. **Continue next phase** runs the planned slot without a focus
override; new flow-enabled runs can use their persisted autoplay preference.

Search this direction is available only during running, idle Evidence with a
remaining planned slot and request/project capacity. Outside that window the
inspector explains the blocking condition. The server rejects busy/pending
requests, foreign or synthetic IDs, excluded claims and unlinked paths. A map
click cannot restart a completed investigation. Focused searches remain parented to the chosen real target; resulting dead
ends are their children. Original claim attribution is retained.

Select citation source cards to Learn from those sources only once eligible
and idle. The inspector offers source selection and copy-link actions; the
ordinary Learn form can continue to use all collected cards. No vault notes or
Dream hypotheses become Learn context. RESEARCH_MAP_CONTRACT.md documents the
status projection and authenticated request contracts.

## Evidence ledger and verification review

Additive, idempotent migrations create `research_passages`, `research_edges` and
`research_claim_versions`, plus source `shared_origin` / `dependence_flag` and run
`rigour_version`, `normal_search_limit`, `repair_count`, `evidence_version`,
`evidence_frozen`, `verification_status`. Existing rows default to the legacy
workflow. No PIN, key, chat, source or run is reset.

Passages contain only literal source `snippet` or `abstract` strings returned in
a completed provider search's source metadata and matching a cited source URL.
They are capped at 2,000 characters / 6,000 bytes and retain supplied locator,
fetch scope and SHA-256. A hash identifies stored bytes; it does not verify the
page. No full-page fetch or generated-quote extraction is implemented. Some
provider responses expose URLs only: those remain honestly citation-only, with
no invented passage, and cannot establish a final non-open verdict. Context
before/after is empty when not supplied. This provider capability needs live
host validation; synthetic fixtures do not establish snippet availability.

Edges store supports / weakens / contradicts / context, source and optional
passage, rationale, strength and version. Helpers reject unknown, excluded or
cross-run IDs and passage/source mismatches. Citation-only edges are low strength
at most. Shared hostname produces a possible-dependence note, not a claim that
two publications share a study. Assessment versions are appended, never replaced.

At verification review, **Finish and freeze evidence** ends the run locally and
increments the evidence version. **Review high-impact gaps once** is available
only for high-impact findings, once, with remaining shared budget. It rechecks
existing evidence without tools and receives the original findings. Unresolved
issues stay open; original findings remain visible after repair. An unknown
repair outcome is not retried. Stop/failure freezes the partial ledger and marks
verification skipped unless already completed. Partial frozen evidence remains
available to Learn with its unverified status visible. Late replies can update
confirmed usage but cannot write ledger records or revive the run.

Learn preserves the stricter existing privacy boundary: selected frozen source
cards and their passages only, plus brief/question; no unchecked prior claims,
edge interpretations, vault notes or live tools. The final claim reconciliation
happens in the ledger-based verifier before freeze. This separates evidence-only
explanations from the auditable assessment history.

Map/List expose typed relations, passage text/locator/hash, assessment history
and retained verification findings. The claim matrix uses the latest assessment
and offers expandable relations/passages; earlier versions remain in history.
Map adds Passages / Edges filters; Finish / Review gaps remain available in
controls and the inspector at verification review.
Challenge slots/searches have distinct titles. No-JS forms retain phase, review,
repair, finish, Stop, Learn and export. Markdown includes ledger, source URLs,
passages, versions, defects and arithmetic checks.

Run `php tests/rigour.php` alongside the existing investigation, HTTP and browser
suites. It covers real captured-string matching, fabricated text/locator refusal,
foreign run IDs, transactional rollback, bounded challenge/repair, frozen writes,
citation-only final-open rules, deterministic arithmetic and Stop races. HTTP
checks include Stop during verifier I/O, repair and finish. Browser fixtures
exercise passage/edge filters, map controls, matrix excerpts, no-JS inspection,
actual freeze/export endpoints and mobile/accessibility. All provider traffic in
these tests is synthetic; no live billed provider or cPanel activation is claimed.

## Usage, project budgets and faster research (current release)

The current default shared research ceiling is **30 calls/hour**, authorised by
the owner. This supersedes the historical ten-call description above. Task agent
requests keep their existing separate three-call limit. Existing runs preserve
the old phase behavior; only newly created flow-enabled investigations autoplay
ranked Evidence searches after human review. Start still needs six calls available.

Open **Project menu** to choose the primary model, project lifetime tracked spend
cap (default **$5 USD**), autoplay and zero to three growth searches. Stronger
high-impact paths and verifier use GPT-4.1 by default; economy work uses mini and
creative idea generation uses nano without search tools. Environment primary-model
overrides remain authoritative. Every model choice is recorded on its request.
One active specialist call remains the shared-host execution model. It does not
start background jobs or parallel workers. Stop remains reachable in Map/List.

Every two completed searches, capacity permitting, a bounded divergent pass
proposes up to six alternative, falsifiable questions using the current findings.
No more than two passes, twelve new question nodes and six searches occur in a
run. Up to three new questions become additional searches, ordered by priority.
The original Challenge slot remains mandatory and is prioritised after at most
two normal searches. Optional expansion reserves remaining calls for extraction
and verification. Ideas are labelled **untested / may be wrong**, purple/dashed,
and cannot acquire claim assessments without being original selected claims.

Priority is a 10–100 attention weight with textual labels, colour and connector
width. It is separate from evidence strength and model confidence. In the map
inspector or Research paths, change an idle active path's weight/model tier.
Failed searches can reduce priority; verifier assessments update it with a
recorded reason. Economy paths remain visible and can continue with mini.
Frozen/stopped runs cannot be reranked into active research.

The new UsageMeter stores returned input, cached-input, output and reasoning
counts, completed search calls, returned model, response ID and a pricing snapshot.
Official model prices checked **2026-11-10**, USD per million tokens:

| Model | Input | Cached input | Output |
|---|---:|---:|---:|
| GPT-4.1 | $2.00 | $0.50 | $8.00 |
| GPT-4.1 mini | $0.40 | $0.10 | $1.60 |
| GPT-4.1 nano | $0.10 | $0.025 | $0.40 |

Sources: https://developers.openai.com/api/docs/pricing and the corresponding
`/api/docs/models/gpt-4.1`, `gpt-4.1-mini`, `gpt-4.1-nano` pages. The app uses the
non-reasoning **web_search_preview** tool: **$0.025 per completed call**, with
search-content tokens free under that tariff. The separate non-preview 8,000-token
mini search-content block is not applied. Changing the tool requires revisiting
the tariff. Rates are release-maintained and dated, not scraped on every call.
Each request saves its rate snapshot so future price changes cannot reprice history.

Calls explicitly request the default service tier to match the saved standard
rates. Costs are **estimates from API usage**, not API-returned invoice amounts. Reasoning
is already included in output; cached input is a subset of input. Neither is
added twice. Unrecognised models/tiers, impossible cache counts, missing usage and
failed/truncated streams remain unpriced/unknown. Old usage is not retroactively
invented. A known subtotal is shown alongside unknown calls and reserved risk.

The tracked cap reserves a conservative upper bound before dispatch (request
bytes plus overhead, maximum output and maximum preview-tool calls). Returned
confirmed usage replaces the reservation. Unknown outcomes retain the reservation
to avoid hiding risk. A cap rejection is an undispatched zero-usage attempt and
ends active research honestly; it does not retry. The cap excludes historical
unpriced requests, actual account credits/discounts, taxes and invoice adjustments.

Tokens & spend exposes project/run totals, per-claim areas, phases, models and each
request. Search costs belong to the requested path/claim. Shared planning, question
generation, mapping, verification and Learn remain a distinct overhead bucket;
they are not counted once for every claim. Legacy simple chat is included in the
project total. Coding-task projects and their runs also show returned-token
estimates. Markdown exports retain the research request ledger and price source.

The top now presents current findings/gaps, provisional recent search observations,
project spend and activity. Setup/projects/history navigation is behind Project
menu. Responses SSE events report accepted/searching/receiving/validating states
and actual visible output-character counts. The UI polls authenticated saved
state, shows elapsed request time and the age of provider activity/poll contact.
It does not claim token counts before the final usage arrives or expose private
reasoning. A truncated stream is uncertain and is not automatically retried.

## Stable concept Map and reliability

The Map is a read-mostly projection of the durable run, notebook and ledger;
List remains the complete chronology. The default canvas contains a root,
selected original concepts and their untested grown questions. All original
claims remain selectable during initial review; excluded claims remain saved
in List/history after Continue. Technical phase, planning, search, synthesis
and Learn records stay in List; linked planning/search/findings history is
also available from the concept inspector. There is no Show process overlay.
Sources/Passages/Edges/Dead ends/Open filters retain concept context and show
an explicit empty state when matching saved evidence does not exist. Old runs
with absent or partial ledger arrays remain readable.

A structural layout replaces the force simulation. In-memory position memory
lasts for the page/run, including cards hidden by filters or collapse. New cards
receive positions and a bounded collision pass; existing positions stay fixed.
**Reset layout** intentionally clears that cache. **Fit**, zoom and pan change
the camera only. Idle/token/activity polls do not lay out the graph; viewport
resize is debounced. `?map_debug=1` enables console graph-count, timing and reuse diagnostics
to help detect regressions. The structural renderer is the only Map engine;
`?map=stable` reaches the same path. The performance target is below 50 ms for projection
plus layout on representative two-to-five-claim fixtures.

The optional inspector shows title/status/full saved text, concept history,
real source URLs, exact passages with locators and edge relation/rationale.
It offers Jump to a persisted List entry when available, copy link, source
selection, eligible Learn/focus and
path-ranking actions. Synthetic passage/edge/source display IDs are never
submitted as focus targets. Shared-source dashed links only identify a common
citation; they do not add an evidence edge or imply independence.

Actual pending requests produce a static stage badge on their owning concept;
shared work can mark selected concepts. There is no continual activity pulse,
and failed/stopped/uncertain runs never appear to be working. Text labels
identify planning, research, discovery and checking without exposing private
reasoning. Priority and speculative status remain separate from evidence.

The compact top sentence remains a local reading queue of public ideas and
findings for 5–10 seconds each. Click it to pause/resume; Idea history holds the
backlog. It makes no provider request and preserves provisional/untested labels.
Sentence fades do not resize the graph or steal keyboard/form focus. Hidden
tabs preserve reading time; reduced-motion preference uses static transitions.

Hover, click/tap or keyboard-activate Map controls for Start/Stop, filters,
Fit/Reset/zoom, List, tokens/spend, Idea history and Project menu. Alt+S submits
the existing Stop action. Error and verification-review notices remain visible
with controls collapsed. Details overlays the canvas; closing it returns space
to the data. Escape closes overlays before returning to List. Review controls
stay synchronized with List, and no-JS keeps the complete forms and notebook.

The release's stored snapshot fixtures are representative synthetic examples,
not captured owner data. Local PHP/HTTP/browser checks and synthetic provider
transport do not establish billed API behavior or cPanel activation. Consult
PROJECT_STATE.md for the final validation scope and RESEARCH_MAP_RELIABILITY_PLAN.md
for the supplied milestones and any remaining authenticated-host checks.
Select citation source nodes to Learn from those cards only, once the run is
idle. The inspector also offers the current source's Learn and copy-link actions.
No vault notes or Dream hypotheses are sent as Learn context. The ordinary Learn
form continues to use all collected cards. See RESEARCH_MAP_CONTRACT.md for the
status fields, request contracts and a separate live-host validation checklist.

## Diagnostic reports

Open Admin and use **Copy contents** under **Detailed error and research log**.
Refresh Admin after reproducing a failure to include the latest events. The
readonly report can also be selected manually if clipboard permission is denied.
It includes runtime capabilities, source fingerprint, provider status/timing,
request correlation, research phases and sanitized exception details. It omits
prompt bodies and vault contents. Logs are private, persist across updates, and
retain roughly the latest 0.5–1 MB. Diagnostic collection is best effort: an
unwritable private directory or a browser unable to reach the server can prevent
an event being recorded. Missing events do not establish success.

Reconciliation's strict provider schema limits each claim to its linked source
IDs; claims without evidence can only be open. Server checks still reject
unrelated/unknown source IDs, duplicate claims and invalid verdicts. A live cloud
browser investigation completed all phases using the environment-configured
OpenAI credential on 12 November 2026 AWST; hosting validation remains separate.

Progress checks use authenticated POST form requests (research_status) with the
same CSRF protection as Start/Stop, avoiding dependence on a custom CSRF header.
They do not invoke the provider or consume request budget. If a host returns a
non-JSON response, the report now includes HTTP status, content type, redirect
flag, response pathname and error category, while excluding the response body.

Provider return diagnostics include a bounded structural summary of the API JSON:
response/model IDs, status, token usage (including cached/reasoning details when
returned), incomplete/error details, tool operations and citation metadata. Large
strings and generated text/arguments are replaced with byte counts and SHA-256
fingerprints. Prompt/input/instructions, private metadata and opaque encrypted
payloads are omitted. Array, depth and total-size caps explicitly mark omissions.
Streaming diagnostics also retain event-type counts and stream error facts, even
when a stream ends without a complete final response. This is a concise sanitized
API-return summary, not an archive of complete response bodies.

## Guided create and draft writing help (local draft)

New research opens an eight-row brief with a working title, starters, examples,
soft hints and scope/evidence coaching. A plain HTML POST still creates a project;
JavaScript is not required. The saved brief is nonempty and limited to 4,000 UTF-8
bytes, title to 160 bytes. Invalid creates retain the text for correction.

POST `research_brief_suggest` requires the normal authenticated session and CSRF.
It accepts `draft` (16 characters minimum, 2,000 UTF-8 bytes maximum) and optional
`name` (160 bytes). It emits SSE start/delta/done/error, has no tools, caps output
at 240 tokens and transport at 25 seconds. Success includes alternatives and
returned token usage/estimated cost. No prompt, vault notes or generated text is
persisted by this feature. Summarized provider errors remain in Admin diagnostics.

Draft assistance has a dedicated 20-request/$0.01 USD rolling-hour allowance,
including unknown-call reserves. It operates before project creation, so it does
not debit project caps or the research request ceiling. Investigation/chat/Learn
continue using their existing project caps. `brief-suggestions.json` privately
retains at most 100 metadata records. The common provider lock prevents overlapping
AI dispatches, while Stop/status remain available. Aborting browser display does
not prove the provider stopped; unknown outcomes are retained without retrying
that same draft for the hour. Quality hints never block creation.

The helper always uses gpt-5.4-nano with reasoning effort none, as explicitly
approved by the owner after the plan's original gpt-4.1-nano returned retirement
HTTP 404. No fallback or primary-model override is used. Saved input/cached/output
rates are $0.20/$0.02/$1.25 per million tokens, checked 12 November 2026 against
https://developers.openai.com/api/docs/models/gpt-5.4-nano. Both the alias and
2026-03-17 snapshot are priced; unknown snapshots remain unpriced. A real cloud
browser successfully streamed three alternatives and saved an applied suggestion
without starting research. This does not establish cPanel stream/proxy behavior.
The UI remains usable with static examples and normal creation if help fails.

Admin's Saved workspace data controls can clear/delete research projects,
individual investigations and legacy conversations; related sources, passages,
ledger relations, rankings and usage are removed in dependency order. See
ADMIN_DATA.md for confirmation phrases, active-worker behavior and reset scope.
Database/PHP technical metadata and a copyable report are now available there.
