# Living star-system Map and camera

Release intent (2026-11-14 AWST): a black, navigable constellation of provisional
ideas. Exploration-first and explicit final Evidence selection remain the research
workflow; this release changes presentation and adds optional map metadata.

## Release notes

- Pure black star-system canvas with restrained knowledge-family colours; ideas
  recede by size/light and remain reachable through full-size navigation.
- Smooth focus/fit, immediate manual pan/zoom, short momentum and deferred growth
  zoom; stable coordinates and explicit Reset layout.
- Dim/recede controls retain flow-2 ideas; locked/evidence paths use a gold accent.
- Existing investigations receive the new renderer while retaining their saved
  research lifecycle. Final Evidence lock-in remains explicit in flow version 2.
- Install the checked update from Admin, then reopen Map. No new investigation or
  data reset is required to use the star-system presentation.

## Shipped milestones

1. **Foundation:** idempotent additive node columns, a pure black grid-free Map,
   continuous size/light hierarchy and existing promote/demote/park actions.
2. **Camera:** one DOM-free CameraController for focus, fit, pan, cursor-anchored
   zoom, short damped momentum and growth scale. Smooth, interruptible focus travel;
   reduced motion recentres immediately with a 120 ms opacity transition.
3. **Constellations and growth:** knowledge-family grouping, weighted attraction
   toward the parent/family and a fixed six-pass damped group separation. Only new
   IDs get positions; cached IDs never move except after explicit Reset layout.
   Growing bounds can request a 2.2-second zoom-out, paused during focus/navigation.
4. **Evidence and access:** gold locked/evidence accents, complete inspector and
   existing explicit selected-branch Evidence action; keyboard, mobile, no-JS List
   and dark-theme contrast coverage. Receding layers/branches does not delete ideas.
5. **Hardening:** deterministic camera/unit tests, migrated legacy fixtures,
   synthetic-provider lifecycle regressions, a 240-star browser graph, 400-star
   layout checks, visual captures, documentation, manifest and checked release.

## User controls

- Select any star to travel to it and open its details. **Find an idea or evidence
  card → Focus selected star** offers a full-size alternative to small distant
  targets. It can retrieve ideas outside the current evidence filter or receded
  branch. Idea history, keyboard arrows and Focus Active also navigate.
- Drag to pan. Wheel zoom follows the pointer; Shift+wheel pans. Zoom buttons and
  keyboard `+`/`-` also work. With the canvas focused, arrows/Page Up/Page Down pan
  and Home fits the view. A new focus, pan or zoom cancels the previous travel.
- Fit moves the camera only. Reset layout is the sole full coordinate-cache clear.
  Returning to List keeps the current in-memory map; no camera state is written
  to a project/database automatically.
- Dim Secondary/Tertiary changes emphasis without removing nodes. For new flow-2
  maps, Recede all/Restore all and Recede branch/Restore branch emphasis keep every
  concept in the graph. The legacy explicit collapse/filter behaviour remains
  compatible for old runs, and the complete durable List always remains available.
- Demoting/parking the current focus hands focus to the nearest remaining Active
  concept (or the root). A demoted ancestor recedes even when the new focus is its
  child. Explicitly focusing any old/parked idea brings it back to full prominence;
  doing so does not promote its saved layer or change evidence status.
- Growth zoom waits for an existing focus flight to finish and for four seconds of
  manual-navigation inactivity. A new explicit focus clears stale growth intent.
  It only zooms out when new concept bounds need it; status/usage polls do not
  repeatedly refit the scene. There is no idle physics or camera animation loop.

## Attention and honesty

The current focus and its Active ancestor logic line have nominal full size and
brightness. Layer, priority, layout weight, relative age, knowledge family, zoom
and distance from camera centre continuously attenuate other ideas. Low-level stars
can fall below 25% glyph size. This is attention, never probability, source quality
or proof. A focus remains full nominal screen size while the surrounding system
zooms; camera movement changes projected positions rather than saved coordinates.

Blue/teal marks core exploration, green associations, amber counters/edge cases,
purple reframings, grey parked ideas and gold locked/evidence paths. The inspector
and accessible button names retain origin/provisional labels. Gold/`locked` means
explicit selection for evidence work, not verified truth. Existing passage-backed
verification/downgrade rules determine `grounded`; all other outcomes stay honest.

Only glyphs and paths dim; visible text retains readable contrast. Distant labels
and colliding lower-priority labels are simplified, with full accessible names,
hover/keyboard focus and inspector text available. The selected label takes
precedence. Full-size navigation and the List provide alternatives to tiny stars.
Background label detail is reduced during travel and peripheral glows simplify
when frame intervals are pressured or the graph exceeds 300 displayed stars.
World-coordinate hit areas are not opaque rectangular cards; tests check actual
star glyph separation rather than requiring transparent bounding boxes not to overlap.

