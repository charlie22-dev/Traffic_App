# Test plan

Phase 5 adds vehicle-card, arrivals-list and verbal-legend tests; hand-checked local nearest-stop distances, vehicle/traffic expiry, aborting late selection responses, static-only offline cache and automatic bootstrap retry. Three production-build Playwright tests run at 360x800 with real Leaflet/Edge (or Chromium): moving markers and countdown, route/stop/vehicle selection, local-only location and denial, API failure/paused estimates/position expiry, service-worker offline reload, static geometry restoration, manifest/icons and no page overflow. External tiles are intentionally blocked in repeatable QA, so these do not certify OSM availability. Root `demo:commuter` separately uses actual simulator drivers/ingestion/public APIs and kills only its owned API to verify friendly retained UI; cleanup ends/revokes the simulator pool. Physical Android install and real route coverage remain manual/pending.

Phase 4 acceptance: hand-calculated math/metrics; sample-window, simulation partition and baseline freshness/day coverage; ETA countdown/occupancy/lifecycle; returned-only prediction logging; out-of-order/duplicate batch arrival matching; long-gap rejection; scheduler frequency. Full suite also executes `analysis/eta_eval.ipynb` from generated fixtures in disposable in-memory SQLite. It prints paired four-scenario comparison metrics and verifies red within 120 simulated seconds and normal countdown. `npm run demo:eta` checks the real authenticated HTTP flow and OFF/token cleanup. The scripted HTTP position jumps are excluded from accuracy evaluation. See [traffic/ETA details](TRAFFIC_ETA.md) and [evaluation methodology](../analysis/README.md).

Phase 0 must validate the public health JSON contract, rejection of unauthenticated API access, Sanctum bearer-token authentication, React loading/success/failure/retry behavior, the Flutter starter and Python environment. The full test command must also compile the web app.

Before marking Phase 0 complete, run setup against a clean checkout with ignored dependencies and environment files absent, then launch dev and check the API through Vite's proxy. Record actual results in PROGRESS.md.

Later phases add simulation-label, consent, retention, GPS validation, route matching and measured ETA-accuracy tests alongside each feature.

## Phase 1

- Geometry: hand-calculated equatorial distances; projection before/after the path; far points; bends; duplicate/single vertices; antimeridian and high latitudes; invalid/empty/antipodal cases; segment coverage/continuity and preserved road vertices.
- Import/API: isolated temporary fixture inputs with in-memory SQLite; stable identities after repeated import/build; transaction safety; off-route and out-of-order stops; malformed/missing inputs; source flags; configurable segment size; changed geometry invalidates old segments; inactive/unknown routes stay unavailable.
- Source scripts: correctly orient connected OSM ways, respect explicit member direction, ignore platform ways and reject gaps/missing geometry.
- UI: loading/empty/error/retry/racing direction responses; show provenance warnings; distinguish segment colors from traffic. Browser QA uses intercepted synthetic fixture responses only, never imports those into the real database.
- Actual-data gate: import the three approved services in both directions, reimport without duplicates and visually review at least one actual pilot path and its segment boundaries on Leaflet. This remains pending while source geometry/stops are missing; passing fixture tests does not satisfy it.

## Phase 2

- Real Sanctum tokens: registration/privilege restrictions, hashing, login/logout, expiry/revocation and login throttling.
- Driver authorization: role rules, vehicle/trip ownership, explicit current-version consent, one active driver/vehicle trip, idempotent end and immediate hiding at end/logout.
- Ingestion: matched batches, stable UUID deduplication and conflicts, normalized UUID/numeric/timezone retries, quality checks, off-route flags, immutable route history, bounded/rate-limited batches, client timestamp limits and per-row outcomes.
- Visibility/privacy: latest recording wins over delayed uploads, exact freshness boundary, no driver identity on public responses, simulation flags, occupancy expiry/trip isolation, incident identity/expiry, queued uploads after trip end stay hidden.
- Retention/demo: purge dry-run, exact cutoff and invalid settings; daily schedule registration; idempotent synthetic seeder and model factories. The real local HTTP demo must show a matched vehicle and then turn tracking OFF/clean up its token.
- Browser: actual seeded fictional line displays **Simulated data**, five segments and two stops at mobile width, with no page errors or horizontal page overflow. No physical device GPS is used.

## Phase 3

- Python geometry/physics: known distances and bends, exact headways/stop arrivals, configured dwell bounds/approach slowdown, mode/hour/segment factors, incident delay/recovery, sparse direction coverage, approximate 5 m noise, independent seed streams and intentional dropouts.
- Deterministic output: compare arrival/sample CSV bytes for repeated seeds and speedups; noisy GPS must not alter true arrivals. Include runtime/input/engine fingerprints for audit. Wall-clock transport is deliberately separate.
- HTTP/lifecycle: retry identical batches after transient errors, recover lost trip-start responses, refuse to take over an existing trip, provision only simulated identities, reject missing consent/synthetic-geometry authorization, and restrict fallback cleanup to the simulator pool.
- Live acceptance: use the real API with 3 and 10 simulated vehicles; record changing matched public positions and incident near-zero speeds; end trips/revoke demo tokens afterward. Verify every stored simulator ping is flagged. Keep real-route field validation open while Phase 1 data is absent.
- Run all four scenario CLIs; sparse must reduce the ten-vehicle fleet while preserving selected direction coverage. Compare normal/incident from one saved snapshot, not unrelated route datasets.
