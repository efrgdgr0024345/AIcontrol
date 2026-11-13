AIcontrol Research — Coding Plan (Handover)

Focus: Expand user understanding + generate new ideas. Web Evidence is a final, user-selected lock-in stage.
1. Vision & Design Intent
Primary purpose of the system

Help the user expand their own understanding and generate new ideas by systematically exploring the latent knowledge, associations, counters, and structures already present inside large language models. The Map becomes a living, growing conceptual workspace that the user steers.
Role of Web Evidence

Web search / external Evidence is not the default or early activity. It is a deliberate final-stage lock-in that the user chooses to run on selected branches after they have explored and decided those ideas are worth grounding. Until that point the entire graph remains model-derived / provisional.
Core interaction loop

Seed or start from a question / brief.
Explore & expand (model-only probes, associations, counters, edge cases, reframings).
User continuously steers: promote, demote, park, focus, grow further.
Map grows as a multi-layer living space (Active / Secondary / Tertiary). Nothing is permanently cut off.
When ready, user selects one or more branches → “Lock in with Evidence” (final stage).
Optional verification / freeze / export on the locked branches.

All existing honesty properties remain non-negotiable: provisional labels, real provenance, budgets, one active request, Stop semantics, no fabricated passages or sources.

2. Non-Goals (Explicit)

Making web search the primary or early growth mechanism.
Automatic or background evidence gathering.
Deleting or fully hiding branches.
Claiming model-derived content is already verified.
Parallel multi-agent workers or unbounded autonomous growth.
Changes to PIN / vault / signing / update system.


3. Data Model Changes (Additive Only)
research_nodes — new columns (nullable for legacy rows)
SQLlayer            INTEGER NOT NULL DEFAULT 0,   -- 0=Active, 1=Secondary, 2=Tertiary
origin           TEXT,                         -- 'model_latent' | 'user' | 'web_evidence' | 'synthesis' | ...
provenance_tag   TEXT,                         -- 'provisional_model', 'parked', 'locked', 'grounded', ...
park_reason      TEXT,
locked_at        DATETIME,                     -- set when user starts final Evidence on this branch
last_layer_change DATETIME
Optional project-level table
SQLCREATE TABLE IF NOT EXISTS research_map_state (
  project_id   INTEGER PRIMARY KEY,
  layer_prefs  TEXT,          -- JSON: visibility, default focus, etc.
  notes        TEXT,
  updated_at   DATETIME
);
Node type guidance

Keep existing types.
Prefer / formalise for exploration: model_idea, association, counter, edge_case, reframing, question.
Web Evidence nodes keep current source / passage / edge semantics and are clearly tagged origin = 'web_evidence'.

Run / flow

New runs use flow_version = 2 (or higher).
Old runs (flow_version <= 1) continue with previous behaviour and remain fully readable.
Project setting or run flag: exploration_first = 1 (default for new work).

Migrations must be idempotent and non-destructive.

4. Phase & Action Model
New primary flow (flow_version ≥ 2)

Seed / Dream (optional, light)

Model surfaces initial ideas or user starts from their own question/brief.
Exploration Loop (main activity, model-only)
User selects node(s) or root.
Actions: Explore further, Generate associations, Surface counters, Edge cases, Reframe, etc.
All new children are created with origin = 'model_latent', layer = 0, clear provisional labelling.
No web tools in this loop.

Map stewardship (always available)
Promote / Demote / Park (move between layers 0/1/2).
Park can carry an optional human reason.
Layer changes are pure metadata writes (no provider call).

Final Lock-in (Evidence) — user-initiated only
User selects one or more Active (or Secondary) branches.
Explicit action: “Lock in with Evidence” / “Ground selected branches”.
This creates a focused, bounded Evidence plan only for the selected nodes.
Re-uses existing Plan → Evidence → extraction → verification machinery as much as possible.
Selected nodes receive locked_at and move toward grounded status.
Unselected branches stay in the living map (can still be explored or later locked).

Review / Freeze / Export

Existing mechanisms, now operating on the locked subset plus the surrounding map context.

New / extended server actions (all session + CSRF + budget protected)

research_explore — model-only expansion of a node (or set).
research_promote_layer / research_demote_layer / research_park
research_lock_evidence — starts the final Evidence stage on user-selected node IDs.
Existing research_step, research_stop, research_learn, research_finish, etc. remain and are reused where appropriate.

Hard rules for Evidence stage

Only runnable after explicit user selection of branches.
Cannot be the first growth action on a fresh run.
Still subject to the same one-request-at-a-time, hourly, and project budget limits.
Stop still freezes and rejects late writes.


5. Map & Frontend Requirements
Living multi-layer map

