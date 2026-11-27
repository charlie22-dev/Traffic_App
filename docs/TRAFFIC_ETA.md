# Traffic and ETA API (Phase 4)

Base URL: `/api`. Both endpoints are public. Simulated rows always carry `is_simulated=true`; envelopes include `contains_simulated_data`. They collect no commuter position or identity. The live vehicle map UI is a later phase.

## Traffic

`GET /traffic?route_id=ID` returns active-route segment rows with `segment_id`, route ID, sequence, along distances, length, polyline, `live_speed_kph`, `sample_count`, `baseline_speed_kph`, `baseline_source`, baseline sample/day counts, `ratio`, `status`, `computed_at`, `window_start`, and `is_simulated`. The envelope includes `as_of` and `window_seconds`. Without a route filter it returns all active routes; invalid/nonexistent filters return 422. Inactive directions produce no rows.

Defaults: aggregate every 30 seconds over the trailing 300 seconds of recorded, received, matched pings; average reported speeds equally, including zero and excluding null. At least three samples are required for live speed/status. Ratio live/baseline >=0.8 is green, >=0.5 is amber, lower is red. Missing/insufficient/stale data is `unknown` (render grey); unknown live speed and ratio are null. Snapshots older than 90 seconds are ignored. Repeated aggregation at the same timestamp updates the same window; subsequent jobs remove old snapshots. This pilot weights pings equally, rather than independent vehicles.

Historical baselines group the preceding 28 days by **Asia/Manila** weekday/weekend and hour, exclude the current live window, and require 30 samples across at least three distinct local dates. Stale (>36-hour), absent, zero or under-sampled buckets use `baseline_source=mode_free_flow_default` (28 kph jeepney, 35 bus); otherwise the source is `historical`. Defaults are assumptions. Daily baseline rebuild is at 00:05 Manila time.

Real/simulated observations are partitioned in live and historical tables. Real routes select real-only traffic by default; append `is_simulated=1` to explicitly select the simulated partition. Synthetic routes always select/advertise simulated data. Arrivals choose the matching trip's partition, so a simulated vehicle on real geometry remains labeled.

## Stop arrivals

`GET /stops/ID/arrivals` returns up to ten upcoming vehicles, sorted by ETA, from active trips on active directions serving that stop. Excludes stale positions (>60 seconds), future positions, stops behind the vehicle and already-observed trip/stop arrivals. Unknown stop IDs return 404. Limit: 60 requests/minute per IP (429 when exceeded).

Each row contains vehicle/route/stop IDs, vehicle label, `eta_seconds`, `predicted_arrival_at`, `occupancy`, `confidence`, `confidence_basis`, `freshness_seconds`, `baseline_sources`, `is_simulated` and logged `prediction_id`. Occupancy rules match the live feed. Every returned row is saved in `eta_predictions` with trip identity, prediction time, method `segment_blend_v1`, paired naive fixed-speed arrival and context. Non-returned candidates are not logged. The envelope includes `as_of` and simulation metadata.

ETA integrates only overlapping lengths from the remaining current segment through the target. For usable live data `w=n/(n+10)` and speed is `w*live+(1-w)*baseline`; otherwise w=0. A 1 kph floor keeps stopped estimates finite; it is an assumption, not a promised arrival. Add 20 seconds per intermediate stop, excluding current/target stops. Subtract age of the last position from remaining time, clamped at zero (due/unconfirmed). Missing segment coverage omits a candidate rather than shortening its ETA. Thresholds/defaults live in `config/commutelive.php`.

Confidence is a **heuristic, not a calibrated probability**: low below 50% live distance coverage, medium above that with mode defaults, high above that with historical baselines throughout the path. New congestion can increase ETAs; countdown need not be monotonic. Cold-start sparse traffic may produce poor estimates.

After ingestion, trip-scoped arrival matching sorts matched pings by recording time. It interpolates a forward crossing when adjacent ping gap is <=120 seconds, or uses a point within 15 meters of the stop (including noisy terminals). `stop_arrivals` is unique per trip/stop. Matching fills `actual_arrival_at` only when the estimated arrival is not before the prediction, with `actual_arrival_source=gps_interpolated|gps_proximity`. Out-of-order/retried batches are reconciled and cannot update another trip. Large-gap crossings stay unmatched. These are approximate GPS observations; evaluation instead joins independent exact simulator arrivals afterward. Trips visiting the same stop ID more than once need a future visit identity extension.

## Run and evaluate

Root `npm run dev` starts the scheduler with the API and web app. Standalone API starts require a separate `php artisan schedule:work`; HTTP requests do not execute scheduled jobs. Run these manually from `backend`:

```sh
php artisan commutelive:update-traffic
php artisan commutelive:build-baselines
php artisan schedule:list
```

`npm run demo:eta` seeds only the separate fictional demo, authenticates, shows red traffic and a decreasing logged ETA, matches a scripted arrival, then ends tracking and revokes its token. Deliberate position jumps test API behavior, not accuracy. `npm run evaluate:eta` runs the fixed-clock notebook with exact simulator truth across four scenarios; see [evaluation instructions](../analysis/README.md). Use `npm.cmd` in PowerShell if needed. Real pilot geometry/stops and field validation remain pending.
