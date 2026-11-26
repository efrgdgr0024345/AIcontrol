# Guided VPS actions, protected notes and separate Systems unlock

23 November 2026. These additions do not run a change merely by installing the
website update. The owner first sets a separate Systems passphrase, then unlocks,
selects a verified server, prepares an action and reviews it. VPS changes require
typing APPLY plus the server ID and confirming provider recovery-console access.

## Protection and readable notes

The website PIN alone cannot reset an established Systems password or dispatch
SSH commands. All Systems POST routes, console/agent steps, setup, secret reveal
and guided jobs enforce the second factor. The first password must be established
while the owner trusts their website sign-in; setup is not an independent proof
of ownership if the PIN was already compromised. There is no default password.

A unique passphrase of at least 12 characters including letters is hashed with
Argon2id. A separate salted Argon2id derivation wraps a random 256-bit Systems
master key. Only a short-lived private server-side session holds an unwrapped
master key. Grants expire after 15 minutes; explicit Lock increments a global
revocation generation. Five unsuccessful/pending unlock attempts per ten minutes
are admitted across sessions. The website PIN provides no password-reset route.
Changing the Systems password requires the current password and rewraps the same master key, preserving SSH identities and notes while revoking older grants.
Loss of the Systems passphrase requires operator recovery through independent
VPS/hosting access, rather than a PIN-only reset that would expose credentials.

On initial password setup, existing SSH private keys are copied, encrypted under
the master key and committed to system_guard_keys before their legacy copies are
atomically removed from the ordinary vault. SSH identities/public keys do not
change. Transfer is hash-bound and retryable on unlock; a failed cleanup does not
silently create a new key or claim the transfer finished. Orphaned legacy key IDs
are retained encrypted for operator inspection. New keys are stored directly in
this protected custody. Revoke deletes both the protected and legacy local key.

Protected notebook values, full observations and playbook data use authenticated,
purpose-separated encryption and server-ID binding. Readable notes, safe saved
inventory, labels with ******** values and timestamped event summaries are cached
separately. Locked responses do not contain protected plaintext in hidden HTML.
Explicit reveal requires unlock and a CSRF-protected POST; browser reveal clears
on Hide, after one minute or when the tab becomes hidden. Values are not placed
in browser storage. Known values and common credential formats are masked in
notes; users must put secrets in the protected fields and review public text.
Free-form prose cannot be perfectly classified automatically.

Connection/setup information and raw legacy task/console reports are hidden
while Systems is locked. Existing normal project/research access is unchanged.
The checked installer refuses activation of a release that removes SystemGuard
once Systems protection is configured. This also rejects an older signed checked
selection still within its normal ten-minute lifetime. PIN/signing identities and
research data are not reset. Trusted hosting/root access and the approved source
repository remain part of the trust boundary; this is not hardware isolation.

## Actions and actual scope

| Action | Behavior |
| --- | --- |
| Check system setup | Saves Linux inventory and checks the managed WireGuard interface/configuration, existing firewall rules, the selected account’s Codex path and the AIcontrol automatic-update policy and timers. Restricted keys use their existing read-only diagnostic allowlist. |
| Lock down system | Fresh Debian/Ubuntu firewall only; refuses active/custom/container rules. Reviews listening service ports, preserves the actual SSH_CONNECTION local port, enables IPv4/IPv6 UFW incoming deny/outgoing allow, and arms rollback before activation. |
| Update system | Signed distribution apt metadata and ordinary upgrade with --no-remove, preserved local configuration and no automatic reboot/full-upgrade/autoremove. Records package-audit/reboot-required results. |
| Set up VPN | Debian/Ubuntu with systemd: WireGuard private-access subnet and a supplied client public key. Private server key stays on the VPS. Optional reviewed UDP allow rule in active UFW; no replacement of other rules or default-route/NAT changes. Cloud rules and client handshake require separate verification. |
| Install Codex | Account-local official OpenAI Linux musl binary 0.171.0 for x86_64/aarch64, pinned archive SHA-256, exact regular-file extraction and version check. Existing binary is not overwritten. No root Codex execution or automatic API/login credential installation. Authenticate from an interactive SSH session as the dedicated account. |
| Automatic security updates | Debian/Ubuntu with systemd: installs unattended-upgrades, adds a separate daily policy, disables automatic reboot, enables and checks the apt daily timers. Existing AIcontrol policy is not silently overwritten. |
| Health check | Read-only available resource and failed-service observations; collecting data is not a claim that the VPS is healthy. |
| Backup readiness | Read-only backup-tool/local-metadata inspection with account-level SSH. File presence does not prove recoverability or an off-server snapshot. |