## Camera API and geometry

`assets/map-camera.js` exports `AIcontrolCamera.CameraController` in the browser
and a CommonJS module for tests. It is bundled locally by build-ui.php. The active
Map exposes its controller as `document.querySelector('[data-mindmap]').camera`.

```js
camera.focus(nodeId, { animate: true, fitPadding: 0.2 });
camera.fitGroup(nodeIds, { animate: true, fitPadding: 0.12 });
camera.setGrowthScale(factor);
const saved = camera.getState();
camera.restoreState(saved);
```

State owns the world-centre `x,y`, scale, optional depth (currently 2D), focus node,
growth scale and observable transition type. Geometry/viewport are supplied via
`setGeometry`; the controller never modifies node positions. Pan/zoom/momentum
all use this same state. Focus duration is distance-scaled from 450 to 750 ms with
cubic-bezier(.4,0,.2,1) easing. Invalid/missing focus IDs do not travel.

Screen centre = viewport centre + (world centre − camera centre) × camera scale.
The renderer translates stars and scales the SVG path field from that formula;
keeping the focused glyph/label nominal size avoids shrinking the user's current
line during global zoom-out. Neither transforms nor lens values become world
coordinates. Off-screen lens updates use overscan; records remain present.
Native focus scrolling is reset so a second scroll position cannot compete with
the camera. Reduced-motion preference changes during focus finish at the selected
star immediately. Camera frames stop after travel/momentum, on hiding the page or
closing the Map. No continuous high-frequency layout or provider work is added.

## Storage and privacy

`research_nodes` additionally carries `weight REAL DEFAULT 1`, nullable
`knowledge_line_id`, `last_focused_at`, `size_factor`, `brightness_factor`. Existing
layer/origin/provenance/park/lock columns remain. Migrations do not rewrite old
records or infer historical provenance. New conceptual roots receive a durable
family key, and children inherit it. Old NULL family keys have a deterministic
read-only ancestry fallback in projection. A family is a layout aid, not a claim
of corroboration. Weight is bounded client-side for safe geometry.

Size/light are computed in the browser from current focus/camera/state. Nullable
cache columns are not presented as actual persisted live camera values. The
optional `research_map_state` table is not needed or created: camera and emphasis
state are in memory and do not leak across projects through shared persistence.

Optional `research_focus_node` records only `last_focused_at` for a real node in
the specified run. It is session/CSRF protected, rejects synthetic/foreign IDs,
does not call a provider and cannot resume a frozen/stopped run. Camera navigation
never sends this action automatically. Snapshots/Admin JSON show actual metadata;
the Admin debug bundle does not capture another browser window's camera/lens state.
PIN, vault, signing, update authorization and provider/budget/Stop gates are unchanged.

## Validation and measured limits

- `php tests/star_map.php`: 11 checks for simulated pre-column legacy migration,
  repeat migration preserving optional values, family inheritance, focus scope,
  no provider spend and stopped-state preservation.
- `node tests/map_camera.cjs`: 11 groups cover easing, bounded duration, interruption,
  anchored zoom, growth deferral, reduced motion, momentum stopping, restore,
  continuous attention and deterministic fresh-only constellation layout.
- `tests/star_map_ui.cjs`: real local PHP with 240 synthetic ideas, focus travel/
  interruption, full-size old stars at camera centre, keyboard navigation, actual
  deferred growth zoom and manual cancellation, coordinate memory, optional focus
  HTTP validation, reduced motion, black background and desktop/mobile axe.
- Existing exploration tests still cover model-only growth, exact position memory,
  demotion handoff, dimming without removal, Evidence selection and no-JS actions.
  The complete legacy PHP/HTTP/Chromium suite remains required.

A local cloud check for 400-star layout, reversed-order determinism, growth and
restoration measured roughly 40–55 ms combined (no DOM). A 240-star Chromium
sample measured a 16.7 ms median frame interval with roughly 83–117 ms p95
outliers during heavier work. JavaScript camera paint work was approximately
4–5 ms maximum in those samples. These headless/software-rendering measurements
are not sustained 60 fps validation: 60 fps is a target, not a claimed guarantee.
The compact fallback reduces peripheral glow/detail but retains focus and all
nodes. Host/browser/GPU performance still needs real-device testing.

Provider validation here is synthetic, not billed AI activity. Live billed
research/source availability remains host-side. Publishing a checked release is
separate from authenticated activation on the user's website.
