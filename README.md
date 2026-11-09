# AIcontrol

A cPanel project control panel, starting with a password-protected planning release.

Available now: sign-in, project briefs, task proposals with acceptance criteria,
system availability, and durable activity history. No agent runs, provider calls,
credential storage, approvals, publishing or deployments are enabled.

Trusted-device approval is deferred at the owner's request. The current session
can propose work only; it cannot authorize consequential operations.

- [Specification](docs/TEAM_HANDOVER.md)
- [Baseline review and architecture](docs/BASELINE_REVIEW.md)
- [Implementation tickets](docs/IMPLEMENTATION_TICKETS.md)
- [Run locally and install on cPanel](docs/RUNNING.md)

## Development

PHP 8.2–8.5 with PDO SQLite, mbstring and sessions is required for the planning
panel. The host check also checks the planned broker/release prerequisites.
Python 3 is needed only for the HTTP integration test.

```sh
php bin/check-host.php
php tests/run.php
python3 -B -m unittest -v tests/http_smoke.py
```

See the running guide for private-storage initialization and server startup.
Tests use disposable metadata and login credentials, never real service keys.
