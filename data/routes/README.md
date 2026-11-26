# Route GeoJSON inputs

This folder contains no fabricated route geometry. Exact pilot directions are awaiting source-backed paths. The importer reads `*.geojson` only, so this README is not imported.

Use one GeoJSON Feature with a LineString per direction. GeoJSON coordinates are **[longitude, latitude]**. Do not simply reverse the outbound line: one-way streets and terminal loops may differ on the return trip.

Required Feature properties: `code` (stable unique direction code), `name`, `mode` (`jeepney` or `bus`), `direction`, `color` (hex), `verification_status` (`needs_review`, `osm_mapped` or `field_verified`), and `source_metadata` containing `source`, `license` and explanatory `notes`. Use `needs_review` for a trace whose route identity, terminal or boarding points need local confirmation. Record source URL, observation date and any modifications in source_metadata.

The format is documented fully in `../README.md`. A route needs at least two actual stops in `../stops.csv` before import. Do not insert arbitrary evenly spaced road points and call them stops.
