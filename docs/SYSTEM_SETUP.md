# Two-command Linux enrollment and SSH command console

**23 November 2026:** [SYSTEM_ACTIONS.md](SYSTEM_ACTIONS.md) adds a separate
Systems password, protected key custody, notes and explicitly approved per-action
workers. Its protection and guided-job rules supersede the original console-only
scope below. Existing enrollment risk limits remain enforced.

2026-11-15 AWST. The primary Systems setup now uses two single-line commands and
two formatted replies. Legacy manual enrollment and its existing keys still work.
No previous PIN, provider key, signing identity or project is reset.

## Flow

1. Choose a risk level. Prepare first command (JavaScript also prepares a fresh
   enrollment when the level changes). Copy and run it in the server's existing
   shell. It requires Python 3 and root or sudo. The readable Python source is
   available under Review what this command runs; no executable is downloaded.
2. Command 1 creates a unique dedicated account, sets the chosen permissions,
   and prints a JSON discovery report between BEGIN/END markers. Paste the whole
   block into First reply. Address/port/hostname, UID, home, machine-specific tag,
   ED25519 host key/fingerprint and risk/challenge are checked together.
3. Copy/run command 2. It verifies the original account, locked password, machine
   tag and host key, installs only the generated public key, and prints a receipt.
   Paste that block into Second reply. The app binds it to the exact public key,
   enrollment, risk, UID and discovery before accepting it.
4. Connect and open command line performs actual pinned-host SSH authentication
   and inspection. A verified result opens the website command console. Routing
   address/port can be corrected for NAT; no pasted claim alone establishes a
   successful connection.

Commands are single shell lines containing an embedded base64 Python program,
not a curl-to-shell installer. Neither command includes an application API key,
PIN, SSH private key or browser session. The formatted reports contain public
connection/enrollment metadata.
Unused first commands expire after 24 hours. Once AIcontrol has accepted the
first reply, open the saved server to resume with its existing command and key.
**Resume a setup from the first reply** finds the matching enrollment regardless
of the risk picker. It still validates the original challenge, account, risk
level and server identity. Both scripts are idempotent for the same enrollment.
Changing risk creates a separate enrollment and account.

The Systems page shows whether discovery, the SSH receipt or verified access is
the next step. Failed setup and verification actions show a reference you can
find in **Admin → Detailed error and research log**. The log distinguishes
receipt rejection, hosting limitations, host identity checks, key rejection and
inspection failure. It omits discovery and receipt bodies, SSH private keys,
and raw SSH output from these failure events.

