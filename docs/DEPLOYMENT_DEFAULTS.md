# Defined deployment defaults

These are the implemented setup rules, not values the owner needs to type. The
code and this document belong in GitHub; host-specific runtime configuration and
private records persist on the hosting server.

| Setting | Current definition |
|---|---|
| Repository | `efrgdgr0024345/AIcontrol` |
| Release channel | `feature/control-panel-foundation` |
| Required exact-SHA check | `planning-panel` / GitHub Actions |
| Entry point | One uploaded `loader.php`, including subfolder support |
| Interface | Tabler 1.7.4 (MIT), bundled locally; no CDN or browser JS required |
| UI build | `php bin/build-ui.php`, then `php bin/build-manifest.php` |
| Display timezone | Australia/Perth (AWST); stored timestamps stay UTC |
| PHP | 8.2–8.5; curl, zip, tokenizer, mbstring, PDO SQLite, sessions, sodium |
| Login | First visit sets 6–12 digit PIN; subsequent visits sign in |
| PIN session | Up to 30 minutes; five incorrect attempts block for up to ten minutes |
| Checked selection | Exact full SHA, expires after ten minutes, one attempt |
| Signing | Server-generated Ed25519; domain `AIcontrol/receipt/v1` plus zero byte |
| Model | `gpt-4.1-mini`, overridden only by server `AICONTROL_OPENAI_MODEL` |
| AI key | Server `AICONTROL_OPENAI_API_KEY`; never browser/source/chat |
| AI testing bounds | One active run, three requests/hour, 1,000 output tokens/call |

## Automatic storage policy v1

`AIcontrolInstaller::autoStorage` derives candidates using PHP's actual Unix
account home (when available), standard hosting-home ancestors such as
`public_html`, and the parent of the actual document root as a generic fallback.
It detects current/context document roots and standard public directories under
the account home. Canonical paths and permissions are checked.

The selected directory is `.aicontrol-<16 hex characters>` under the chosen
private base. The suffix is derived from the absolute loader path, giving each
installation a stable separate location. The loader creates it with mode 0700.
It does not use temporary storage for the permanent application database/keys.
The initial PIN verifier uses private temporary storage only until it can move
into the permanent private home.

Candidates inside detected public roots are rejected. Failed writable-location
candidates are skipped; an existing automatic storage directory without its
installation record is preserved and requires recovery, not silently replaced.
Existing `.aicontrol-location.php` state takes precedence over all new detection.
If no suitable location exists, the loader reports a concrete hosting prerequisite.

This detects roots available to PHP and standard hosting layouts. It cannot
claim a complete inventory of unrelated addon domains or custom web-server aliases
without host metadata. Shared-account testing is the current scope; full account
isolation/root inventory remains a production hosting requirement.

## What persists across updates and chats

- GitHub: source, release manifest, these defaults, PROJECT_STATE and tests.
- Public hosting folder: loader, `.aicontrol-location.php`, `.aicontrol-pin.php`.
- Private home: `pin/`, `signing/`, `receipts/`, `runtime/`, retained `releases/`,
  `current.json`, `journal.json`, deployment lock and temporary bootstrap marker.
- The location marker saves the actual private path, detected roots, storage
  policy version/rule and public signing pin. It holds no raw private key or PIN.
- A new chat reads AGENTS and PROJECT_STATE, fetches the implementation branch,
  and inspects current code/runtime evidence; it does not invent host facts or
  ask the owner to reconstruct previous decisions.

Changing a detected path or private identity is never an automatic code-update
side effect. Do not delete server markers, regenerate keys or reset runtime data
to work around an installation error.
