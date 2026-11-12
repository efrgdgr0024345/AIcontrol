What’s wrong with the current UI
From ResearchView.php the create form is:

Buried inside a <details> under “Project menu”
Two bare fields: Research name + Research brief (3-row textarea)
One static guidance line with a single example
No visual hierarchy, no progressive help, no sense of “this brief drives the whole investigation”
Feels like an admin form, not the start of a research session

That matches the “crap UI and feel” complaint. The brief is the most important input in the whole system (Dream, Plan, Evidence, ranking, and Learn all hang off it), yet the form treats it like a random note field.

Recommended rework (product + UX)
1. Make “Create research project” a first-class starting screen
When there is no active project (or user clicks “New research”):

Full-width, calm card (not a nested details)
Clear headline: What do you want to find out?
Short subcopy: A good brief produces better hypotheses, sharper searches, and clearer dead ends.
Then the form.

Keep the existing project list, but demote it to a secondary “Open an existing project” section.
2. Split the form into a guided structure (still one POST)
Keep the same backend fields (name, brief) but present the brief as guided pieces that concatenate into one string, or keep a single textarea and heavily scaffold it.
Recommended fields:



































FieldPurposeNotesWorking titleShort name for the project listmax 160, requiredCore questionThe actual research question1–2 sentences, requiredContext / scopePlace, time, audience, constraintsoptional but encouragedWhat would change your mind?Success / falsification criteriaoptional, very high leverageOut of scopeWhat not to chaseoptional
On submit, join them into the existing brief text (or store structured later). This stays compatible with the current schema.
3. Make the brief area feel like a coach, not a blank box

Larger textarea (6–8 rows minimum) when using a free-form brief
Persistent side/bottom panel: “What makes a strong brief”
Live character / quality hints (soft, not blocking)
Example chips that insert into the field on click (not just show text)

4. Progressive enhancement, no-JS safe
All of the above must work as plain HTML forms. JS can only add:

Click-to-insert example chips
Soft quality checklist (“Has a clear question?”, “Mentions place/time?”, “States what evidence would settle it?”)
Optional expand/collapse of advanced fields


Suggestions for the “Research brief” field
Goal: give the model (and the human) the best chance of useful Dream claims, focused searches, and honest dead ends.
Principle: a good brief answers four things

What is the question? (specific, answerable)
In what context? (place, time, audience, constraints)
What would count as evidence either way?
What is explicitly out of scope?

Vague briefs produce vague, overlapping Dream claims and unfocused web searches. Sharp briefs produce ranked, testable claims.
Example chips / starters (click to insert)
Offer 4–6 short templates the user can click:

Decision under constraints

Is [option A] better than [option B] for [person/org] in [place/time], given [budget/constraint]? What evidence would settle it?
Claim check

How well supported is the claim that “[claim]”? Focus on [primary sources / recent data / [year]+]. Mark weak or indirect evidence clearly.
Compare approaches

Compare [approach A] vs [approach B] for [goal] in [context]. Prefer primary sources and note where the evidence is thin.
Local / practical

For a [household/business] in [city/region], is [action] worthwhile under current [prices/rules]? What numbers or rules would change the answer?
Open investigation

What is currently known about [topic], what remains uncertain, and what would be the highest-value next question to test?
Perth / AU example (matches your existing guidance)

Is rooftop solar worthwhile for a typical Perth household on current retail tariffs and feed-in rates? What bill, system size, and payback assumptions matter most?

Soft quality checklist (next to the field)
Show as non-blocking ticks that light up as the user types:

Has a clear, specific question
Mentions place, time, or audience when relevant
Says what evidence would change the conclusion
Avoids “tell me everything about X”

