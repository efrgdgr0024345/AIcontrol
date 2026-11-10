# Research Map reliability plan

The owner supplied the plan below. This copy preserves the attachment text;
RESEARCH_MAP_CONTRACT.md describes the resulting implementation contract.
The checklist records the implemented milestones and verified local scope.
An authenticated production snapshot capture remains a separate external check.

## Milestone completion checklist

- [x] 0 — Make stable the only Map renderer; add developer-only graph/timing/reuse
  diagnostics and representative fixtures. Five representative snapshots are explicitly synthetic; authenticated owner
  snapshot capture remains the external check below.
- [x] 1 — Deterministic concept-first projection, selected/review rules, correct
  question/evidence ownership, filter context, old/partial-ledger safety and pure
  fixture checks.
- [x] 2 — Structural placement, page/run position memory, bounded new-card
  collision handling, explicit Reset, camera-only Fit and token/idle-poll stability.
- [x] 3 — Calm distinct card types, keyboard inspector, nondestructive filters,
  evidence empty states, exact passages/locators and typed-edge rationale;
  static activity rather than continual pulse.
- [x] 4 — Explicit visible focus eligibility/reasons, exact real focus ID/note POST,
  server rejection of invalid/busy/exhausted targets, and saved focus-search/dead-
  end ancestry in Map history and List.
- [x] 5 — Tested Map default on desktop, List default on mobile, fully usable
  no-JS forms/notebook and synchronized claim review. The compact sentence queue
  must preserve layout and keyboard/form focus.
- [x] 6 — Truthful source/passage/edge views and shared-source links; preserve the
  existing provider-snippet ledger and citation-only/empty behavior without
  fabricating evidence or fetching full pages.
- [x] 7 — Pure/backend/HTTP/browser regression checks, stable-position and timing
  measurements, updated contract/admin/state docs, rebuilt UI/manifest and exact-
  commit required GitHub check. Published GitHub release is separate from cPanel
  activation.

## Validation record

Validated 2026-11-11 AWST on PHP 8.4.24: host and syntax checks; all twelve
application PHP suites (419 numbered assertions and unnumbered storage/adapter/
workspace checks); all five required Python HTTP/installer/authentication suites;
27 pure map checks and the complete Chromium UI suite, including 33 axe audits
with zero violations. UI and release-manifest reproducibility checks pass.
The browser verifies unchanged coordinates on usage/activity polls and new-node
insertion, filter position restoration, collapse controls, Fit/Reset, inspector keyboard focus, visible
focus rejection, persisted List jumps, source-selection synchronization, legacy
fallback IDs/cyclic parents, evidence empty states, mobile and no-JS behavior.

A 1,000-sample-per-size cloud microbenchmark for two/five concept-only snapshots
measured projection plus layout medians 0.015/0.014 ms and maxima 3.339/0.334 ms.
This excludes DOM rendering and does not establish mid-tier device performance.

- [ ] Authenticated production snapshot capture and live-host smoke check: no
  hosting session/PIN/key is configured here. Five representative fixtures are
  marked synthetic. These local checks do not establish billed provider
  operation or cPanel activation.

The owner has authorized publishing tested updates to `main` and fast-forwarding
the compatibility pointer. The exact GitHub SHA/check identifies release evidence;
host activation still requires authenticated evidence.

## Supplied plan (verbatim)

Deep Coding Plan: Research Map — Make the Concept Map Actually WorkGoal

Turn the current broken “steerable mind map” into a reliable, concept-first investigation canvas that:
Shows the user’s selected claims (and any questions grown from them) as the primary objects.
Makes evidence (sources → passages → edges) inspectable without drowning the canvas in process noise.
Supports explicit human steering of the next Evidence search.
Stays stable under polling, works on shared hosting, and degrades cleanly to List / no-JS.
Never lies about what evidence actually exists.
The map is a read-mostly projection + interaction surface on top of the durable run/ledger. It is not the source of truth and must not re-invent the phase machine.1. Product definition (lock this first)Primary job of the Map

Answer, at a glance:“What are we investigating, what evidence have we collected for each claim, and what is the system doing right now?”Secondary jobs
Let the user focus the next search on a specific claim or dead-end.
Let the user open a source / passage / edge in an inspector without leaving the canvas.
Provide a calm overview; detailed chronology lives in List / claim board.
Non-goals (explicitly cut or demote)
Live force-directed theatre that re-simulates on every poll.
Showing every phase/plan/search node as a first-class card by default.
Treating the map as an editable graph database.
Fancy mind-map editing (drag to re-parent, free-form links, etc.).
Success criteria (measurable)
After Dream + claim selection, the map shows the selected claims as stable cards under a root.
After Evidence, filtering to Sources or Edges shows real stored data (or a clear empty state).
Layout does not jump on status poll while the run is idle or only token counts change.
Focus / “Search this direction” works only when the phase machine allows it, and is obviously enabled/disabled.
Mobile and no-JS users are never forced through a broken Map; List remains fully functional.
Old runs without ledger data still render without errors.
2. Current failure modes (what “completely broken” maps to)












































