# Test plan

Phase 0 must validate the public health JSON contract, rejection of unauthenticated API access, Sanctum bearer-token authentication, React loading/success/failure/retry behavior, the Flutter starter and Python environment. The full test command must also compile the web app.

Before marking Phase 0 complete, run setup against a clean checkout with ignored dependencies and environment files absent, then launch dev and check the API through Vite's proxy. Record actual results in PROGRESS.md.

Later phases add simulation-label, consent, retention, GPS validation, route matching and measured ETA-accuracy tests alongside each feature.

## Phase 1

- Geometry: hand-calculated equatorial distances; projection before/after the path; far points; bends; duplicate/single vertices; antimeridian and high latitudes; invalid/empty/antipodal cases; segment coverage/continuity and preserved road vertices.
- Import/API: isolated temporary fixture inputs with in-memory SQLite; stable identities after repeated import/build; transaction safety; off-route and out-of-order stops; malformed/missing inputs; source flags; configurable segment size; changed geometry invalidates old segments; inactive/unknown routes stay unavailable.
- Source scripts: correctly orient connected OSM ways, respect explicit member direction, ignore platform ways and reject gaps/missing geometry.
- UI: loading/empty/error/retry/racing direction responses; show provenance warnings; distinguish segment colors from traffic. Browser QA uses intercepted synthetic fixture responses only, never imports those into the real database.
- Actual-data gate: import the three approved services in both directions, reimport without duplicates and visually review at least one actual pilot path and its segment boundaries on Leaflet. This remains pending while source geometry/stops are missing; passing fixture tests does not satisfy it.
