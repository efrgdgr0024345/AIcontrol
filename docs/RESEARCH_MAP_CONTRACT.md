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
The next slot is replaced, never appended: at most two searches, one web tool
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
only the selected source cards, project brief and optional question: no tools,
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
