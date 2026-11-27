# CommuteLive progress

## Phase 0 — complete (2026-11-26)

Goal: a local monorepo with a React page that displays API OK from Laravel.

- Inspected the existing `myapp` Laravel starter; no custom application features were found.
- Moved `myapp` to `backend` and initialized the monorepo Git repository.
- The team approved reuse of Laravel 12 and compatible installed runtime versions.
- Verified PHP 8.2.12, Node 24.19.0, Python 3.12.10 and Flutter 3.47.6 / Dart 3.13.5.
- Sandbox execution began failing during environment refresh after repository initialization. Elevated execution can run; use it only as needed to continue workspace work.
- Added health JSON, a protected user endpoint, Sanctum token storage and tests of actual bearer-token authentication.
- Added React/Vite with health loading, success, failure, timeout and retry states. No mock vehicle or measurement data is displayed.
- Scaffolded Android-only Flutter 3.47.6 with an explicit Tracking OFF starter; no location permissions.
- Added a Python health client and local HTTP tests; created the virtual environment. Simulation libraries wait until a simulator phase needs them.
- Added Node-based shared scripts and a PHP 8.2/8.3 backend CI matrix. Setup succeeded locally.
- Full original-workspace run passed: 5 backend tests (9 assertions), 7 React tests, 1 Flutter widget test, 2 Python HTTP tests, Flutter analysis, web production build and HTTP smoke check.
- Installed Edge rendered the actual React page with API OK. Preview servers were stopped after verification.
- A clean clone under ignored `.runtime/fresh-clone` installed all dependencies, created its own key/database/venv and migrated all tables. Repeated setup preserved its `.env` byte for byte. The clone exposed a Composer content-hash mismatch; synchronized the lock metadata without changing package versions and validated it successfully.
- The sandbox-created repository triggered Git's different-owner check. A narrowly scoped user Git `safe.directory` entry now trusts only this project; no wildcard trust was added.
- The full suite passed in the clean checkout too: 15 tests total, Flutter analysis, web build and real HTTP integration. The clean checkout's React page also displayed API OK in headless Edge against its own newly created database and app key. Both servers were stopped afterward.

### Phase gate

- [x] Backend and Sanctum installed; health endpoint tested.
- [x] React/Vite health screen shows API OK against the running API.
- [x] Flutter Android starter scaffolded and tested.
- [x] Python simulator virtual environment created.
- [x] Setup, dev and test commands work from a fresh checkout.
- [x] Full suite passes.
- [x] Backend GitHub Actions workflow added.
- [x] README setup fits in fewer than ten steps.

### Running it

From the repository root: `npm run setup`, then `npm run dev`; open http://127.0.0.1:5173. On Windows, `npm.cmd` works if PowerShell blocks npm.ps1. README contains six setup steps. Phase 1 updated the smoke check to allocate separate ports so `npm test` can now run alongside dev.

### Limits and open questions

- GitHub Actions is configured but has not run remotely: no GitHub remote was supplied. Local backend checks passed with PHP 8.2; the PHP 8.3 CI matrix awaits a hosted run.
- Flutter analysis and widget tests passed. No Android emulator session or APK build was performed; the installed Android toolchain passed `flutter doctor`. Missing Chrome and Visual Studio entries concern Flutter web/Windows targets, outside this Android-only starter.
- PWA installation, maps, route imports, GPS collection, consent controls, ping purge and simulator scenarios belong to later phases. The simulator currently only checks API health. No location data is accepted or collected.
- The sandbox refresh error affected tool execution, not project commands. Workspace setup and all checks ran using reviewed elevated execution; the independent clean checkout passed without repository ownership warnings.
- No implementation blocker remains for Phase 0. Wait for the team to say **continue** before any Phase 1 work.

### Follow-up checklist review

Rechecked the repeated Phase 0 requirements against the existing files. No application changes were needed; the full-suite and browser results above remain the latest execution results. Updated the formerly blank pilot notes with the three routes supplied by the team in chat; see `data/README.md`. Those route details are unverified and have not been imported. Phase 1 remains unstarted.

