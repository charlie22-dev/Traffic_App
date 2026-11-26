# CommuteLive

A free commute assistant for a small Philippine jeepney/bus pilot. **Phase 0 is a connection check only:** React calls Laravel and displays **API OK**. No GPS tracking, route data, traffic measurements, vehicles or ETA claims exist yet.

## Local setup (6 steps)

1. Install free prerequisites: Git, PHP 8.2 or 8.3 with Composer 2 and `pdo_sqlite`, `mbstring`, `openssl`, `fileinfo`, `curl`, `dom`, `xml`; Node 22.12+ (Node 24 recommended); Python 3.11–3.13 with venv/pip; Flutter stable with Dart 3.13.5+. Keep their commands on PATH. Android Studio, its free Android SDK and a JDK are needed only to run/build the Android app. No service account or credit card is required.
2. Clone this repository and open a terminal at its root.
3. Run `npm run setup`. This installs locked dependencies, copies the backend environment template if needed, generates an app key once, migrates SQLite, resolves Flutter packages and creates `simulator/.venv`. Internet access is needed for dependency downloads. Existing local `.env` values are preserved; use `DB_CONNECTION=sqlite` for the default workflow.
4. Run `npm run dev` and open **http://127.0.0.1:5173**. The page should display **API OK**. The API is at http://127.0.0.1:8000/api/health. Ctrl+C stops both servers.
5. Stop dev, then run `npm test`. It runs backend tests, React tests/build, Flutter analysis/widget tests, Python tests and a real HTTP integration check. Ports 8000 and 5173 must be free; the integration check starts and stops its own servers.
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

## Guardrails and next phase

No paid APIs or hosting. Keep secrets in ignored `.env` files; commit only `.env.example`. Do not commit local SQLite databases, APKs, dependency folders or virtual environments. Future simulated records must have `is_simulated = true`, with a visible **Simulated data** badge whenever shown. Future driver tracking requires explicit consent and a persistent ON indicator; commuter location stays on the device by default. Retention defaults to 30 days in `backend/config/commutelive.php`; implement and test a purge command before collecting any pings.

The team supplied three pilot routes: Malabon–Monumento via Letre, Monumento–Navotas via Letre, and Gasak–Recto via Dagat-Dagatan/C-3. Their supplied codes, terminals and coverage are recorded in [pilot data notes](data/README.md); route identities and geometry still need verification before import. Phase 0 does not implement the PWA, map, traffic/ETA/routing engines or GPS simulator. Work advances only after the current phase passes its checklist and the team says **continue**.

See [progress](docs/PROGRESS.md) for actual verification results and known issues. Backend CI runs on GitHub Actions for pushes and pull requests; GitHub applies its own free-tier quotas (public repositories have free standard hosted runners).
