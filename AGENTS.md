# AIcontrol session entry point

Read `docs/PROJECT_STATE.md`, `docs/DEPLOYMENT_DEFAULTS.md` and
`docs/STANDALONE_LOADER.md` before changing installation behavior.
`docs/TEAM_HANDOVER.md` is the wider production specification; PROJECT_STATE
records the owner's subsequent testing-stage decisions.

The implementation and release branch is `main`. Use the existing checkout,
preserve user changes and local commits, and do not create a worktree unless
requested. `feature/control-panel-foundation` is retained only as a compatibility
pointer for older installed loaders. Keep that pointer fast-forwarded to tested
main releases while those loaders still depend on it; do not force-push it.

Current intended flow: upload one loader, set PIN on first visit, sign in on the
next visit, click Check GitHub, review and install a checked commit. No hosting
login/REMOTE_USER, manual hash, filesystem-path or release-ref fields are required.
Private storage is selected automatically and recorded server-side. Never reset
an existing PIN/key/database merely to make setup pass.

Keep private credentials, PIN verifiers, signing identities, sessions and runtime
records out of GitHub and public document roots. Do not ask for secret values in
chat. Server-generated keys sign update evidence; they do not replace provider
API authentication or independent owner authorization.

Use PHP 8.2–8.5. In the prepared cloud snapshot PHP may be available at
`/workspace/toolchains/php/php`; otherwise inspect the current environment rather
than assuming this path exists. Run the checks in PROJECT_STATE after changes,
run `php bin/build-ui.php` before the release manifest when UI sources change,
regenerate the release manifest after application-source changes, and confirm
the required GitHub Actions check on the exact release commit.

Update the state/defaults documents when behavior or scope changes. Record exact
validation scope. Do not label API fixtures as live AI operation, model output
as executed code, or cloud validation as a completed cPanel deployment.

The owner has authorized publishing tested application updates to the existing
implementation/release branch without asking for deployment confirmation. Finish
the release and verify the required `planning-panel` check on the exact SHA;
do not stop at local changes. This does not authorize changing cloud environment
publication settings. Distinguish a published checked release from activation on
the live host, and never claim host activation without authenticated evidence.
