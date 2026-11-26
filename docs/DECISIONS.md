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
