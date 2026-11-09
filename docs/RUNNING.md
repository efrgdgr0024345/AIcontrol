# Running the planning panel

For the intended **one uploaded file → GitHub download → installation** workflow,
use [the standalone loader guide](STANDALONE_LOADER.md). The source-checkout
procedures below are for development and manual operator installations.

## What this release does

One password-authenticated user can record project briefs and task proposals,
inspect planned systems, and follow proposal history. No credentials, agents,
provider billing or deployment authority are available. The password is a panel
login only; never reuse a cPanel, GitHub or provider password.

PHP 8.2–8.5, sessions, PDO SQLite and mbstring are required. The broader
`bin/check-host.php` also checks planned production dependencies (PDO MySQL,
curl, JSON, OpenSSL, sodium and zip). No Composer dependencies are used yet.
SQLite is for this metadata prototype; production job/grant/budget concurrency
will use MariaDB/MySQL as specified in the handover.

## Initialize private state

Use the existing checkout in its isolated cloud task. Do not create a worktree
unless explicitly requested. Run setup from the repository root. Choose a
directory outside the checkout and **all** web document roots. Inventory addon
domains and same-account sites first. Private file permissions do not protect
against another compromised application under the same Unix user.

Run from Bash on the server through an authorized management session. Replace
the example paths. `read -s` keeps the login password out of terminal echo and
shell history; it is passed through stdin, not a command-line argument.

```sh
cd /path/to/AIcontrol
read -r -s -p 'New panel login password (14–256 bytes): ' panel_password
printf '\n'
printf '%s' "$panel_password" | php bin/setup.php \
  --private-dir=/private/path/aicontrol \
  --public-root=/path/to/AIcontrol \
  --public-root=/path/to/other/document-root
unset panel_password
```

Supply each document root with another `--public-root`. All paths must exist;
private storage must use its canonical absolute path. The setup command creates
the database, password hash and session directory with private permissions. It
refuses to overwrite nonempty state. A failed partial setup needs operator
inspection; it is not automatically deleted or reset.

Private files must not be uploaded to GitHub, packed in a release, served as
downloads or copied into this checkout. Back up the private directory separately
on the server with restricted access. There is no password-reset endpoint yet;
do not delete state to reset a login. Server-operator password rotation/recovery
is an outstanding operations task before wider use.

## Local development

```sh
cd /path/to/AIcontrol
export AICONTROL_PRIVATE_DIR=/private/path/aicontrol
export AICONTROL_LOCAL_DEV=1
php -S 127.0.0.1:8080 bin/dev-router.php
```

The HTTP exception only works for a loopback peer and explicit development flag.
The development router serves only the stylesheet and application routes, not
arbitrary repository files. The PHP development server is not a production web
server. Processes must be restarted in a new task; the private database persists
independently. No localhost preview link is needed for onboarding validation.

## cPanel installation

The owner selected shared cPanel for testing. Use synthetic or nonconfidential
project data at this stage; no production service credentials. Other applications
sharing the Unix user remain inside the same filesystem trust boundary.

Live installation requires the target domain/account details and an authorized
server-management path. This cloud checkout alone does not deploy to cPanel.

1. Confirm a dedicated account where possible, enumerate every public root, and
   run the host check on the actual account. Enable required PHP extensions.
2. Fetch the reviewed source commit directly from GitHub to cPanel through the
   authorized server-management path. Do not transfer private state with it.
   This checkout-based procedure is a manual operator installation. The separate
   standalone loader now provides the checked-commit download/install flow.
3. Put the source in the intended document root. The supplied Apache 2.4
   `.htaccess` routes through `loader.php`, allows the single CSS asset and
   disables directory indexing. Verify rewrite support; it must not be ignored.
4. Initialize private state with the CLI procedure above. Do not expose setup
   as a web route. Set `AICONTROL_PRIVATE_DIR` in the server's PHP environment.
   With PHP-FPM/CGI that does not inherit Apache environment settings, an
   operator can create a private PHP file containing
   `<?php putenv('AICONTROL_PRIVATE_DIR=/private/path/aicontrol');` and reference
   its absolute path using PHP's `auto_prepend_file` setting (for example in an
   account-managed `.user.ini`). Keep this host configuration outside future
   update-managed paths. The private directory path is not an API credential.
5. Require HTTPS. Leave `AICONTROL_LOCAL_DEV` unset on cPanel. The application
   checks the server HTTPS indicator and does not trust arbitrary forwarded
   headers; configure the hosting handler correctly rather than bypassing it.
6. Verify sign-in, logout, a project and task proposal, activity and persistence
   after a PHP worker restart. Inspect cookie Secure/HttpOnly/SameSite flags,
   then confirm `/bin/setup.php`, `/tests/run.php`, source/config paths and
   unknown paths are inaccessible. Never make private storage web-accessible.
7. Confirm `/health` reports `metadata_panel_only` with broker/deployment false.
   Missing or unsafe private state returns 503 without recreating it.

Do not enter production provider keys. Trusted-device approvals, vault custody,
agent dispatch, production transactional jobs and the loader updater remain
disabled/unimplemented. Hosting logs should not record request bodies or cookies.

## Validation

```sh
php bin/check-host.php
php tests/run.php
PHP_BINARY=php python3 -B -m unittest -v tests/http_smoke.py
git diff --check
```

The HTTP test initializes disposable private state, starts a loopback server,
checks login/CSRF/throttling, escaped proposal rendering, durable records, event
links, blocked routes and fail-closed damaged configuration, then stops its
server and deletes its synthetic state. It does not contact cPanel or providers.

Current cloud testing uses PHP 8.4.24. Other supported versions and actual cPanel
behavior still need validation; a version predicate unit test is not a PHP
8.2–8.5 runtime matrix. Browser walkthrough screenshots contain synthetic data.
