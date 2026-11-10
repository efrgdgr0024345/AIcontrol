# AIcontrol

A PIN-protected cPanel workspace for coding projects and bounded research investigations.

The full implementation and release channel are on `main`.

Available now: project briefs, task proposals with acceptance criteria,
system availability, and durable activity history. A bounded OpenAI task-response adapter is available once its API connection is
configured directly on the server. Autonomous code execution, publishing and
production deployment remain unconnected.

Trusted-device approval is deferred. The loader uses a PIN set on its first
visit to protect the panel and updater. Use test data.

- [Current state for the next chat](docs/PROJECT_STATE.md)
- [Defined installation defaults](docs/DEPLOYMENT_DEFAULTS.md)
- [Specification](docs/TEAM_HANDOVER.md)
- [Baseline review and architecture](docs/BASELINE_REVIEW.md)
- [Implementation tickets](docs/IMPLEMENTATION_TICKETS.md)
- [Run locally and install on cPanel](docs/RUNNING.md)
- [Upload only loader.php: standalone installation](docs/STANDALONE_LOADER.md)
- [Server-generated signing keys and update receipts](docs/SIGNING.md)
- [Tabler interface and UI build](docs/UI.md)
- [Automatic updates, hash verification and progress](docs/UPDATES.md)

`loader.php` asks you to set and confirm a 6–12 digit PIN on the first visit.
On the next visit, sign in with that PIN and install a checked GitHub commit.
There is no Directory Privacy, REMOTE_USER or manual hash setup. The application and data are downloaded/created in private server
storage. See the standalone guide for prerequisites and the testing channel.

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
