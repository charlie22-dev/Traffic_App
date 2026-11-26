# References and licensing

## Phase 0

- Laravel starter and framework: MIT; existing source retained. Framework license is included in the installed package (`backend/vendor/laravel/framework/LICENSE.md`). [Laravel source](https://github.com/laravel/laravel).
- [Laravel Sanctum documentation](https://laravel.com/docs/12.x/sanctum): API token setup reference. No third-party application code copied.
- Sanctum 4.5.0: MIT, verified in `backend/vendor/laravel/sanctum/LICENSE.md`. The token-table migration follows Sanctum's schema so the standard token model works.
- React 19.3.1: MIT, verified in `commuter-web/node_modules/react/LICENSE`.
- Vite 8.3.8: MIT, verified in `commuter-web/node_modules/vite/LICENSE.md`.
- Flutter 3.47.6 / Dart 3.13.5: generated the Android starter with `flutter create --platforms=android --empty`; BSD-style Flutter license verified in the installed SDK's `LICENSE`. No application code copied from the inspiration projects.

## Later-phase project review

The projects listed in the build brief remain candidates for review. Before relying on any of them, inspect the README, structure, tests, license and data provenance; record the exact revision, idea used and license here. No route data or code from these projects has been imported in Phase 0.

## Phase 1

- [OSM data license](https://www.openstreetmap.org/copyright): ODbL 1.0; cached responses retain copyright/license metadata. Derived route exports must retain OSM attribution and ODbL metadata. No OSM wiki prose copied.
- [OSM route inventory](https://wiki.openstreetmap.org/wiki/Metro_Manila/Jeepney_and_UV_Express_routes): checked the supplied names/refs; T104 is described as Malabon–MCU, formerly Malabon–Monumento via Letre. This is mapping evidence, not a guarantee of present service.
- [Overpass API](https://wiki.openstreetmap.org/wiki/Overpass_API): scripted bounded, cached relation queries. Exact query and successful source snapshot are in `data/sources/`.
- [Leaflet 1.9.4](https://leafletjs.com/reference-1.9.4.html): map/GeoJSON APIs. BSD 2-Clause license verified in `commuter-web/node_modules/leaflet/LICENSE`; no code from the reference commute applications copied.
- [OSM tile policy](https://operations.osmfoundation.org/policies/tiles/): standard HTTPS tile URL and visible attribution; browser caching is retained. No bulk downloads, tile prefetching or geocoding. Basemap can be turned off; route geometry remains viewable.
- [Sakay GTFS repository](https://github.com/sakayph/gtfs), [README](https://github.com/sakayph/gtfs/blob/master/README.md) and [license](https://github.com/sakayph/gtfs/blob/master/LICENSE.md): inspected historical feed structure and custom DOTC terms, which are not an MIT code license. No shapes/stops/code were imported or mixed into OSM-derived data. The feed is a historical hint only.
- Direct OSM API snapshots for relations [11246064](https://www.openstreetmap.org/relation/11246064) and [11246065](https://www.openstreetmap.org/relation/11246065): rejected as substitutes after inspecting their current names and termini. See source notes.
