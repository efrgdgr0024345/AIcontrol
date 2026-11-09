# Single-file installation

Upload **only `loader.php`** to a PHP website directory, including a subdirectory
such as `/control/`. The loader downloads the application directly from GitHub
and keeps releases and runtime data in a private directory. It needs no shell,
Composer, Docker, manual source upload or laptop file relay during installation.

This is the metadata-only testing release. Shared cPanel is the owner's selected
testing host. No real service keys, provider calls, agents or production
deployments are enabled. Trusted-device approval remains deferred. Installer
password access currently permits code installation; it is not the independent
owner-approval boundary required for production credential custody.

## Requirements

- PHP 8.2–8.5 with curl, zip, tokenizer, mbstring, PDO SQLite and sessions.
- HTTPS with the PHP HTTPS indicator correctly configured.
- Outbound verified HTTPS to `api.github.com` and `codeload.github.com`.
- Permission to create one small location file next to `loader.php` and a private
  directory outside **every** document root on the account.
- Working PHP session storage. About 30 MB free is enough for this initial
  release; retain space for previous releases and backups as they accumulate.
- GitHub Actions enabled on the source repository. The `planning-panel` check
  must pass on the exact selected commit. Pending/missing checks block install.

It works on compatible PHP hosting, not on a server without these prerequisites.
No API key is needed for the currently public AIcontrol repository. For a private
repository, configure a narrowly scoped `AICONTROL_GITHUB_TOKEN` directly in the
server environment. There is no browser form for a GitHub service token. Tokens
are sent only to the fixed GitHub API host and not forwarded to download redirects.

## One-time installer authentication

The uploaded file starts **locked**, so the first internet visitor cannot claim
the installation. Set an installer password hash through your hosting account:

- Set the server environment variable `AICONTROL_INSTALL_PASSWORD_HASH`, or
- Edit the `AICONTROL_INSTALL_PASSWORD_HASH` constant near the top of the single
  file to contain a PHP `password_hash` result.

Use a new installer password, not a hosting, GitHub or provider password. If a
Terminal is available, generate the hash on the server without echoing or logging
the password:

```sh
read -r -s -p 'Installer password: ' installer_password
printf '\n'
printf '%s' "$installer_password" | php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT), PHP_EOL;'
unset installer_password
```

Paste only the resulting hash into that constant or the server environment.
The hash is not a service credential. If the host has no Terminal, ask its
administrator to generate/configure the PHP password hash using its supported
server-management tools. Do not send the password or any provider key in chat.
This is one-time authentication configuration; it does not require uploading
the application files. Protect the hosting administrator session itself.

## Browser installation

1. Visit the uploaded file over HTTPS and sign in with the installer password.
2. Enter a new empty private-directory path. Its parent must exist and be
   writable. On cPanel this is usually under the account home, outside
   `public_html`, not a directory inside the website with an `.htaccess` block.
3. List every additional public document root on the account, one per line.
   The loader also checks its own directory and PHP's document-root setting.
   It cannot discover every addon domain or same-user website automatically.
4. Choose a **different** panel login password (14–256 bytes). This gives access
   to project/task proposals, not installer authority. No service credentials.
5. Click **Check GitHub**. The initial release channel is explicitly
   `feature/control-panel-foundation`; `main` still needs normal reviewed merge.
   The loader resolves the ref to a full SHA, requires the GitHub Actions check,
   and fetches the manifest from that exact commit.
6. Review the commit, manifest digest, destination and ten-minute expiry. Click
   **Install this checked commit**. Changing the selection requires another check.
7. Open the panel and sign in using the separate panel password. Add one test
   project and task, then confirm their activity records.

For later installs, open the same file with `?installer=1`, sign in, check the
next commit and install it. A checked selection permits one attempt and expires
after ten minutes. Installer authentication expires after fifteen minutes.
Repeated failed sign-ins are throttled in server-side locked state.

## What is created

The public directory contains the original `loader.php` and a generated
`.aicontrol-location.php`. That small PHP file exits without displaying its
contents; it holds only the private path and public-root inventory, no passwords
or service keys. Keep its permissions `0600`. It is unmanaged installation
configuration, not a release file. Never overwrite it with a release archive.

Private storage contains:

```text
private-home/
  deployment.lock
  current.json              # active code SHA and expected manifest hash
  journal.json              # activation/recovery status and prior code pointer
  releases/<full-sha>/      # checked code only; old releases retained
  runtime/config.json       # panel password hash and public-root inventory
  runtime/panel.sqlite      # project/task proposals and events
  runtime/sessions/         # panel sessions
```

An initial `bootstrap.json` may exist while first setup is pending. It contains
the password hash, not plaintext, and is removed after successful activation.
Do not expose, commit or bundle private storage. Back it up separately.

The loader does not extract the whole repository. It validates the archive root,
paths, types, duplicates, expansion limits and manifest, then writes only the
manifest's allowed application files into a fresh private staging directory.
PHP files are syntax-checked without a shell. TLS and checksums stay enabled.
Artifact content is bound to the manifest fetched from the checked commit on
the fixed repository; a required Actions result is not an independent security
review or a cryptographic publisher signature.

## Recovery and update limits

A deployment lock serializes updates. A journal records activation intent before
the code pointer changes. The bounded local readiness probe verifies file hashes,
private configuration and database readability (SQLite busy timeout: five
seconds). Failed activation restores the prior code pointer. A later request
recovers an interrupted activation conservatively. Runtime data and password
state are never rolled back with code. Local release modifications are refused,
not overwritten. Existing staged/release code can be checked and reused safely.

This first release has no database migrations or security-grant model. Future
releases must add schema compatibility windows, security floors, revocations and
independent authorization before enabling those production features. The current
local readiness probe is not an external end-to-end hosting health check. Run
the browser walkthrough on the actual cPanel host after installation.

If configuration or runtime state is missing/corrupt, the panel fails closed;
it does not silently replace it. Partial initialization may need operator
inspection. Retain the old code and private data while diagnosing; do not delete
the private directory to make an error disappear. Old releases are retained
without automatic pruning; monitor disk space. The standalone loader itself is
not automatically overwritten by downloaded application code. Review bootstrap
updates separately and preserve its authentication configuration.

## Developer validation

```sh
php bin/build-manifest.php           # after application source changes
php bin/build-manifest.php --check
php tests/loader.php
php tests/run.php
python3 -B -m unittest -v tests/http_smoke.py tests/standalone_smoke.py
```

The standalone HTTP test starts with one public PHP file, serves a verified
private release in a subfolder, checks isolated panel/installer sessions and
stylesheet routing, detects tampering, and recovers interrupted activation.
Its archive is synthetic and does not substitute for live GitHub download tests.
