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
| Reference source | Official NASA PDF downloaded successfully over HTTPS; section/process structure checked and SHA-256 recorded in knowledge/docs |
| Clean first use | Create a disposable private workspace, select a saved project, start NASA planning, open guidance and create documents |
| No-key operation | Manual documents, requirements, local review, baseline and exports work without OpenAI; AI requests fail before provider dispatch |
| Saved-work compatibility | Additive, repeatable tables; distinct task/research source identities; version history, linked context and secret identities retained |
| Review boundaries | Drafts cannot approve a gate; requirements need owner-recorded evidence; high open residual risks prevent phase advance; tailoring is explicit |
| Provider simulation | Structured draft/review replies, scope/schema rejection, superseded replies, unknown outcome reservation, no automatic retry and rate/spend admission |
| Integrations | Explicit draft-task creation and user-origin unchecked map proposals; Stop and selected-project guards preserved |
| Browser | Desktop/mobile, light/dark, real forms, explanations, keyboard tabs/focus, no-JS editing, scoped download and Chromium PDF output |
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

Local outcomes: all 22 PHP suites passed; NASA's unit/integration suite contains
61 assertions. All eleven Python suites passed, with four NASA HTTP cases covering
empty, no-key, owner, CSRF, password and public-testing paths. The complete Chromium
suite passed; a final NASA rerun covers archive/restore and PDF export. Light/dark
desktop/mobile NASA axe audits found no violations. The generated PDF was also
text-extracted locally to confirm the current owner document, requirement and
risk content is present. Source deletion/ID-reuse tests retain private keys while
clearing task, research and Systems project associations. Live OpenAI remains
blocked by the expired binding above; no live model success is claimed.
