# CommuteLive API

Phase 5: public `GET /api/client-config` returns only UI polling/request timeouts, ETA/vehicle/traffic freshness, static-route cache duration, marker interpolation duration and nearest-stop count. Values come from the `commuter` section and shared thresholds in `config/commutelive.php`; no environment secrets or driver identity are exposed. The commuter PWA uses existing public route/vehicle/traffic/arrivals endpoints and never uploads geolocation. See [commuter run/privacy/cache instructions](../commuter-web/README.md).

Traffic and stop-arrival contracts, algorithms and scheduled operations are documented in [Phase 4 API details](TRAFFIC_ETA.md). Auth, ingestion and simulator provisioning follow below.

Base URL for ordinary local dev: `http://127.0.0.1:8000/api`. Send `Accept: application/json`; POST/PUT bodies are JSON. Dates returned by the API are UTC with explicit timezone. Token responses contain credentials: keep them out of logs, screenshots and Git.

## Authentication and tracking

| Method/path | Request | Behavior |
| --- | --- | --- |
| `POST /register` | `name`, `email`, `password`, `password_confirmation`, optional `role` (`commuter` or `driver`) | Creates a user and expiring Sanctum token; rejects admin/simulation impersonation. Driver registration does not assign vehicles. |
| `POST /login` | `email`, `password` | Returns `data.user`, `data.token`, `data.token_type`, `data.expires_at`. Invalid credentials use a generic validation error. |
| `POST /logout` | Bearer token | Revokes that token and ends the driver's current trip, immediately hiding its live position. Other tokens are not revoked. |
| `GET /user` | Bearer token | Returns the authenticated user; password is omitted. |
| `GET /privacy/tracking` | Public | Current notice version, GPS retention days and plain-language tracking notice. |
| `GET /driver/vehicles` | Driver bearer token | Only that driver's assigned vehicles, including route and simulation status. |
| `GET /driver/trips/active` | Driver bearer token | That driver's active trip or null, plus `tracking_on`. Used to recover a lost simulator trip-start response without starting a second trip. |
| `POST /driver/trips/start` | `vehicle_id`, `tracking_consent: true`, `privacy_notice_version`, optional `start_battery_pct` (0–100) | Requires the current notice version and an owned vehicle with an active, segmented route. Returns 201 with `tracking_on: true`. One active trip per driver and per vehicle. |
| `POST /driver/trips/{id}/end` | Optional `end_battery_pct` (0–100) | Owner only. Idempotent; first end time/battery are retained. Returns `tracking_on: false`. |

Driver endpoints require `Authorization: Bearer <token>`. Commuter/admin roles cannot write driver pings or reports. Ownership is checked as well as role; an arbitrary vehicle/trip ID never grants write access. Registration of admins, vehicle assignments and approval management have no public endpoint in this phase.

Tokens expire after 1,440 minutes by default. Login/register have per-email/IP and per-IP throttles. Driver updates and ingestion have separate per-user throttles, configured in `commutelive.php`. HTTP 429 includes `Retry-After`. No paid SMS, email or map service is used.

## GPS batch

`POST /driver/pings` accepts this envelope. The illustrative coordinates below belong to the explicitly fictional demo, not a real service:

```json
{
  "trip_id": 1,
  "pings": [
    {
      "client_ping_uuid": "af16f0e6-56cc-47bb-b7a8-d4b159901d72",
      "lat": 14.65,
      "lng": 120.951,
      "speed_kph": 20,
      "heading": 90,
      "accuracy_m": 5,
      "recorded_at": "2026-11-26T10:00:00Z",
      "is_simulated": true
    }
  ]
}
```

Use a freshly started owned trip, a new UUID and current recording time in actual requests; the literal IDs/time above are documentation, not replay instructions. Run `npm.cmd run demo:ingestion` for a working example that supplies these values automatically.

- Default maximum: 200 records per request. Empty, oversized or malformed envelopes return 422; missing auth returns 401, wrong role/owner 403, unknown trip 404, unavailable route segments 409.
- Individual row failures do not discard valid neighboring rows. HTTP **200 means the batch was evaluated**, not that every row was stored. Inspect `data.results` and `data.counts`.
- Each result has its input index/UUID and a status: `accepted`, `flagged_off_route`, `duplicate` or `rejected`. Rejections include a machine-readable `code` and either validation `errors` or a message.
- Coordinates are range checked. Speed is optional, bounded to 160 kph by default; heading is optional and must be at least 0 and below 360. Accuracy above 50 m is rejected by default. Missing speed/heading stays null instead of becoming a measured zero.
- ISO 8601 recording time must include a timezone. Future timestamps beyond the default 15-second clock allowance, times outside the consented trip and samples older than the 30-day retention window are rejected. Delayed uploads after trip end are allowed only for samples recorded while that trip was ON; they do not restore live visibility.
- Matched records store projected distance along the route, perpendicular offset and segment ID. Off-route samples beyond 100 m by default keep the raw point and diagnostic offset with `is_off_route=true`, but receive no route-distance/segment assignment and are excluded from public position selection.
- The server derives simulation status from the trip, vehicle, route and demo identity. Clients cannot turn a simulated ping into a real one. An optional client `is_simulated` must agree with the server; otherwise that row is rejected.
- Same UUID plus the same normalized payload is a duplicate, including within one batch. Different content or another trip using that UUID is rejected as `uuid_conflict`. UUID case, numeric representation and equivalent timezones are normalized. A retry never overwrites stored coordinates.
- Out-of-order uploads are accepted inside the time window. The live feed selects by recorded time and breaks ties by server record ID, so a delayed older upload cannot replace a newer position.

