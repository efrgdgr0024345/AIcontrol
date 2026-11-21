# NASA Managed

NASA Managed is a visual, project-scoped planning and review workspace inspired
by **NASA/SP-2016-6105 Rev 2**. It adapts the handbook to software and AI work.
It is not NASA affiliation, certification, mission approval or evidence that code
was inspected, executed, tested or deployed.

## First use

1. Open **NASA Managed** and choose an existing task or research project. Select
   **Start managing this project** once. Same-numbered projects from the two
   databases remain distinct; switching projects never mixes their records.
   For work already in progress, choose the closest plain-English starting stage
   and record why. This does not approve earlier gates; repeated Start actions
   never reset an existing plan's phase.
2. Read the highlighted phase and the **next useful move** card. The ring counts
   local readiness checks, not software quality or certainty. The timeline shows
   current-record coverage against each phase, not historical gate approvals.
3. Select a dotted term for an explanation, software example and handbook section.
   Hover offers a short definition. The searchable helper works without external
   documentation. Beginner guidance can be hidden without hiding the controls.
4. Create a starter outline or, if connected, request an AI draft. Edit it and
   explicitly mark it reviewed. Previous versions remain available to read or
   restore as a new draft. Nothing runs merely because a model proposes it.
5. Record testable requirements, risks and measurements. Run a local readiness
   review. Address the gaps or record a proportionate tailoring exception, then
   explicitly advance the phase. You can keep working elsewhere while gaps remain.

No API key is needed for manual outlines, documents, structured records, local
reviews, explanations, phase guidance, baselines or exports.

## Feature and acceptance map

| Area | Implemented behavior |
| --- | --- |
| Visual guidance | Seven-phase timeline, current phase, coverage ring/checklist, next action, artifact state cards and color-labelled residual risk matrix |
| Handbook | Versioned original explanations for seven phases, all 17 technical processes, eight artifact types, review acronyms and supporting terms; hover/click/keyboard access and searchable helper |
| Documents | ConOps, requirements, verification matrix, risk register, performance measures, interfaces, decisions/trades, tailoring/engineering plan |
| Living records | Immutable document and structured-record versions; owner edit/review/baseline states; old document restore creates a draft version |
| Requirements | Stable project-local IDs, shall statements, source need, verification method and owner-recorded evidence; deterministic wording guidance; no automatic test attestation |
| Risks | Initial and residual likelihood/impact, response owner, rationale/mitigation, requirement/artifact links, open/mitigated/accepted/closed state, 5×5 filterable matrix |
| Measures | Units, target/direction, current observation, measurement method/date/evidence, within/outside-target indicator; values are recorded by the owner, not fabricated telemetry |
| Reviews | Free local readiness checks and optional bounded AI advisory reviews with strengths, gaps, severity and next actions; outdated revisions remain labelled |
| Phase gates | Required reviewed documents or explicit phase-specific tailoring; traceable requirements in A–E; evidence in D/E; no high residual risk left open; current owner local review before advance |
| Decisions | Options, choice/rationale, reconsideration trigger and proposed/decided state; phase/document tailoring exceptions retain reasons |
| Configuration | Current-revision local review before a named SHA-256 baseline; planning records, provenance, deterministic linked/focused research hash and available installed-code SHA; visible deltas and append-only restore with requirements reset to unverified |
| Integration | Both source project types; explicit research link for task projects; scoped saved ideas, source metadata/passages, task/agent outcome metadata and linked Systems metadata |
| Action proposals | Explicit click creates a normal draft task without dispatching an agent; explicit click adds an unchecked user-origin idea to an idle linked living map, never Evidence |
| Export | Project-scoped Markdown and JSON review packages, full stored version history and retained AI outcomes; browser Print / Save PDF includes actual current document content |
| Access | Existing owner authentication and CSRF; public-testing sessions cannot read or mutate NASA Managed |
| Accessibility | Keyboard tabs, ordinary forms/links without JS, focusable scrolling matrix, explanations that return focus, contrast in light/dark layouts, mobile stacked cards and horizontal timeline |

