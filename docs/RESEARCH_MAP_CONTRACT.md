# Research Map contract

## Rotation, common-idea links and current model work (2026-11-14 AWST)

Map rotation now supports buttons, Shift-drag, a drag-mode toggle, keyboard Q/E/0
and two-finger twist/pinch/pan. Labels stay upright and saved positions stay fixed.
Related branches share family colours; labelled display-only links expose common
family, matching wording and shared citations without creating evidence. Current
model work has a visible banner, per-node static rings/badges and request details.
Markers track actual pending context, clear on Stop/unverified progress and recover
after confirmed status, including unchanged snapshots. No extra model calls occur.

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


## Exploration-first living map (2026-11-13 AWST)

New runs use flow version 2: tool-free provisional seed → user-steered model
exploration → explicit selected-branch Evidence lock-in → verification/review.
This supersedes earlier new-run defaults in this document; existing runs keep
their persisted workflow. Active/Secondary/Tertiary metadata never deletes ideas.
Map and List provide model exploration, layer/park controls and scoped lock-in;
Admin JSON and Markdown expose the stored provenance. See
[EXPLORATION_FIRST.md](EXPLORATION_FIRST.md) for exact behavior, milestones,
limitations and tests, and [the supplied plan](EXPLORATION_FIRST_PLAN.md).


The Map is a read-mostly projection of a saved investigation. The run, notebook
and evidence ledger are authoritative; projection, filters, collapse, camera
and position memory do not write research rows or change the phase machine.
The stable structural renderer is the only Map path. No graph service, CDN,
physics loop, background worker or additional provider request is introduced.

## Snapshot and evidence

Authenticated `status` returns the existing run, nodes, sources, requests,
budget and usage. Session/CSRF checks still guard actions. Polling reads saved
state and derives relationships in memory; it does not perform per-node SQL or
send provider requests. Status remains available while provider I/O releases
the session lock.

Nodes retain `id`, `parent_id`, `type`, `status`, `title`, `content`,
`sort_order`, `data_json`, `selected` and timestamps. Additive fields include
`confidence`, `linked_claim_ids`, `source_ids`, `dead_end_reason`, path weight,
model tier and speculative status. Unknown confidence is not evidence support.
The ledger is `{claims, sources, passages, edges, claim_versions}`. Missing or
partial legacy ledger arrays are treated as empty; existing notebook/source
rows remain readable. Malformed parent chains must terminate safely.

Passages contain source/run IDs, literal `exact_text`, optional locator, scope,
SHA-256 and timestamp. They come only from provider snippet/abstract fields
captured in a completed search-tool event. A URL-only result is citation-only;
the UI does not imply a full-page fetch or construct a quotation from model
text. Typed edges retain relation, rationale and strength. The latest saved
per-claim assessment supplies the outcome; earlier versions remain history.
Source URLs always come from the stored source table and must be safe HTTP(S).

The root and synthetic `source-ID`, `passage-ID` and `edge-ID` display IDs are
client presentation only, never new `research_nodes` records or valid focus
targets. Map projection never creates passages, edges or assessments. Empty
evidence filters say that no matching stored evidence exists.

## Projection and ownership

`assets/map-engine.js` is pure and DOM-free; `assets/map-view.js` owns the Map
DOM, camera, cache and inspector, with snapshot/actions wired through `panel.js`.
`build-ui.php` embeds these local sources into the generated UI/loader. The same snapshot, filter and collapse state produce the same
ordered graph. The default Concepts projection contains a synthetic root,
selected original `dream_claim` nodes and their grown `question` descendants.
During initial claim review all original claims are available for selection.
After Continue, excluded claims remain in the durable List/history, rather
than reappearing as active research paths on the overview.

Question ancestry resolves to its original selected claim. Source ownership
uses saved node ancestry, source metadata and ledger links. Passage ownership
uses passage-specific relation owners first, then source associations; edge
ownership follows its saved claim. An
unresolved or obsolete reference must not be invented as a selected claim.
The projection normalizes absent optional collections before walking them.