Rows are processed transactionally under the owned trip lock, and database uniqueness constraints enforce UUID and active-trip identities. Tests use SQLite; MySQL-specific concurrent load validation is reserved for later evaluation.

## Occupancy, incidents and public feeds

| Method/path | Request/response |
| --- | --- |
| `PUT /driver/status` | Active owned `trip_id`, `occupancy` (`empty`, `seats`, `full`). Server sets update time and simulation status. |
| `POST /driver/incidents` | Active owned `trip_id`, `type` (`heavy_traffic`, `road_closed`, `accident`), `lat`, `lng`. Derives reporter/route/expiry/simulation status. Rejects reports beyond the configured route-offset limit. |
| `GET /vehicles?route_id=` | Public latest matched position, heading/speed/accuracy, route and segment IDs, occupancy, recording time, freshness, tracking state and simulation flag. Driver identity/contact and trip identifiers are omitted. |
| `GET /incidents?route_id=` | Public unexpired incidents. Reporter identity is omitted. |

Vehicle freshness is based on recording time, not receipt time. Default visibility ends after 60 seconds without a matched sample; exactly 60 seconds is still visible. Small allowed future clock skew gives a clamped freshness of zero. An ended trip disappears immediately. A newer flagged off-route row does not overwrite the last matched position; that position still ages out normally.

Occupancy becomes `unknown` when absent, older than 300 seconds or associated with another trip. That is an absence of current information, not a measured load. Incidents expire from public responses after 30 minutes by default. Both public feeds include `contains_simulated_data`; every returned simulated record has `is_simulated=true`. Route review also shows a **Simulated data** badge for the synthetic demo geometry. The full commuter live-vehicle UI is still a later phase.

## Retention and operations

From `backend`:

```sh
php artisan commutelive:purge-pings --dry-run
php artisan commutelive:purge-pings
php artisan schedule:work
```

The purge deletes pings whose recording **or** receipt time is older than the configured cutoff. The exact boundary is retained; invalid nonpositive retention configuration fails without deleting. It runs daily when Laravel's scheduler is actually running. Use `schedule:work` locally or the standard scheduler cron on a hosted installation; the API server alone does not execute scheduled commands.

Purge affects GPS ping rows; it does not delete user accounts, trip metadata or expired incident records. Broader deletion/consent administration is Phase 8 work. Field validation is still pending.

Once trips reference a route, import/build commands refuse actual geometry or segment-layout changes under that code. Use a new version/direction code for changes so historical samples do not silently acquire new segment meanings.

## Synthetic local demonstration

`npm.cmd run demo:ingestion` generates `DEMO_PASSWORD` in ignored `backend/.env` if absent, migrates, seeds and starts its own local servers. It creates four demo identities ending in `@commutelive.test`, two `SIM-JEEP-*` vehicles and `DEMO-SYNTHETIC-OUT` with explicitly fictional stops. The three pilot imports are untouched. Default `DatabaseSeeder` calls this same local/testing-only seeder; manual `php artisan db:seed` requires a local `DEMO_PASSWORD` of at least 12 characters. Reserved demo identities that collide with non-demo data are rejected rather than overwritten.

The script logs in, explicitly starts synthetic tracking, submits one GPS record twice, checks one accepted/one duplicate, updates occupancy, verifies public visibility, holds tracking ON for 15 seconds, then ends the trip, confirms it is hidden, revokes its token and stops its servers. Use `--hold-seconds 0` for an immediate check or up to 60 seconds for manual inspection of the printed URLs. Credentials/tokens are not printed. This is a single-position ingestion demo; the separate Phase 3 simulator provides moving vehicles.

## Phase 3 simulator provisioning

The moving simulator is now available via root `npm.cmd run demo:simulator` and `simulator/simulate.py`; see `simulator/README.md`. It uses the same public/driver endpoints, and all simulated drivers, vehicles, trips and pings carry server-controlled simulation flags. It never bypasses trip consent, ownership, recording-time, quality or rate rules.

`php artisan commutelive:provision-simulator --vehicles=10 --consent-simulated-tracking` provisions one simulated driver per vehicle across selected active non-synthetic directions. Use repeated `--route=CODE` to choose directions. Explicit fictional geometry additionally requires `--allow-synthetic-route`. Tokens and exported simulator parameters go into ignored `simulator/.env`, never stdout. Existing active or non-simulator identities are rejected. Configuration/re-provisioning must happen while the pool is OFF.

`php artisan commutelive:stop-simulator` is a local/testing-only fallback: end simulator-pool trips and revoke only its named tokens. Real drivers, other namespaces and Phase 2 demo vehicles are excluded. The root wrapper invokes it even if a child process is interrupted. Re-provision after token revocation. Ordinary CLI runs retain reusable tokens until expiry/re-provisioning unless `--revoke-tokens` is requested.