## Phase 1 — in progress; real pilot geometry and stops pending

Goal: source-backed pilot directions, ordered stops and configurable segments in the database.

- Retained all three supplied pilot services. The team explicitly chose to keep Malabon–Monumento terminals and flag the T104/MCU discrepancy.
- Queried Overpass and cached 17 nearby transit relations. The successful query did not include exact matches for the supplied three services. A broader query returned HTTP 502; the public mirror timed out; a smaller refs query also returned HTTP 502. See `data/sources/README.md` for the evidence and reproducible commands.
- Checked historical OSM relations 11246064 and 11246065 directly. They currently have different route names/termini, so neither was silently relabeled as an approved pilot route.
- Inspected Sakay's GTFS README, file layout and custom DOTC license. It remains a historical hint, not an imported source of trusted geometry.
- Added spherical haversine, finite-polyline projection and segmentation helpers. Tests cover before/after endpoints, far points, repeated vertices, bends, antimeridian/high latitudes, zero-length routes and invalid coordinates.
- Added migrations/models, transactional `commutelive:import-routes`, idempotent `commutelive:build-segments`, and the three public route endpoints. Reimports retain route/stop/segment IDs; changed geometry invalidates old segments; malformed or off-route stops fail before writes.
- Added the Leaflet `/routes` review page with direction selection, provenance warnings, ordered stops, segment popups, optional OSM basemap and an honest empty-data state. Colors distinguish segment boundaries, not traffic.
- Added cached Overpass discovery and strict OSM exporter scripts. The exporter refuses mismatched identities/directions, disconnected ways and unnamed/missing stops. It does not reverse an outbound route to invent an inbound route.
- Added `data/pilot.json` with six pending directions and `data/stops.csv` with the required header. No fabricated geometry or boarding points have been imported. Local schema migrations passed.
- Final automated verification: **45 tests passed** (26 backend tests / 105 assertions, 12 React tests, 1 Flutter widget test, 2 simulator HTTP tests and 4 source-script tests), plus Flutter analysis, the production web build and real HTTP smoke checks for health/auth/routes/review-page serving.
- The first full-suite smoke attempt found an existing server on port 8000. Updated test startup to allocate separate API/web ports and pass the API target to Vite on the server side; the final full suite passed while the existing dev servers kept running.
- Edge browser QA at a 390 px viewport verified the actual empty database message. Browser-only intercepted synthetic responses exercised the real Leaflet renderer, two colored segments, stops, segment popup, basemap toggle and mobile overflow; no page errors remained. No synthetic data was imported. Browser QA caught an initialization-order bug, fixed by setting the view before vector attachment; a real-Leaflet StrictMode regression test now covers it.
- The real-data acceptance gate remains unmet. Default `commutelive:import-routes` correctly fails with “No route GeoJSON files found” rather than inserting guessed routes/stops. The dev database has the schema, not a completed pilot dataset.

### Phase gate

- [ ] All three actual pilot routes, both directions, have source-backed GeoJSON and stops loaded.
- [x] Import and segment building are idempotent in automated fixture tests.
- [x] Route list/detail/segment APIs return correct data in automated fixture tests.
- [ ] A real approved pilot direction and its segments have been visually checked on Leaflet.
- [x] Geometry tests including required edge cases pass.
- [x] Full repository suite passes with the final Phase 1 infrastructure implementation.

### Required input

Asked the team for exact matching OSM relation IDs or manually traced travel directions and stop coordinates. Supplied road names and route names do not uniquely define the path, one-way return loops, stop order or boarding locations. Keep the phase incomplete until source data resolves this gap. Do not begin Phase 2.

The team subsequently supplied wiki road sequences for T104 and reference services T115/T185, and noted that T105/T107 have no road sequence there. Recorded these as tracing leads in `data/sources/README.md`; they do not supply exact coordinates or change the chosen services. Retain the earlier explicit Monumento-terminal decision.

### Review and next step

