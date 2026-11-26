# Source evidence — Phase 1

No exact pilot paths/stops have been verified or imported. Cached OSM records are research evidence, not imported pilot routes. OSM data is © OpenStreetMap contributors, [ODbL 1.0](https://opendatacommons.org/licenses/odbl/1-0/); preserve that license/attribution when distributing derivatives.

- `osm-pilot-relations.json`: successful Overpass response; OSM base timestamp `2026-11-26T09:28:35Z`. Query is saved in `pilot-query.overpassql`. It returned 17 relations matching a bounded name search for Malabon/Navotas/Gasak/Monumento and bus/share_taxi/minibus route tags. None had the exact pilot service identities. Nearby services must not be substituted without confirmation.
- A broader legacy/name/ref query, now implemented in `../scripts/fetch_osm.py`, returned HTTP 502 from `overpass-api.de`; `overpass.kumi.systems` timed out. A smaller T104/T107/Dagat-Dagatan tags query also returned HTTP 502. These failures do not prove the routes are absent from all OSM data.
- `osm-relation-11246064.json`: direct OSM API relation version 40, timestamp `2026-09-12T23:23:24Z`. Current tags describe Hulong Duhat–Recto via Dagat-Dagatan, ref 3131, not the supplied Gasak service/terminus. Retained only as a lead for local review.
- `osm-relation-11246065.json`: direct OSM API relation version 24, timestamp `2026-08-31T10:23:19Z`. Current tags describe Pandacan–L. Guinto, ref 3193. Rejected; historical relation numbers are not reliable identifiers of a current service.

Retry bounded discovery manually with `python data/scripts/fetch_osm.py`; subsequent successful responses are cached as `osm-pilot-candidates.json`. `--refresh` explicitly fetches again; no tight retry loop exists. These scripts do not use Nominatim.

If a matching current relation ID is found, export one direction with `python data/scripts/export_osm.py --relation-id ID --code MON-NAV-OUT`. This caches a dedicated query/response, verifies identity/from/to tags, joins only exactly connected member ways in their existing order, and writes GeoJSON plus the relation's named platform/stop nodes to `stops.csv`. It fails rather than bridging gaps, guessing boarding points or relabeling a different service. Review all outputs before import.

Alternatively, trace each direction in geojson.io using local knowledge. Record source, license, date, unresolved road/terminal choices and actual stop names/coordinates in the documented input format. Outbound and inbound paths require separate verification.

## Road-sequence leads supplied by the team

The team supplied the following OSM-wiki road sequences as tracing references. These are textual leads, not GeoJSON or verified stop inventories. They do not replace the three selected pilot services.

- T104: Malabon City Hall, Rizal Avenue Extension, P. Aquino Avenue, Dagat-Dagatan Avenue, C4 Road, Samson Road, Monumento. Preserve the agreed Monumento endpoint and flag the MCU/code mismatch.
- T115 (reference only): Don Basilio Bautista Boulevard, L. Gutierez, M. Naval, A. Bonifacio, C. Arellano, Kalahi, General Luna, Governor Pascual Avenue, Araneta Avenue, Samson Road. Not added to the pilot.
- T185 (reference only): Don Basilio Bautista Boulevard, F. Navarrete, Flamenco, J.P. Rizal, Paliwas Road, Obando. Not added to the pilot.
- T105 and T107: listed without road sequences in the supplied wiki notes. Exact pilot T107 path, return direction and stops still require source data/local tracing.

Street sequences help constrain a trace, but do not specify the exact lane/way at divided roads, all connecting turns, terminal loops or legal boarding points. No shortest-road guess was substituted for those missing details.
