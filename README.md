# CommuteLive

A free commute assistant for a small Philippine jeepney/bus pilot. Phase 0 is complete: React calls Laravel and displays **API OK**. Phase 1's importer, geometry tools, APIs and Leaflet review page are implemented, but the real pilot paths/stops are still awaiting source data. No GPS tracking, traffic measurements, vehicles or ETA claims exist yet.

## Local setup (6 steps)

1. Install free prerequisites: Git, PHP 8.2 or 8.3 with Composer 2 and `pdo_sqlite`, `mbstring`, `openssl`, `fileinfo`, `curl`, `dom`, `xml`; Node 22.12+ (Node 24 recommended); Python 3.11–3.13 with venv/pip; Flutter stable with Dart 3.13.5+. Keep their commands on PATH. Android Studio, its free Android SDK and a JDK are needed only to run/build the Android app. No service account or credit card is required.
2. Clone this repository and open a terminal at its root.
3. Run `npm run setup`. This installs locked dependencies, copies the backend environment template if needed, generates an app key once, migrates SQLite, resolves Flutter packages and creates `simulator/.venv`. Internet access is needed for dependency downloads. Existing local `.env` values are preserved; use `DB_CONNECTION=sqlite` for the default workflow.
4. Run `npm run dev` and open **http://127.0.0.1:5173**. The page should display **API OK**. The API is at http://127.0.0.1:8000/api/health. Ctrl+C stops both servers.
5. Run `npm test`. It runs backend tests, React tests/build, Flutter analysis/widget tests, Python tests and a real HTTP integration check. The integration check starts/stops its own servers on automatically allocated ports, so dev can remain running.
6. Optional: connect an Android device or start an emulator, then run `cd driver-app` and `flutter run`. The starter shows **Tracking OFF**. It requests no location permission. `flutter build apk --debug` creates a local development APK once the Android toolchain is configured.

On Windows PowerShell, use `npm.cmd` instead of `npm` if script execution policy blocks npm.ps1. Do not change system execution policy. On Linux/macOS, the Python command is `python3`; on Windows it is `python`.

## Repository

| Folder | Purpose |
| --- | --- |
| `backend` | Laravel 12, Sanctum bearer-token infrastructure, SQLite development database |
| `commuter-web` | React + Vite connection screen; mobile layout |
| `driver-app` | Android-only Flutter starter |
| `simulator` | Python environment and API health client |
| `analysis` | Future reproducible evaluation notebooks |
| `data` | Future verified pilot routes, stops and scenarios |
| `docs` | Progress, decisions, references, ERD and test plan |
| `scripts` | Shared setup, dev, test and smoke commands |

The team approved runtime deviations from the original brief; see [decisions](docs/DECISIONS.md). Laravel's original server-rendered welcome page and frontend files remain in `backend` as starter files; the commuter interface lives in `commuter-web` and requires no backend npm install. Vite proxies `/api` to Laravel locally, so no permissive CORS configuration or API secrets are embedded in browser code. Sanctum is tested using issued bearer tokens; login/registration and driver roles come later.

## Route data workflow (Phase 1 source data pending)

Open **http://127.0.0.1:5173/routes** while dev is running. With no actual routes imported, the page explains the missing source data. It does not draw a fabricated pilot. See [input format and pilot scope](data/README.md) and [source investigation](data/sources/README.md).

Once matching OSM relation IDs or manually traced GeoJSON and actual stop coordinates are supplied, put one Feature per direction in `data/routes/*.geojson` and its stops in `data/stops.csv`. Then run from `backend`:

```sh
php artisan commutelive:import-routes
php artisan commutelive:build-segments
```

Repeat both commands to reimport/rebuild without duplicates. Configure `SEGMENT_LENGTH_M` (default 250) and `STOP_MAX_OFFSET_M` (default 100) in `backend/.env`; defaults live in `backend/config/commutelive.php`. The last segment may be shorter. An invalid dataset fails before writes. Geometry changes remove old segments until the build command runs again. The full test command checks import/build idempotency with isolated test fixtures; that does not certify real route coverage.

Public endpoints: `GET /api/routes` (direction list, counts and source status), `GET /api/routes/{id}` (GeoJSON and ordered stops with along/offset distances in meters), and `GET /api/routes/{id}/segments` (ordered segments with LineString geometry). Missing or inactive routes return 404. Segment colors on the review page distinguish boundaries, not traffic. The OSM basemap is optional; geometry remains viewable with tiles turned off.

## Guardrails and next phase

No paid APIs or hosting. Keep secrets in ignored `.env` files; commit only `.env.example`. Do not commit local SQLite databases, APKs, dependency folders or virtual environments. Future simulated records must have `is_simulated = true`, with a visible **Simulated data** badge whenever shown. Future driver tracking requires explicit consent and a persistent ON indicator; commuter location stays on the device by default. Retention defaults to 30 days in `backend/config/commutelive.php`; implement and test a purge command before collecting any pings.

The team supplied three pilot routes: Malabon–Monumento via Letre, Monumento–Navotas via Letre, and Gasak–Recto via Dagat-Dagatan/C-3. Their supplied codes, terminals and coverage are recorded in [pilot data notes](data/README.md); route identities and geometry still need verification before import. Phase 0 does not implement the PWA, map, traffic/ETA/routing engines or GPS simulator. Work advances only after the current phase passes its checklist and the team says **continue**.

See [progress](docs/PROGRESS.md) for actual verification results and known issues. Backend CI runs on GitHub Actions for pushes and pull requests; GitHub applies its own free-tier quotas (public repositories have free standard hosted runners).