Use `npm.cmd run dev` and open http://127.0.0.1:5173/routes (or open that URL in the already-running development server). The default view is an honest empty-data state. Follow `data/README.md` to supply the six direction Features and actual stops. After importing and segmenting them, repeat idempotency/API checks against the actual dataset and visually check a real approved pilot route before marking Phase 1 complete. Phase 2 has not begun.

## Phase 2 — complete for the approved synthetic demonstration (2026-11-26)

The team explicitly requested Phase 2 while the Phase 1 real-data gate remains open, and approved a separate synthetic demo dataset. Phase 1 is not marked complete. Phase 2 tests and demonstration must distinguish synthetic evidence from real-route validation.

- Added all remaining starting-model tables/models/factories, plus user roles, explicit consent records on trips, stable active-trip constraints, simulation flags and ping quality/deduplication fields.
- Added Sanctum register/login/logout, driver role middleware, assigned-vehicle discovery, consented trip start/end, batch pings, occupancy updates, driver incidents and public live feeds.
- GPS batches return per-row accepted/flagged/rejected/duplicate outcomes. Bad accuracy is rejected; off-route points are stored as flagged diagnostics with no segment assignment and excluded from public positions. UUID payload conflicts cannot overwrite existing data. Matching uses the trip's route; history prevents geometry/layout mutation.
- Public vehicles use the latest matched ping by recorded time, filter stale data after 60 seconds by default, and expose no driver name/email. Ended trips stay hidden even when queued pings recorded during the trip arrive afterward. Occupancy expires independently; incidents expire from public responses.
- Added daily scheduled `commutelive:purge-pings` with a dry-run and 30-day default. Actual scheduler execution must be configured by the operator.
- Added idempotent local-only demo seeding: 1 synthetic admin, 2 synthetic drivers, 1 synthetic commuter, 2 simulated vehicles and a separately named fictional test line. All demo data is marked simulated. Generated credentials stay in ignored `.env`; none are committed or printed.
- Real HTTP demonstration passed twice, including repeat seeding after existing trip history. Each run logged in, consented to tracking ON, accepted one ping, identified its duplicate, updated occupancy, returned a matched simulated vehicle publicly, ended the trip, confirmed it was hidden and revoked its token. Cleanup audit: **0 open simulated trips and 0 demo tokens**, with 2 explicitly simulated ping rows retained for the normal retention window.
- Final full suite passed: **79 tests** (59 backend / 317 assertions, 13 React, 1 Flutter widget, 2 simulator HTTP, 4 source-script), Flutter analysis, web production build and real HTTP smoke checks. SQLite/PHP 8.2 were used locally; PHP 8.3 CI and MySQL load testing have not run here.
- Edge at a 390 px viewport verified the actual seeded demo's **Simulated data** badge, fictional-data warning, five segments/two stops, basemap toggle and no page errors/overflow. The long direction selector initially overflowed; constrained its minimum/maximum sizing and reran browser verification successfully.
- Verified `commutelive:purge-pings --dry-run` against the dev database: 0 expired rows; it deleted nothing. Verified the daily purge appears in `schedule:list`. Root dev does not start a scheduler automatically; operations are documented in `docs/API.md`.
- Added a demo collision safeguard so reserved demo stop/vehicle identities cannot overwrite non-demo data. Passwords/tokens remain outside tracked files. The team's existing development servers were left running; test/demo-owned servers stopped.

### Phase gate

- [x] Feature tests cover ingestion, duplicates, accuracy, off-route flags, auth and role rules.
- [x] A real HTTP sample batch creates a visible, explicitly simulated vehicle and cleans up tracking afterward.
- [x] Ping purge exists and passes boundary/configuration tests.
- [x] Full repository suite passes.
- [x] Demo simulation label checked in the browser.

### Run and review

`npm.cmd run demo:ingestion` holds the synthetic trip ON for 15 seconds and prints its live JSON/review URLs before cleanup. For a fast repeat use `npm.cmd run demo:ingestion -- --hold-seconds 0`. For persistent ordinary local development, `npm.cmd run dev` serves http://127.0.0.1:5173/routes with the seeded demo line visibly labeled. Request/response/retention details are in `docs/API.md`.

