# Decisions

## D001 — Reuse the installed stack (Phase 0)

The team explicitly approved reusing `myapp` as `backend` and allowing installed versions where compatible. Retain Laravel 12 instead of downgrading to the requested Laravel 11; use local PHP 8.2 and Python 3.12 instead of requiring PHP 8.3 and Python 3.11. Keep dependency constraints compatible with those local runtimes. CI may also verify PHP 8.3.

Reason: preserve the existing starter and make local setup practical without replacing system runtimes. All later Python dependencies must be checked for Python 3.12 compatibility.

## D002 — Local development only (Phase 0)

No external deployment, paid service, account registration or production tracking in this phase. SQLite is the development database as permitted by the prompt.

## D003 — Direction identities and source provenance (Phase 1)

One route row represents one travel direction; its `code` is unique. Added stop `code` for stable deduplication, route `length_m`, route/stop verification and source metadata, and route-stop `offset_m` to audit projection quality. Sequence, rather than stop ID, identifies a route-stop occurrence so future loops can revisit a stop. Geometry changes remove stale segments; unchanged imports and segment rebuilds preserve their IDs. Source files omitted from a later import are not implicitly deleted from the database.

Reason: imports need stable keys and explicit evidence quality. These extend the starting model without implementing later-phase vehicle ingestion.

## D004 — Retain the supplied Monumento terminal (Phase 1)

The team explicitly chose Malabon–Monumento instead of silently extending to the Malabon–MCU service described in the OSM wiki for T104. Preserve the supplied terminals and flag the code mismatch. The two services are not automatically interchangeable.

## D005 — Missing pilot data keeps Phase 1 incomplete

The successful Overpass query did not supply the exact pilot directions. Other requests failed or returned different services. No route was fabricated, inferred by shortest-path routing or copied blindly from historical GTFS. The importer, APIs and review page can be implemented/tested independently; the actual-data and visual-review gates remain pending until matching OSM data or the team's manual traces/stops are supplied. This is an unresolved requirement, not a completed-phase exception.

The stop offset limit defaults to 100 m and segment length to 250 m in `config/commutelive.php`. The offset check catches visibly off-corridor input while allowing separately mapped roadside platforms; it does not establish legal/safe boarding locations. It must be tuned using local stop verification.

## D006 — Isolate HTTP smoke ports (Phase 1)

The HTTP smoke check now allocates separate free local ports and gives Vite its backend proxy target through the server-only `COMMUTELIVE_API_PROXY_TARGET` environment variable. Ordinary dev retains ports 8000/5173. Reason: the first full-suite run encountered an already-running dev server; tests should not stop it or accidentally verify a different process.

## D007 — Phase 2 synthetic demonstration while pilot data remains pending

The team requested Phase 2 and explicitly approved adding a separate synthetic demo dataset. Implement the backend against isolated fixtures and demonstrate the real HTTP loop using a clearly named synthetic line; keep Phase 1's real-route acceptance gates open. Demo seeding never writes pilot input files or asserts that the fictional line is a real service. This authorization permits the Phase 2 synthetic demonstration, not a claim of real-route correctness.

## D008 — Ingestion and consent behavior

Public registration permits commuter/driver roles only; it cannot create admins, simulation identities or vehicle assignments. Admin approval workflows remain in Phase 8. Driver writes require role and vehicle/trip ownership. Trip start requires accepted tracking consent and the current notice version; trip end and logout hide live tracking. Queued samples recorded inside that consented time window can upload after trip end without restoring live visibility.

Each ping is evaluated independently in a bounded batch. Accuracy failures are rejected, off-route positions are flagged (offset retained; route distance/segment omitted), identical UUID retries are duplicates, and UUID reuse for different content is rejected. Simulation status comes from the trip/vehicle/route, never client trust. Client timestamps must be ISO 8601 with an explicit timezone and lie within the trip/retention window with only a configurable small future-clock allowance. Public position ordering uses recorded time, not upload order.

Added trip driver/route snapshots and nullable unique active-driver/vehicle keys for consistency/concurrency; ping payload hashes and quality offsets/flags for audit; status trip identity to prevent occupancy carryover; source/simulation markers on synthetic domain records. Geometry and segment layout cannot change once a route has trip history; create a new direction/version code for a real change. Microscopic numerical round-trip differences are tolerated.

## D009 — Phase 3 uses an explicitly approved synthetic geometry demo

The team approved demonstrating Phase 3 on the separately labeled fictional route while keeping real-route validation open. By default provisioning selects active real directions; use an explicit synthetic-route option for the demo. The engine reads the route/stops/segments APIs and can run against verified real directions once supplied. No road geometry is guessed or silently substituted.

## D010 — Deterministic simulation clock and bounded real transport

Physics, arrivals, GPS noise, dropouts and occupancy use seeded per-vehicle random streams and a fixed, configurable simulation epoch. Speedup changes wall pacing, not the physical timeline. `arrivals.csv` and `samples.csv` contain simulation times/labels/codes and are reproducible for the same seed, inputs and runtime; database IDs, run-specific transport UUID namespaces and wall timestamps do not enter them. API pings carry actual UTC recording times because the real ingestion API rejects future/outside-trip samples. `transport.jsonl` links each sample's virtual elapsed time to its API recording timestamp; at speedup other than 1, evaluation must use that mapping rather than compare raw clocks directly. Network capacity can reduce achieved playback speed, but cannot skip physics ticks or alter truth.

The simulator currently uses the Python standard library and our own spherical geometry implementation, matching the Laravel distance convention. Shapely/pyproj/requests/pandas are deferred until analysis/import needs them; this avoids a second distance metric and Windows native-package setup for the core CLI. Defaults/thresholds live in `config/commutelive.php` and are exported at provisioning. Scenario-specific experimental multipliers/windows/fleet limits live in the requested scenario JSON files.

## D011 — Simulator lifecycle and credentials

Provision one simulated driver per vehicle so existing ownership/active-trip/rate rules remain in force. Provisioning refuses active or non-simulator identities and keeps tokens only in ignored `.env`. Ordinary runs retain reusable tokens until expiry/re-provisioning; demo runs revoke them on exit. Queue retries reuse the same UUID/payload. A private current-trip endpoint recovers a lost start response; cleanup ends only owned simulated trips. The local emergency cleanup command targets only the simulator namespace and provides a fallback for abrupt process termination, including Windows child-process interruption. Real drivers and other demo vehicles are excluded.
