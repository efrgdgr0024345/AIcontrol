# Running the planning panel

For cPanel, use [the standalone loader guide](STANDALONE_LOADER.md): protect the
hosting directory, upload one loader file, check GitHub and install. No app
password or hash is required. Only the planning panel is enabled; there are no
agent runs, provider credentials, billable calls or production deployments.

## Source-checkout development

Use the existing checkout in its isolated cloud task; do not create a worktree
unless requested. PHP 8.2–8.5, PDO SQLite, mbstring and sessions are required.
The broader host check also checks future broker prerequisites. Python 3 is
needed only for HTTP tests. No Composer dependencies are used yet.

Choose an empty canonical private directory outside the checkout and every
public document root. Its parent must exist. The following creates a loopback
planning demo without a password:

```sh
cd /path/to/AIcontrol
php bin/setup.php --access-mode=local \
  --private-dir=/private/path/aicontrol \
  --public-root=/path/to/AIcontrol
export AICONTROL_PRIVATE_DIR=/private/path/aicontrol
export AICONTROL_LOCAL_DEV=1
php -S 127.0.0.1:8080 bin/dev-router.php
```

Repeat `--public-root` for all other document roots. Setup refuses nonempty
state. It creates SQLite records, access configuration and sessions with private
permissions. Local access requires both the explicit development flag and an
actual loopback peer. The development server is not a production web server.
Processes must restart in a new task; retained private records persist.

For host access, omit `--access-mode=local`. The setup default is `host`, which
requires web-server-authenticated `REMOTE_USER` and HTTPS. No caller-supplied
identity header is accepted. Configure authentication through the host; never
allow access by setting a fixed identity on all public requests.

## Verification

```sh
php bin/check-host.php
php bin/build-manifest.php --check
php tests/run.php
php tests/loader.php
python3 -B -m unittest -v tests/http_smoke.py tests/standalone_smoke.py tests/passwordless_smoke.py
git diff --check
```

Tests initialize disposable metadata, run loopback servers and clean up their
own files/processes. Existing password fixtures test older-install compatibility;
new normal setup does not read a password or generate a hash. The cloud machine
runs PHP 8.4.24; cPanel and all supported PHP versions still need their own
runtime validation. A version predicate check is not a multi-version matrix.

The health response identifies `metadata_panel_only`. Missing or damaged private
state returns failure without recreation. Do not commit private files, expose
sessions/database files, or store real provider keys in this testing release.
