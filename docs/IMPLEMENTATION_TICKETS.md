# Implementation tickets

These are repository-tracked work tickets, not claims that GitHub Issues were
created. Phase numbers follow `TEAM_HANDOVER.md`. All production acceptance
tests in that specification remain required. Trusted-device work is deferred
by the owner's current scope instruction, not marked complete.

| ID / phase | Deliverable | Dependency / status | Acceptance evidence |
|---|---|---|---|
| BASE-01 / 0 | Inventory actual code and gaps | Initial review recorded | Baseline commit, missing draft confirmed, boundaries in BASELINE_REVIEW |
| HOST-01 / 0 | Confirm account and hosting compatibility | Needs target hosting details | All document roots and same-user sites inventoried; private path, HTTPS, extensions and database verified on cPanel |
| PLAN-01 / 3 subset | Useful planning release | Implemented locally; live installation pending | Sign in, create project/task, see criteria and activity; restart preserves records; unauthorized/CSRF requests refused |
| BUILD-01 / 0 | Reproducible PHP release and compatibility CI | Next; no release workflow yet | PHP 8.2–8.5 test matrix, pinned build dependencies/actions, repeatable artifact manifest and secret exclusions |
| AUTH-01 / 2 | Independent trusted-device enrollment and recovery | Deferred by owner | Maintained WebAuthn library selected/tested; laptop session cannot enroll, approve or recover owner authority |
| DATA-01 / 0, 5 | Production transactional model | Before jobs/grants/budgets | MariaDB schema/migrations for spec entities; foreign keys, uniqueness, race tests, backup/restore; SQLite prototype migration |
| VAULT-01 / 1 | Server custody and lifecycle | HOST-01 and AUTH-01 before privileged UI | Sodium AEAD, separate keys, server CSPRNG, metadata-only responses, trusted import, rotation, tamper failure and restore drill |
| GRANT-01 / 2 | Immutable requests and bounded grants | AUTH-01, DATA-01 | Complete canonical action binding, expiry/use count/revocation, tamper rejection and concurrent single-spend test |
| PANEL-02 / 3 | Full project/agent/system dashboard | DATA-01 and real reporting adapters | States with evidence, dependencies/scope revisions, stale heartbeat display, permission catalog, redacted timeline |
| BROKER-01 / 4 | Metadata-only authenticated GitHub workflow | GRANT-01, DATA-01 | Fixed trusted OIDC keys/issuer/audience and immutable repo/workflow/commit/run claims; wrong identities fail; callback idempotency |
| JOB-01 / 5 | Secret-free isolated test job | BROKER-01 | No vault, owner/publisher identity or service credentials in runner; job evidence links to exact commit and attempt |
| PROVIDER-01 / 4 | One bounded OpenAI operation | VAULT-01, GRANT-01, usage reservation | Server-only key use, allowlisted destination/model, measured or unknown cost, timeout reconciliation and limits tested |
| WORK-01 / 5 | Task to patch to tests to reviewed PR | JOB-01, PANEL-02 | Clean publisher isolated from generated code; fencing leases/outbox, duplicate callbacks harmless, no auto-merge |
| DEPLOY-01 / 6 | Exact-commit loader update | BUILD-01, AUTH-01 | Ten-minute selection, required checks/artifact provenance, unsafe archive rejection, lock/journal, health rollback, security floor |
| OPS-01 / 7 | Usage, cancellation and recovery | Working broker/job path | Reservations vs actuals, original currencies, unknown cost, revoked agents refused; uncertain remote cancellation shown honestly |
| OPS-02 / 7 | Backups and independent audit checkpoint | VAULT-01, DATA-01 | Restore drill; no resurrection of revoked permissions/spent grants; external checkpoint with explicit threat limits |
| ACCEPT-01 / all | Production release review | All production tickets | All handover §11 gates executed with exact commit evidence; independent security review before real credentials |

## Next demonstrations

1. Install and verify the planning panel on the selected hosting account. Record
   hosting evidence without sending credentials through the laptop/chat.
2. Complete host isolation, reproducible builds and the production data model.
3. Resume independent authorization when a trusted device is available, then add
   metadata-only broker authentication and a secret-free job.
4. Add bounded provider use, coding-to-PR and finally reviewed production delivery.

Do not silently enable privileged operations to bypass deferred AUTH-01. Any
change to that security scope requires an explicit design decision and review.
