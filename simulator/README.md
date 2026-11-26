# CommuteLive simulator

All motion, arrivals, GPS noise, occupancy and dropouts here are **simulated**. The engine reads stored route geometry/stops/segments; it never invents missing roads. Phase 1's real pilot inputs remain pending. The approved demonstration uses the separately labeled fictional line.

## Quick local demonstration

After root setup, run from the repository root:

```powershell
npm.cmd run demo:simulator
npm.cmd run demo:simulator -- --scenario incident --speedup 10
```

The wrapper seeds the fictional example, provisions 3 simulated drivers/vehicles, starts servers on free ports, runs 360 simulated seconds, and polls the public API to verify changing matched positions. The incident check also requires a near-zero speed on its chosen segment. It ends tracking, revokes simulator tokens and stops only its own servers afterward.

For ten vehicles: `npm.cmd run demo:simulator -- --vehicles 10 --duration 720 --speedup 60`. Network/server capacity can reduce achieved playback speed; physics ticks and truth are never skipped to catch up.

## Requested Python CLI

Keep root `npm.cmd run dev` running. From `backend`, provision the currently approved synthetic example:

```sh
php artisan commutelive:provision-simulator --vehicles=10 --route=DEMO-SYNTHETIC-OUT --allow-synthetic-route --consent-simulated-tracking
```

The synthetic seed must exist first (`npm.cmd run demo:ingestion` creates it). For real imported directions, omit the synthetic option; select direction codes with repeated `--route=CODE`, or let provisioning select all active non-synthetic routes. Every direction needs stops and built segments. Provisioning does not import/guess geometry.

Activate the virtualenv, then from `simulator`:

```sh
python simulate.py --scenario normal --vehicles 10 --seed 42 --speedup 1
```

Without activation use `.venv/Scripts/python.exe` on Windows or `.venv/bin/python` on Linux/macOS. `--duration` sets a horizon in simulated seconds. Defaults/thresholds come from `backend/config/commutelive.php`, exported into ignored `.env`; re-provision after config changes. Tokens are never printed. Normal CLI runs retain them until expiry/re-provisioning; `--revoke-tokens` revokes them on exit. Root demos revoke their tokens and leave a temporary server URL in `.env`, so provision again against the ordinary port 8000 before a direct run.

## Model and scenarios

- One real API-authenticated simulated driver per vehicle, per-direction headways, seeded vehicle speed variation and one traversal of the assigned direction. No teleporting back to the origin or invented return path.
- Free-flow assumptions by mode, a 24-hour profile and per-segment modifiers. Stops use true distance along the polyline; vehicles slow on approach and dwell 10–30 seconds by default.
- GPS every 5 simulated seconds, about 5 m radial RMS noise, 2% independent dropouts, seeded empty/seats/full occupancy. True arrival is recorded before dwell and independently of noisy GPS.
- `normal`: configured hour/segment variation. `rush_hour`: extra congestion. `incident`: default segment 3 of the first direction gets a 0.02 multiplier during simulated seconds 120–240. `sparse`: up to 3 vehicles where possible, retaining at least one per selected direction.
- Experimental scenario values live in `data/scenarios/*.json`; shared defaults live in the PHP config. Missing incident targets fail clearly. These are synthetic assumptions, not measured road speeds.

## Outputs and reproducibility

Generated files are ignored under `simulator/output/`:

| File | Meaning |
| --- | --- |
| `arrivals.csv` | Stable vehicle/route/stop codes, stop occurrence, actual **simulated** arrival time, elapsed seconds, `is_simulated=true` |
| `samples.csv` | True distance, noisy GPS, virtual speed, heading, occupancy and intentional dropouts; all simulated |
| `routes.json` | Public geometry/segment snapshot, parameters and assignments; no tokens |
| `transport.jsonl` | Actual UTC recording timestamps, virtual sample links, API UUIDs and batch outcomes; no tokens |
| `run.json` | Seed/scenario, input/engine hashes, Python version, clocks, horizon/completion, counts and cleanup |
| `live-verification.json` | Root wrapper's observed movement and incident speeds in public JSON |

The same seed, route data, assignments, settings, scenario and runtime produce byte-identical arrival and sample CSVs. Speedup, network timing and database IDs do not enter those files. Transport and run metadata intentionally include nondeterministic wall time. The arrival epoch is fixed in config; no logged number is presented as a real field measurement.

After an online run, replay without HTTP or sleeps:

```sh
python simulate.py --offline --snapshot output/routes.json --scenario normal --vehicles 3 --seed 42 --speedup 1 --duration 600 --output output/replay-a --allow-synthetic-route
python simulate.py --offline --snapshot output/routes.json --scenario normal --vehicles 3 --seed 42 --speedup 20 --duration 600 --output output/replay-b --allow-synthetic-route
python compare_scenarios.py --snapshot output/routes.json --vehicles 3
```

Compare the two `arrivals.csv`/`samples.csv` files byte for byte. The comparison script writes `output/comparison/comparison.json` with simulated normal/incident speeds and arrival delays.

**Two clocks:** arrivals use deterministic simulation time; API pings use their actual UTC recording time to satisfy consent/retention checks. At speedup other than 1, evaluation must use `transport.jsonl` to align them, rather than subtract raw timestamps. Use speedup 1 for direct wall-clock studies; transport lag is still recorded.

## Reliability and cleanup

Each driver has one bounded upload worker, batching and retrying identical UUID/payloads. Occupancy writes are throttled separately. Intentional dropouts are distinct from transport failures. Exhausted retries or rejected records fail the run instead of reporting success. Unsent rows/trip IDs are preserved in `unsent-pings.json`; use another output directory afterward to preserve that evidence.

Completion and Ctrl+C end owned trips. `run.json` distinguishes reaching the requested horizon from every vehicle reaching its terminus; a short run can contain only some arrivals. Cleanup failure is reported. A `.env.run.lock` prevents concurrent use of one credentials file. After an abrupt crash, confirm the old process stopped before removing a stale lock.

Emergency local cleanup: from `backend`, `php artisan commutelive:stop-simulator`. It ends/revokes only the simulated `SIMULATOR-*` pool; other drivers and `SIM-JEEP-*` demo vehicles are excluded. Re-provision to replace revoked credentials. Root demos always run that fallback before stopping their servers, including after child-process errors.

Run Python tests here with `python -m unittest discover -s tests -v`, or the entire repository with root `npm.cmd test`.