Three visual layers with clear treatment:
Active (0): full colour / opacity / weight.
Secondary (1): lighter grey, reduced opacity (~55–65%), softer emphasis.
Tertiary (2): even lighter grey, lower opacity (~30–40%).

No permanent removal. Demotion = move to higher layer number.
Position memory (existing) is preserved across layer changes and new child insertion.
New nodes are placed relative to parent; existing cards do not jump.
Layer visibility toggles (show/hide Secondary / Tertiary) without data loss.
“Reset layout” remains the only full clear of position cache.

Inspector & controls

On selected node(s):
Explore further (model)
Promote / Demote / Park (+ optional reason)
Lock in with Evidence (only when appropriate; explains why disabled otherwise)

Global controls: layer toggles, focus Active, Fit, Reset layout.
Idea queue continues to surface recent provisional model text; can focus the corresponding node.
Clear visual and textual distinction between model_latent and web_evidence content.

Performance & accessibility

Layer changes are style-only (no re-layout).
Keep current deterministic structural layout + position cache.
Keyboard operable, reduced-motion support, sufficient contrast for grey layers, no-JS forms for all new actions.


6. Implementation Milestones (Shipable Increments)
Milestone 1 — Layer Foundation

Additive schema (layer, origin, provenance_tag, park_reason, locked_at).
Backend get/set layer, promote, demote, park.
Status payload carries new fields.
Map projection + rendering respects layers (grey / opacity).
Existing runs continue to work unchanged.
Tests: migration, projection, visual treatment, position stability.

Milestone 2 — Exploration Actions (Model-only)

research_explore (and variants) with strict model-only prompts.
New nodes appear as children with correct origin/layer tags.
Inspector wiring + live appearance via existing status polling.
Budget reservation + Stop guards.
Synthetic-transport tests + browser growth tests.

Milestone 3 — Living Map UX

Full promote / demote / park flows + park reason.
Layer visibility toggles.
Idea queue integration with new node types.
Keyboard + no-JS parity.
Accessibility pass (contrast, focus, reduced motion).

Milestone 4 — Final Evidence Lock-in

research_lock_evidence action.
User selects branches → starts focused Evidence plan only on those nodes.
locked_at + origin tagging.
Re-use existing Evidence / passage / edge / verification path.
Clear UI affordance and disabled-state explanations.
Tests for selection → lock → evidence growth → Stop behaviour.

Milestone 5 — Polish, Docs, Release

Update PROJECT_STATE.md, ADMIN_RESEARCH.md, RESEARCH_MAP_CONTRACT.md, and any flow docs.
Full regression (PHP suites, HTTP, Chromium + new cases).
Performance check on larger multi-layer maps.
Release notes emphasising the exploration-first + final-lock-in posture.


7. Testing Requirements

Migrations leave all existing data readable and old flows intact.
Synthetic provider for every new model call.
Layer changes never delete nodes or break position memory.
Explore creates only model-latent nodes (no accidental sources/passages).
Lock-in requires explicit selection and only then may create web Evidence.
Stop / budget / one-request guards continue to hold.
Browser: visual layers, growth without jumps, promote/demote/park, lock-in flow, keyboard/no-JS, axe clean.
Explicit statement in docs: live billed validation remains host-side.


8. Prompt & Safety Rules (Non-Negotiable)

Exploration prompts must force output to be clearly marked as model-internal / provisional / unchecked.
Never promote model text into passages or source cards.
Creative / association / counter probes stay bounded.
Evidence stage may only run after explicit user lock-in of selected branches.
All failure modes (Stop, timeout, uncertain, budget) remain honest and visible.


9. Success Criteria

User can spend the majority of time exploring and expanding ideas from the model while the Map grows as a living multi-layer space.
Branches are never permanently removed; they move to Secondary/Tertiary (lighter grey).
Web Evidence appears only as a final, user-chosen lock-in on selected branches.
The system measurably helps the user expand understanding and generate new ideas rather than primarily verifying existing ones.
Existing investigations and all safety/budget/Stop properties continue to work.
Each milestone leaves the product in a usable, non-regressed state.


10. Suggested Starting Tasks for the Implementing Agent

Read docs/PROJECT_STATE.md, docs/ADMIN_RESEARCH.md, docs/RESEARCH_MAP_CONTRACT.md, and the current map JS files.
Write the additive migration for the new node columns.
Extend the status snapshot and pure map-engine projection to carry layer / origin.
Implement the three-layer visual treatment in map-view.js.
Add promote / demote / park backend + inspector actions.
Only after the above is solid and tested, implement the first research_explore model-only path.
Implement research_lock_evidence last among the behavioural changes.


This plan is ready for handover. It keeps the current architecture, contracts, and safety model while re-orienting the product around user understanding and idea generation, with Web Evidence as a deliberate final lock-in stage under explicit user control.