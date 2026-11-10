# Private admin vault and research projects

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

### Steerable mind map

Desktop investigations open in Map; List remains available and is the default on
mobile or without JavaScript. Node buttons reveal full details and citation URLs
in the inspector. Scroll to pan, zoom or Fit (with a readable minimum zoom), and
collapse branches. Filters focus on claims, sources, dead ends or open findings.
Map claim checkboxes mirror the list's review selection; Continue still freezes
that selection before planning. The outcome strip jumps to a verdict and its
claim/source path. Filters and collapse never delete saved nodes.

Before each Evidence search, choose a selected claim or linked dead end and use
**Search this direction**, optionally with a short note. This replaces the next
planned slot; it does not add searches. The original plan is labelled incomplete
when redirected so its query is not falsely reported as executed. Alternatively,
use **Continue next phase** to run the planned search. Focus is disabled outside
an idle Evidence phase, including completed runs and unlinked open questions.
A completed investigation does not start another cycle from a map click.

Select citation source nodes to Learn from those cards only, once the run is
idle. The inspector also offers the current source's Learn and copy-link actions.
No vault notes or Dream hypotheses are sent as Learn context. The ordinary Learn
form continues to use all collected cards. See RESEARCH_MAP_CONTRACT.md for the
status fields, request contracts and a separate live-host validation checklist.

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
Map adds Passages / Edges filters and visible header Finish / Review gaps controls.
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
