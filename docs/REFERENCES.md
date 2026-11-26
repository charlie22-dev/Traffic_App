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
