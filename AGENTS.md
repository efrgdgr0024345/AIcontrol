# AIcontrol implementation rules

- Read SECURITY.md and docs/ARCHITECTURE.md before changing trust boundaries.
- PHP 8.2+; no Composer or shell execution is required by the web application.
- Edit src/ modules, then run `python3 scripts/build.py`. Commit the generated loader.php too.
- Preserve the one-file upload and the owner's Check GitHub / Install checked commit workflow.
- Never commit credentials, generated keys, tokens, runtime data, or authentic customer logs.
- Never add a raw-secret retrieval endpoint, caller-controlled credential destination, arbitrary PHP execution, or browser storage of server secrets.
- Keep API keys out of agent prompts and generated-code execution environments.
- New installs must not be claimable by their first unauthenticated visitor.
- All sensitive owner actions require server-side authorization, CSRF and fresh reauthentication.
- Agent approval binds the identity, exact operation and input; expiry and use count are enforced server-side.
- Runtime history and credentials must survive code updates and rollbacks.
- Required checks: package-contract, security-tests (8.2), security-tests (8.5).
- Preserve tests for archive attacks, vault tampering, bootstrap replay, token scope, audit integrity and update recovery.
- Passing tests, a reviewed change, a merge and a live cPanel installation are separate states. Report them accurately.
