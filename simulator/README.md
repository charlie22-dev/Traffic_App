# Simulator starter

Setup creates `.venv` with Python 3.11–3.13. Phase 0 has no simulation dependencies and generates no vehicles. With the API running, use `.venv/Scripts/python.exe healthcheck.py` on Windows or `.venv/bin/python healthcheck.py` on Linux/macOS.

Route geometry, reproducible scenarios and simulation libraries belong to a later phase. All emitted simulated records must have `is_simulated = true`.