### Remaining limits and questions

Phase 1's actual pilot geometry/stops still need source data and visual validation. The synthetic line does not resolve that gate or provide measured traffic/ETA accuracy. Real Flutter tracking, permissions and persistent notifications remain in the driver-app phase. No field GPS collection or MySQL concurrent load test was performed. Configure the scheduler to enforce daily retention in any running deployment. Wait for **continue** before Phase 3.

## Phase 3 — complete on approved synthetic demo (2026-11-26)

The team explicitly approved using the separate fictional route while retaining Phase 1's real-route validation gap.

- Added Python CLI, pure seeded motion, spherical interpolation compatible with the backend distance convention, per-direction headways, configurable speed/hour/segment factors, stop approaches/dwell, noise, dropouts and occupancy.
- Added normal/rush_hour/incident/sparse scenario JSON. The incident acts only on a chosen direction/segment during its configured simulation-time window; these are experimental assumptions, not measured conditions.
- Added simulated-only local provisioning that stores tokens in ignored `simulator/.env`. Added private active-trip lookup to recover a lost start response, and a scoped emergency stop/revoke command that leaves other vehicles/drivers untouched.
- Added per-driver nonblocking batched upload workers, bounded retries/outboxes, actual UTC recording timestamps, deterministic simulation-clock arrivals/sample CSVs and transport clock-link logs. Credentials never enter output artifacts.
- Added a real HTTP wrapper that polls changing matched positions and verifies cleanup. Initial normal runs showed all three simulated vehicles moving through the public endpoint; synchronous transport was replaced with workers to reduce accelerated-playback lag. A 360-second synthetic run reached downstream stops and cleaned up tracking/tokens.
- Final suite passed: **98 tests** (64 backend / 349 assertions, 13 React, 1 Flutter widget, 16 simulator Python and 4 source-script tests), plus Flutter analysis, web build and HTTP smoke checks. Added a startup-lock provisioning guard and regression test before the final run. The Phase 2 ingestion demo also passed after extracting shared setup.
- Actual normal HTTP demonstration with **10 vehicles**, seed 42, horizon 720 simulated seconds: all 10 were observed moving in public JSON; **515 pings accepted**, 4 intentional dropouts, 18 arrival events, 8 vehicles reached their final dwell/completion before the horizon. The other two were stopped/hidden correctly at the horizon. No records were falsely reported as complete arrivals.
- The ten-vehicle run requested speedup 60 but took about 82.36 wall seconds including driver lifecycle/cleanup on the local server. This is not a claim of achieving 60× throughput; transport/server capacity remains the limiting factor. Truth remains deterministic.
- Compared the actual online run with offline replays at speedups 1 and 60: **both arrivals.csv and samples.csv matched byte for byte**. Arrival SHA-256: `3492f0286d9daa15153b6b8acdd3d0745661e2905a3f18c0dee8a862502e6cef`; sample SHA-256: `3d55222ed42f66822e69452a7ce270c3683bb2c65693c63240fc61a87e6d274b`.
- Final incident HTTP demo (3 vehicles, speedup 10) exposed about **0.35–0.38 kph** on the chosen segment in public JSON. Offline normal/incident comparison on the same snapshot produced mean affected-segment speeds about 18.26 vs 0.36 kph and arrival delays about 96.51 / 61.42 / 0 simulated seconds. These are synthetic scenario results, not field measurements.
- Ran the remaining scenario CLIs offline: sparse reduced the ten-vehicle snapshot to 3 vehicles and logged 6 arrivals; rush-hour ran 10 vehicles with 14 arrivals within its selected horizon. All four scenario definitions validate.
- Final audit after demonstration: **0 open simulator trips, 0 simulator tokens and 0 simulator pings mislabeled as real**. Generated credentials, lock files, arrival/sample/transport logs and run metadata are excluded from Git.

### Phase gate

