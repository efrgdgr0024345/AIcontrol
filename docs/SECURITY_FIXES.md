# SSH setup and managed credentials — security fixes

This release addresses the two findings from the 22 November 2026 source review.

## Isolated setup interpreter

Both copyable enrollment commands invoke `/usr/bin/python3 -I -c`. Python ignores
the caller's working directory, user site packages and inherited PYTHON* settings
when resolving the bootstrap imports. Root/sudo privilege checks and existing
challenge, account, host-key and risk binding remain unchanged.

New readonly inspectors use `/usr/bin/python3 -I` in the forced SSH command and
their shebang. Command 2 removes prior entries for the same public key before
writing the single current restricted entry. Other public keys are retained.
This avoids accepting an older duplicate before the new restriction takes effect.

After updating, copy setup commands freshly from Systems. Commands copied before
the update retain their original text. Application installation does not remotely
rewrite accounts or previously installed inspectors. Where an existing bound
setup workflow remains available, running its freshly generated command 2
refreshes that target's inspector and key entry. Existing account identities and
private keys are not automatically rotated, deleted or re-enrolled.

## Owner-only Admin and credential mutation

The legacy public-testing profile is not owner authentication. It now receives
HTTP 403 for Admin pages, generic vault actions, and admin-prefixed actions on
every workspace route, including project JSON exports and data cleanup. Possessing
a legitimate demo-session CSRF token does not bypass this boundary. PIN-verified
requests continue to take precedence over the demo flag. Public demos should
continue to use separate disposable data rather than an owner workspace.

The vault reserves every name beginning `ssh_system_`. Generic save/delete actions
cannot alter these entries or convert them into AI-shared notes. Systems has
explicit internal key-create/delete methods for normal enrollment, failed-enrollment
cleanup and Disconnect. Admin lists managed keys with a Systems link instead of
a generic Delete form. Even a pre-existing malformed shared note in this namespace
is excluded from model context; its stored bytes are retained for recovery.

PIN/password/host-protected and loopback development owner controls retain ordinary
provider-key and private-note management. No new login flow, schema migration,
PIN reset, vault reset or key regeneration is introduced.

## Verification

- Generated wrappers execute a harmless payload successfully with malicious
  `base64.py`/`sitecustomize.py` fixtures in the current directory and PYTHONPATH,
  and an invalid PYTHONHOME. The old non-isolated invocation triggers the benign
  canary, demonstrating that the regression detects the original vulnerability.
- Bootstrap simulations for all three risk levels retain account/risk/host binding,
  idempotence and symlink refusal. Readonly inspection rejects unapproved commands
  while ignoring a malicious inherited module. An old forced-command entry and an
  unrestricted duplicate are replaced, with an independent key preserved.
- Disposable HTTP tests seed managed SSH, provider and note entries. Public-test
  visitors with valid CSRF tokens receive 403 for reads/exports/cleanup and all
  credential mutations; vault and encryption-key bytes remain unchanged.
- Owner HTTP tests retain ordinary note save/delete and Admin access while generic
  managed-key edits/deletion return 422. Systems tests cover key creation, no
  credential in model context, revocation and failed-enrollment cleanup.

These are local disposable integration and synthetic-provider tests. No production
server enrollment, installed host upgrade, or real-secret recovery is established
by them. Deploying this release fixes the application paths; it does not establish
that a previous key was never altered, or restore a credential already deleted.
