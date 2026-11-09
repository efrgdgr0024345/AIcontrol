# Baseline review — 9 November 2026

Reviewed repository baseline: `5e69416` (full SHA available in Git history).
Only `README.md` and `docs/TEAM_HANDOVER.md` were tracked. No PHP prototype,
loader, vault, authentication library, tests, dependency lockfile, workflow or
release artifact was present. The earlier smoke-test branch had been removed.
There was no draft implementation to reuse or certify.

## Current scope decision

The owner authorized creating the missing files, then requested deferring the
trusted-device requirement until a device is available. Deliver a working
**planning panel** first. This narrows the initial release; it does not make a
laptop session an independent approver. The original production specification
remains the target for later phases.

The planning release supports one password-authenticated panel user, project
briefs, task proposals, acceptance criteria, a catalog of unconfigured systems,
and attributable proposal events. Authentication grants proposal access only.
No fake agents, fabricated progress, zero-cost claims or green connection states.

Deferred: vault/import routes, provider calls, agent dispatch, OIDC, grants,
budgets, owner approvals, credential management, PR publishing and installation.
No production credential is needed or accepted. Unknown endpoints fail closed.

## Architecture and data boundaries

| Component | Initial implementation | Boundary |
|---|---|---|
| Public bootstrap | `loader.php` | Routing/rendering only; no install or arbitrary execution |
| Panel | Server-rendered PHP and static CSS | Authenticated proposal access; no JavaScript or browser storage |
| Authentication | PHP password hash, strict server sessions, CSRF, login throttling | One temporary planning identity; not owner approval |
| Persistence | PDO SQLite transactions and foreign keys | Metadata-only prototype; not the future concurrent broker engine |
| Private files | Operator-selected canonical directory, 0700/0600 | Outside checkout and every declared public document root |
| Systems | Explicitly unconfigured catalog | No remote integration represented as healthy |
| Tests | CLI checks, real HTTP flow, Chromium walkthrough | Disposable synthetic data, no hosting/production claim |

The application does not call a shell or run proposed code. Developer CLI tools
and the local test server are not web dependencies. A dedicated MariaDB/MySQL
schema and least-privilege database user remain required before multi-agent
production execution. SQLite is not being substituted for the final grant,
budget, outbox and job transaction model.

Data classification:

- Project briefs/task criteria are potentially confidential. They stay in the
  private database and authenticated HTML, never automatically in GitHub.
- Login password hashes and session state are private and never deployed as code.
- Catalog names and capability descriptions are nonsecret.
- Proposal events contain record references and bounded descriptions, not prompt,
  HTTP body, password or authorization-header dumps.
- No service keys, tokens, private operational keys or vault data exist in this release.

## Hosting evidence still required

The owner confirmed shared cPanel for this testing stage. The target domain,
Unix user, same-account application inventory and document roots are not yet
known. Keep this installation metadata-only; do not treat a shared subdomain as
process isolation or authorize storing real service credentials there.

The cloud development machine is not the cPanel account. Local PHP compatibility
does not establish hosting compatibility. Before live installation, record:

1. Target domain, account Unix user, PHP handler/version and HTTPS behavior.
2. Every document root, addon domain, alias and application sharing that Unix user.
3. A canonical private directory outside all those roots and verified permissions.
4. Whether a separate hosting account/user can isolate AIcontrol. A subdomain or
   second directory under the same user is not isolation.
5. PDO SQLite availability for planning; MariaDB/MySQL transactions and PDO MySQL
   for later production execution; curl, sodium, zip, mbstring and TLS trust.
6. Apache rewrite behavior, session persistence, backups, cron access, and actual
   GitHub outbound HTTPS access from cPanel.

Do not send hosting passwords or provider keys through chat. Bootstrap through
the account's existing authorized server-management path.

## Release limits

The initial planning commit preserved the public entry filename without an
updater. The follow-up standalone loader implements authenticated GitHub check,
exact-commit archive staging and code-pointer recovery; see STANDALONE_LOADER.md
for its testing scope and remaining production release requirements.
The health endpoint identifies its scope as `metadata_panel_only`; the fallback
without configured private storage is HTTP 503. A deployment of this planning
release is not approval to load real credentials or run generated code.

Review status: implementation proposal and locally tested foundation. Host
inventory, live cPanel validation, independent security review and production
acceptance are outstanding. No reviewer approval is implied by this document.