Example of a strong vs weak brief
Weak
Research solar power
Strong
Is rooftop solar still worthwhile for a typical Perth household in 2026 under current Synergy tariffs and feed-in rates? Focus on payback, system size, and bill impact. Treat marketing claims as provisional until matched to independent numbers. Out of scope: commercial systems and off-grid setups.
The second version gives Dream something concrete to hypothesise about, Plan something to search for, and Evidence something to falsify.
Microcopy under the field
Keep something close to the current guidance, but make it actionable:
Write a specific question, the place or timeframe that matters, and what evidence would change your mind. Vague briefs produce vague searches.

Suggested layout (desktop)
Text┌─────────────────────────────────────────────────────────┐
│  What do you want to find out?                          │
│  A focused brief leads to better hypotheses and searches│
│                                                         │
│  Working title                                          │
│  [ .............................................. ]     │
│                                                         │
│  Research brief                                         │
│  [                                              ]       │
│  [                                              ]       │
│  [                                              ]       │
│                                                         │
│  Quick starters:  [Decision] [Claim check] [Local] ...  │
│                                                         │
│  ✓ Clear question  ○ Context  ○ What would settle it    │
│                                                         │
│  [ Create research project ]                            │
└─────────────────────────────────────────────────────────┘
On mobile: same order, starters as horizontal scroll chips, checklist collapsed under “Tips”.

Implementation notes (fit the existing stack)

No schema change required for a first pass: still POST name + brief.
Put the new markup in ResearchView::render (the project-create block around the current form).
Keep progressive enhancement: pure HTML form works; JS only for chip insertion + soft checklist.
Reuse Tabler form controls and existing card styles so it stays consistent with the rest of the workspace.
After create, land on the project with the brief visible and Start research as the obvious next action (this already exists; just make the create step feel worthy of it).


Priority order if you only do a little

Un-bury the form — full card, not nested details.
Bigger brief field + better guidance + 4–5 clickable example chips.
Soft quality checklist.
Optional structured sub-fields later if you want even sharper briefs.

