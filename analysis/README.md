# Evaluation

`eta_eval.ipynb` generates and evaluates **simulated data only** on a fictional 1.076 km line with three stops. It requires no prior output, driver credentials, server, or imported pilot data. Real pilot geometry and field accuracy remain unverified.

From the repository root:

```powershell
npm.cmd run setup
simulator/.venv/Scripts/python.exe analysis/run_notebook.py
```

On Linux/macOS replace the Python path with `simulator/.venv/bin/python`. Setup installs the notebook packages in `analysis/requirements.txt` into that ignored venv. Existing clones can install just those dependencies with `simulator/.venv/Scripts/python.exe -m pip install -r analysis/requirements.txt` after updating backend dependencies/migrations. The runner uses this venv's kernel without a global kernel install and saves `analysis/output/eta_eval.executed.ipynb`. The unexecuted notebook is committed; generated logs and results are ignored. `npm test` executes the notebook as part of phase acceptance.

For the same experiment without Jupyter, run:

```powershell
simulator/.venv/Scripts/python.exe analysis/evaluate.py
simulator/.venv/Scripts/python.exe analysis/evaluate.py --seed 43 --output analysis/output/seed-43
```

The four scenarios are normal, rush_hour, incident, and sparse. Default seed is 42, fleet size five (three for sparse), horizon 1200 simulated seconds. The fictional evaluation fixture adds a 400 m intermediate stop; it does not alter the separately seeded demo or pilot imports. Parameters come from Laravel config, geometry uses the shared spherical convention, and scenarios come from `data/scenarios/`. The simulator writes `samples.csv` and exact `arrivals.csv` per case.

`replay.php` unconditionally selects disposable in-memory SQLite before any migrations/writes. It feeds only noisy, non-dropped GPS to the actual ingestion service, runs the traffic engine every 30 simulated seconds, and calls the actual arrivals controller every 15 seconds. Baselines are cold-start mode defaults. The predictor never reads ground truth or future pings. Simulator exact arrivals are joined afterward by vehicle/stop on the same clock. No real account, application DB, token or network is used. Endpoints and auth are independently covered by Laravel feature tests.

The results table reports MAE, median absolute error and percentage within an inclusive +/-60 seconds. Each returned commuter prediction is one sample; both methods use identical paired samples. The naive comparator is distance divided by configured fixed speed (20 kph), without dwell. Censored/unobserved or already-passed arrivals are excluded and counted, never assigned zero error. Repeated polling rows within a trip are correlated; N is not a count of independent journeys. `results.csv`/`results.json` contain counts, flags and gates, with per-case snapshots/predictions/traffic states for inspection. A service-code fingerprint is recorded.

Acceptance asserts incident segment red within 120 simulated seconds and a normal ETA countdown. Synthetic accuracy may improve or regress against fixed speed in each scenario; the notebook prints the differences honestly. Extend runs across seeds, modes, verified routes and held-out historical data before making performance claims. This experiment establishes cold-start behavior and reproducibility, not field accuracy or calibrated confidence.