- [x] Actual HTTP run shows changing vehicle positions.
- [x] Same seed/data/settings produce byte-identical arrival CSVs.
- [x] Incident produces an observable chosen-segment slowdown.
- [x] All emitted/stored simulated records are flagged.
- [x] Full repository suite passes.

Real pilot geometry remains unverified/unimported; the simulator consumes stored route geometry and never invents missing road paths. Synthetic-demo authorization does not complete Phase 1.

### Run and review

From root: `npm.cmd run demo:simulator`, or `npm.cmd run demo:simulator -- --scenario incident --speedup 10`. The full direct Python CLI/provisioning and byte-replay instructions are in `simulator/README.md`. Generated ground truth is `simulator/output/arrivals.csv`; `compare_scenarios.py` produces a reproducible synthetic slowdown report. Root demos leave tracking OFF and revoke their pool credentials.

### Remaining limits / next phase

Real pilot field validation still requires Phase 1's missing geometry/stops. Offline replay is deterministic for fixed inputs/runtime; wall-clock metadata and transport are deliberately not. Use the transport clock links for accelerated ETA evaluation, not raw subtraction of virtual arrivals from wall timestamps. The model has no car-following/physical overtaking, creates one one-way trip per vehicle and uses synthetic speed/dwell/load assumptions. Browser vehicle markers, measured ETA/traffic engines and field testing remain later phases. Phase 4 has not started; wait for **continue**.


## Phase 4 - Traffic and ETA engines (complete on approved synthetic scope)

Goal: turn matched pings into source-labeled segment speeds, traffic states and logged stop ETAs. Keep real pilot validation open and demonstrate on explicitly synthetic fixtures.

Implemented trailing-window speed aggregation, daily historical baselines, source-isolated traffic API, blended partial-segment ETA integration, intermediate-stop dwell, trip-scoped GPS arrival reconciliation and logged public arrivals. Added the 30-second scheduler registration and development worker. First 14 backend tests pass (70 assertions); first four-scenario causal replay passes countdown and red-within-120s gates (red after 60s). Full-suite/notebook acceptance still pending. Early cold-start results improve MAE in normal/rush/incident but regress in sparse; no tuning or real-road claims.


Phase 4 interim verification: 17 new backend tests now cover pure math, ingest-to-arrival reconciliation, partition/history isolation and returned-only logs. Four-scenario evaluation and actual notebook execution pass. Exact simulator truth gives cold-start MAE (blend/fixed, seconds): normal 29.37/33.06, rush 143.56/174.11, incident 33.70/41.73, sparse 32.92/26.65. Sparse regression is retained/reported. Incident red delay is 60 simulated seconds. Real HTTP demo passed with red mode-default traffic and ETA 123 -> 68 seconds, matched arrival and end/logout cleanup. No field accuracy claim. Full suite and clean-checkout notebook verification remain pending.


### Final Phase 4 verification

- **117 tests passed**: 81 backend (437 assertions), 13 React, 1 Flutter widget, 16 simulator, 4 source-script and 2 metrics tests. Web production build, Flutter analysis, notebook execution and real HTTP smoke all passed.
- Four-scenario notebook executes from a fresh Git archive without backend/simulator environment files, SQLite database or prior output. Only installed Composer/Python dependencies were reused. It regenerates its own fictional fixture, exact simulator truth and predictions in disposable in-memory SQLite. Clean-checkout results match the main workspace.
- Incident segment 3 turns red **60 simulated seconds** after incident start. At that timestamp, the same segment in the identical-seed normal control is amber with usable data; the strengthened control gate passes. Normal ETAs count down. All 132 normal predictions were matched to GPS-derived arrival records, while evaluation metrics independently use exact simulator ground truth.
- Real HTTP demo passed twice: red traffic, `mode_free_flow_default`, fresh seats occupancy, logged ETA **123 -> 68 seconds**, reached-stop hiding, trip end and logout. Its deliberate position jumps are API smoke only, excluded from accuracy evaluation.
- Cleanup audit: **0 open ETA-demo trips, 0 remaining driver tokens, 0 unflagged demo pings**, 4 predictions filled with approximate GPS arrival source/time. No real driver or pilot import was changed.
- Notebook packages installed successfully after retrying one transient package-download timeout; `pip check` reports no broken requirements. Installed direct notebook package license metadata was checked (BSD 3-Clause).

