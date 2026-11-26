"""Synthetic vehicles on imported route geometry, through the real driver API."""

import argparse
import json
import sys
from pathlib import Path

from commutelive_sim.client import ApiError
from commutelive_sim.runner import run

ROOT = Path(__file__).resolve().parent


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--scenario", choices=["normal", "rush_hour", "incident", "sparse"], default="normal")
    parser.add_argument("--vehicles", type=int, default=10)
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument("--speedup", type=float, default=1)
    parser.add_argument("--duration", type=float, help="Simulated seconds; defaults to the provisioned config")
    parser.add_argument("--env", type=Path, default=ROOT / ".env")
    parser.add_argument("--output", type=Path, default=ROOT / "output")
    parser.add_argument("--allow-synthetic-route", action="store_true", help="Explicitly allow the separate fictional demo geometry")
    parser.add_argument("--offline", action="store_true", help="Run deterministically without network or sleeping")
    parser.add_argument("--snapshot", type=Path, help="An earlier output/routes.json for offline replay")
    parser.add_argument("--revoke-tokens", action="store_true", help="Revoke provisioned simulator tokens on exit; re-provision before another online run")
    args = parser.parse_args()
    try:
        scenario = json.loads((ROOT.parent / "data" / "scenarios" / f"{args.scenario}.json").read_text(encoding="utf-8"))
        return run(args, scenario)
    except (ApiError, ValueError, KeyError, OSError, TypeError) as error:
        print(f"Simulator stopped: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