The first command collects local addresses and the active SSH session port (or
sshd's effective listening port). It optionally queries https://api.ipify.org
with a three-second limit to discover the public address. Failure falls back to
local detected addresses. NAT's external forwarded SSH port cannot be inferred;
the address/port override remains available before connecting.

## Risk levels and enforcement

| Level | Account created by command 1 | Permissions |
|---|---|---|
| Low — Read-only diagnostics | Unique aicontrol_ro_* | Locked password, no sudo, root-owned home; the key installed by command 2 has a root-owned forced-command diagnostic allowlist. |
| Medium — Account maintenance | Unique aicontrol_ops_* | Locked password, writable private home, shell within normal Linux permissions, no sudo. |
| High — Administrator | Unique aicontrol_adm_* | Locked password plus a dedicated full NOPASSWD sudo policy, checked with visudo and an actual noninteractive sudo identity check. |

All accounts have a separate primary group. Existing users are not repurposed.
Private setup markers, bootstrap locks, inspector code and the administrator
policy are root-owned. Symlinks, unsafe owners/modes and unexpected hard links
are rejected where keys/markers are read. Writes use private temporary files,
fsync, ownership/mode setting and atomic replacement. Existing authorized-key
lines are retained. Read-only transport only requests named diagnostics; its
server-side wrapper rejects a raw arbitrary SSH command even if the client is
modified. Key options also disable forwarding and PTY allocation.

Administrator deliberately grants full server administration. Disconnect removes
AIcontrol's local credential and disables further dispatch, while account/sudo
permissions created on the server remain until the server operator removes the
account and its /etc/sudoers.d/aicontrol-ENROLLMENT policy. Removing an app record
is not remote account deprovisioning. Low/Medium do not acquire sudo privileges.

## Command console

The console requires the existing authenticated workspace and CSRF checks. Each
console ID is tied to its creating sign-in session, selected system and 30-minute
expiry. IDs alone do not grant command access. Commands use the existing strict
host-key SSH transport and encrypted per-system key; no provider call is made.

The SSH transcript opens with the server's advertised SSH banner when present,
readable `/etc/motd` for maintenance accounts, and observed server details.
Authentication uses the saved key. Manual, guided and agent command records use
the same transcript, with color and text labels for their source. The console
supports escaped stdout/stderr, exit status, output truncation,
Up/Down history, clear, and last-confirmed working-directory persistence. Each
command runs in a fresh noninteractive shell. Full-screen editors, password
prompts, persistent environment variables and a PTY session are not provided by
this PHP command console. Use noninteractive sudo -n where Administrator applies.
The prompt shows the current directory. On an account with shell access,
plain `ls` lists visible names in that directory. A successful empty listing
offers `ls -la` to include names beginning with a dot.

Read-only console input is mapped to the named diagnostic allowlist. Account and
Administrator allow normal shell commands within the chosen Linux permissions.
The existing single SSH execution lock excludes overlapping commands and agent
steps; stop active agent tasks before using manual commands. A 60-command/hour
per-system console limit and the existing 15-second remote / 20-second local
SSH limits apply, with up to 16 KB per output stream. Unknown/timed-out results
close the console and are never automatically retried. Disconnect interrupts a
running local SSH client; remote effects may already have occurred.

Console commands are saved in system_runs/system_commands and appear in the
copy/download execution report. Additive system_enrollments and system_terminals
tables retain the enrollment bindings and console session records. Admin inventories
them. Disconnect/purge handles their references; project resets keep independent
systems. No-JS forms retain prepare, paste, connect, command and disconnect paths.

## Verification

- PHP setup tests cover all three roles, malformed/cross-risk reports, mismatched
  receipts, public-key identity, idempotent paste, console session isolation,
  read-only command rejection, directory confirmation and record cleanup.
- Generated single-line commands pass shell syntax checks. Python tests execute
  both generated scripts for all levels using real temporary files and simulated
  root/account/sudo APIs. They check permissions, group/role policy, idempotence,
  marker binding, symlink refusal and actual inspector allow/deny execution.
  These tests never modify real Linux accounts or sudoers on the cloud host.
- Native OpenSSH/keyscan against the local SSH protocol fixture verifies actual
  authentication, Unicode output, directory persistence, disconnect, forced
  read-only inspection, raw-command bypass rejection, Stop and timeouts.
- Chromium exercises risk changes, single-command copying, two pasted reports,
  mismatch rejection, console output escaping/history/directory display,
  disconnect, no-JS preparation and desktop/mobile axe. Its SSH results are
  explicitly synthetic; native transport is covered separately.

Run php tests/system_setup.php, python3 -B -m unittest -v
tests/system_bootstrap.py, the existing systems_ssh.py integration with its test
requirements installed, and npm run test:ui. Existing application/installer and
research/map regressions remain required, followed by exact-SHA planning-panel CI.
This does not establish installation on the owner's host, creation of an account
on their server, or a billed model-directed administration task.

Release validation: the local checks above passed on PHP 8.4.24, alongside all
twenty PHP suites, eight HTTP suites and the complete Chromium regression suite.
The required planning-panel check must pass on the exact published commit before
installation readiness is reported.

The Systems page always shows a DigitalOcean VPS connection entry. While locked
it points to separate-password setup/unlock without exposing generated commands.
After unlock, the full two-command form is visible even with an existing server
selected. Open the existing Droplet console or SSH terminal to run the commands;
this flow does not provision a Droplet through the DigitalOcean API.
