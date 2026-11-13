# Exploration-first living map

New investigations default to `flow_version=2`, `exploration_first=1`. Existing
runs retain their saved flow version and phase, including human claim review and
legacy search settings. Existing project data is retained. The project database
flag `exploration_first=0` selects version one for subsequent runs; this is not a
new public settings field. PIN, vault, signing and update behavior are unchanged.

## User workflow

1. Start from the project brief. One tool-free seed surfaces provisional model
   hypotheses, then pauses in `exploring`. No automatic planning or searching.
2. In Map Details or List, choose zero to five branches and **Explore further
   (model only)**. With no selection the brief is the root. Modes are explore,
   associations, counters, edge cases and reframing. Each explicit action returns
   one to five bounded ideas with uncertainty and a possible falsification test.
3. Promote, demote or park branches, optionally with a park reason. Active,
   Secondary and Tertiary are metadata layers 0, 1 and 2; no nodes are deleted.
   Park sets layer 2; promote moves it back one level. These actions are free of
   provider calls and remain available for saved/stopped maps. Layer changes keep
   original provenance and evidence status; being parked does not change truth.
4. Select one to five unlocked Active/Secondary model branches and explicitly
   choose **Lock in with Evidence**. Each selected idea receives a timestamp and
   linked grounding claim. Original ideas remain on the map. Only the grounding
   targets enter Plan, searches, extraction and grouped verification. One normal
   search per selected branch plus a reserved challenge is planned. A malformed
   or incomplete selection plan is rejected atomically. Remaining hourly capacity
   must cover N+4 requests. The project spend cap is still checked before every
   provider dispatch; hourly capacity does not promise funds or provider success.
5. Continue each bounded Evidence search explicitly. Lock-in does not enable
   autoplay. Unselected ideas remain available and cannot be used to redirect a
   search outside the selection. Sources/passages still require actual completed
   tool citations/snippets, and model text never becomes a literal passage.
6. At verification review, explore more, lock other unlocked branches, optionally
   review high-impact gaps once per evidence round, export, or finish and freeze
   the run. Frozen/stopped investigations remain readable and can change layers,
   but cannot perform further model growth. A new investigation starts fresh work.

A branch cannot be locked twice in the same investigation. A later question can
be explored as a new child and locked separately. Previous rounds, source cards,
passages and assessment versions remain stored; the next round's model ledger
contains only selected target claims and associated sources/passages/relations.
`grounded` means a supported final model assessment with an actual passage-backed
relation after deterministic defect downgrades. It is not independent truth
verification. Unsupported/open/contradicted/weakened targets keep `locked`; their
actual assessment remains in the ledger. Original origin stays `model_latent`.

## Storage and contracts

`ExplorationFlow.php` adds idempotent SQLite columns `layer`, `origin`,
`provenance_tag`, `park_reason`, `locked_at`, `last_layer_change` on
`research_nodes`, plus project/run `exploration_first`. Historical origins remain
NULL rather than inventing provenance. No optional map-state table is necessary:
layer visibility is browser-local and layout position memory uses the existing
map cache. Times are Unix seconds, as in existing research tables.

Actions `research_explore`, `research_promote_layer`, `research_demote_layer`,
`research_park`, `research_lock_evidence` use the existing authenticated,
CSRF-protected Research API and ordinary POST fallback. IDs are run-scoped and
validated as a complete set before writes. Exploration and lock-in use the shared
ProviderGate, hourly admission and single-active-request guards. Explore reuses
reservation, usage, uncertain outcome and Stop/late-write protection from `step`.
No daemon, background worker or parallel agents are added.

New idea types are `model_idea`, `association`, `counter`, `edge_case`, `reframing`
and existing `question`/`dream_claim`. Every exploration child is Active,
`model_latent`, `provisional_model`. Source/search nodes are `web_evidence`;
passage/edge display records also carry that origin. New run snapshots, Admin
JSON inspection and Markdown outline retain layer/provenance/lock/park metadata.
Grounding claims carry `data_json.grounding_node_id`; they reuse the established
claim ledger without rewriting the original model idea into a source.

## Map behavior

All living concepts remain projected, including original claims outside the
current evidence selection. Layer styling changes no layout input or position.
Secondary/Tertiary have lighter grey surfaces and 60%/35% accent emphasis; text
stays opaque for accessible contrast. Explicit labels communicate layer/origin
without relying on colour. Visibility toggles hide layers in this browser only;
children on visible layers can remain visible even if a parent layer is hidden.
Cards retain cached world coordinates through layer changes, filters and new
children. Reset layout is the sole position-cache clear. Fit moves the camera.
Focus Active selects an Active concept. The Idea queue includes new model types
and its history can focus a matching idea. Full branch/action selectors work by
keyboard and ordinary no-JavaScript POST forms in List.

## Milestones and validation

All five supplied milestones are implemented in this release: additive layer
foundation; bounded model-only exploration; living-map controls; selected final
Evidence lock-in; docs/regression/release checks. The supplied plan is retained
in EXPLORATION_FIRST_PLAN.md. Validation is recorded in PROJECT_STATE.md.

`tests/exploration.php` uses synthetic Responses transport for migration,
metadata, tool-free growth, selection scoping, full evidence rounds, unsupported
verification downgrade, repeat lock-in, freeze and Stop race tests.
`tests/exploration_ui.cjs` drives real local PHP HTTP actions with a synthetic
provider, and checks growth, exact old-card coordinates, layer controls/reasons,
visibility, disabled lock explanation, complete Evidence lifecycle, mobile,
keyboard/no-JS and axe. `tests/map_engine.cjs` includes a 300-node multilayer
projection/layout growth check. Its timing excludes DOM and is not a guarantee
for host hardware or arbitrary map sizes. The legacy suites explicitly retain
version-one/zero fixtures; no production workflow test switch is introduced.

No billed provider calls were made for this feature. Live billed validation
remains host-side, including source snippet availability and large real maps.
Publishing a checked release does not establish installation or activation on
the user's host. The product's usefulness for understanding/idea generation needs
user testing; automated checks cannot establish that subjective outcome.
