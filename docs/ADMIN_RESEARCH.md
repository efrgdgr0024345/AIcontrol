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

The phase machine runs Dream → Plan → Evidence → Reconciliation. Dream returns
up to four concise unchecked hypotheses, uncertainty/confidence and verification
angles (not private internal reasoning). Plan derives up to two verification
steps linked to saved claim IDs. Each Evidence step allows one web-search tool
call, one branch at a time. Reconciliation must cover every saved claim with
supported/weakened/contradicted/open and valid collected source IDs. Non-open
verdicts require source references; they remain model assessments, not proof.

Only HTTP(S) citation URLs from a **completed web-search tool event** create
source cards. URLs merely written in generated prose cannot become cards.
Cards preserve provider title, URL/domain, capture time, model summary and linked
claim/search metadata. They are provider citations, not independent server-side
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
not consume a provider request. A normal two-search investigation consumes five
requests (Dream, Plan, two Evidence, Reconciliation); every request permits at
most 1600 output tokens and has a sixty-second transport timeout. One running
investigation or pending research/Learn request is allowed per workspace. Budget
exhaustion mid-run leaves an incomplete saved tree rather than silently retrying.
Key/model presence and input/source/output size caps are checked server-side.

Learn appears only once source cards exist and is available after active work
stops. Explain, caveats, compare, go deeper and check-understanding actions send
only the selected run's collected cards, its project brief and the optional
user question. No web tool, other project's chat, unchecked Dream content or
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
two searches follow, so selected claims without useful evidence can remain open.
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
