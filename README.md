# CommuteLive

A free commute assistant for a small Philippine jeepney/bus pilot. The backend supports driver authentication, consented trips, GPS batches, occupancy, incidents and live vehicle JSON. A seeded Python simulator drives explicitly **simulated** vehicles through that API. Real pilot geometry/stops remain pending from Phase 1; no field accuracy, traffic or ETA claims are made.

## Local setup (6 steps)

1. Install free prerequisites: Git, PHP 8.2 or 8.3 with Composer 2 and `pdo_sqlite`, `mbstring`, `openssl`, `fileinfo`, `curl`, `dom`, `xml`; Node 22.12+ (Node 24 recommended); Python 3.11–3.13 with venv/pip; Flutter stable with Dart 3.13.5+. Keep their commands on PATH. Android Studio, its free Android SDK and a JDK are needed only to run/build the Android app. No service account or credit card is required.
2. Clone this repository and open a terminal at its root.
3. Run `npm run setup`. This installs locked dependencies, copies the backend environment template if needed, generates an app key once, migrates SQLite, resolves Flutter packages and creates `simulator/.venv`. Internet access is needed for dependency downloads. Existing local `.env` values are preserved; use `DB_CONNECTION=sqlite` for the default workflow.
4. Run `npm run dev` and open **http://127.0.0.1:5173**. The page should display **API OK**. The API is at http://127.0.0.1:8000/api/health. This starts the API, frontend and scheduler; Ctrl+C stops those local processes.
5. Run `npm test`. It runs backend tests, React tests/build, Flutter analysis/widget tests, Python tests and a real HTTP integration check. The integration check starts/stops its own servers on automatically allocated ports, so dev can remain running.
6. Run `npm run demo:ingestion` for the synthetic driver-to-public API demo, or connect an Android device/emulator and run `cd driver-app`, then `flutter run` to view the starter. The Flutter starter still shows **Tracking OFF** and requests no GPS permission; real driver tracking UI arrives in a later phase.

On Windows PowerShell, use `npm.cmd` instead of `npm` if script execution policy blocks npm.ps1. Do not change system execution policy. On Linux/macOS, the Python command is `python3`; on Windows it is `python`.

## Repository

| Folder | Purpose |
| --- | --- |
| `backend` | Laravel 12, Sanctum bearer-token infrastructure, SQLite development database |
| `commuter-web` | React + Vite connection screen; mobile layout |
| `driver-app` | Android-only Flutter starter |
| `simulator` | Seeded motion, scenario CLI and exact synthetic arrival logs |
| `analysis` | Reproducible synthetic traffic/ETA evaluation notebook and replay |
| `data` | Future verified pilot routes, stops and scenarios |
| `docs` | Progress, decisions, references, ERD and test plan |
| `scripts` | Shared setup, dev, test and smoke commands |

The team approved runtime deviations from the original brief; see [decisions](docs/DECISIONS.md). Laravel's original server-rendered welcome page and frontend files remain in `backend` as starter files; the commuter interface lives in `commuter-web` and requires no backend npm install. Vite proxies `/api` to Laravel locally, so no permissive CORS configuration or API secrets are embedded in browser code. Sanctum is tested using issued bearer tokens; login/registration and driver roles are implemented.

## Route data workflow (Phase 1 source data pending)

Open **http://127.0.0.1:5173/routes** while dev is running. Before seeding/import it explains the missing data. After the explicit demo seed, it offers a clearly labeled synthetic test line with a **Simulated data** badge. See [input format and pilot scope](data/README.md) and [source investigation](data/sources/README.md) for the real routes still awaiting input.

Once matching OSM relation IDs or manually traced GeoJSON and actual stop coordinates are supplied, put one Feature per direction in `data/routes/*.geojson` and its stops in `data/stops.csv`. Then run from `backend`:

```sh
php artisan commutelive:import-routes
php artisan commutelive:build-segments
```

Repeat both commands to reimport/rebuild without duplicates. Configure `SEGMENT_LENGTH_M` (default 250) and `STOP_MAX_OFFSET_M` (default 100) in `backend/.env`; defaults live in `backend/config/commutelive.php`. The last segment may be shorter. An invalid dataset fails before writes. Geometry changes remove old segments until the build command runs again. The full test command checks import/build idempotency with isolated test fixtures; that does not certify real route coverage.

Public endpoints: `GET /api/routes` (direction list, counts and source status), `GET /api/routes/{id}` (GeoJSON and ordered stops with along/offset distances in meters), and `GET /api/routes/{id}/segments` (ordered segments with LineString geometry). Missing or inactive routes return 404. Segment colors on the review page distinguish boundaries, not traffic. The OSM basemap is optional; geometry remains viewable with tiles turned off.

## Driver ingestion demo

```powershell
npm.cmd run demo:ingestion
```

