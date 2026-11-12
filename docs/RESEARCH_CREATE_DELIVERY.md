# Research create flow — implementation and evidence

Owner's supplied specification: RESEARCH_CREATE_PLAN.md (retained verbatim).
Baseline: checked release 9226ca8; ResearchView::render contained a nested
project-create details, a title and three-row brief, and static guidance. Existing
ui_smoke's first-project fixture is retained and updated for the new heading.

| Milestone | Implemented scope | Validation |
|---|---|---|
| 0 Baseline | Baseline revision recorded, supplied plan retained, existing empty-project fixture preserved | Existing and new browser fixtures |
| 1 Create screen | First-class card, eight-row brief, Working title, six copyable starters, coach, secondary project picker, New research link, explicit newly-created-project redirect | brief_ui; no-JS creation and browser screenshots |
| 2 Checklist | Soft question/context/evidence/scope hints, byte count; never blocks based on quality | brief_ui typing and starter checks |
| 3 Endpoint | Auth/CSRF POST, streaming Responses, hard-coded nano, 16-character/2,000-byte validation, no tools, 240 output tokens, 25-second timeout, private usage journal | research_brief_suggest.php; brief_http.py |
| 4 Live client | 500 ms debounce, AbortController, generation guards, incremental reader, Apply/Append/Undo, idle/thinking/receiving/ready/error/busy/limit states, submit cancellation | brief_ui synthetic stream, stale result and failure assertions |
| 5 Guards/polish | Shared provider lock, dedicated 20/hour and $0.01/hour draft budget, accessible polite announcements, mobile chips/tips, progressive enhancement | PHP conflict/cost/privacy tests; concurrent HTTP test; Chromium axe/mobile/no-JS |
| 6 Release | UI build and manifest regenerated; local regression suites pass | Live nano browser success; final exact-SHA GitHub checks required during release |

## Product decisions

- Keep one free-form brief, strongly scaffolded, and the existing `name`/`brief`
  POST fields. No structured columns or suggestion tables are added.
- Saved brief: nonempty, up to 4,000 UTF-8 bytes; working title up to 160 bytes.
  Existing project data is not rewritten. Invalid creates retain submitted text.
- Writing help operates before a project exists. It has a dedicated workspace
  allowance: 20 requests/hour and $0.01 USD/hour including conservative reserves
  for unknown outcomes. It does not spend an existing project's cap or consume
  its investigation allowance; existing project spend caps remain authoritative
  for investigation/chat/Learn. This implements the plan's dedicated-sub-limit
  option without creating dummy projects or modifying the schema.
- Admission uses private `provider-request.lock`. Suggestions hold it until the
  provider finishes, and refuse active investigation/chat/Learn/task records.
  Creation/step/chat/Learn/task dispatches share the lock. Stop and status do not.
- `brief-suggestions.json` is an atomically replaced mode-0600 metadata journal
  under private runtime storage, bounded to 100 records. It stores request hashes,
  timestamps, statuses, usage and estimates; never drafts or generated text.
- Aborting the browser prevents stale UI updates, but an already-dispatched call
  may finish and be billed. The server retains its usage. An uncertain draft hash
  is not resubmitted within the hour. Different drafts remain independently
  bounded; no unknown outcome is retried automatically.
- Static examples and the create form work without JavaScript. Suggestions are
  hints, not evidence, and never submit the form or start research.

## Live-model blocker (12 November 2026 AWST)

A real cloud-browser attempt with the configured credential reached OpenAI.
`gpt-4.1-nano` returned HTTP 404, code `model_not_found`, with a retirement message.
The provider's deprecations page lists its shutdown on 23 October 2026:
https://developers.openai.com/api/docs/deprecations

The owner approved `gpt-5.4-nano` as the replacement on 12 November 2026 AWST.
The original supplied plan is preserved unchanged; this approval supersedes only
its exact model name. The helper always uses that nano model with reasoning effort
`none`; project/server model overrides are ignored and no fallback is automatic.
Its documented shutdown is 1 April 2027. Standard prices are $0.20 input, $0.02
cached input and $1.25 output per million tokens. Only the alias and its explicit
2026-03-17 snapshot are recognized for those rates:
https://developers.openai.com/api/docs/models/gpt-5.4-nano

## Successful live validation

A fresh cloud Chromium test used the configured OpenAI credential and real
Responses SSE. Starting with “Research solar power for Perth households”, the
helper returned three distinct questions adding economic/technical/policy scope
and concrete evidence cues. First displayed text arrived after 1,175 ms including
the 500 ms debounce. The returned snapshot was gpt-5.4-nano-2026-03-17, with 100
input tokens, 162 output tokens, zero reasoning/tool tokens/calls, and an estimated
$0.0002225 USD at saved rates (not an invoice claim). Usage was recorded in the
private journal. Apply then Create saved the selected brief and revealed Start
research, without starting an investigation. No browser script errors occurred.

Synthetic tests cover the failure paths and concurrency; local browser checks
cover desktop/mobile, no-JS, axe, starters/checklist, use/append/undo, cancellation,
stale streams, busy/rate-limit/early-end states and submit cancellation. The
existing full map/ledger/research UI suite and all PHP/HTTP suites remain required.
This establishes live cloud operation and a concrete example of improved brief
specificity; it does not prove hosted cPanel stream behavior or general quality.
Publication must verify planning-panel on the exact release commit on both refs.
