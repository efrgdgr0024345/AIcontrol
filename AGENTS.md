# Start here for AIcontrol

Read `docs/PROJECT_STATE.md` and `docs/DEPLOYMENT_DEFAULTS.md`. They record the
owner's current decisions and setup defaults for continuing across chat sessions.

The working implementation is on `feature/control-panel-foundation`, not this
documentation-only main branch. Before application work, inspect the working
tree and fetch that branch. Use the existing checkout and switch to it when
clean, preserving user changes/local commits. Do not reset work or create a
worktree unless requested. Then read that branch's AGENTS.md and current guides.

Current installation: one loader file; first visit sets a PIN; next visit signs
in and offers Check GitHub. Storage and release settings are automatic. Do not
ask the owner for filesystem paths, REMOTE_USER, Directory Privacy or copied keys.
Private PIN/key/session/runtime state stays on the server, not in GitHub/chat.

Keep the continuity documents updated as decisions change. Do not call model
fixtures a live provider connection or cloud tests a completed cPanel deployment.
