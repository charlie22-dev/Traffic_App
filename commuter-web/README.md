# Commuter app

The home screen is a mobile-first Leaflet map with OSM attribution, traffic segments, recent vehicle positions, stop arrivals, a vehicle card, route filter, map legend and permission-based nearest stops. `/routes` preserves the source/geometry review; `/status` preserves the original API OK diagnostics. Real pilot geometry remains pending; the approved fictional demo always displays **Simulated data**.

## Run

After root `npm run setup`, run root `npm run dev` and open `http://127.0.0.1:5173`. That starts API, web and scheduler. In PowerShell use `npm.cmd` if needed. Run root `npm run demo:commuter` for a self-contained real-simulator/browser acceptance check: it provisions only simulated drivers, checks 360px movement/traffic/countdown, intentionally stops its own API, verifies recovery UI, then ends the simulated trips/revokes tokens. The check uses its own ports and leaves unrelated servers alone. It finishes automatically and saves screenshots/JSON in ignored `commuter-web/test-results/`.

For interactive moving data in an already-running root dev session, provision and run from separate terminals:

```sh
# backend
php artisan commutelive:provision-simulator --vehicles=2 --route=DEMO-SYNTHETIC-OUT --allow-synthetic-route --consent-simulated-tracking --api-url=http://127.0.0.1:8000
# simulator, using its activated venv
python simulate.py --scenario normal --vehicles 2 --seed 42 --speedup 1 --allow-synthetic-route --revoke-tokens
```

This assumes the separate synthetic demo was seeded (root `npm run demo:ingestion -- --hold-seconds 0` does that). Choose the synthetic direction, tap its destination stop, and open a vehicle. Scheduler updates traffic every 30 seconds. Start/stop/provisioning details and emergency cleanup are in `simulator/README.md`. Normal simulation uses a virtual clock; accelerated wall-clock views demonstrate behavior, not ETA accuracy. Use the Phase 4 notebook for accuracy comparisons.

## Install and offline

Service workers are enabled in **production builds**, with a prompt before loading an updated version. Keep the API running and use a separate terminal:

```sh
cd commuter-web
npm run build
npm run preview
```

Open `http://127.0.0.1:4173`. Preview proxies `/api` to `http://127.0.0.1:8000` by default, like dev; override the server-only `COMMUTELIVE_API_PROXY_TARGET` when starting preview if needed. Use the Install app button when supported, otherwise the browser's install/add-to-home-screen menu. This is local-only; no paid hosting or account is needed. HTTPS or localhost is required for service workers and geolocation. Plain LAN HTTP on a phone may display the app but cannot promise those features.

For an optional Android USB check with an installed Android SDK, use `adb reverse tcp:4173 tcp:4173` and open `http://localhost:4173` on the phone while preview/API are running. Revoke the reverse rule afterward with `adb reverse --remove tcp:4173`. Actual physical-device installation is not claimed by automated browser testing.

The service worker precaches the versioned app shell, manifest and original project icons. It never intercepts `/api` navigation with HTML and never caches live feeds, authenticated data or OSM tiles. Only public configuration/route inventory/geometry snapshots are saved in localStorage, with a configured expiry (seven days by default), so an offline reload can retain static lines/stops. Saved geometry is labeled. API failure preserves the map and a retry action. Vehicles expire at the server-configured freshness cutoff even without another response; failed/stale arrivals pause instead of continuing a false countdown; offline/expired traffic is grey.

## Location and data honesty

“Use my location” calls geolocation only after a tap, uses it in memory for straight-line nearest-stop distances, and clears on reload or Clear my location. Coordinates are never sent to Laravel/Nominatim, put into URLs, or written to storage. OSM tile requests reveal the viewed map area; the basemap can be switched off. Straight-line distance is not a walking route or a safe boarding recommendation. Route/stop verification notices and simulated labels remain visible.

Traffic uses Phase 4's explicit source partition: real routes default to real speed observations, synthetic routes to simulated ones. Simulated vehicles on real geometry are labeled but do not silently change the map to simulated traffic. A source-selector UI can be added if future evaluation needs it. Occupancy E/S/F/? means Empty/Seats/Full/Unknown, not a measured passenger count. Confidence remains the backend's uncalibrated sample-coverage heuristic. Empty/stale feeds do not imply no service exists.

## Verify

From root, `npm test` runs the entire repository suite, including React tests, production PWA build, three Playwright checks and the Phase 4 notebook. From this folder, use `npm test` for component/hook tests, then `npm run build` and `npm run test:e2e` for browser tests. Stop manual preview on port 4173 before the browser tests; they own that port. System Edge is used when available on Windows; otherwise setup installs Playwright Chromium. Set `PLAYWRIGHT_CHANNEL` to explicitly choose another installed supported channel.

Browser tests use clearly synthetic intercepted API records and avoid external tile downloads. They verify moving markers, countdown, stop and vehicle controls, filters, location privacy/denial, no 360px page overflow, API failure, expiring positions, offline shell/static geometry and manifest/icon/SW behavior. Real HTTP movement and actual API shutdown are checked separately by `demo:commuter`. No physical-phone field test or real pilot coverage is claimed.
