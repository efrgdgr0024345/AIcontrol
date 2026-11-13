# Defined deployment defaults

These are the implemented setup rules, not values the owner needs to type. The
code and this document belong in GitHub; host-specific runtime configuration and
private records persist on the hosting server.

| Setting | Current definition |
|---|---|
| Repository | `efrgdgr0024345/AIcontrol` |
| Release channel | `main` |
| Required exact-SHA check | `planning-panel` / GitHub Actions |
| Entry point | One uploaded `loader.php`, including subfolder support |
| Interface | Tabler 1.7.4 (MIT), bundled locally; local JS enhances update checks/progress |
| UI build | `php bin/build-ui.php`, then `php bin/build-manifest.php` |
| Display timezone | Australia/Perth (AWST); stored timestamps stay UTC |
| PHP | 8.2–8.5; curl, zip, tokenizer, mbstring, PDO SQLite, sessions, sodium |
| Login | First visit sets 6–12 digit PIN; subsequent visits sign in |
| PIN session | Up to 30 minutes; five incorrect attempts block for up to ten minutes |
| Checked selection | Exact full SHA, expires after ten minutes, one attempt |
| Update detection | Signed 15-minute cache; browser checks local endpoint every 5 minutes; explicit refresh at most once/minute |
| Loader replacement | Checked manifest bootstrap hash, atomic replacement and private verified backup |
| Signing | Server-generated Ed25519; domain `AIcontrol/receipt/v1` plus zero byte |
| New research investigations | Exploration-first `flow_version=2`: provisional model growth, explicit selected-branch Evidence lock-in; saved runs retain their flow |
| Task/chat model | `gpt-4.1-mini`, overridden only by server `AICONTROL_OPENAI_MODEL` |
| AI key | Server `AICONTROL_OPENAI_API_KEY`; never browser/source/chat |
| Task agent bounds | One active run, three requests/hour, 1,000 output tokens/call |
| Research bounds | One active investigation/pending request; 30 calls/hour shared with chat/Learn; 3,200 output tokens for new specialist flows, 1,600 for legacy/Learn; 60 seconds |
| Evidence lock-in | New flow: N selected branches need N+4 available calls (plan, N searches + Challenge, extraction, verifier); searches advance explicitly; one optional repair per round. Saved version-one runs retain prior bounds |
| Research map | Stable structural concept layout and position memory; camera-only Fit and explicit Reset; evidence on demand; static activity, one fading idea sentence and on-demand controls; no extra provider calls |
| Research models | Primary/high-impact/verifier GPT-4.1; economy GPT-4.1 mini; new model-only exploration uses the project primary model; saved version-one divergent thinker retains GPT-4.1 nano; server primary-model override retained |
| Research spend cap | $5 USD tracked per project by default; adjustable in Project menu; saved API-usage estimates + conservative reservations, not an invoice cap |
| Rate provenance | Official standard USD prices checked 2026-11-10, saved per request; missing/historical unpriced usage stays unknown |

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

Admin/research defaults: the same PIN session manages the vault; private runtime
`vault.key`, encrypted `vault.json` and `research.sqlite` persist across releases.
The `openai_api_key` vault entry supplies research/task provider credentials unless
the server environment overrides it. Shared notes require explicit opt-in.

Guided-create draft defaults: saved title/brief remain 160/4,000 UTF-8 bytes.
The optional pre-project suggestion action uses 16–2,000-byte drafts, 500 ms
client debounce, 240 output tokens, 25-second provider timeout, a separate
20-request/$0.01 rolling-hour workspace allowance, and private metadata-only
journal storage (100 records). Project research caps/limits remain unchanged.
The shared provider-request lock serializes provider dispatch without covering
Stop/status. The owner approved gpt-5.4-nano to replace the retired model mandated in the
original plan. It is hard-coded for writing help with reasoning effort none;
project/server primary-model overrides and fallback are not used. Saved standard
rates: $0.20 input/$0.02 cached/$1.25 output per million tokens, checked 12 November
2026 against official model documentation. Existing investigation models remain
unchanged by this create-flow plan.

Admin data management uses existing runtime panel.sqlite/research.sqlite schemas;
no database paths are accepted from browser forms. Deletion/reset is allowlisted,
CSRF-protected and typed-confirmed, with active-provider exclusion. It preserves
PIN, encrypted vault/key, signing identity, configuration, security throttling
and installed code. Diagnostic/suggestion cleanup is separately selected. See
ADMIN_DATA.md for exact scopes and queried SQLite/PHP inventory fields.
