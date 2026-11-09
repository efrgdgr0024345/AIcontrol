# Loader-owned signing keys and update receipts

`loader.php` generates an **Ed25519** installation identity on the hosting server
using PHP sodium. No key generator, private-key transfer or hash preparation is
needed on the laptop. Generation happens once private storage has been selected,
before the first GitHub check. A normal page load cannot guess all account
document roots, so the private path must be selected first.

The private key lives at `private-home/signing/identity.json` (0600) inside a
0700 directory outside every declared document root. It is not embedded in the
public PHP source. The loader manages this file; application releases, code
rollback and GitHub archives never replace it. Runtime keys are not committed.
The key is stored in recoverable server-private form; no separate encryption
master key is claimed. Other applications sharing the Unix user remain inside
the same account trust boundary.

The public key is pinned in the loader's generated location file. Existing
unsigned testing installations receive a server-generated identity once on
upgrade. After a pin exists, missing, corrupt, wrongly permissioned or mismatched
key state blocks operation. The loader never silently substitutes a new key.
Back up the private identity through protected server backup procedures; losing
it requires explicit operator recovery, not a browser reset button.

## What is signed

- Each outgoing GitHub API/download request: method, destination host, complete
  URL digest, request ID, nonce, issue/expiry times and correlation context.
- Its HTTP/transport outcome: matching request receipt ID, HTTP status, response
  body digest, duration and GitHub request ID when supplied.
- The checked selection: repository, full commit, manifest digest, destination
  and document-root digests, access mode, requesting host identity digest, ref,
  expiry and single-use intent. Install verifies that signature as well as the
  existing session-bound expiry/consumption checks.
- Update requested/completed/stopped records and interrupted-activation recovery.

Signed request metadata is actually sent in `X-AIcontrol-Key-Id`,
`X-AIcontrol-Payload` and `X-AIcontrol-Signature` headers. Redirects get a new
destination-specific signature. The private key is never transmitted. Signed
records are retained as new, uniquely named files under `private-home/receipts`.
The installer links to the public identity and the completed-install receipt.

Records deliberately exclude authorization headers, cookies, raw response bodies
and raw redirect URLs. Private GitHub archive redirect URLs may carry transient
credentials, so only their digest and destination host are recorded.

## Verify with an independently pinned public key

After PIN login:

- `loader.php?identity=1` returns the algorithm, public key and SHA-256 key ID.
- `loader.php?receipt=<32-hex-id>` returns a verified signed envelope.

Neither endpoint exports the private key or provides a generic signing service.
Pin the public key through a trusted enrollment path before relying on receipts.
Do not accept whatever public key an untrusted receipt supplies as its trust root.

An envelope's `payload` is base64-encoded canonical UTF-8 JSON. Its signature is
base64-encoded Ed25519 over these exact bytes:

```text
AIcontrol/receipt/v1\0 + decoded payload bytes
```

Here `\0` means a literal zero byte. Object keys are recursively sorted, array
order is retained, JSON slash/Unicode escapes are disabled, and floats/objects
are rejected. `AIcontrolSigning::verify($envelope, $pinnedPublicKey)` verifies
the algorithm, domain-separated signature, canonical payload, public key and key
ID. It returns the decoded payload on success and null on rejection.

Evidence receipts remain verifiable after their request's expiry. A service
accepting a signed action must additionally enforce its audience, time window,
nonce/replay rules and permissions; signature verification alone is insufficient.

## Scope and limits

GitHub does **not** treat these custom headers as API authentication or verify
them for us. Private repository access still uses separately configured GitHub
authentication; a self-generated Ed25519 key cannot replace that authorization.
The current installer uses read-only GET API/download operations. Future GitHub
post/write adapters must explicitly bind their method/body and retain results;
no write adapter is claimed by this feature.

Incoming code still requires the exact checked commit, successful required
GitHub Actions check, TLS and the checked manifest/file hashes. Signing our
request does not establish publisher provenance or make arbitrary code safe.

Receipts establish that the installation key signed the recorded facts. They
are not proof that a human approved an action, nor an immutable log against a
server/account attacker holding the key. Local deletion/truncation cannot be
excluded without an independently retained checkpoint. An HTTP response receipt
does not turn an uncertain request into a proven completed operation.

The testing release uses first-visit PIN setup for panel and installer access.
Signatures identify the server signing key, not independent trusted-device approval. Independent Android approvals and arbitrary agent
execution remain later work.
