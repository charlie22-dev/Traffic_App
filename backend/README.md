# CommuteLive backend

Laravel 12, Sanctum tokens, SQLite development database; PHP 8.2/8.3. Use the root README and shared setup/dev/test scripts.

Phase 2 includes register/login/logout, driver role/ownership enforcement, explicit trip consent, batch GPS matching, occupancy, expiring incidents and public vehicle freshness. See ../docs/API.md for request formats, per-row outcomes, limits and privacy behavior.

Run backend tests with `php artisan test`. Tests use in-memory SQLite and isolated fixtures. Run `php artisan commutelive:purge-pings --dry-run` to inspect the configured retention cutoff. `php artisan schedule:work` executes the daily purge schedule locally; the API server alone does not run scheduled jobs.

Root `npm run demo:ingestion` generates ignored local credentials if needed and exercises a completely synthetic route/vehicle through real HTTP, then turns tracking OFF and revokes its token. Manual `php artisan db:seed` uses DemoSeeder, requires a local DEMO_PASSWORD of at least 12 characters, and is disabled outside local/testing environments. Pilot imports remain untouched.

Phase 1's actual pilot geometry/stops are still pending. A working synthetic demonstration does not certify those services or provide real traffic/ETA measurements. The Flutter app remains a starter until its own phase.
