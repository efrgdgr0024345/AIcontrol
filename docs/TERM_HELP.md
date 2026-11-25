# Click-to-explain research terms

Research briefs, open output bodies and the selected map concept reader highlight
technical terms with a dotted underline. Highlighting uses a local phrase list,
long-word and acronym heuristics, at most eight distinct terms per region. This
is intentionally approximate; it does not identify every specialist phrase.
It makes no discovery, hover, focus, scroll or polling requests to an AI model.

Click or press Enter on a highlighted term to request a definition. The first
uncached click uses `gpt-5.4-nano`, reasoning effort `none`, no tools, `store:false`,
at most 160 output tokens and a 20-second timeout. The prompt requests two short
sentences plus an example, up to 75 words. It receives the saved project title,
term and at most 700 characters around its occurrence. Server validation requires
the term to occur in saved text belonging to the selected project. Other projects,
vault notes and system credentials do not enter this context.

The popup labels results as AI learning aids, not verified evidence. It renders
provider text literally, preserves the original research and supports touch,
keyboard, Escape and desktop/mobile layouts. After loading, hovering or focusing
the term can show its local saved explanation without another request. A click
after a page reload retrieves the private saved definition without model credits.
Loading and failed results never start an automatic retry.

The popup owns its outside-click/Escape dismissal so clicking an already hovered
term keeps it open. It follows the term while a long mobile concept reader scrolls,
and closes when its source disappears or scrolls out of view. Closing it restores
keyboard focus without scrolling the reader.

The shared provider lock serializes dispatch with research, chat and writing help.
Saved definitions are available even without a provider credential or while that
lock is occupied. Quick explanations have their own workspace-wide rolling-hour
allowance: 30 dispatches and US$0.01 estimated spend plus unverified reservations.
They do not consume the investigation request allowance. Conservative reservations
are recorded before dispatch; returned usage settles them. A timeout retains its
reservation and blocks another same-context call until the user explicitly chooses
Retry. A retry does not erase earlier unknown costs. These are local estimates,
not provider invoice totals or a guarantee about billing.

Additive private `research.sqlite` tables:

- `research_term_explanations`: definition, term, owning project/node, model,
  source digest, creation time and cache key.
- `research_term_usage`: dispatch metadata, tokens, estimated/reserved cost and
  saved pricing provenance; no original excerpts or definitions.

Cache keys include stable project/run identities, model, source kind, source
digest and normalized term. Changed source text invalidates reuse; a changed
source during generation is not cached. Project export includes learning aids and
their usage. Admin's database report inventories the tables and reports the
rolling-hour allowance. Resetting/deleting project content clears its definitions;
usage metadata remains for allowance accounting, detached from deleted projects.
Deleting a node/run cascades its definitions. No PIN, signing or vault changes.

Only owner-authenticated sessions have the highlighting configuration or paid
endpoint. The POST requires the existing CSRF token; public-testing sessions are
denied. JavaScript-disabled pages retain their original readable research text.

Validation: `php tests/term_help.php` exercises synthetic provider responses,
source isolation/integrity, bounded prompts, cache/versioning, admission,
uncertainty/retry, quotas, export and Admin cleanup. `node tests/term_help_ui.cjs`
uses real local HTTP/SQLite with synthetic model replies to verify zero hover
calls, one-click dispatch, reload cache reuse, literal output, auth/CSRF/demo
boundaries, map keyboard behavior and desktop/mobile/dark accessibility.
Fixtures are not evidence of a live model call or hosted activation.