Sources, Passages, Edges, Dead ends and Open filters reveal matching saved
records with their concept context. They change only the projected set. Phase,
plan, search, synthesis and Learn records remain in the complete List; linked
planning/search/findings history is also inspectable from a concept. They are
not separate cards on the overview. There is no separate Show process overlay.
Collapse is local to the page.

Dashed shared-source links mean that concepts cite the same stored source ID.
They do not assert corroboration, independence, causation or evidence strength.
Path priority controls attention/model routing and connector weight; it is
distinct from a claim's confidence, edge strength and verification verdict.
Grown questions remain labelled untested and possibly wrong.

## Structural layout and camera

The layout places the root and concept branches structurally, with questions
and optional evidence attached to their owning branch. There is no continuous
force simulation. An in-memory position cache is retained for the page/run;
only newly visible IDs receive initial coordinates and a bounded collision
separation. Existing cached coordinates remain fixed as new nodes arrive.
Hidden/collapsed cards retain their cached positions for later restoration.

New IDs, filter/collapse changes, a debounced viewport resize and explicit
**Reset layout** can update the projected layout. None of those changes
silently clears existing position memory. Reset is the deliberate action that
clears it and builds a fresh arrangement. Idle, usage, token, request-activity,
status-text and reading-queue updates do not trigger layout or move cards.

**Fit** changes only camera scale/scroll to frame visible cards. Zoom and pan
also preserve world coordinates. Large evidence views can exceed the viewport;
the interface keeps readable cards and scrolling instead of deleting records
or shrinking text indefinitely. The inspector is an overlay, not a reserved
canvas column. The target for projection plus structural layout is under
50 ms for a representative two-to-five-claim fixture; fixture measurements
are not a guarantee for every device or arbitrarily large ledger.

The stable renderer is also reachable with `?map=stable`; it is not a second
engine. `?map_debug=1` enables developer-only console diagnostics with projected
node/edge counts, layout duration and position reuse. They do not reveal credentials, private reasoning or a
provider-progress percentage.

## Interaction and progressive enhancement

Tested desktop sessions open in full-screen Map; mobile starts in List with
Map optional. Without JavaScript, the complete List, review controls, source
links and phase/Stop/Learn/export forms remain usable. Map/List switches do
not change the run. Map review checkboxes mirror the existing List selection;
`research_continue` locks it before Plan.

Cards are keyboard buttons with arrow navigation. Selecting one opens an
optional inspector containing saved title, status, full text, concept history,
source URLs, exact passages with locators and typed-edge relation/rationale.
It also offers Jump to List when a persisted entry can be resolved, copy source
link, source selection and applicable
Learn/rank/focus actions. Text is inserted as text, never model HTML. Clipboard
failure leaves a visible source URL that can be copied manually. Filters do
not delete saved data. Escape closes controls, then details, then returns to
List, with keyboard focus restored.

One small handle reveals controls on hover, click/tap or keyboard activation.
Start/Stop, Fit/Reset/zoom, filters, List, usage and Project menu are there;
Alt+S submits the ordinary Stop action. Failure and review states retain a
compact notice with the controls collapsed. Claim review remains reachable.

Actual pending request attribution (`path_node_id`, `claim_id` or saved node
ancestry) supplies a static activity badge on the owning concept. Shared work
can mark selected concepts. Planning, evidence, discovery and checking are
textual execution states, not private reasoning. No continual activity pulse
runs, and failed/stopped/uncertain runs never appear to be actively working.

One public sentence remains in the compact Idea strip. A deduplicated local
queue holds existing claim/question/findings excerpts for 5–10 seconds each;
it does not call an AI to summarize them or upgrade provisional text into fact.
Click/tap pauses or resumes, hidden tabs retain reading time, and Idea history
exposes the backlog. Fades replace only the sentence and preserve map layout,
form state and focus. Reduced-motion preference uses immediate/static changes.

## Explicit search steering

