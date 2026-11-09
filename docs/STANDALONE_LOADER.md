# Testing installation — upload one file and open it

The current testing release has **no password, Directory Privacy or REMOTE_USER
requirement**. Security-device enrollment is deferred. Anyone who can reach this
URL can use the testing panel and installer; use test data.

1. Upload the latest `loader.php` into a dedicated website folder.
2. Open its HTTPS URL. If Directory Privacy was enabled earlier, disable it for
   this test folder to remove the hosting password prompt.
3. Enter a new empty private directory outside every web document root. Its
   parent must already exist and be writable. List any addon-domain public roots.
4. Click **Check GitHub**, review the exact commit, then **Install this checked
   commit** within ten minutes. The testing branch is
   `feature/control-panel-foundation`.
5. Open the panel, create a project, add a task and its acceptance criteria.

No password hash, copied signing key or extra application-file upload is needed.
PHP 8.2–8.5 with curl, zip, tokenizer, mbstring, PDO SQLite, sessions and sodium
is required. GitHub downloads need verified outbound HTTPS to `api.github.com`
and `codeload.github.com`. The required GitHub Actions check must pass. These
requirements still apply even though the access gate is removed.

The loader generates its own signing identity in private server storage before
checking GitHub. It signs requests, selections and update results, preserves
keys/data on updates, and recovers interrupted code activation. Public testing
signatures identify the server key; they do not identify an authenticated owner.
See [signing details](SIGNING.md). There is no raw private-key download endpoint.

## First AI task connection

The panel includes a real OpenAI Responses API adapter. It sends the project
brief, task and acceptance criteria from cPanel, retains the proposed answer,
usage and provider result ID, and marks the result **in review**. It never claims
that generated code ran, passed tests or was committed.

Configure `AICONTROL_OPENAI_API_KEY` directly in the server's PHP environment,
through the hosting provider's supported server configuration. Do not paste a
key into chat, GitHub, loader source or the browser. The optional
`AICONTROL_OPENAI_MODEL` selects the model; the default is `gpt-4.1-mini`.
The hosting environment must also allow HTTPS to `api.openai.com`.

Without this connection the panel explicitly says **Agent not connected yet**.
This chat subscription does not supply API authentication or API billing. No
provider call has been validated against a real account in the cloud tests.

Once configured, a task shows **Run AI task**. One request permits up to 1,000
output tokens, with one active run globally and at most three requests per hour
for this test installation. Costs are shown as unknown, not zero. Timeouts can
mean the provider did work; uncertain outcomes are retained without automatic
retry. Results are displayed as escaped text. Any visitor can trigger these
bounded calls in public testing mode.

Repository checkout, autonomous code execution, isolated tests, PR publishing
and production deployment are still unconnected. This is a first bounded task
response, not a completed autonomous coding-to-PR workflow.

## Later access protection

`AICONTROL_TESTING_MODE` near the top of the loader defaults to true in this test
release. Setting it false restores server-authenticated `REMOTE_USER` access
(or explicit loopback development). Changing the PHP constant requires a reviewed
loader upload. Android/phone approval and production authorization remain later
work, not prerequisites for this testing panel.

## Private storage

Only `loader.php` and its generated `.aicontrol-location.php` live publicly.
The location file contains a private path/root inventory and public signing pin.
Private storage contains releases, runtime configuration, SQLite records,
sessions, signing identity, receipts and activation/recovery state. Keep it
outside every public document root, with directories 0700 and sensitive files
0600. Updates do not overwrite private state or silently recreate a missing key.
A shared hosting Unix user is not filesystem isolation from other sites.

## Validation

```sh
php bin/build-manifest.php --check
php tests/run.php
php tests/loader.php
php tests/signing.php
php tests/agent.php
python3 -B -m unittest -v tests/http_smoke.py tests/standalone_smoke.py tests/passwordless_smoke.py
```

Tests include the public installer without REMOTE_USER, retained host/legacy
compatibility, CSRF, signed selections, key loss, agent state/rate limits and
private-release recovery. Agent lifecycle tests make no paid provider requests.
