AIcontrol Research Tab — Implementation Plan

(Hand-over document for OpenAI Codex / coding agent)Goal

Evolve the existing Research tab from simple project-scoped chat + one-shot web search into a visible, interruptible investigation workspace with:
An explicit Dream phase (model externalises its internal prior).
A Ground / Expand phase that attaches real sources and data.
Live, expandable visibility of the process and token use.
Start / Stop control.
First-class dead ends.
A Learn subtab that operates only on collected evidence.
Preserve the project’s existing constraints: PIN-protected workspace, private SQLite, bounded OpenAI usage, honest status model (pending / complete / failed / incomplete / uncertain), no invented success, no automatic retry of uncertain outcomes.1. Current baseline (do not break)
src/Research.php — projects + messages in research.sqlite
WorkspaceView::research() — create project + per-project chat UI
Admin vault for openai_api_key (or env)
Rate limits: 10 research requests/hour, 1 pending globally, 60s timeout, 1600 output tokens, message size caps
Statuses: saved, pending, complete, failed, incomplete, uncertain
resolve() for stale pending
Output escaped as text; citations appended as plain URLs
No streaming today; full request/response
All new work must remain compatible with the existing loader, PIN session, vault, and update/signing system.2. Target user experienceInvestigate view (default)
User selects or creates a research project (name + brief).
Clicks Start research.
Live tree / timeline appears and expands in real time:
Phase: Dream
Phase: Plan (optional short step)
Phase: Evidence (branches / source cards appear)
Phase: Reconciliation

Token / step counter updates live.
Prominent Stop button. Stopping marks the active run (and any open nodes) as interrupted / uncertain. Never reports success.
Dead-end branches stay visible with reason.
Source cards are first-class: title, real URL, short extract/key data, date/domain if available, linked claim.
Learn subtab
Only available once sources exist.
Operates on the collected source cards + project brief.
Actions: explain finding, caveats, compare sources, progressive disclosure, optional “check my understanding”.
Does not invent new external searches unless the user explicitly starts a new Investigate run.
Synthesis (can be a section inside Investigate or a light third view)
Supported / weakened / contradicted / still-open claims.
Open questions.
Simple Markdown export that includes real URLs.
3. Data model changesExtend research.sqlite (additive, non-destructive migrations).New / evolved tables (suggested)textresearch_projects          -- keep existing (id, name, brief)
research_runs              -- one investigation attempt
  id, project_id, status, started_at, finished_at,
  token_input, token_output, stop_reason, model

research_nodes             -- the visible tree
  id, run_id, parent_id, type,   -- dream_claim | plan_step | search | source | dead_end | synthesis
  status, title, content,        -- content = text or structured JSON
  sort_order, created_at, updated_at

research_sources           -- first-class source cards
  id, run_id, node_id, url, title, extract, domain, retrieved_at, meta_json

research_messages          -- keep for backward compatibility / simple chat fallback
  (or migrate later; do not delete existing data)Status values for runs and nodes must include:

pending, running, complete, failed, incomplete, uncertain, interrupted, dead_end.Keep all private data inside the existing private runtime directory.4. Core flow (server-side)Start research (new action)
Validate project, rate limits, no other pending/running research.
Create research_runs row (status = running).
Create root nodes.
Execute phases sequentially (or as a small state machine), streaming progress events to the client.
On any hard failure or Stop → mark run + open nodes appropriately; never auto-retry.
Phase A — Dream (no tools)
Prompt forces structured output only:
Core claims the model currently holds
Key uncertainties
Suggested angles to verify
Claims it is least confident about