Mutation recipes require the existing Administrator enrollment; Codex can use
Account maintenance. Permissions are never raised to make a recipe work. Low-risk
keys retain their server-enforced diagnostic restrictions. Unsupported platforms
receive an explanation rather than speculative root shell commands.

Firewall activation is provisional for two minutes. The existing host-pinned SSH
transport must establish a fresh connection before commit; otherwise a private
watchdog disables the provisional firewall and restores the prior inactive UFW
configuration. Provider recovery access remains necessary: a watchdog reduces
lockout risk but is not a replacement for independent access or cloud firewall
review. Stop never commits a firewall change that is awaiting rollback.

## Long jobs and evidence

The short SSH console remains bounded. Longer installations use an explicitly
approved per-operation detached Python worker with private state/log files and a
30-minute overall bound. This is a per-action process, not a persistent agent.
The selected, verified server opens with the SSH console first and its action
buttons immediately below. An approved guided action uses the selected server's
pinned SSH connection and shows its command records in the console. The
console polls the same operation for bounded stdout, stderr and exit status.
It shows a command while its output is pending. Reopening the server resumes
the transcript of its active operation. Manual input is disabled during an
active or uncertain action and returns after reconciliation releases the lock.
The completed transcript stays available through the selected operation.
The action buttons appear below the console. A command that returns neither
stdout nor stderr shows “No output returned” along with its exit status. The
setup check shows its observation time and disables installation or setup where
it detects existing software or configuration. Missing observations keep the
associated actions available for review. A VPN client handshake still needs a
separate test. Repeatable actions such as package updates stay available.

To choose an action using the saved OpenAI key, enter a goal in **What should
AI set up?** and select **Ask AI to prepare**. Review the AI's choice and reason
in the console. Changes still require **Apply this reviewed action** and any
required setup details. During the run, the same SSH console shows the current
command and the output returned by the VPS. Use the reference on a failed
request to find the matching event in Admin diagnostics.

For a multi-step task, enter a direction below the SSH prompt and select
**Send to agent**. The agent uses each confirmed result to choose its next
step. **Agent updates** show the plan, observations and next checks. Its
commands and VPS results appear in the same scrollback as manual and guided
commands, and its reply appears in **Agent conversation**. Use **Stop system
task** to end the run. The console limits automatic work to eight decisions
and pauses it when the page is hidden.

Each **Learn more** section explains the planned steps. After a recorded run,
it also lists available command lines, exit statuses and short explanations.
The console shows bounded, redacted output excerpts. Learn more lists commands,
exit codes and explanations without duplicating the output.
The worker and rollback watchdog inherit a remote execution lock; status checks
only inspect the same token. Local blocking records exclude console/model steps
and other guided jobs while a mutation is running or uncertain. Stop pauses
before the next safe change, allowing a package command already running to finish.
Timeouts and partial changes are not labelled success or automatically repeated.

A lost start reply is reconciled against the same operation; its launch is never
reposted automatically. A lost firewall commit can also be reconciled. Unknown
worker state retains uncertainty. An explicit INSPECTED confirmation can release
an uncertain local lock only after independent operator inspection; it preserves
the uncertain result. Locking Systems prevents new dispatch, while an already
approved remote job may finish. Unlock is needed to reconcile its saved result.

Private operation/step records retain commands, exit codes, bounded output,
truncation and status. Notebook observations retain the latest 100 summaries;
full operation records remain separately saved. No model/provider call is needed
for these deterministic recipes. Console commands, SSH checks and agent steps also
append observations. Agent context uses the selected server’s readable notes and
masked history, excluding protected values and SSH private keys. Admin inventories the additive system_guard,
system_guard_keys, system_guard_attempts, system_notebooks and system_playbooks
tables in systems.sqlite. Project reset preserves the independent guard and keys;
explicit disconnected-server purge removes its notebook and action records.

The Codex source was checked through the official openai/codex GitHub release API
on 23 November 2026. Archive digests are pinned in SystemPlaybooks.php; updates
require a reviewed code release. Only verified HTTPS downloads are used.

## Validation boundaries

PHP tests cover factor setup/throttle/revocation, existing-key preservation and
legacy removal, encrypted/masked notes, replay/scope checks, permissions/platform
admission, review confirmation and unknown-start reconciliation. HTTP tests check
that PIN-only sessions cannot use SSH/agent/reveal routes or reset the factor.
Python tests execute actual recipe logic on temporary files with simulated Linux
tools: firewall ordering/watchdog, changed-rule refusal, Stop, update limits,
WireGuard key non-disclosure, VPN overlap and Codex checksum/archive safety.
Chromium checks forms, explicit reveal, locked DOM, copy/export, desktop/mobile
and accessibility. Native SSH fixtures cover the actual transport separately.
These fixtures are not claims that a live owner's VPS was modified successfully.