`research_step` accepts optional numeric `focus_node_id` and a UTF-8
`focus_note` of at most 1,000 bytes. The selected real node must belong to the
same run and resolve through persisted `parent_id` ancestry to a selected
original claim: that claim, one of its
questions, a linked dead end or a linked open assessment. Synthetic evidence
display IDs, foreign IDs, excluded paths and unlinked questions cannot spend
a search. A recorded verdict takes precedence over a legacy title prefix.

Metadata-only ownership fallbacks support display and cannot make a node
focusable. Focus requires a running, idle Evidence phase, no pending provider
request, a remaining planned slot and available request/project budget. Flow-
enabled runs must have an actual pending plan row. The UI enables **Search
this direction** only in that window; otherwise it explains the blocking
condition. Server validation is authoritative and errors remain visible.
An optional note affects this search only. The exact real node ID and note
are submitted through the existing authenticated/CSRF action.

Focus replaces the next slot; it never adds capacity or restarts a terminal
run. The replaced plan records that its original query was not executed.
Focused searches are parented to the chosen target; any resulting dead end
is a child of that search. Sources belong to the resulting search, and the
original claim attribution is retained. The inspector/history and List expose that saved relationship.
The reserved Challenge keeps its counter-evidence requirement when redirected.
**Continue next phase** executes the pending plan without a focus override.

New flow-enabled runs preserve human claim review, then use their persisted
autoplay setting. They permit at most six searches, two creative passes and
twelve untested grown questions, while preserving extraction/verification
capacity. Older runs retain their saved flow and limits. One active specialist
request at a time, the shared hourly ceiling, output limits, timeout and Stop
guards remain in force. A late response after Stop cannot write evidence.

## Related actions and accounting

`research_learn` optionally accepts unique `source_ids[]` belonging to the run.
Explicit selection cannot be empty. Idle/frozen-evidence and budget guards
apply. Provider context is only those frozen source cards and matching
passages, the project brief and optional question: no tools, Dream hypotheses,
vault notes, other projects or keys. Source selection is recorded on Learn.

`research_finish` needs idle `verification_review`, completed verification
and assessments; it freezes/version-bumps and completes locally.
`research_repair` needs the same state, an original high-impact finding,
no prior repair and one remaining request; it makes one tool-free review
before returning to review. Both retain findings/history and use existing
session/CSRF, phase-token and Stop guards. Neither resumes an uncertain run.

Usage remains API-returned token counts with dated USD estimates and saved
pricing provenance, not invoice totals. Missing usage/unpriced models remain
unknown. Shared overhead and per-path costs remain separate. Project cap
reservations, unknown outcomes, routing/ranking history, model choices and
bounded growth are described in ADMIN_RESEARCH.md; this Map update does not
alter them. Streamed provider activity is saved public activity, not a fake
token ticker or private reasoning trace.

## Validation boundary

Pure fixtures cover review/selected projection, ownership, filters, missing
and partial ledgers, deterministic ordering, stable cached positions and
new-node insertion. Browser checks cover review synchronization, evidence
inspection, focus state, Reset/Fit, token-only stability, controls, mobile,
keyboard, reduced motion and no-JS fallback. Backend/HTTP checks cover exact
focus contracts and preserved budget/Stop behavior. See PROJECT_STATE.md for
the final commands and measured scope of the release.

The stored snapshot fixtures are explicitly synthetic and representative;
they are not captures from the owner's live database. No hosting session,
PIN or provider key is available in this cloud checkout. Synthetic provider
transport and local browser checks do not establish a billed live API run
or activation on cPanel. A checked GitHub release and its loader compatibility
pointer must be distinguished from an authenticated host installation.

Admin JSON inspection now reuses this payload builder without timeout recovery
and the exact AIcontrolMap.project implementation. It exposes the difference
between stored rows, delivered payload and filtered/grouped cards, including
source IDs versus persisted/display-only node IDs. Inspection does not advance
research. See PROJECT_JSON_INSPECTION.md for scope, export and view-state limits.