AreaSymptomRoot causeLayoutNodes jump, overlap, fly off canvasFull force + collision re-run on every poll; weak position memoryProjectionWrong parents, empty canvas, cards under rootFragile owner() walk + incomplete/inconsistent parent_id / selectionEvidenceFilters show nothing usefulLedger (passages/edges) incomplete or not projected reliablyInteractionFocus / Search this direction feels deadStrict phase gating + poor visual affordance + disabled statesMental modelUser can’t see the investigationDefault concept-only view hides process; List is secondaryPerformance / feelConstant motion, hard to clickPoll-driven re-layout + reading-queue ticker + activity pulsesResilienceErrors or blank map on older runsAssumptions about node types / ledger presenceThe plan below attacks these in order of leverage.3. Target architecture3.1 Data contract (server → client)

Status / snapshot remains the single source. Map never invents nodes.Required fields (already partly present; make complete and stable):textrun: { id, status, phase, ... }
nodes: [ { id, parent_id, type, status, title, content, data_json, selected, ... } ]
sources: [ { id, node_id, url, title, extract, meta_json, ... } ]
requests: [ pending / recent ]
ledger: {
  claims: [...],          // optional convenience
  sources: [...],
  passages: [{ id, source_id, exact_text, locator, fetch_scope, ... }],
  edges: [{ id, claim_id, passage_id?, source_id?, relation, rationale, strength, ... }],
  claim_versions: [...]
}Rules:
Map projection is pure and deterministic given the same snapshot + filter + collapse set.
Synthetic client IDs (passage-123, edge-45) are allowed for display only; they are never sent back as focus_node_id.
Missing ledger → empty arrays, not nulls that crash the projector.
3.2 Projection model (concept-first, evidence-on-demand)Default visible set:
Synthetic root
dream_claim nodes that are selected (or all claims if no selection yet / review stage)
question nodes that hang off those claims (if growth/exploration exists)
Hidden by default (available in List or via explicit “Show process” / history):
phase, plan_step, search, synthesis, learn
Filter modes (mutually understood, not destructive):
all / concepts (default)
sources
passages
edges
dead_ends
open (open verdicts)
Shared-source links: dashed edges between claims that share a source_id. These are informational only.3.3 Layout strategy (the critical reliability fix)Replace continuous force simulation with a stable, mostly-deterministic layout:
Structural layout first (tree / radial / layered):
Root at centre or top.
Claims in a first ring or row.
Questions as children of their claim.
Evidence nodes (when filtered) as a second ring or side column attached to their claim.

Position memory:
Persist {nodeId → {x, y}} in memory for the lifetime of the page (and optionally sessionStorage keyed by run_id).
On snapshot update, only re-position new nodes; existing nodes keep their coordinates unless the user clicks “Reset layout”.

Light collision only:
One-shot or very short separation pass for new nodes.
No 180-step force on every poll.

Fit / zoom / pan:
Keep existing pan/zoom.
“Fit” recomputes a camera, not a new physics world.

This single change will remove most of the “completely broken” feeling.3.4 Interaction model
Click card → open inspector (title, status, content, linked sources/passages/edges, actions).
Claim checkbox (during dream_review) stays in sync with List.
When phase is idle Evidence and a slot remains:
Selected claim or its dead-end shows Search this direction.
Optional short note.
Submitting sets focus_node_id + focus_note on the next research_step.

Outside that window the control is hidden or clearly disabled with reason (“Available when the next search is ready”).
Activity: subtle, non-animated (or reduced-motion friendly) indicator on the claim that owns the pending request. No continuous pulse that fights the layout.
3.5 Inspector
Always shows real URLs from sources table.
Passages show exact_text + locator when present.
Edges show relation + rationale.
Actions: Learn from this source (if idle), copy link, jump to List entry.
4. Implementation plan (milestones)Milestone 0 — Freeze and measure (½–1 day)
Add a feature flag or query param: ?map=stable (or make stable the only path).
Log (client-side, dev only) projection output size, layout time, and whether positions were reused.
Capture 3–5 real snapshots (Dream only, mid-Evidence, completed with sources, old run) as fixtures.
Milestone 1 — Reliable projection (core)Files: assets/map-engine.js (or successor), status consumer in panel JS, any ResearchView helpers.
Rewrite project(snapshot, filter) to be strict and well-tested:
Deterministic ordering.
Clear rules for which claims appear (selected vs all).
Correct ownership for sources/passages/edges/dead_ends.
Empty-ledger safe.