That alone will change the feel from “admin form” to “start of a real investigation” and will improve the quality of everything that follows (Dream → Plan → Evidence).turn this into a coding plan -  also mate the suggestions live stream the api suggestions - (just the fastest model so it feels good for the user4:21 AMCoding Plan: Rework “Create Research Project” + Live Brief Suggestions
Goal
Replace the current buried, bare create form with a first-class, guided starting experience, and add live streaming brief suggestions powered by the fastest model (gpt-4.1-nano) so the UI feels responsive and helpful without becoming expensive or noisy.
Constraints to respect:

PHP + SQLite, no daemons
One active provider request at a time (workspace-wide)
Existing research hourly limit + project spend cap
CSRF + session auth
Progressive enhancement (full create form works with no JS)
Tabler UI, existing loader/PIN patterns


Product decisions (lock these first)

Create form becomes a first-class card when no project is selected (or when user opens “New research”).
Backend stays compatible: still POST name + brief (action=research_project). No schema change in v1.
Live suggestions are progressive enhancement only:
No-JS users still get static example chips + guidance.
JS users get streaming nano suggestions as they type.

Fastest model only: hard-code gpt-4.1-nano for this feature (ignore project primary model / server override).
Suggestions never auto-submit and never start an investigation. They only help write the brief.
Cost/rate guardrails:
Separate lightweight endpoint
Debounced client requests
Short output cap
Share the existing hourly research ceiling (or a small dedicated sub-limit)
Refuse if a full investigation/chat is already pending



Architecture
New endpoint
POST /loader.php?view=research

action=research_brief_suggest
Inputs:

csrf
draft (current brief text, max ~1500–2000 bytes)
optional name (for light context)

Behaviour:

Auth + CSRF required
Reject if another research request is pending/running
Reject empty/too-short draft (e.g. < 12 chars) or oversize
Call OpenAI Responses with gpt-4.1-nano, streaming
Return SSE (or chunked text) of 2–4 short suggestion bullets / improved brief starters
Do not write to the project/run tables (except optional usage metering)

Client
In assets/panel.js (or a small research helper):

Debounce input on the brief textarea (~400–600 ms)
Abort previous in-flight suggestion request when user keeps typing
Stream tokens into a suggestions panel
“Use this” buttons that insert/replace text in the textarea
Clear UI states: idle / thinking / streaming / error / rate-limited

No-JS fallback
Static example chips remain server-rendered. They insert via plain links or are just copyable text; no streaming required.

Milestone plan
Milestone 0 — Freeze current behaviour + fixtures (½ day)
Files

docs/PROJECT_STATE.md (note the upcoming create-flow change)
Add a short note in docs/ADMIN_RESEARCH.md about the new suggest action

Work

Capture current create form HTML as the baseline
Add a synthetic fixture for “no projects” research view if missing
Decide exact copy for static chips (from previous advice)

Done when

Baseline documented; no behaviour change yet


Milestone 1 — First-class create UI (no API yet) (1 day)
Files

src/ResearchView.php (main)
assets/app.css / panel CSS as needed
Possibly assets/panel.js for chip insert only

Work

When $project is null (or explicit “new”), render a full Create research card instead of the nested <details class="project-create">.
Structure:
Eyebrow + headline: “What do you want to find out?”
Short subcopy about why a good brief matters
Working title (name)
Research brief textarea (larger, 6–8 rows)
Static Quick starters chips (4–6)
Soft guidance line + “What makes a useful brief?” details
Primary button: Create research project

Keep existing POST contract:
action=research_project
name, brief

Existing project picker becomes secondary (“Open an existing project”).
After create, keep current behaviour: land on project with brief + Start research.

Static chips (server-rendered)

Decision under constraints
Claim check
Compare approaches
Local / practical
Open investigation
Perth solar example (or equivalent)

Clicking a chip fills/appends the textarea (JS progressive; no-JS can show the text under the chip).
Done when

Create form feels like a real starting screen
No-JS create still works
Visual regression / axe smoke still pass


Milestone 2 — Soft quality checklist (client-only) (½ day)
Files

assets/panel.js
CSS

Work

As the user types the brief, light up non-blocking checks:
Has a clear question?
Mentions place / time / audience?
States what evidence would change the conclusion?
Not just “research X”

Pure regex/heuristics; no API
Do not block submit

Done when

Checklist updates while typing and never prevents create


Milestone 3 — Streaming suggest endpoint (server) (1–1.5 days)
Files

src/Research.php or src/Investigation.php / small dedicated helper
src/ResearchApi.php (or wherever actions are dispatched)
src/UsageMeter.php (meter the call)
Tests: tests/research_brief_suggest.php (or extend existing research tests)

API contract
Request:
TextPOST action=research_brief_suggest
draft=<text>
name=<optional>
Rules:

Session + CSRF
draft trimmed, UTF-8, min length ~12, max ~2000 bytes
Reject if any pending research/investigation/Learn request exists
Count toward the shared research hourly limit (or a dedicated lower sub-limit, e.g. 20/hour — decide and document)
Model: hard-coded gpt-4.1-nano
max_output_tokens: low (e.g. 180–250)
No tools / no web search
Timeout: short (e.g. 20–30s)
Stream response

Prompt shape (keep tight)

System/instructions roughly:

User is writing a research brief for a bounded investigation.
Return 3 short alternatives or improvements.
Each suggestion: one concrete question + optional context/falsification cue.
No preamble, no markdown essay, no claims of having searched.
Prefer specificity over breadth.

Streaming

Prefer OpenAI Responses streaming
Emit SSE events the browser can consume, e.g.:
event: delta / data: {"text":"..."}
event: done / usage summary
event: error

On transport/provider failure: mark uncertain/failed cleanly; do not invent text

Metering

Record model, tokens, estimated USD like other research calls
Label role something like brief_suggest so usage UI can show it separately if desired

Done when

Endpoint works with synthetic transport in PHP tests
Rejects busy/pending/over-limit cases
Streams deltas and finishes cleanly


Milestone 4 — Live suggestions UI (client) (1 day)
Files

assets/panel.js
src/ResearchView.php (suggestions panel markup)
CSS

UX

Panel under the brief: Suggestions
States:
Empty: “Type a bit more for live suggestions”
Debouncing / thinking
Streaming text
Ready: 2–4 suggestion cards with Use this / Append
Error / rate limit / busy: clear human message

Debounce 400–600 ms after typing stops
AbortController cancels previous request
Do not fire while draft is below min length
Do not fire while a create submit is in progress
Reduced-motion: no distracting animation; just replace text

Interaction

Use this → replace textarea contents (or replace selected portion)
Append → add as a new paragraph
Suggestions are hints only; user always owns the final brief

Done when

Typing feels live with nano stream
Abort works (no stale suggestion overwriting newer draft)
No-JS path still clean


Milestone 5 — Budget, safety, and polish (½–1 day)
Work

Rate / cost
Confirm hourly sharing vs dedicated sub-limit
Cap concurrent suggest to 1 (already true via global pending rule)
Optional: max N suggests per project/session to avoid spam

Copy
Error when OpenAI not configured
Error when investigation already running
Error when hourly limit hit

Accessibility
Suggestions region aria-live="polite"
Buttons labelled
Focus not stolen while streaming

Mobile
Chips scroll horizontally
Suggestions stack cleanly

Docs
Update ADMIN_RESEARCH.md: new action, model, limits, no-JS behaviour
Update PROJECT_STATE.md


Done when

Documented, safe under existing hosting constraints, accessible


Milestone 6 — Tests + release checks (½–1 day)
Tests

PHP: validation, CSRF, pending conflict, hourly limit, model forced to nano, usage recorded
HTTP: concurrent suggest vs running investigation
Browser (Playwright):
Create form visible when no project
Static chips work
Checklist updates
Suggest panel appears after typing (mock/synthetic stream if needed)
Create still works with JS disabled
Axe: no new violations


Release

Rebuild UI/manifest if assets changed
Required GitHub check still green


Concrete technical choices

















































TopicChoiceModelgpt-4.1-nano onlyTransportStreaming Responses API → SSE to browserDebounce500 ms defaultMin draft length~12–20 charactersMax draft for suggest~2000 bytesOutput cap~200 tokensAuthSame session + CSRF as other research actionsPending ruleShare global one-pending research lockPersistenceNo new tables in v1; brief still a single text fieldNo-JSFull create + static examples; no live suggest

Suggested file touch list

src/ResearchView.php — create card markup, suggestions host, chips
src/Research.php / API dispatcher — research_brief_suggest action
src/UsageMeter.php — measure nano suggest calls
assets/panel.js — debounce, stream reader, insert actions, checklist
assets/app.css (or panel CSS) — create card + suggestions layout
docs/ADMIN_RESEARCH.md, docs/PROJECT_STATE.md
tests/... — suggest endpoint + UI smoke


Out of scope for this plan

Changing Dream/Plan prompts themselves
Structured brief DB columns (can be a later migration)
Auto-starting research after create
Using GPT-4.1 / mini for suggestions (too slow/expensive for this UX)
Multi-user collaborative editing


Success criteria

Create research is an obvious, calm starting screen — not a nested admin form.
Users can create a project with no JavaScript.
With JS, typing a partial brief yields fast streamed suggestions from gpt-4.1-nano.
Suggestions are easy to apply, never auto-run research, and respect pending/hourly/spend guards.
Brief quality improves in practice (more specific questions, context, falsification cues).
Existing investigation flow, map, and limits remain intact.


Implementation order (practical)

Milestone 1 (UI shell) → shippable alone
Milestone 2 (checklist) → quick win
Milestone 3 + 4 (stream endpoint + client) → the “feels good” part
Milestone 5–6 → harden and document

If you want this broken into exact tickets/PR slices next, the cleanest split is:

PR1: Create UI + static chips + checklist
PR2: research_brief_suggest streaming endpoint + tests
PR3: Client stream UI + docs + browser checks