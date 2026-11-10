# Research map contract

`status` remains authenticated by PIN/session and CSRF. It reads existing run,
node, source and request rows; map relationships are derived in memory. No schema
reset, external graph service, provider request or per-node SQL occurs on polling.
The shared budget and confirmed input/output counts remain in the snapshot.

Each node retains `id`, `parent_id`, `type`, `status`, `title`, `content`,
`sort_order`, `data_json`, `selected` and timestamps. Additive response fields:
`confidence` (claim prior confidence or null), `linked_claim_ids`, `source_ids`
and `dead_end_reason` (dead-end text or null). The synthetic map root is client
presentation only. Types: phase, dream_claim, plan_step, search, source, dead_end,
synthesis and learn. Unknown confidence never means evidence support.

Map/List switches do not change the run. Claim map checkboxes mirror the existing
review form. `research_continue` still locks selection before Plan. Without JS,
the list, source links and all phase/Stop/Learn/export forms remain usable.

`research_step` accepts optional `focus_node_id` and `focus_note` (UTF-8, at most
1,000 bytes). Focus requires a running, idle Evidence phase and a remaining
planned slot. A focus node must be a selected claim, its dead end or its linked
open verdict. Unlinked questions have no inferred claim; they cannot spend a
search. Wrong run, excluded claim, busy phase and exhausted slots are rejected.
The next slot is replaced, never appended: new runs allow 1–2 normal searches
plus one reserved Challenge (legacy runs retain two searches), one web tool
call per request, 1,600 output tokens and the existing timeout/hourly cap. JS
pauses before each Evidence slot so the owner can steer it or explicitly run
the planned search. No-JS uses Continue next phase for the planned search.

Focused searches are parented to the chosen node; their `data_json` records the
node, claim and note. The replaced plan is marked incomplete with an explanation
that its original query was not executed. Citations still require a completed
web-search tool event and safe HTTP(S) URLs. A late answer after Stop never
applies evidence. Focus cannot revive a terminal run or restart Dream/Plan.

`research_learn` optionally accepts `source_ids[]`. All IDs must belong to the
chosen run, with no duplicates or empty selection. Its provider context contains
only the selected frozen source cards and matching passages, project brief and
optional question: no tools,
Dream text, notes from the vault, other projects or keys. Existing idle/budget
and Stop behavior applies. Source selection is recorded on the Learn node.

The map offers native scrolling, zoom, Fit, collapse/expand, filters, keyboard
node buttons and arrow navigation. The inspector exposes full text and real
source links; clipboard access falls back to copying the displayed link. Node
text is rendered with textContent, never model HTML. Filters/collapse are local
views: excluded claims and dead ends remain stored and visible in All/Expand all.
The outcome strip jumps to verdicts and highlights the original claim and cited
sources. Desktop starts in Map; mobile and no-JS start in List. Markdown export
contains the notebook report plus a node-to-parent outline and all citation URLs.

Host validation remains separate: install the checked release using the current
loader, connect a host-side key, perform one focused search, Stop mid-Evidence,
inspect uncertain usage, Learn from one source and export the report. CI uses
synthetic transport and does not perform billed provider calls.

## Rigour snapshot and actions

Status adds `ledger: {claims, sources, passages, edges, claim_versions}`; old runs
receive empty ledger arrays except their existing claims/sources. New run fields
are `rigour_version=1`, `normal_search_limit`, `evidence_version`,
`evidence_frozen`, `verification_status` (pending/complete/skipped), `repair_count`.
Edges and passages are client map nodes with `edge-ID` and `passage-ID` IDs; they
are not new `research_nodes` types or valid focus-node IDs. Relations stay typed;
source links always come from the stored source table. Outcome totals count the
latest per-claim assessment; history stays visible in the branches/ledger.

`research_finish` accepts `run_id`, requires idle `verification_review`, completed
verification and assessments, then freezes/version-bumps and completes locally.
`research_repair` requires the same state, an original high-impact finding, no
previous repair and one remaining request. It invokes one tool-free repair review
and returns to review, preserving original findings. Both require session/CSRF;
repair releases the session before I/O and uses the existing phase token/Stop
guard. Neither can resume interrupted or uncertain runs.

New phases: `extraction`, `verification`, optional `repair`, `verification_review`.
JS pauses at verification review; visible map header controls and list/no-JS
forms offer Finish and optional repair. During Evidence it still pauses before
each search so user steering remains explicit. Challenge identity survives a
focus replacement and the prompt retains the counter-evidence requirement.

Passages include source/run IDs, literal `exact_text`, optional locator, scope,
SHA-256 and timestamp. Only provider snippet/abstract fields captured from a
completed tool event can populate them. URL-only responses stay citation-only;
no page fetch or model-constructed quote is implied. Frozen partial interrupted
runs remain inspectable and can supply evidence-only Learn, with verification
skipped shown. No data or historical findings are removed by freeze or repair.
