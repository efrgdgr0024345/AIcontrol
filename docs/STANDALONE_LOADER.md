# Single-file installation — no app password

Upload **only `loader.php`** into a directory protected by the hosting server.
The loader downloads the checked application from GitHub into private storage.
It needs no Composer, shell commands, password hashes or manual source upload.

## cPanel steps

1. Create a dedicated directory, for example `public_html/control`.
2. In **cPanel → Directory Privacy**, protect that directory with a dedicated
   restricted web-access user. This is hosting access protection, not another
   AIcontrol login. Do not use the cPanel administrator password.
3. Upload `loader.php`, then open its HTTPS URL. The server must authenticate the
   request and supply PHP's `REMOTE_USER`. The installer checks that value on
   every request. Missing identity returns 403; entering a client identity header
   or ordinary Basic Authorization header cannot bypass the check.
4. Enter a new empty private directory outside **every** public document root.
   Its canonical parent must already exist and be writable. List all additional
   addon-domain document roots. Shared Unix-user websites are not isolated from
   each other by directory permissions.
5. Click **Check GitHub**. The testing channel is
   `feature/control-panel-foundation`; main still requires normal reviewed merge.
6. Review the exact full commit SHA, manifest digest, destination and expiry,
   then click **Install this checked commit** within ten minutes.
7. Open the panel. There is **no application password, hash-generation step or
   separate panel sign-in**. Create a project and task, then inspect Activity.

If Directory Privacy authenticates successfully but the installer still returns
403, ask the host to propagate authenticated `REMOTE_USER` into PHP-FPM/CGI.
Do not substitute `PHP_AUTH_USER`, `HTTP_REMOTE_USER` or forwarded headers; those
can be derived from unverified client input. Verify the host actually rejects
unauthorized requests, not merely that it sets a username variable.

The host login still protects the site from other internet visitors. It does not
make a compromised laptop trustworthy. Independent phone approval remains a
future phase. This testing panel cannot use provider keys, run agents, spend
money, publish PRs or deploy production projects. Installer access can change
AIcontrol code, so protect the entire directory, including `?installer=1`.

## Host requirements

PHP 8.2–8.5; curl, zip, tokenizer, mbstring, PDO SQLite and sessions; HTTPS;
outbound verified HTTPS to `api.github.com` and `codeload.github.com`; writable
private storage and permission to create a small location file beside the loader.
No Apache rewrite rules are required when visiting `loader.php` directly.

The public AIcontrol repository needs no token. Private repositories require a
narrowly scoped GitHub token configured directly in the server environment as
`AICONTROL_GITHUB_TOKEN`; there is no browser token-import form. Credentials are
sent only to the fixed GitHub API host, not forwarded to redirect destinations.

GitHub Actions must have a successful `planning-panel` result for the exact
selected SHA. Missing or pending checks block installation. This is not a claim
of independent security review or signed publisher provenance.

## Storage and recovery

Only `loader.php` and generated `.aicontrol-location.php` live publicly. The
location file exits without displaying data; it contains a path/root inventory,
not credentials. Keep its mode 0600. Private storage (0700) contains:

- `releases/<full-sha>/`: immutable checked code, manifest and prior releases.
- `runtime/config.json`: access mode and public-root inventory, no new password hash.
- `runtime/panel.sqlite`: project/task proposals and append-only proposal events.
- `runtime/sessions/`: server-side sessions used for CSRF protection.
- `current.json`, `journal.json`, `deployment.lock`: activation/recovery state.

Do not put private storage in Git, release archives or any web document root.
The loader validates archive paths/types/duplicates/size, manifest file hashes,
and PHP syntax before activating. It refuses modified local releases. A lock
serializes updates; the journal restores the previous code pointer after failed
or interrupted activation. The bounded local health probe verifies private
configuration and database readability; SQLite waits at most five seconds on a
busy database. Project records, sessions and access state are not rolled back.

Missing/damaged private state fails closed instead of being reset. Partial first
setup may require operator inspection. Old releases are retained; monitor disk
usage. The bootstrap itself is replaced only by an explicitly reviewed upload.
Production security floors, migration windows, grants and independent approval
are still future work. There is no background coding or deployment process yet.

## Existing password-based testing installations

Upload the new loader into the host-protected directory and install a current
checked release. The updater changes the legacy runtime to host access without
removing project records. A legacy hash/version may remain unused solely so the
retained old code can still load during rollback; new installations create none.
Old code can still ask its old password after a code rollback. Do not delete
private state to remove a login prompt.

## Local development and tests

Source-checkout setup defaults to host access:

```sh
php bin/setup.php --private-dir=/private/path/panel --public-root=/path/to/checkout
```

For a password-free loopback demo, initialize with `--access-mode=local`, set
`AICONTROL_PRIVATE_DIR` and `AICONTROL_LOCAL_DEV=1`, then run the development
server. Local mode also requires the actual peer to be 127.0.0.1 or ::1; it is not
an internet bypass. The standalone loader permits this same explicit loopback
fixture. Leave `AICONTROL_LOCAL_DEV` unset on cPanel.

```sh
php bin/build-manifest.php --check
php tests/run.php
php tests/loader.php
python3 -B -m unittest -v tests/http_smoke.py tests/standalone_smoke.py tests/passwordless_smoke.py
```

Legacy password-mode tests remain for backward compatibility. The host-auth HTTP
fixture checks direct entry, no password forms, CSRF, forged-header rejection,
identity revocation on existing cookies and installer protection. Its synthetic
HTTPS/identity is test infrastructure, not a production authentication adapter.
Actual cPanel authentication and identity propagation need live host validation.