Stream the structured dream into research_nodes of type dream_claim.
UI language: “Model’s prior thoughts — not yet checked.”
Phase B — Plan (short, optional but recommended)
Model proposes ≤ N concrete verification/search steps derived from the dream.
Store as plan_step nodes.
(Future: allow user edit/approve before continuing; v1 can auto-continue under limits.)
Phase C — Evidence
Bounded tool use (prefer existing OpenAI Responses + web_search_preview or equivalent).
Hard caps: max tool calls per run, max concurrent branches, existing hourly + global pending limits.
For each useful result → create research_sources row + linked node.
Real URL required for source cards. Short extract preferred.
If a planned step yields nothing useful → create explicit dead_end node with reason.
Phase D — Reconciliation
Produce nodes that map original dream claims → supported / weakened / contradicted / open.
Dead ends remain in the tree.
Final run status set honestly.
Stop
Client can POST stop at any time.
Server sets run status to interrupted, open nodes to interrupted or uncertain.
Any in-flight provider call is not retried; outcome recorded as uncertain if unknown.
5. Real-time visibility requirementsv1 pragmatic approach (fits current PHP architecture):
Server writes progress into research_nodes / run token counters as it goes.
Client polls a lightweight status endpoint (or uses Server-Sent Events if easy to add) every 1–2 s while a run is running.
UI expands the tree as new nodes appear.
Show live: current phase, node count, approximate tokens used, “Stop” button.
When status leaves running, stop polling.
True token-by-token streaming of every node is desirable later; progressive node appearance + live counters is the minimum viable “see it expand in real time.”6. UI / front-end changesInvestigate tab
Project header + Start Research button.
Live tree component (expandable nodes).
Source card component (URL is real and clickable).
Token / phase status bar.
Stop button (always available while running).
Clear visual treatment for dead ends and interrupted nodes.
Empty / disconnected / rate-limit states stay honest (reuse existing alert patterns).
Learn tab
List of source cards for the project/run.
Actions that call a bounded “explain / compare / caveats” endpoint using only stored sources + brief as context.
No hidden extra web search unless user starts a new Investigate run.
Reuse Tabler + existing WorkspaceView / AIcontrolWorkspaceUi patterns. Keep forms working without JS; progressive enhancement for the live tree and Stop.Accessibility: keyboard operable tree, status region, proper labels, no information only in colour.7. API / actions to implementAll behind existing PIN session + CSRF.
research_project (existing create)
research_start — begin a new run for a project
research_stop — interrupt running run
research_status — poll run + new nodes + token counts (JSON)
research_learn — explain/compare/etc. on existing sources
Keep or thin-wrap existing chat path for backward compatibility / simple fallback
Server must continue to enforce:
one active research run globally (or per workspace)
hourly request ceiling
model / key presence
size and token caps
no automatic retry of uncertain provider outcomes
8. Prompt & safety rules (non-negotiable)
Dream phase instructions must state that output is unchecked prior knowledge.
Evidence phase must not claim a search occurred unless the tool was actually used.
Source cards require real URLs; model-generated HTML is never executed (escape everything).
Shared Admin notes still only included when explicitly opted in; keys never enter prompts.
Dead ends and contradictions must be representable and must not be deleted by later synthesis.
Stop / timeout / transport failure → uncertain or interrupted, never silent success.
9. Implementation sequence (for Codex)Milestone 1 — Data & skeleton
Additive schema migration for runs / nodes / sources.
Research class methods: createRun, appendNode, addSource, updateRunStatus, getRunSnapshot.
Basic status endpoint.
No UI changes yet beyond feature flag or hidden route.
Milestone 2 — Dream + Plan (no tools)
Start run → Dream phase → Plan phase → complete.
Nodes written; status endpoint returns them.
Simple UI: start button, polling tree of dream/plan nodes, stop button.
Tests with synthetic transport.
Milestone 3 — Evidence + source cards
Integrate bounded web search / tool calls.
Create real source cards with URLs.
Dead-end nodes.
Reconciliation phase.
UI source cards + dead-end styling.
Milestone 4 — Learn subtab
Read-only over stored sources.
Bounded explain/compare endpoints.
UI subtab.
Milestone 5 — Polish & hardening
Token counters, better empty/error/rate-limit states.
Export Markdown.
Accessibility pass.
Extend tests/workspace.php and browser fixtures.
Update docs/ADMIN_RESEARCH.md and PROJECT_STATE.md.
Each milestone should leave the system in a shippable, non-regressed state.10. Testing requirements
Unit / integration: schema, run lifecycle, stop semantics, dead-end creation, rate limits, key missing, uncertain outcomes.
Synthetic provider transport (existing pattern) — no live key required in CI.
Browser: start → see nodes appear via polling → stop → status becomes interrupted; source cards render real links; Learn tab only appears with sources; accessibility checks.
Confirm existing projects/tasks/admin/vault/update paths untouched.
Explicitly document that live billed OpenAI validation remains a host-side step.
11. Out of scope for this plan
Multi-user collaboration or public sharing
Arbitrary code execution / data analysis sandboxes
Full academic literature graph (arXiv, Semantic Scholar, etc.) unless added as a later explicit tool
Unbounded or multi-hour autonomous agents
Changing the PIN / vault / signing / update system
Claiming production readiness without host-side key and real run validation
12. Success criteria
User can start a research run and watch a tree of dream → evidence expand.
Real source URLs appear as first-class cards.
Stop cleanly aborts and leaves an honest interrupted/uncertain state.
Dead ends remain visible.
Learn subtab helps the user understand collected findings without inventing new evidence.
Existing rate limits, privacy boundaries, and status honesty are preserved.
Docs and tests updated; no regression to projects, tasks, admin, or updater.
Hand-over note for Codex

Work only on the main branch (or the current implementation branch). Read docs/PROJECT_STATE.md, docs/ADMIN_RESEARCH.md, src/Research.php, and src/WorkspaceView.php before changing behaviour. Prefer additive schema and progressive UI enhancement. Keep every failure mode explicit. When in doubt, favour a smaller visible tree with real links over a more fluent but opaque answer.