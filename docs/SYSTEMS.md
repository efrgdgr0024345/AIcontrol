# Linux systems and bounded SSH agents

Systems now registers Linux servers and performs actual remote commands, separate
from the older tool-free task response adapter. Existing research behavior and
SSH/PIN/signing identities are retained. No server is enrolled automatically.

## Setup

1. Open Systems → Add a Linux server. Enter a name, IP/hostname, port and account.
   Inspection is the default; Maintenance allows shell commands within that
   Linux account's existing permissions. Optionally associate a task with an
   existing task project for history (this does not apply a research spend cap).
2. Copy the public-key setup script and run it on the selected server as root or
   through sudo. It creates a dedicated account if missing, adds one restricted
   authorized-key line, retains existing keys, and grants no sudo privileges.
   Password login is disabled for a newly created account; existing account
   authentication is retained. Check the script before running it.
3. Paste the SHA256 ED25519 host fingerprint printed by the script. Verify identity
   and test SSH checks that exact identity before authenticating, then stores
   actual Linux/account/resource output. An existing verified identity cannot be
   silently replaced; disconnect and enroll again after investigating a change.
4. Describe a task and start it. Advance one step manually or explicitly choose
   automatic steps. Inspect the command report and result. Manual/no-JS forms work.

The PHP host needs enabled `proc_open`, `/usr/bin/ssh` and `/usr/bin/ssh-keyscan`,
plus network access to the selected server's SSH port. The target needs a normal
Linux shell, GNU timeout and an ED25519 SSH host key (standard Ubuntu/Debian
installations). PHP does not need Composer, an SSH extension or a new daemon.
The page detects a missing local SSH runner and explains it before connection
setup. If cPanel disables these capabilities, the hosting administrator must
allow them, or the workspace must run on a Linux host with them. An external
worker/relay for restricted PHP hosting is not implemented in this release.

## Execution contract

Each step persists its working state before making one provider decision and at
most one SSH command. The OpenAI Responses API uses the existing server/vault
connection, default model GPT-4.1 mini, up to 1,000 output tokens, a 30-second API
limit, eight decisions/task and ten system tasks/hour. This is a separate bounded
allowance from research/chat. Provider metadata retains returned usage; this
release does not implement a Systems dollar cap or invoice accounting.

Inspection maps model-selected tool names to fixed commands for system info,
resources, services, web-service diagnostics, listening ports and processes.
Free-form shell requests are rejected in Inspection. Maintenance deliberately
permits shell commands; Linux ownership and permissions still apply. The setup
script does not grant root or unrestricted passwordless sudo. Missing privileges
and nonzero exits appear as actual command results rather than fabricated success.

One global system execution lock serializes steps; one active system task is
allowed. Provider dispatch shares the existing provider admission gate. Session
locks are released before network I/O so authenticated Stop remains available.
Stop/disconnect prevents the next dispatch and interrupts local SSH. A command
already dispatched may have changed the target; interrupted connections, timeouts
and transport failures retain uncertain status and are never automatically retried.
A lost PHP worker's working state also cannot silently resume a command.

Remote commands have a 15-second timeout plus two-second kill grace, and local SSH
has a 20-second bound. Each stdout/stderr stream retains up to 16 KB with an explicit
truncation flag. No interactive terminal, persistent background agent, forwarding
or unbounded package-install session is added. Long operations may time out and
need a separately designed task rather than an invented success result.

Automatic steps are opt-in, sequential and stop on failure/uncertainty/completion.
Closing/navigating away or hiding the page disables further automatic dispatch;
an already dispatched bounded step can finish. No-JS uses manual Next/Stop forms.

## Credentials and records

Every server has a separate ED25519 key pair. Only the public key is rendered.
The private key is encrypted in the existing vault under `ssh_system_ID`, is
never supplied to the model/browser, and is materialized only in a temporary
0600 file during SSH. Temporary key and known-host files are removed afterward.
OpenSSH ignores user/global configuration and enforces the pinned host key,
noninteractive key authentication, no agent forwarding and no port forwarding.

`systems.sqlite` contains `systems`, `system_runs`, `system_commands` and
`system_messages`. It is private, additive and independent of existing project
schemas. Admin's technical inventory includes it when present. Reports include
commands, outputs, exit codes, duration, truncation, uncertain states, agent
choices and bounded provider-return metadata. Known vault/provider values are
redacted; arbitrary remote logs can contain other private content, so review a
report before sharing. Copy and JSON download are available on each task page.

Disconnect disables dispatch, stops active tasks and deletes the local private
key while retaining history. Remove its labelled authorized_keys line on the
server to revoke remote authorization as well. A separate typed Delete action
removes the disconnected server and its task/command/decision records, excluding
active workers. The separate global diagnostic journal is managed in Admin. Project reset does not implicitly remove independent systems.

Systems actions require the authenticated owner workspace plus CSRF. Public
`testing` access cannot read/control Systems. The existing PIN is a testing-stage
workspace boundary; this release does not add independent multi-user grants.

## Validation

- PHP unit fixtures cover input/shell-option rejection, encrypted key storage,
  script contents, pinning, inspection scope, command records, Stop before/after
  dispatch, uncertain worker recovery, bounds, disconnect and typed deletion.
- The real `/usr/bin/ssh` and `ssh-keyscan` integration connects to a local Python
  SSH protocol fixture using real generated keys. It rejects the wrong host
  fingerprint, authenticates, executes real Linux commands in a disposable
  directory, captures stdout/stderr and a nonzero exit, truncates large output,
  completes a synthetic model loop, checks actual remote timeout/interruption and
  missing-host-runner refusal, and cleans up credentials. Paramiko is a
  test-only dependency; it is not shipped or needed on the PHP host.
- Chromium uses real PHP forms and explicit synthetic saved task/provider data:
  script generation/copy, CSRF rejection, sequential automatic steps and Stop
  between steps, report
  copy/download, Admin inventory, mobile/desktop axe and no-JS disconnect/delete.
- Existing PHP, HTTP, installation, research, map, workspace and browser regressions
  remain required. Run `php tests/systems.php`, the SSH Python test with
  `tests/ssh-requirements.txt` installed, and `npm run test:ui`.

These checks do not establish a connection to the owner's real server, a billed
model-directed SSH task, or installation/activation on the live host. Publish and
verify the required exact-SHA planning-panel check before calling a release ready.

Local release verification passed on PHP 8.4.24: nineteen PHP suites, eight HTTP
suites, the real SSH integration above, generated UI/manifest checks, PHP syntax
and the complete Chromium regression suite. Final automatic-step changes were
rechecked with the Systems browser suite. The workspace axe report has 34 views
and zero violations; Systems has additional desktop/mobile audits. Synthetic
provider replies and saved UI fixtures are explicitly separate from real SSH
command execution. Required release CI must still pass on the published SHA.
