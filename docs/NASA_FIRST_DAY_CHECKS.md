# NASA Managed — first-day validation record

The available Cloud environment onboarding plugin workflow was applied: inspect
the existing checkout, reuse its installed and locked dependencies, verify actual
startup and functional requests, distinguish application defects from environment
prerequisites, and preserve credentials/configuration. This did not publish a new
cloud-environment snapshot or change its network policy.

Environment observations: connected/running, current spec revision 3, enforced
unrestricted outbound policy. Actual tools: PHP 8.4.24, Node v24.22.1 and Chromium
156.0.8078.79. Existing PHP extensions, private loopback router, Playwright and
axe are reused. The NASA feature adds no dependency or service-start requirement,
so no environment draft change is needed.

| Check | Evidence / expected result |
| --- | --- |
| Reference source | Official NASA PDF downloaded successfully over HTTPS; eight §3.11.2 tailoring criteria, 17 processes, phase prompts and source SHA-256 recorded in knowledge/docs |
| Clean first use | Create a disposable private workspace, select a saved project, start NASA planning, open guidance and create documents |
| No-key operation | Manual documents, requirements, tailoring, evidence attachment/review, interfaces and exports work without OpenAI; a baseline requires an explicit local review of the current revision; AI requests fail before provider dispatch |
| Saved-work compatibility | Additive, repeatable tables; distinct task/research source identities; version history, linked context and secret identities retained |
| Review boundaries | Drafts cannot approve a gate; requirements need matching reviewed linked evidence plus a saved explicit owner-confirmation marker; high open residual risks prevent phase advance; structured tailoring records criterion, residual risk and revisit trigger |
| Provider simulation | Structured draft/review/collaborator replies, pending bundles that write no planning records before owner acceptance, atomic accepted drafts, scope/schema rejection, superseded replies, unknown outcome reservation, no automatic retry and unchanged rate/spend admission |
| Integrations | Research promotion and linked provenance, captured evidence with separate owner review, focused research gaps and user-origin unchecked map proposals; Stop and selected-project guards preserved |
| Browser | Desktop/mobile, light/dark, real forms, beginner/practitioner views, explanations, keyboard tabs/focus, no-JS critical workflows, scoped downloads and Chromium PDF output |
| SE Context Pack | Scoped JSON and short Markdown retain current phase/posture, requirements/methods/effective verification, unclosed risks, tailoring rationales, interfaces and versioned reviewed provenance; artifact/source excerpts and omitted histories are explicit |
| Confirmation compatibility | Historical verified flags remain readable but never become effective verification by merely attaching reviewed evidence; explicit owner confirmation saves a subject-bound marker; edits/restores require fresh confirmation |
| Access / content | Owner auth, CSRF, public-testing denial, safe HTML rendering, no Vault/cross-project prompt context |
| Release | UI/manifest/syntax, application and HTTP suites, real local SSH regression, complete browser suite and exact-SHA planning-panel CI required before readiness |
| Live OpenAI | **Blocked:** the existing environment binding returns HTTP 401, provider code `expired_secret_key`. Confirmed with two different HTTPS clients; connectivity works, authentication does not |

The invalid key was never printed or copied into code, logs or documentation.
No substitute credential was invented. The owner has been asked to replace the
existing binding securely in environment settings; the live check can then be
retried. This does not establish whether the separately configured live website's
Admin key is valid. Do not describe synthetic model replies as live AI success.

The validation results and release SHA are recorded in PROJECT_STATE.md when the
checks complete. Browser images and a sample PDF are generated under the ignored
`test-artifacts/` directory and retained by CI.

Initial NASA release validation, before the evolution slices: all 22 PHP suites passed; NASA's unit/integration suite contains
61 assertions. All eleven Python suites passed, with four NASA HTTP cases covering
empty, no-key, owner, CSRF, password and public-testing paths. The complete Chromium
suite passed; a final NASA rerun covers archive/restore and PDF export. Light/dark
desktop/mobile NASA axe audits found no violations. The generated PDF was also
text-extracted locally to confirm the current owner document, requirement and
risk content is present. Source deletion/ID-reuse tests retain private keys while
clearing task, research and Systems project associations. Live OpenAI remains
blocked by the expired binding above; no live model success is claimed.

Evolution export/confirmation checks (2026-11-22): `php tests/nasa_context_pack.php`
passes 30 scoped PHP/SQLite assertions. These include selected-project isolation,
Vault/key/history exclusion, read-only exports, full current requirement and
tailoring facts, residual-risk posture, source hashes/versions/review limitations,
explicit excerpt lengths, Markdown continuation facts, and the legacy-confirmation
boundary. A historical verified row with newly reviewed evidence remains unverified
in the context pack and readiness until the owner explicitly confirms it. A saved
confirmation is bound to the exact requirement/method; editing or restoring does
not restore effective verification. These are local synthetic evidence fixtures,
not live model, production-test or hosted-deployment success. The final aggregate
regression result and exact checked release SHA belong in PROJECT_STATE.md.
