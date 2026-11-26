"""Reproduce normal/incident runs from one snapshot and report synthetic slowdown."""

import argparse
import csv
import json
from argparse import Namespace
from pathlib import Path
from statistics import mean

from commutelive_sim.runner import run

ROOT = Path(__file__).resolve().parent


def compare(snapshot, output, vehicles=3, seed=42, duration=600):
    output = Path(output)
    cases = {}
    for name in ("normal", "incident"):
        scenario = json.loads((ROOT.parent / "data/scenarios" / f"{name}.json").read_text(encoding="utf-8"))
        args = Namespace(env=ROOT / ".env", offline=True, snapshot=Path(snapshot), allow_synthetic_route=True,
                         vehicles=vehicles, speedup=1, duration=duration, seed=seed, output=output / name, revoke_tokens=False)
        if run(args, scenario) != 0:
            raise RuntimeError("Offline comparison did not complete")
        cases[name] = json.loads((output / name / "run.json").read_text(encoding="utf-8"))
    incident = cases["incident"]["incident"]
    speeds = {}
    final_arrivals = {}
    for name in cases:
        with (output / name / "samples.csv").open(encoding="utf-8") as handle:
            rows = list(csv.DictReader(handle))
        selected = [float(row["speed_kph"]) for row in rows if row["route_code"] == incident["route_code"]
                    and int(row["segment_sequence"]) == incident["segment_sequence"]
                    and incident["start_seconds"] <= float(row["elapsed_seconds"]) < incident["start_seconds"] + incident["duration_seconds"]]
        if not selected:
            raise ValueError("No samples reached the incident segment during its window; choose a longer window or a different scenario segment")
        speeds[name] = mean(selected)
        with (output / name / "arrivals.csv").open(encoding="utf-8") as handle:
            arrivals = list(csv.DictReader(handle))
        by_vehicle = {}
        for row in arrivals:
            by_vehicle[row["vehicle"]] = row
        final_arrivals[name] = by_vehicle
    delays = {}
    for label, row in final_arrivals["normal"].items():
        other = final_arrivals["incident"].get(label)
        if other and other["stop"] == row["stop"] and int(row["stop_sequence"]) > 1:
            delays[label] = float(other["elapsed_seconds"]) - float(row["elapsed_seconds"])
    report = {"is_simulated": True, "seed": seed, "incident": incident, "mean_segment_speed_kph": speeds,
              "slowdown_percent": (1 - speeds["incident"] / speeds["normal"]) * 100 if speeds["normal"] else None,
              "arrival_delays_seconds": delays,
              "time_basis": "Fixed simulation clock; these are synthetic scenario results, not measured road speeds"}
    (output / "comparison.json").write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return report


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot", type=Path, default=ROOT / "output/routes.json")
    parser.add_argument("--output", type=Path, default=ROOT / "output/comparison")
    parser.add_argument("--vehicles", type=int, default=3)
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument("--duration", type=float, default=600)
    args = parser.parse_args()
    print(json.dumps(compare(args.snapshot, args.output, args.vehicles, args.seed, args.duration), indent=2, sort_keys=True))
