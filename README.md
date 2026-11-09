# AIcontrol

A cPanel project control panel, starting with a public testing release.

Available now: project briefs, task proposals with acceptance criteria,
system availability, and durable activity history. A bounded OpenAI task-response adapter is available once its API connection is
configured directly on the server. Autonomous code execution, publishing and
production deployment remain unconnected.

Trusted-device approval and access protection are deferred at the owner's
request. Public testing permits proposals, checked AIcontrol installation and
bounded AI responses when configured. Use test data.

- [Specification](docs/TEAM_HANDOVER.md)
- [Baseline review and architecture](docs/BASELINE_REVIEW.md)
- [Implementation tickets](docs/IMPLEMENTATION_TICKETS.md)
- [Run locally and install on cPanel](docs/RUNNING.md)
- [Upload only loader.php: standalone installation](docs/STANDALONE_LOADER.md)
- [Server-generated signing keys and update receipts](docs/SIGNING.md)

`loader.php` opens directly in public testing mode. Upload that one file, then
check and install an exact GitHub commit. No app password, Directory Privacy or
hash configuration is needed. The application and data are downloaded/created in private server
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