For a research source, **Create a task** creates a task project when one is needed.
Repeated creation from the same finding is idempotent while that task exists.
Map proposals are idempotent per project/review/finding and retain `origin=user`,
`provenance_tag=user_proposal`. They cannot bypass Stop, frozen runs, Evidence
lock-in or provider admission. Neither integration runs an agent automatically.

## AI and context contract

The existing OpenAI connection is reused on the server. Model selection respects
`AICONTROL_OPENAI_MODEL`, with the existing GPT-4.1 mini default. A saved pricing
entry is required. One explicit action uses one tool-free Responses API request,
strict structured output, `store=false`, up to 6,000 output tokens and a 45-second
provider timeout. Up to eight requests per workspace/hour and $1 estimated plus
reserved risk per source project/rolling day are admitted. This is a separate
NASA allowance, not an invoice cap or an extension of research's budget.

The shared ProviderGate serializes provider work with the rest of the application.
Reservation is committed before dispatch. Confirmed usage replaces the risk
reservation; unknown outcomes retain it and are never retried automatically.
Budget/rate records survive project cleanup. Daily enforcement uses saved standard
rates and conservative input-byte reservation; missing/unpriced usage stays unknown.

Context contains the selected project's brief and current engineering records,
its task criteria/outcome metadata, an explicitly linked research project's latest
50 idea summaries and up to 12 sources/passages, and associated Systems metadata.
No other project's context, Vault notes, credentials, remote command output or
arbitrary filesystem/repository contents are added. Input is capped at 120,000
JSON bytes before admission. The UI makes the saved-record scope explicit.

AI never sets reviewed/baseline states, verifies a requirement, accepts a risk,
advances a phase or creates a task/map node by itself. Server validation rejects
unknown fields, scope expansion and unsupported tools. AI artifact regeneration
creates a new draft while retaining prior versions. Context hashes and project
revision checks prevent late replies from overwriting changed owner records;
superseded output is retained in the JSON export for inspection.

The optional AI-review result is advisory. A separate owner local-readiness review
is required before phase advancement. Tailoring affects local planning checks
only; it cannot waive PIN, CSRF, cost limits, SSH permissions or release checks.

## Storage and lifecycle

All new tables are additive in the existing private `panel.sqlite`:
`nasa_projects`, `nasa_items`, `nasa_reviews`, `nasa_baselines`, `nasa_links`,
`nasa_ai_requests`, `nasa_baseline_restores`. The Admin inventory queries their actual schema and row counts.
Child planning records cascade when their managed project is deleted. Deleting
the corresponding source project through Admin removes its planning records too;
another project's records remain untouched. Resetting tasks/research history alone
retains NASA planning. Workspace-wide reset removes planning content and stored
AI output but retains provider counters, reservations and security identity.

Baseline restoration restores planning records, not code, external tasks, source
projects, linked server state or billed usage. Links and requirements are project
scoped; risk acceptance and verification evidence remain owner-recorded assertions.
No pre-existing PIN, signing key, vault key, SSH credential or project is reset by
the additive migration.

Structured records can be archived from their editor and restored under Decisions
& tailoring. Both actions append versions. Restored requirements are unverified,
risks open and decisions proposed; restoration does not assert fresh evidence.
Deleted source projects also clear research/task bridge links so reused numeric
IDs cannot silently attach a different project's context.

## Reference provenance

Official source: https://www.nasa.gov/wp-content/uploads/2018/09/nasa_systems_engineering_handbook_0.pdf

Retrieved during development; PDF SHA-256:
`8eeb4887a4dc57a23049da7dd2ed556833cf98e214b240468d987873164ff688`.
Knowledge version: `2026-11-22.2`, in `src/NasaKnowledge.php`. References are
handbook sections/appendices, avoiding confusion between printed and viewer pages.
The PDF/full book is not shipped or required at runtime. Explanations and software
examples are original adaptations. Future knowledge changes should update the
version, source verification and the relevant glossary tests.

