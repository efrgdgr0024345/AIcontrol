# Map rotation, related ideas and current model work

Release update: 2026-11-14 AWST. These additions extend the star-system renderer;
the research lifecycle, provider budgets, Stop guards and explicit Evidence
selection retain their existing behavior.

## Rotate the map

Map controls offer Rotate left/right (15 degrees), Reset rotation and Rotate by
dragging. Shift-drag also rotates; normal drag pans. Two fingers can twist, pinch
and pan together. With the canvas focused, Q/E rotate and 0 resets. Reset respects
reduced motion; a new gesture interrupts a focus flight immediately.

Rotation turns the 2D constellation plane. Labels, the inspector and controls stay
upright. The camera owns `rotation` in radians alongside x/y/scale. Public methods
are `rotateBy(radians, anchor)`, `resetRotation(options)`, `transformBy(gesture)`,
`worldToScreen(point)` and `screenToWorld(point)`. Existing pan, cursor zoom, short
momentum, focus, Fit, growth bounds and restore all use the same rotated geometry.
Old saved camera objects without rotation restore at zero. World node coordinates
never change as a consequence of camera navigation.

## Links and colours

Idea families share a deterministic colour, retained across polls and navigation.
Gold continues to mark Evidence lock-in/sources and grey parked ideas. Their actual
status remains in text; colour does not establish evidence strength or truth.

- Solid paths: existing saved parent/child relationships.
- Short dashes: ideas in the same saved knowledge family, or an explicitly labelled
  ancestry-based family fallback for older records.
- Dotted paths: matching idea wording after case, punctuation and Unicode-width
  normalization. This is a text match, not verified semantic equivalence.
- Long dashes: the same cited source, not independent corroboration.

Cross-family links use endpoint gradients matching the linked stars. **Connection
colours and line styles** explains the key and lists family swatches. **Show related
idea links** toggles the family/wording overlay. **Connected ideas** in the inspector
states the reason for each link and can focus its other endpoint.

New common-idea links are a display projection of saved metadata/text. They are
sparse deterministic chains, not all-pairs networks, and are marked `display_only`.
They are never inserted into `research_edges`, never create evidence, never grant
search eligibility and do not change layout positions. Structural layout ignores
non-parent connection edges. No semantic-match AI call, embedding request or web
search is added. During pressured camera flights the compact fallback can omit
peripheral overlay links temporarily; they return when the flight settles. Nodes
remain present and the selected/working connections retain emphasis.

## What is working now

A full-size **Working now** marker stays on the Map when a real saved provider
request is pending. A static bright ring and labelled badge mark its actual context
nodes; the marked stars stay legible even when their saved layer is receded. Clicking
the marker or a Current model work entry focuses the relevant idea.

The inspector reports the saved model/role, public request activity and confirmed
visible-response character count. Tokens remain confirmed only after a response.
This displays request state, never private reasoning or a fabricated thought trace.
There is no perpetual activity animation or invented parallel-worker display.

Multi-node exploration uses the validated context IDs recorded on its phase node.
One request covering two ideas is labelled **2 marked branches · 1 shared request**.
Shared planning/verification is likewise marked as shared, root exploration targets
the project root, and pending source-only Learn work can appear on completed runs.
Terminal/uncertain requests cannot keep ordinary research work markers active.

When a status/action error makes progress unverified, active markers are removed
and the banner says **Activity unverified**. A successful authenticated response
restores confirmation even if the saved snapshot is byte-for-byte unchanged; this
does not resume automatic phases or retry an unknown provider operation. Stop clears
pending work markers through the normal saved request/run state.

All provider actions remain session/CSRF/budget protected. Rotation, links and work
inspection issue no provider calls and change no saved research facts. Camera state,
related-link visibility and browser contact confirmation are local view state. Admin
JSON contains the captured saved status and derived links, not another window's
camera rotation or connection-confirmation state.

## Access and validation

Working badges and line keys retain readable dark-theme contrast. The scrollable
family key is keyboard focusable. Stars partly covered by the viewport edge or a
Map overlay retain their glyph, but their covered button is temporarily unavailable;
the full-size star selector, keyboard navigation and List remain alternatives.
This avoids small slivers of obscured click targets while keeping saved ideas visible.
The complete no-JavaScript List and research controls remain available.

Tests cover rotation buttons/keyboard/Shift-drag/native two-finger input, upright
focus, rotated pan/zoom/Fit/growth math, old camera restore, immutable coordinates,
common-wording/family links and colours, real PHP polling with synthetic pending
context, identical-snapshot recovery, Stop, mobile and axe. The pure suites now have
32 projection groups and 14 camera groups. The complete application/HTTP/browser
regression remains required before release. Fixtures are synthetic, not billed
provider operation or an authenticated installation on the user's host.

Existing star-map frame timing limits still apply. The local software-rendered
240-star case can have slow frames, particularly with the extra connection overlay;
60 fps is a target, not established for every browser/device. The compact rendering
fallback preserves the selected logic line and actual work indicators.
