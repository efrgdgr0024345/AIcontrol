# Three-dimensional research Map

2026-11-14 AWST. This update replaces the flat camera rotation described in
MAP_ROTATION_LINKS_ACTIVITY.md. Its family colours, common-idea links and verified
pending-work markers remain in use. Research actions and saved records retain
their existing behavior.

## Controls

- Drag to orbit around the current point in space. **Orbit focused idea** returns
  to the selected star and makes it the orbit centre. Turn and Tilt buttons offer
  the same horizontal and vertical steering without dragging.
- **Fly freely** keeps the camera eye fixed when looking around. With the canvas
  focused, hold W/S to fly forward/back, A/D to strafe and R/F to rise/descend.
  Shift increases flight speed. Arrow keys or Q/E steer while flying.
- The six **Fly through space** buttons support a tap, a held mouse/touch press,
  and keyboard activation. **Stop camera movement** stops navigation; **Stop
  research** and Alt+S still stop provider work independently.
- Shift-drag pans relative to the camera. Wheel and +/- move nearer/further;
  two fingers pan and pinch. Single-finger dragging orbits or looks around.
- **Find an idea or evidence card → Focus selected star** retrieves a distant,
  receded or behind-camera idea. Home/**Fit** frames the constellation volume.
  **Reset view** levels the heading; **Reset layout** alone discards positions.

Releasing keys/buttons stops flight. Window blur, hidden-page changes, leaving
Map, choosing another star and Stop camera movement clear held movement. Manual
steering interrupts automatic travel. Reduced motion uses immediate focus/fit
and a short opacity transition; movement explicitly held by the user still works.

## Geometry and camera ownership

`map-engine.js` assigns stable, deterministic X/Y/Z layout positions. Family
groups occupy volumes; new descendants receive depth near their parent. Cached
IDs retain all three coordinates across growth, usage/activity polling and
camera navigation. These coordinates are a browser display layout, not model
output, evidence, or new database records. There is no continuous physics loop.

`SpaceCameraController`, also exported as the active `CameraController`, owns a
perspective camera with physical eye X/Y/Z, yaw, pitch, orbit pivot and distance.
Nearby and distant ideas exhibit different parallax as the eye moves; this is a
volumetric scene rather than a rotated plane. Projection uses a 55-degree vertical
field of view. The DOM/SVG renderer draws upright screen-facing labels and
depth-attenuated stars; it does not require WebGL or external assets.

Connections are reprojected and clipped against near/far planes. A star behind
the camera stops drawing instead of mirroring across the screen. Its saved data
and position remain available through the selector and List. Stars can overlap
in perspective. Only exposed, sufficiently large click targets accept pointer
or Tab focus; occluded stars keep their light and can be brought forward with
the full-size selector. Keyboard parent/child navigation hands focus to the
target when its camera flight makes it available.

Focus travels for 450–750 ms, preserving heading and centring on the target's
actual Z coordinate. Fit bounds the whole three-dimensional group. Growth
retreats along the camera heading over 2.2 seconds when needed and waits during
active navigation/focus plus the existing four-second manual grace. Frame work
runs only during travel, held flight or short pan momentum; there is no idle
camera animation.

Public camera methods include `focus`, `fitGroup`, `panBy`, `zoomBy`, `turnBy`,
`resetRotation`, `setMode`, `flyBy`, `setFlightInput`, `setGrowthScale`,
`worldToScreen`, `screenToWorld`, `projectSegment`, `getState` and `restoreState`.
`getState()` identifies `space3d-v1`; `scale` is a compatibility view of orbit
distance. Old X/Y/scale states restore as front-facing views at Z=0. The old
planar controller remains exported as `LegacyCameraController` for compatibility
checks; Map uses the spatial controller. Camera state and cached XYZ positions
remain local to the browser window. Admin JSON reports this distinction and
continues exporting the saved payload and display projection, not the live pose.

## Validation and limits

Local release checks passed on PHP 8.4.24: host/syntax, generated assets/manifest,
all eighteen PHP suites, all seven HTTP suites, 32 projection groups, 14 legacy
camera groups, 14 spatial camera groups and the full Chromium suite. The
workspace report covers 34 axe views with zero violations, alongside the new
3D desktop/mobile audits. Sentence updates keep readable contrast throughout
their transition; a deterministic lowest-opacity audit covers the timing case
that CI caught during an automatic update.

The spatial unit suite covers depth/parallax, orbit geometry, fixed-eye free
look, camera-relative held flight/stop, focus interruption, oblique-volume Fit,
near-plane segment clipping, inverse projection, cursor-anchored pan/dolly,
reduced motion, deferred growth, old/new restore and frozen XYZ after insertion.
The existing projection and legacy-camera suites remain separate.

Chromium tests use real local PHP actions with synthetic provider data. A
241-card scene checks noncoplanar positions, yaw/pitch, held and tapped flight,
native touch, fixed-eye look, perspective parallax, behind-camera recovery,
upright focus, colours/links, exact XYZ retention, current request attribution,
unverified-status recovery, Stop and desktop/mobile axe. Existing exploration,
star-map and older-flow UI regressions still run. Screenshots are generated in
`test-artifacts/ui-map-3d-*.png`.

These cloud checks do not establish sustained 60 fps on every device, a billed
provider run, or activation on the owner's host. Large maps use the existing
compact-detail fallback. Published releases require the exact-commit
`planning-panel` check; installation and testing on the hosting site remain a
separate step in Admin.