## Developer / first-day checks

Use the existing checkout and toolchain; do not create a worktree. No new runtime
service, package dependency or external document store is required. The existing
PHP loopback router and frozen Node dependency lock remain sufficient.

```
php bin/build-ui.php
php bin/build-manifest.php
php tests/nasa.php
php tests/nasa_discipline.php
PHP_BINARY=php python3 -B -m unittest -v tests/nasa_http.py
PHP_BINARY=php node tests/nasa_ui.cjs
```

The new suites are in required planning-panel CI and `npm run test:ui`. They cover
fresh and returning projects, no-key operation, source separation, immutable
versions, restoration, phase guards, unknown/malformed/stale provider outcomes,
cost/rate limits and reset resistance, owner/CSRF/public-testing boundaries,
XSS-safe rendering, task/map proposals, light/dark mobile/desktop axe, keyboard,
no-JS forms, scoped downloads and actual Chromium PDF generation. Model replies
are explicitly synthetic in these tests. A live-provider check must be reported
separately, with any credential/provider blocker left explicit.

### Structured tailoring and process mechanics

Each new exception requires one or more of the eight handbook §3.11.2 criteria,
a residual-risk explanation and a revisit trigger/date/condition, alongside the
phase, artifact and rationale. Edit creates another immutable version. Readiness
shows the applied exception and saved local review packages retain it. Historical
incomplete exceptions need an owner update before they count. The handbook helper
search covers 17 process purposes/failure modes, phase entrance/success prompts,
recursive practice, SEMP and maturity. These are software adaptations, not quoted
NASA certification criteria; see NASA_KNOWLEDGE_PROVENANCE.md.

### Configuration discipline and interface contracts

A planning baseline requires a local readiness review recorded against the exact
current NASA revision. A review may honestly contain open gaps: a baseline is a
configuration snapshot, not a gate approval. AI advisory reviews do not satisfy
this requirement. The ordinary form carries the expected revision; stale forms
are rejected without creating a snapshot.

The v2 baseline retains current planning records, their provenance, the local
review ID, knowledge version, a linked/focused research content snapshot and
SHA-256, and `AICONTROL_INSTALLED_SHA` when the installed loader supplied a valid
40-character release SHA. Unknown code references remain null. Research reads
are scoped and consistent; they do not reconcile timeouts, invoke a provider,
change a map or inspect external systems. Camera state, focus timestamps,
render-size caches, spend and worker heartbeats do not affect the research hash.
Owner-created focused research is included only while its immutable project
identity matches the saved origin, preventing reused numeric IDs from adding
unrelated content. Other projects, Vault notes and worker identities are excluded.

The Reviews area shows added, missing and changed planning records plus the
baseline/current research hashes, provenance counts and available code SHAs.
Typed restore appends versions and tombstones in one transaction, retains the
before-restore comparison in `nasa_baseline_restores`, and resets restored
requirements to unverified. Current research, evidence reviews, code, tasks,
servers and billed usage remain as recorded. Old snapshots without research
metadata remain usable and are labelled as not having captured a research hash.
Corrupt, cross-project or structurally inconsistent snapshots are rejected.

Living interface contracts are versioned `interface` records in `nasa_items`
with stable `IF-###` keys: name, purpose, both sides, payload/schema/compatibility,
error and recovery behaviour, authentication/authorisation, owner, and change
review rule. The state is draft or owner-reviewed. Editing appends a version;
archive/restore retains history, and a restored contract returns as draft. The
structured contract complements the existing Interfaces document. No contract
violation detection or automatic enforcement is claimed.

`tests/nasa_discipline.php` covers local-review/CAS gates, scoped and stable
research hashes, focused-source identity, comparison and legacy restoration,
malformed snapshots, forced mid-restore rollback and versioned interfaces, with
no provider requests.