Seed 42 cold-start synthetic results (N is paired polling predictions, not independent trips):

| Scenario | N | Blend MAE (s) | Fixed MAE (s) | Blend median error (s) | Blend within +/-60 s |
| --- | ---: | ---: | ---: | ---: | ---: |
| normal | 132 | 29.37 | 33.06 | 23.85 | 87.12% |
| rush_hour | 286 | 143.56 | 174.11 | 113.58 | 30.77% |
| incident | 140 | 33.70 | 41.73 | 14.67 | 81.43% |
| sparse | 76 | 32.92 | 26.65 | 26.91 | 86.84% |

The notebook prints median and within-60 metrics for both methods, all flags and censored counts. This run has zero censored predictions. Sparse MAE regresses by 6.27 seconds; rush-hour estimates remain inaccurate despite relative improvement. These are measured simulator experiment results, not real road measurements or general performance guarantees. No tuning was done to conceal poor scenarios.

### Phase gate

- [x] Scheduled 30-second speed aggregation and daily historical baselines, with configurable windows/thresholds and explicit mode-default fallback.
- [x] Public traffic and stop arrivals APIs, source isolation, occupancy/freshness, logged returned predictions and trip-scoped approximate arrival reconciliation.
- [x] Incident red within one or two simulated minutes, confirmed against the normal control.
- [x] Sensible approaching-vehicle ETA countdown observed through the API and simulator replay.
- [x] Hand-calculated averaging, ratio, ETA and metric tests pass, including edge cases.
- [x] Notebook runs without prior local data and prints across-scenario comparison table.
- [x] Full repository suite passes.

### Run and remaining questions

From root: `npm.cmd run demo:eta` for authenticated HTTP demonstration; `npm.cmd run evaluate:eta` for the notebook; `npm.cmd run dev` for web/API plus scheduler. Outputs are under ignored `analysis/output/`; instructions and limitations are in `analysis/README.md` and `docs/TRAFFIC_ETA.md`.

Real pilot geometry/stops and field validation remain pending from Phase 1. Historical baselines and confidence need calibration with real consented data. Current aggregation weights pings, includes stop dwell, and may overstate congestion near stops; dwell allowance can double-count some delay. Confidence labels are heuristics. GPS-derived actual arrivals are approximate; large gaps remain unmatched. Repeated visits to the same stop ID within one trip require a future visit/sequence identity extension before loop services are evaluated. The approved synthetic directions have no repeated visits. No new user input is needed for the completed synthetic Phase 4 gates. Phase 5 has not started; stop and wait for **continue**.


## Phase 5 - Commuter web app (complete on approved synthetic scope)

Goal: mobile-first commuter map with live vehicles/traffic/arrivals, local nearest-stop lookup, accessibility, offline handling and installable PWA. Scope follows the Phase 5 checklist in the local build brief. Preserve explicit synthetic provenance and pending Phase 1 real geometry validation.

Implemented the commuter home screen, stable Leaflet map and animated received positions; traffic segments from Phase 4; route filter, stops, arrivals, occupancy/age/confidence vehicle card; local one-shot geolocation and clear-location control; verbal legends, keyboard alternatives, 44px controls, reduced motion; recoverable errors and expiring data. Public client settings share backend thresholds. Retained diagnostics at `/status` and source review at `/routes`. Production PWA caches only shell/static assets, with explicit static route snapshots for offline reload and a user-triggered version update.

Initial checks: 26 React/component/hook tests pass, one backend config test passes, PWA build succeeds, and all three 360px Playwright tests pass (movement/countdown/filter/location privacy, API failure/offline expiry/reload, install manifest/icons and permission denial). Actual simulator + real API + browser demo passed with two simulated drivers; it showed changing markers, colored traffic, countdown ETA, vehicle card and no overflow/page errors. It killed only its owned API, retained the map and displayed friendly error/paused estimates; fallback ended two trips and revoked simulator tokens. Full-suite gate and final documentation are still pending.


