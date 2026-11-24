# Writing-help activity, allowance and project start

25 November 2026. Automatic writing suggestions remain enabled by default when
OpenAI is configured, as requested by the owner. A typing burst waits two seconds;
trusted mouse movement and scroll intent wait one second. Activity bursts combine
into one queued update with at least eight seconds between automatic dispatches.
Mouse, wheel, touch-scroll and keyboard-scroll intent are supported. Layout-only
or programmatic scrolling does not repeatedly buy suggestions. Refresh remains an
explicit full-set request; selecting/append applies the chosen alternative and
requests one new alternative in that same slot while keeping the other cards.

Only one browser suggestion request runs at a time. Further input changes the
queued draft; it does not abort the dispatched call. Stale response text cannot
replace newer user work, but returned usage still updates the allowance display.
Errors pause automatic dispatch until Refresh or an explicit toggle, avoiding
unattended retries. Page departure can interrupt a response; unknown outcomes
still keep their reservations. Saving waits for the current bounded writing-help
request, cancels queued help, then submits the project once.

Full sets use gpt-5.4-nano, no tools, 240 output tokens. A replacement requests
one alternative with 100 output tokens. Existing alternatives are a bounded
untrusted input to avoid repetition; exact repeats are filtered. The metadata
journal stores no raw brief or alternatives. Both kinds share the existing
20-request/$0.01 USD workspace-wide rolling-hour allowance and ProviderGate.

The UI separates usage estimated from returned tokens from money reserved for
unverified usage; reservations are not confirmed charges. It shows request count
and when the oldest request expires. Owner/CSRF-protected research_brief_usage
provides the same summary and up to 20 recent metadata records without brief text,
fingerprints, credentials or provider output. Public testing cannot read it.

An orphaned reservation can be reconciled only with a matching private diagnostic
request ID, requested model, HTTP 200, completed response and priceable returned
usage. The record is labelled usage_recovered, without pretending lost suggestion
text was recovered. Missing, failed, incomplete or unpriced evidence leaves the
reservation intact. Reads compute the supported allowance without changing the
journal; the next admitted suggestion persists any supported recovery while holding
the provider lock. New completed usage is saved before validating or emitting
suggestion results. No budget is raised and no provider call is made to reconcile.

Create and start research saves the brief, creates one ordinary bounded run and
redirects to its full-screen map. Normal authenticated polling begins its model
exploration. Stop, request/spend caps and explicit web-Evidence selection remain
authoritative; this does not enable indefinite automatic growth. Missing keys,
active requests or exhausted research limits retain the saved project and show
why it did not start. Existing integrations omitting start_research=1 retain their
save-only behavior. No-JS users get the saved running investigation and ordinary
manual phase controls; the 3D map still requires JavaScript.
