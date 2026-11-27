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
| Research term help | Local highlighting; explicit click only; gpt-5.4-nano, no tools, 700-character context, 160 output tokens, 20 seconds; saved source-aware definitions; separate workspace 30-call/US$0.01 rolling hour including unverified reservations |
| Task agent bounds | One active run, three requests/hour, 1,000 output tokens/call |
| Research bounds | One active investigation/pending request; 30 calls/hour shared with chat/Learn; 3,200 output tokens for new specialist flows, 1,600 for legacy/Learn; 60 seconds |
| Evidence lock-in | New flow: N selected branches need N+4 available calls (plan, N searches + Challenge, extraction, verifier); searches advance explicitly; one optional repair per round. Saved version-one runs retain prior bounds |
| Research map | Black star-system canvas; stable knowledge constellations, continuous attention and perspective 3D orbit/flight camera and stable XYZ positions; common-idea links/family colours and real pending-work markers; camera-only Fit and explicit Reset; dim/recede retain ideas; no extra provider calls |
| Research voice | Signed-in Research map on HTTPS with browser microphone permission, GPT-Realtime-2.1 mini with Marin via WebRTC and the existing OpenAI credential. Browser voice sessions end after ten minutes. Voice usage is billed separately from the tracked project cap. Spoken expansion uses the existing model-growth budget. |
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

Systems defaults: Inspection access, per-server ED25519 keys encrypted in the
vault, manual fingerprint verification, eight decisions/task, ten tasks/hour,
1,000 output tokens/decision, 30-second provider/20-second local SSH limits,
15-second remote command limit plus kill grace, 16 KB retained per output stream.
Requires proc_open, /usr/bin/ssh, /usr/bin/ssh-keyscan and target-port access on the
PHP host. An external relay is not included. Private systems.sqlite persists across
updates; Admin inventories it. Disconnect/delete is explicit in Systems, separate
from project resets. See SYSTEMS.md for exact access and uncertainty semantics.

Two-command Systems setup defaults: Low/Read-only, a unique
locked-password account and no sudo. Medium grants an account-level shell; High
explicitly creates a full passwordless sudo policy. Root/Python 3 is needed on
the target for setup. Finished setup requires both bound replies and actual
host-pinned SSH authentication. Additive system_enrollments/system_terminals
persist in systems.sqlite. Web console sessions are bound to the creating login,
expire after 30 minutes, and allow at most 60 bounded commands/hour/system.
The console is noninteractive; no terminal daemon or external relay is installed.
Linux server cards show saved setup observations and their check time after
Systems unlock. Rendering the list makes no SSH requests.
The verified server page starts with the SSH console and its action buttons.
The console displays the saved-key sign-in result, the advertised SSH banner
when present, and the selected server's observed welcome and system details.
Manual commands, Linux agent commands and guided actions share one scrollback
with source labels and colors. The agent direction field and plain-language
reply sit below the scrollback and command prompt.
The prompt shows the retained directory. A successful plain `ls` with no
visible results displays a short hint to use `ls -la` for hidden entries.
Guided operations report the current command and bounded, redacted stdout and
stderr in that console. An active or uncertain guided action blocks manual
command entry until reconciliation releases the operation lock.
Guided actions sit below the console. The read-only setup check disables
one-time actions only when it detects existing components or configuration
for the selected connection. Learn more panels explain reviewed steps and show
recorded commands and exit codes where available.
Ask AI to prepare uses the configured OpenAI model and saved key for one
choice among the actions available to that server. AI supplies the action
and its reason. Existing review and approval are required for changes.
Command request errors and guided action failures show an Admin diagnostic
reference.
Linux agent tasks display activity in the same console. Automatic steps
run sequentially while the page is visible, with up to eight decisions
per task. Operator updates use plain text separate from command output.
Unused first commands expire after 24 hours. Saved discoveries can resume with
the same public key and bound receipt. Setup and SSH verification failures
include a reference tied to a private Admin diagnostic event.
See SYSTEM_SETUP.md for server-side enforcement and account removal semantics.

Security boundaries (2026-11-22): pasted bootstrap and new readonly inspector
invocations use Python isolated mode (`-I`). The `ssh_system_` vault namespace is
managed only through Systems, not generic vault forms, and is never AI-shared.
Legacy public-testing sessions cannot open Admin, mutate any vault entry, export
Admin reports or run Admin cleanup. PIN-verified access overrides that demo flag.
These restrictions preserve existing records and keys; they do not reset access
configuration or modify existing remote servers during application installation.

