# Single-file installation — PIN setup on first visit

1. Upload the latest `loader.php` into a dedicated website folder.
2. Open its HTTPS URL. The **first visit** shows **Set your PIN**. Enter and
   confirm 6–12 digits, then click **Set PIN**. The server stores only a salted
   password hash. No GitHub download or application setup happens at this stage.
3. Follow **Continue to sign in**, or open the loader again. This **next visit**
   asks for the same PIN, then opens application setup.
4. Choose a new empty private directory outside every web document root; its
   parent must exist. List any additional addon-domain document roots.
5. Click **Check GitHub**, review the exact commit and **Install this checked
   commit** within ten minutes. The testing branch is
   `feature/control-panel-foundation`.
6. Open the panel. Later visits use the same PIN for both panel and updater.
   A PIN session lasts thirty minutes. Use **PIN session / Lock panel** to lock it.

No Directory Privacy, hosting login, REMOTE_USER, manually generated hash or key
copy is needed. If you previously enabled Directory Privacy for this test folder,
disable it to remove the separate browser hosting-login prompt. Existing private
keys and project data are preserved when replacing the loader file.

PHP 8.2–8.5 with curl, zip, tokenizer, mbstring, PDO SQLite, sessions and sodium
is required. Normal installation uses verified outbound HTTPS, not shell commands
or Composer. The selected commit must have a passing required GitHub Actions check.

## PIN storage and recovery

Before application storage is selected, the hash is in a private 0700 bootstrap
directory outside the website, with its file mode 0600. A small generated
`.aicontrol-pin.php` file contains only its location and exits without displaying
it. Once private application storage is selected, the PIN state moves into
`private-home/pin/auth.json`. The marker remains; do not delete it during updates.

Only the first successful setup can enroll a PIN. Setup does not sign the user
in automatically: the next visit performs login. The login rotates its session,
uses CSRF protection and blocks further attempts for up to ten minutes after five
incorrect PINs. Throttling is stored on the server and survives new browser cookies.

A missing, damaged or unsafe PIN record is a recovery error, not permission to
set another PIN. There is no public PIN-reset endpoint. Preserve the private
state and recover through the server operator. A PIN is a testing-stage login;
it does not implement independent trusted-device approval.

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

## Private storage

Only `loader.php` and its generated `.aicontrol-location.php` live publicly.
The location file contains a private path/root inventory and public signing pin.
The separate PIN marker stores a private PIN-directory pointer, never the PIN.
Private storage contains releases, runtime configuration, SQLite records,
sessions, PIN state, signing identity, receipts and activation/recovery state. Keep it
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
python3 -B -m unittest -v tests/http_smoke.py tests/standalone_smoke.py tests/passwordless_smoke.py tests/pin_smoke.py
```

Tests include the public installer without REMOTE_USER, retained host/legacy
compatibility, CSRF, signed selections, key loss, agent state/rate limits and
private-release recovery. Agent lifecycle tests make no paid provider requests.
