# Decisions

## D001 — Reuse the installed stack (Phase 0)

The team explicitly approved reusing `myapp` as `backend` and allowing installed versions where compatible. Retain Laravel 12 instead of downgrading to the requested Laravel 11; use local PHP 8.2 and Python 3.12 instead of requiring PHP 8.3 and Python 3.11. Keep dependency constraints compatible with those local runtimes. CI may also verify PHP 8.3.

Reason: preserve the existing starter and make local setup practical without replacing system runtimes. All later Python dependencies must be checked for Python 3.12 compatibility.

## D002 — Local development only (Phase 0)

No external deployment, paid service, account registration or production tracking in this phase. SQLite is the development database as permitted by the prompt.