### Final Phase 5 verification

- **135 tests passed**: 82 backend (441 assertions), 27 React/component/hook, 3 Playwright browser, 1 Flutter widget, 16 simulator, 4 source-script and 2 analysis metrics tests. Production PWA build, Flutter analysis, Phase 4 notebook and actual HTTP smoke also passed.
- Production browser checks use 360x800 with real Leaflet and installed Edge: marker positions change after polls, stop arrivals count down, route filtering works, selected vehicle details show occupancy/freshness, and page width stays within the viewport. Component tests cover vehicle card, arrivals and verbal legend; race/freshness/offline tests cover the underlying hook and helpers.
- Location privacy/denial checks pass. API requests and storage contain no user-location coordinates; denied permission leaves manual stop selection usable. Location clears on reload. Static route coordinates remain cached because they are public map data, not a commuter trace.
- Offline reload serves the installed shell and labeled static route geometry, with no live markers or invented ETA. Traffic becomes grey; positions expire locally after the configured cutoff. Service-worker cache audit found no API or OSM tile entries. Manifest and 192/512px icons respond successfully; update flow is user-triggered.
- Actual real-API/simulator browser check passed with two simulated drivers at speedup 4 and 360x800. It observed changing vehicle markers, sampled traffic colors and countdown ETA (first display 2m 01s), opened a vehicle card, then intentionally stopped only its owned API. The app retained the map, showed a friendly error and paused estimates. No page errors or horizontal overflow were observed. This demonstrates behavior, not accelerated ETA accuracy.
- Post-demo database audit: **0 open simulator trips, 0 simulator tokens, 0 simulator pings mislabeled as real**. Other drivers/servers and pilot imports were untouched. Real demo screenshots and JSON evidence are in ignored `.runtime/phase5/`; fixture/browser outputs are in ignored `commuter-web/test-results/`.
- Initial full-suite attempt found the existing Windows batch-argument guard rejecting the colon in `test:e2e`. The root runner now invokes Playwright's Node CLI directly, retaining the guard. The subsequent full run passed. Added a regression for one-shot config recovery after an API restart. A copy-only clarification after the run explains partial-feed failures without implying that all still-fresh estimates must pause.

### Phase gate

- [x] Phone-sized browser shows moving simulator vehicles, sampled traffic colors and countdown arrivals for a chosen stop.
- [x] Actual demo API shutdown shows a friendly error and retained map, rather than a blank page.
- [x] Vehicle card, arrivals list and legend have component coverage; browser smoke checks pass.
- [x] Local one-shot location, route filters, verbal legends, simulation badges, keyboard alternatives and reduced motion are implemented.
- [x] Production install manifest/service worker and offline shell/static-route reload are verified in a real browser.
- [x] Full repository suite passes.

### Run and remaining questions

From root: `npm.cmd run dev` opens the commuter app at http://127.0.0.1:5173 (diagnostics/API OK at `/status`, source review at `/routes`). `npm.cmd run demo:commuter` performs the self-contained simulator/browser acceptance run and cleans up. To install, keep the API running and use `npm run build` then `npm run preview` from `commuter-web`; localhost preview is http://127.0.0.1:4173. See `commuter-web/README.md` for interactive simulator and optional Android USB forwarding instructions.

Real pilot geometry/stops and field validation remain pending from Phase 1. Physical Android PWA installation has not been tested; browser install prerequisites/offline behavior are verified. HTTPS/localhost is needed for geolocation/service workers, so plain phone-to-LAN HTTP cannot promise those features. Reproducible browser QA blocks external tiles rather than claiming OSM availability. Traffic on real geometry remains real-only by default even if labeled simulated vehicles are visible; no silent source mixing is introduced. Phase 4's sparse/rush-hour ETA limitations and uncalibrated confidence still apply. No additional input is needed for the completed Phase 5 gates. Phase 6 has not started; stop and wait for **continue**.
