# Private admin vault and research projects

Open **Admin** using the existing workspace PIN session. No separate account or
password is introduced. Everyone who can enter this testing workspace can manage
its vault. Add or replace named entries (lowercase letters, digits, underscores).
Entries can be a key or private text. Existing values are never returned in page
HTML; replace an entry by saving its name again. Delete removes the current vault
entry, not copies already present in hosting backups or past provider requests.

The special key `openai_api_key` connects both task agents and research chat.
An existing server `AICONTROL_OPENAI_API_KEY` takes precedence. Other keys can be
stored and retrieved server-side through `Vault::value` for future integrations;
there is no generic arbitrary-endpoint or automatic tool execution facility.

Private text is excluded from AI by default. Marking **Share this note with AI**
explicitly includes it in agent/research provider requests, up to 12000 bytes of
shared context. Key entries are never included in model prompts. Do not put
credentials in shared notes or research messages.

Vault files live in the existing private runtime directory: `vault.json` is
Sodium secretbox authenticated ciphertext; `vault.key` is a separately stored
0600 random key. Updates use a lock and atomic replacement. Corruption, missing
keys or wrong keys fail without silently overwriting the stored vault. The key
is protected by the same hosting account as the ciphertext: encryption does not
isolate secrets from an attacker who controls that account. Back up both files
privately together. Neither is part of a release, manifest or GitHub commit.

**Research projects** maintains separate project briefs and persistent message
threads in private `research.sqlite`, independent of the coding-project tables.
AI responses use OpenAI Responses, the stored/environment key and the server
model (default `gpt-4.1-mini`). Web search can be selected per message, with one
tool call and 1600 output tokens maximum. This is provider-billed use, not free
local search. Provider citation URLs are retained with answers. Responses and
URLs are escaped as text; model-generated HTML is never executed.

Each request includes its project brief, the last twelve completed/user messages
from that project (each capped at 6000 bytes), and opted-in shared notes. Up to
ten requests per hour and one pending research request globally are allowed.
Requests time out at sixty seconds. Errors/timeouts retain the user message and
record a failed/incomplete/uncertain outcome without automatic retries. A pending
request left by a killed PHP worker can be marked uncertain after two minutes.
There is no claim that this cancels or reverses provider billing. Normal submits
show a researching state; research answers are not streamed token by token.

Existing releases/PINs/keys/tasks remain intact. Install the checked GitHub
release through Application updates; uploading only the bootstrap does not
replace the workspace. New private tables/files are created as needed without
resetting the existing database. Code rollback leaves private data intact.

Validation includes `php tests/workspace.php` with a synthetic provider transport:
encryption/no-plaintext, metadata masking, key-loss/tamper refusal, sharing bounds,
key retrieval, project isolation, web configuration, citations, uncertain outcome
and deletion. Browser tests save private guidance and a research project, assert
no saved plaintext is returned, check disconnected chat, both tabs/accessibility
and mobile layout. No live provider key or billed research request was supplied
in this cloud session; actual provider/host validation follows on the user's host.
