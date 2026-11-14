# Admin research project JSON inspector

Open Admin → Research project JSON inspector, select a project and Load project
JSON. It defaults to that project's newest investigation. Choose another saved
investigation with Load investigation JSON. All saved runs are available, including
those older than the workbench's 20-run history list. Project cards also have an
Inspect project JSON link. No AI request is made and no provider budget is used.

## Three layers to compare

1. **Stored state** in the debug bundle: the selected run's database rows (nodes,
   sources, requests, evidence ledger), plus its project definition. Internal busy
   tokens, vault/config/session files and other projects' content are excluded.
2. **Map input JSON**: the same `getRunSnapshot` payload builder used by research
   polling, including enriched nodes, sources, ledger, requests, findings and
   usage. The inspector explicitly disables timeout reconciliation, so merely
   looking does not stop a stale worker or change pending/outcome state.
3. **Projected map cards and links**: the browser uses the existing pure
   `AIcontrolMap.project(snapshot, filter)` implementation. Select the same filter
   as the map (concepts, claims, questions, sources, passages, evidence relations,
   dead ends or open findings). No parallel PHP approximation is introduced.

Stored source IDs can differ from map node IDs. A persisted source node uses its
node ID, while legacy/display-only sources can use a synthetic source-N ID. Phase
wrappers and grouped records need not get a separate card. The projected report
lists input-node IDs with no same-ID card; this alone is not a missing-data bug.

Checks list missing parents, parent cycles, malformed node/source JSON, missing
source/claim/passage/edge references and request references to absent nodes. A
healthy reference report does not prove evidence quality or that every branch is
visible under the selected filter.

## Copy/download behavior

- Copy map input JSON: exact displayed polling payload.
- Copy debug bundle: project definition, available run metadata, raw stored state,
  payload and checks. With JavaScript it also includes the chosen browser
  projection, making this the useful single report to send back for diagnosis.
- Download map input JSON/debug bundle: with JavaScript, JSON files for the exact
  displayed capture (including projection in the bundle); without JavaScript,
  normal authenticated CSRF POST downloads take a fresh server-side read.
- Download projected JSON: the current projection as a browser-generated file.

No public JSON file is written. Downloads contain the selected project's research
content. Server endpoints retain normal PIN/session/CSRF protection and no-store
responses. Numeric IDs, run ownership and export kinds are validated; titles do
not become filenames or query paths. HTML rendering escapes the JSON.

## Timing and limitations

An inspection is an explicit point-in-time capture in a SQLite read transaction.
It never advances research and can be read while provider admission is held.
Refresh by loading the project/run again to see later work. Normal polling may
subsequently reconcile stale state, unlike this read-only capture.

Viewport, zoom, cached layout positions, collapsed branches and the selection in
a separate research window are browser-local state and are not captured here.
The inspector projects the uncollapsed data under its chosen filter. The bundle
records installed release and UI fingerprint, and the projection records its
source snapshot timestamp to help compare like captures.

If payload construction fails (for example, malformed stored metadata), the
bundle retains the raw rows and a class/message/file/line error instead of
inventing map data. Copy/download the debug bundle; the payload itself is null
until its builder succeeds. A project with no runs likewise has no invented map.

## Validation

- `php tests/project_inspector.php`: project/run scope, exact payload builder,
  read-only stale/pending state, all-run selection, privacy, missing IDs/cycles,
  failed-payload fallback, and reads during provider admission.
- `tests/admin_http.py`: authenticated/CSRF JSON downloads, content disposition,
  input validation, foreign-project rejection, HTML escaping and no stale-state
  mutation through a real PHP server under `/aiadmin/loader.php`.
- `tests/admin_ui.cjs`: project/latest/older-run selection, projection equality
  with the actual map engine, source ID vs node ID, clipboard, downloaded bundle
  equality, projected download, mobile overflow, axe and no-JS JSON export.

Tests use disposable synthetic data. A live user's reported map disconnect is
not considered diagnosed or fixed until its actual capture is examined.

Star-system note: database snapshots now include optional weight/family/focus/cache
columns. Live size/light and camera/emphasis state belong to the Research window;
they are not inferred from nullable cache columns or captured by Admin projection.
See STAR_SYSTEM_MAP.md for the camera API and geometry contract.

Rotation/update note: projected JSON now includes display-only common-family and
matching-wording links, plus pending request context IDs. They do not create ledger
relations or evidence. Browser rotation, link visibility and contact-confirmation
state remain specific to the Research window. See MAP_ROTATION_LINKS_ACTIVITY.md.
