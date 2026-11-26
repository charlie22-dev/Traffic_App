# CommuteLive progress

## Phase 0 — in progress

Goal: a local monorepo with a React page that displays API OK from Laravel.

- Inspected the existing `myapp` Laravel starter; no custom application features were found.
- Moved `myapp` to `backend` and initialized the monorepo Git repository.
- The team approved reuse of Laravel 12 and compatible installed runtime versions.
- PHP 8.2.12, Node 24.19.0 and Python 3.12.10 are available. Flutter is on PATH; verification is pending.
- Sandbox execution began failing during environment refresh after repository initialization. Elevated execution can run; use it only as needed to continue workspace work.

### Phase gate

- [ ] Backend and Sanctum installed; health endpoint tested.
- [ ] React/Vite health screen shows API OK against the running API.
- [ ] Flutter Android starter scaffolded and tested.
- [ ] Python simulator virtual environment created.
- [ ] Setup, dev and test commands work from a fresh checkout.
- [ ] Full suite passes.
- [ ] Backend GitHub Actions workflow added.
- [ ] README setup fits in fewer than ten steps.

Pilot routes are intentionally undecided. Ask for two or three intersecting real routes before Phase 1.
