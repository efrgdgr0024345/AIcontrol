# NASA workspace evolution — release checks

This remains a local software planning aid, without NASA affiliation,
certification or approval. Handbook knowledge version: `2026-11-22.2`.

| Slice | Delivered behaviour | Principal checks |
| --- | --- | --- |
| Foundation | Actual eight tailoring criteria, recursive/phase/process guidance, versioned residual-risk exceptions and readiness rationale | nasa.php, nasa_http.py, nasa_ui.cjs |
| Integration | Scoped research promotion, captured provenance, explicit focused exploration and evidence-gated owner verification | nasa_integration.php, nasa_http.py, nasa_ui.cjs |
| Collaborator | Bounded next moves and pending atomic bundles, editable proposals, explicit accept/reject | nasa_collaborator.php, nasa_ui.cjs |
| Discipline | Current-review baselines, research/code references, visible deltas, immutable restores and structured interfaces | nasa_discipline.php, nasa_atomicity.php, nasa_ui.cjs |
| Polish | Beginner/practitioner view, current posture, scoped SE Context Pack JSON/Markdown and print/PDF | nasa_context_pack.php, nasa_http.py, nasa_ui.cjs |

Foundation shipped independently before the integrated slices. Every later slice
has its own storage/boundary suite and participates in the cumulative required
regression checks. Research and planning use their existing private databases;
no public JSON file is created. Export downloads stay behind owner authentication.

The first-day browser flow starts a project, reads guidance, proposes and explicitly
accepts all eight artifacts in three bundles, edits versions, reviews evidence,
confirms a requirement, records tailoring/interface contracts and a reviewed
baseline, exports a scoped package/PDF and exercises keyboard/no-JS paths.
Provider replies in that flow are synthetic. The real SSH test uses a disposable
local server. Neither is evidence of a cPanel installation or successful live AI.

The environment plugin reports a running, connected workspace. The unchanged
OpenAI binding previously failed with `expired_secret_key`; replace it through
protected environment/Admin settings before testing successful live AI. No secret
values belong in chat, diagnostics or this repository.

After the exact release commit has green `planning-panel` checks, open Admin →
Application updates → Check GitHub, review the release and install it. Then use
a disposable research/planning project for the first-day checks in
[NASA_FIRST_DAY_CHECKS.md](NASA_FIRST_DAY_CHECKS.md). Installation retains existing
PIN, signing keys, Vault entries, projects and history.
