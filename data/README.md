# Pilot data

The team supplied these three pilot routes. Names, codes, terminal descriptions and coverage below are user-provided and remain unverified; no geometry has been imported.

| Route / signboard | Supplied code | Terminals | Cities covered |
| --- | --- | --- | --- |
| Malabon–Monumento via Letre | T104 | Malabon Bayan / Ospital ng Malabon area to Monumento (Samson Road / MacArthur Highway) | Malabon, Caloocan |
| Monumento–Navotas via Letre | T107 | Monumento to Navotas Bayan / Navotas Terminal | Caloocan, Malabon, Navotas |
| Gasak–Recto via Dagat-Dagatan and C-3 | Not supplied | Gasak / Tonsuya area (Don Basilio Bautista Boulevard / Letre) to Recto Avenue | Malabon, Caloocan, Manila |

Requested pilot corridors: P. Aquino Avenue, Dagat-Dagatan Avenue, General Luna Street and Letre Road. Verify which route directions actually traverse these roads; do not assume every route uses every corridor.

Before importing data, verify the route identities, terminals, intersections and both directions against sources or local observations. Record geometry sources, licenses and verification dates. Every future simulated record must carry `is_simulated = true`.

## Phase 1 input status

Phase 1 infrastructure is implemented, but exact source-backed pilot geometry and stops remain pending. The team confirmed retaining the supplied Malabon–Monumento terminal rather than extending it to MCU. The T104 mismatch is flagged in `pilot.json`. See `sources/README.md` for the cached OSM research; no nearby or historical service has been silently relabeled as a pilot.

Six direction codes are reserved in `pilot.json`: `MAL-MON-OUT`, `MAL-MON-RETURN`, `MON-NAV-OUT`, `MON-NAV-RETURN`, `GAS-REC-OUT`, `GAS-REC-RETURN`. Each direction needs its own actual path and stop sequence. `stops.csv` currently contains only its header; this is not a completed stop inventory.

Phase 2 additionally has a separately seeded `DEMO-SYNTHETIC-OUT` fictional line for the explicitly approved ingestion demo. It lives in the database, carries simulation flags, and does not populate or replace any of these pilot GeoJSON/CSV files. Its endpoints are synthetic test points, not boarding recommendations.

## GeoJSON format

Save a single GeoJSON Feature per direction under `routes/CODE.geojson`. The geometry must be a nonzero LineString in `[longitude, latitude]` order. For a geojson.io export, take the line Feature from its FeatureCollection and supply these properties:

| Property | Value |
| --- | --- |
| `code` | One of the direction codes above |
| `name` | Service name matching the selected pilot |
| `mode` | `jeepney` |
| `direction` | Human-readable travel direction from `pilot.json` |
| `color` | Six-digit hex color, e.g. the value in `pilot.json` |
| `verification_status` | `needs_review`, `osm_mapped` or `field_verified`; do not claim field verification without evidence |
| `source_metadata` | Object with `source`, `license`, `notes`; also record source URL/ID, observation date and unresolved choices |

No route file with invented coordinates is supplied as a default. An empty `routes` folder causes import to fail clearly. An OSM relation exporter is available in `scripts/export_osm.py`; it refuses unmatched names/termini, missing stops and disconnected ways instead of filling gaps.

## Stop CSV format

One combined file contains all directions, distinguished by `route_code`:

```csv
route_code,stop_code,name,lat,lng,sequence,source,verification_status
```

Use actual stop/platform/boarding-point coordinates, not invented 250 m intervals. `stop_code` is a stable identity (for example, the source OSM node ID prefixed with `osm-node-`). Reuse it only for the same physical stop, name, coordinates and provenance. Opposite roadside stops need separate identities. Sequence starts at 1, is contiguous per direction, and follows travel order. Quote names containing commas using standard CSV rules.

Each route requires at least two stops. Import projects them onto the polyline, keeps the original stop coordinates, stores cumulative meters and the perpendicular offset, and rejects offsets above the configured limit. It rejects reversed or ambiguous nonmonotonic stop order rather than silently sorting the input by proximity. At self-intersections/repeated coordinates, the geometry helper resolves equal-distance ties to the earliest occurrence; locally review ambiguous stop visits before importing loops.

Run the commands in the root README after source review. Source files removed from an input folder do not automatically delete existing routes or shared stops. Reimport updates the supplied direction keys; inactive route handling is available in the model/API.
