# Admin data management and runtime inventory

Admin now contains Saved workspace data and Database setup, tables and PHP runtime.
The existing PIN/session gates these controls; every mutation is POST + CSRF and
requires its displayed confirmation phrase. Building or installing this feature
does not itself delete any data.

## Available operations

| Control | Removed or reset | Kept |
|---|---|---|
| Clear research history | All project investigations, sources/passages, ledger relations/versions, rankings, requests/usage and legacy chat | Research project name, brief and model/budget settings |
| Delete research project | Project plus all of the above | Other projects |
| Delete investigation | Selected run and its complete dependency graph | Project, chat and other runs |
| Clear conversation | Selected project's legacy user/assistant messages and usage | Investigation evidence and project |
| Clear tasks and runs | Project tasks, run outputs/usage and activity | Task project name/brief |
| Delete task project | Project plus tasks, outputs/usage and activity | Other task projects |
| Clear task history | Task run outputs/usage, task activity and evidence; task returns to draft | Task title and acceptance criteria |
| Delete task | Selected task and its run outputs/usage/activity | Project and other tasks |
| Clear task activity log | Workspace task activity events | Projects, tasks and run outputs |
| Clear diagnostic log | Prior diagnostic events; one new cleanup event is recorded | Project data, credentials and usage |
| Clear writing-help history | Metadata usage journal, including its local rolling-hour allowance | Projects, vault and provider-side charges |
| Reset all project data | Both project types, tasks, conversations, investigations, evidence, usage and task activity | PIN, encrypted vault/key, signing identity, security throttling, configuration, sessions, installed code, diagnostics and writing-help journal |

Existing vault controls still replace or delete individual key/note entries.
Schema, database files, security throttling and loader-owned identity/receipts are
not dropped or recreated by project cleanup. Local usage/counters attached to
removed history are removed too; provider charges are not refunded. Database
file sizes may stay unchanged because SQLite reuses free pages.

## Consistency and active requests

A shared provider-request lock blocks destructive changes while a live task,
suggestion, investigation phase, chat or Learn provider call is executing. Stop
and status continue to work. Saved interrupted/paused records can be cleared
when no provider worker holds the lock. No database filename or SQL can be
supplied by the form: operation names and affected tables are allowlisted.

The two fixed SQLite files are attached to one PDO connection for each database
operation. A transaction deletes descendants before parents, checks foreign-key
relationships in both databases, then commits. A failure rolls back both database
changes. Full project reset also clears orphaned legacy rows in known managed
project tables; scoped deletions never reach other projects. No claim is made about power-failure atomicity under host-specific WAL
configuration. Diagnostic truncation uses the log writer's file lock; writing-help
journal reset uses atomic file replacement under the provider lock.

Successful mutations rotate the current panel session's CSRF token so old forms
from that session cannot resubmit against potentially reused IDs. Other open
workspace pages should be refreshed. The operation and target ID are recorded in
the private diagnostic journal; provider request text and credentials are omitted.

## Technical inventory

The report reads actual values from the running installation, rather than listing
only expected configuration:

- `panel.sqlite`: projects, tasks, agent runs, activity, security throttling.
- `research.sqlite`: research projects/messages/runs/nodes/sources/requests,
  passages, evidence edges, claim versions and path rankings.
- Each database: actual PDO database-list path, SQLite driver/library version,
  size, permissions/writability, journal mode, quick check, foreign-key issues.
- Every application table: row count, columns, types, primary-key/NOT NULL flags,
  foreign keys and CREATE TABLE schema. System SQLite tables are omitted.
- PHP: version/SAPI, OS family, active and additional INI paths, error-reporting
  mask, timezone, available PDO drivers, extension versions, memory use and
  selected effective limits/error/streaming settings.
- Private runtime file presence/size/permissions and purpose. Contents of config,
  vault keys, encrypted vault and sessions are never dumped.

Copy technical report produces JSON containing those metadata, server paths and
schema, excluding project contents, credentials, environment values and full
phpinfo output. Existing Copy contents remains the application/PHP diagnostic log.
No arbitrary PHP execution or SQL console is exposed.

## Validation

`php tests/admin_data.php` covers scoped graph deletion/reset, no orphan rows,
wrong confirmation, unknown operations, active-worker rejection, protected-state
hashes, real inventory metadata, secret exclusion and rollback after synthetic
failures (including failure in the second attached database).

`python3 -B -m unittest -v tests/admin_http.py` exercises real authenticated/CSRF
HTTP forms, malformed IDs/operations, wrong confirmation, concurrent provider
lock, successful deletion, stale-token rejection and preserved configuration.

`tests/admin_ui.cjs` uses disposable data for actual browser deletion, inventory
inspection, clipboard equality, retained-project checks, mobile overflow, axe and
no-JavaScript history reset. It joins the existing brief/map/workspace UI suite.
All mutation validation uses local disposable fixtures, not the owner's live host.

## Selected-project JSON and map inspection

The Admin JSON inspector shows a selected project's investigations, raw stored
rows, polling payload and same-engine browser projection with filters. Copy or
download the debug bundle for a matched capture. It is read-only, includes
reference/cycle checks, and preserves raw rows when payload construction fails.
See PROJECT_JSON_INSPECTION.md for capture timing, no-JS downloads and browser
view-state limitations. No public JSON file or provider request is created.