Map execution controls: compact play, Stop and automatic growth. With an idle
or stopped flow-2 idea selected, play grows the same pathway for one round.
A stopped map at the project root offers a fresh investigation. Frozen Evidence
remains read-only during resumed growth. Automatic model-only growth defaults
to five rounds, accepts 1–20, dispatches sequentially and pauses on user
interruption, errors or limits. Both use the existing model, spend cap, rate
limit and flow-2 permissions. The pathway display toggle only changes the canvas.

NASA Managed defaults (2026-11-22): owner-only planning for explicitly selected
task/research projects, additive nasa_* tables in panel.sqlite, original versioned
handbook guidance, manual use without an API key, and tool-free optional drafts
using the existing server model/key. Separate NASA admission is eight calls per
workspace/hour and $1 estimated plus reserved risk per source project/rolling day;
6,000 output tokens, 120,000 input JSON bytes, 45-second provider timeout, shared
ProviderGate and no automatic retries. AI output is always a draft. Phase gates
are local planning guidance and cannot grant execution/deployment permissions.
See NASA_MANAGED.md for integration, snapshot, export and cleanup semantics.

NASA workspace evolution (2026-11-22): additive private panel tables retain
provenance, pending collaboration proposals, focused-research origins and baseline
restore audits. Research project/run identity tokens migrate idempotently in the
existing research.sqlite; they are internal scope identities, never credentials.
Owner/CSRF protection covers every NASA action and export. Existing data and
identity stay intact; no records are cleared during install. Baseline creation
now requires a current local review. Requirement verification requires reviewed
linked evidence plus persisted owner confirmation. NASA drafting retains the
existing 8/hour workspace and $1/source-project rolling-day allowance.

Everyday/delivery defaults (2026-11-22): new Engineering workspaces default to
Everyday work; existing NASA projects remain rigorous until an explicit owner mode
decision. Existing budgets/authentication remain. New dedicated AI proposals have
a 10,000-byte editable envelope limit and never mutate operative planning before
owner adoption. Scope/configuration constraints are required for new Everyday
promise verification; unknown risk is stored as unknown. See EVERYDAY_ENGINEERING.md.

Website automation reuses the normal owner PIN and checked installer. The site
PIN is supplied through protected AICONTROL_SITE_PIN on the coding machine,
never a new site authentication/deployment channel. Admin retains separate
private release/test records with source-labelled observations and bounded output;
client results do not become server attestation. See DELIVERY_AND_TEST_REPORTS.md.

Guided Systems defaults (2026-11-23): separate owner-chosen Systems passphrase,
15-minute grants, five unlock admissions/ten minutes, Argon2id-wrapped master key
and encrypted per-server SSH custody/records; public notes remain masked/readable.
The passphrase is not set by installation or recoverable using the website PIN.
Reviewed long jobs have a 30-minute bound and no automatic launch retry; firewall
has a two-minute rollback pending fresh pinned SSH verification. See
SYSTEM_ACTIONS.md for supported OS/permissions and key-transfer semantics. The
installer rejects releases removing configured SystemGuard protection. Research
handover exports all saved selected-project research read-only; see
RESEARCH_HANDOVER.md for method-length, scope and review questions.

Writing-help activity defaults (2026-11-25): automatic two-second typing idle and
one-second trusted pointer/scroll intent, coalesced with an eight-second automatic
request gap; explicit Refresh requests a full set, selection one replacement.
Full set/replacement output bounds are 240/100 tokens. Existing rolling 20/$0.01
workspace limits remain; displayed measured estimates and unknown reservations
are separate. Supported orphan-usage recovery uses matching private completion
metadata, never a blind allowance reset. Create and start research opens the map
and starts bounded model exploration, retaining the project if start is blocked.
See BRIEF_WRITING_HELP.md for admission, no-JS and legacy save-only contracts.

Term explanations now request Responses streaming and forward text deltas as SSE.
The popup paints each delta before completion, labels partial text unfinished,
and only caches a definition after server validation/persistence. Truncated streams
retain partial text with an error and never retry automatically. Cached results
remain immediate. A delayed synthetic provider regression asserts partial text is
visible while final output is still unavailable.

Whole-output Explain: explicit owner click only, gpt-5.4-nano with no tools,
ASD-STE100 writing principles (not certification), complete saved B up to 32 KB,
4,000 output tokens/24 KB and 60 seconds. Separate ste100 workspace allowance:
10 dispatches/US$0.05 per rolling hour including unverified reservations. Saved
source-aware C is auxiliary; original research/Evidence remain unchanged.

OpenAI API billing and project quota are managed in the OpenAI API platform.
The Systems connection card shows whether an API key is configured. Runtime
rejections appear on the affected request and in the private Admin diagnostic log.
