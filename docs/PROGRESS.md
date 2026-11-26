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

From the repository root: `npm run setup`, then `npm run dev`; open http://127.0.0.1:5173. On Windows, `npm.cmd` works if PowerShell blocks npm.ps1. Stop dev before `npm test` so the integration-test ports are available. README contains six setup steps.

### Limits and open questions

- GitHub Actions is configured but has not run remotely: no GitHub remote was supplied. Local backend checks passed with PHP 8.2; the PHP 8.3 CI matrix awaits a hosted run.
- Flutter analysis and widget tests passed. No Android emulator session or APK build was performed; the installed Android toolchain passed `flutter doctor`. Missing Chrome and Visual Studio entries concern Flutter web/Windows targets, outside this Android-only starter.
- PWA installation, maps, route imports, GPS collection, consent controls, ping purge and simulator scenarios belong to later phases. The simulator currently only checks API health. No location data is accepted or collected.
- The sandbox refresh error affected tool execution, not project commands. Workspace setup and all checks ran using reviewed elevated execution; the independent clean checkout passed without repository ownership warnings.
- No implementation blocker remains for Phase 0. Wait for the team to say **continue** before any Phase 1 work.

Pilot routes are intentionally undecided. Ask for two or three intersecting real routes before Phase 1.