Unit tests for projection with the fixtures above (node, edge counts, parent relationships, filter behaviour).
No layout changes yet — just correct graph data.
Milestone 2 — Stable layout engine
Implement structural layout + position memory + minimal collision.
API roughly:JavaScriptlayout(nodes, edges, viewport, previousPositions) → { positions, worldSize }
“Reset layout” control.
On status poll: if only usage/tokens/activity changed, do not re-layout; only update badges/indicators.
Visual regression or simple screenshot fixtures optional but valuable.
Milestone 3 — Canvas chrome and inspector
Card rendering: claim vs question vs source vs passage vs edge (distinct but calm).
Inspector drawer or panel (keyboard accessible).
Filters that only change the projected set; they never delete server data.
Clear empty states (“No passages stored for this run yet”).
Reduced-motion: no continuous animation.
Milestone 4 — Steering that feels intentional
Surface Search this direction only when phase + budget + idle conditions allow it.
Wire focus_node_id / focus_note exactly as the contract specifies.
After focus search, show the new search/dead-end under the focused claim in both Map and List.
Disable/hide cleanly; never silently no-op.
Milestone 5 — Default experience and progressive enhancement
Recommendation: default desktop to Map only after Milestone 2–3 are solid; until then default to List (or Map with a prominent “List is authoritative” notice).
Mobile: List default; Map available but not required.
No-JS: full List + forms; Map is progressive enhancement only.
Claim review checkboxes stay synchronized.
Milestone 6 — Ledger-aware evidence view
Once passages + edges are populated by the investigation pipeline (parallel track from the earlier rigour plan):
Filters become actually useful.
Edge cards show supports / weakens / contradicts / context.
Shared-source links become meaningful.

Map remains a projection; it does not write edges.
Milestone 7 — Hardening and docs
Tests: projection unit tests, layout stability (“same snapshot → same positions when previous provided”), focus gating, empty ledger, old runs.
Browser fixtures: start run → review claims → see them on map → complete a search → filter sources → open inspector → stop.
Update RESEARCH_MAP_CONTRACT.md to match the implemented behaviour (remove aspirational language that the code does not honour).
Update ADMIN_RESEARCH.md / PROJECT_STATE.md with the new reliability bar.
5. Concrete technical decisions (recommended)












































TopicDecisionLayoutStructural + position memory; force only as optional “spread” for new nodesRe-layout triggersNew nodes, filter change, explicit Reset, viewport resize (debounced). Not token-only pollsDefault viewList until Map is stable; then Map on desktop, List on mobileProcess nodesHidden by default; available in List and optional “Show process” overlaySynthetic IDsDisplay only; never valid focus targetsActivityStatic badge or short CSS transition; honour prefers-reduced-motionReading queueKeep only if it does not cause layout or focus loss; otherwise demote to a collapsible “Idea” stripPersistence of positionsIn-memory + optional sessionStorage per run_idPerformance budgetProjection + layout < 50ms for typical 2–5 claim runs on mid-tier hardware6. Suggested file / module shapetextassets/map-engine.js          → pure: project(), layout(), owner(), sentence()  (keep pure)
assets/map-view.js            → DOM: render cards, inspector, filters, pan/zoom, position cache
panel.js / Research UI        → wires snapshot → map-view; handles focus POST; Map/List switch
src/ResearchView.php          → server-rendered List + Map shell + no-JS forms
docs/RESEARCH_MAP_CONTRACT.md → rewrite to match reality after Milestone 2–4
tests/map-projection.test.js  → (or PHP/JS fixtures) projection + layout stabilityKeep the pure engine free of DOM so it stays testable.7. Parallel track (does not block map reliability)The map becomes genuinely useful only when the ledger is real:
Persist passages from provider snippets.
Write typed edges at reconciliation / verification.
Enforce citation integrity (status requires edges/passages).
Do that work on the investigation pipeline (earlier plan). The map plan above can proceed with graceful empty states in the meantime.8. Out of scope for this plan
Free-form graph editing or user-drawn links.
Automatic beautiful “mind map” aesthetics at the cost of stability.
Replacing List as the authoritative notebook.
New backend graph database or heavy visualisation libraries.
Making Map required for no-JS users.
9. Execution order (practical)
Milestone 1 (correct projection) + Milestone 2 (stable layout) — these two alone will stop the “completely broken” feeling.
Milestone 3–4 (inspector + steering).
Decide default view based on real use.
Align ledger population so evidence filters earn their place.
Docs and tests to lock the contract.
Summary

The idea is sound: a calm, concept-first canvas with evidence on demand and explicit steering. The current implementation fails because it prioritises a live physics simulation and maximal process hiding over stability, truthful projection, and clear interaction states.