This local-only script generates a demo password in ignored `backend/.env` if absent, seeds 1 admin/2 drivers/1 commuter and 2 vehicles, starts its own servers on free ports, logs in, starts an explicitly consented synthetic trip, sends the same ping twice and verifies **one accepted / one duplicate**. It prints the simulated vehicle JSON and review URLs, holds tracking ON for 15 seconds, then ends the trip, verifies it disappears and revokes its token. Use `npm.cmd run demo:ingestion -- --hold-seconds 0` for a quick check or up to 60 seconds to inspect the printed URLs. No device GPS is used and no password/token is printed. Repeating the demo seed does not duplicate accounts/vehicles or alter pilot imports.

Full endpoint/request/error details are in [docs/API.md](docs/API.md). `GET /api/vehicles?route_id=` hides stale samples after 60 seconds and ended trips immediately. `GET /api/incidents` returns unexpired reports. Role and trip/vehicle ownership are enforced; simulation flags come from the server. Driver registration cannot create an admin or assign a vehicle.

Run `php artisan commutelive:purge-pings --dry-run` from `backend` to inspect expiry, and omit `--dry-run` to purge. Default retention is 30 days. Root `npm run dev` starts the scheduler; when running the API manually, also run `php artisan schedule:work` for periodic jobs.

## Moving simulator (Phase 3)

```powershell
npm.cmd run demo:simulator
npm.cmd run demo:simulator -- --scenario incident --speedup 10
```

The approved fictional-route demo provisions simulated drivers/vehicles, runs the Python engine, observes changing positions in `GET /api/vehicles`, and turns all simulator tracking OFF afterward. It has normal, rush-hour, incident and sparse scenarios; seeded headways, stop dwell, GPS noise/dropouts and occupancy; and a deterministic `simulator/output/arrivals.csv` ground-truth log. Output data is simulated and ignored by Git. The moving vehicle map UI is still a later phase; current live verification uses public JSON.

The full CLI is `python simulate.py --scenario normal --vehicles 10 --seed 42 --speedup 1` from `simulator` after provisioning. See [simulator setup, clocks and replay instructions](simulator/README.md). Defaults come from `backend/config/commutelive.php`. Arrival/sample CSVs use a reproducible simulation clock; API samples use real recording time with a separate clock-link log. Offline replay and `compare_scenarios.py` provide reproducible incident comparisons. Do not compare raw wall-clock predictions against accelerated arrival timestamps.

## Guardrails and next phase

Phase 4 adds `GET /api/traffic?route_id=ID` and `GET /api/stops/ID/arrivals`. From `backend`, run `php artisan commutelive:update-traffic` for an immediate speed refresh and `php artisan commutelive:build-baselines` to rebuild historical weekday/weekend hourly speeds. Scheduled updates run every 30 seconds and baselines daily at 00:05 Manila time. New/under-sampled routes explicitly return mode-default baselines and unknown traffic when live data is insufficient. Real-route feeds exclude simulated speed samples by default. Arrivals are logged, with trip-scoped approximate GPS arrival matching. See [API details](docs/API.md).

Run `simulator/.venv/Scripts/python.exe analysis/run_notebook.py` on Windows, or `simulator/.venv/bin/python analysis/run_notebook.py` elsewhere, after setup. It executes [the ETA evaluation notebook](analysis/eta_eval.ipynb) across all four seeded scenarios and prints error metrics against a fixed-speed comparator. Use `npm.cmd run demo:eta` for the authenticated local HTTP demonstration or `npm.cmd run evaluate:eta` for the notebook. No real route input or running server is needed for evaluation. Outputs are clearly synthetic; the evaluator cannot write the application database. See [evaluation instructions and limits](analysis/README.md). Notebook execution is included in `npm test`.

No paid APIs or hosting. Keep secrets in ignored `.env` files; commit only `.env.example`. Do not commit local SQLite databases, APKs, dependency folders or virtual environments. Simulated records have `is_simulated = true`; the route review shows a **Simulated data** badge for the fictional demo geometry. API trip start requires explicit consent; the demo prints tracking ON/OFF. The persistent driver notification and GPS permission flow belong to the later Flutter phase. No commuter location is collected. Retention defaults to 30 days in `backend/config/commutelive.php`; a tested, scheduled purge command is included.

The team supplied three pilot routes: Malabon–Monumento via Letre, Monumento–Navotas via Letre, and Gasak–Recto via Dagat-Dagatan/C-3. Their supplied codes, terminals and coverage are recorded in [pilot data notes](data/README.md); route identities and geometry still need verification before import. Route review, simulator and traffic/ETA APIs are implemented. The PWA, commuter live-map UI and alternate-routing engine remain later work. Work advances only after the current phase passes its checklist and the team says **continue**.

See [progress](docs/PROGRESS.md) for actual verification results and known issues. Backend CI runs on GitHub Actions for pushes and pull requests; GitHub applies its own free-tier quotas (public repositories have free standard hosted runners).
