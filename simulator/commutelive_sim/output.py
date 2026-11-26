"""Deterministic truth files; nondeterministic wall-clock transport is separate."""

import csv
import json
from pathlib import Path


class Logs:
    def __init__(self, directory):
        self.directory = Path(directory)
        self.directory.mkdir(parents=True, exist_ok=True)
        self.handles = []
        self.arrivals = self._csv("arrivals.csv", ["vehicle", "route_code", "stop", "stop_sequence", "actual_arrival_at", "elapsed_seconds", "is_simulated"])
        self.samples = self._csv("samples.csv", ["vehicle", "route_code", "elapsed_seconds", "sample_index", "true_distance_m", "lat", "lng", "speed_kph", "heading", "accuracy_m", "dropped", "segment_sequence", "occupancy", "is_simulated"])
        self.transport = (self.directory / "transport.jsonl").open("w", encoding="utf-8")
        self.handles.append(self.transport)

    def _csv(self, name, fields):
        handle = (self.directory / name).open("w", newline="", encoding="utf-8")
        self.handles.append(handle)
        writer = csv.DictWriter(handle, fieldnames=fields, lineterminator="\n", extrasaction="ignore")
        writer.writeheader()
        return writer

    def event(self, event):
        row = dict(event)
        row["is_simulated"] = "true"
        row["elapsed_seconds"] = f"{row['elapsed_seconds']:.3f}"
        if event["type"] == "arrival":
            self.arrivals.writerow(row)
        elif event["type"] == "ping":
            for key in ("true_distance_m", "speed_kph", "heading", "accuracy_m"):
                row[key] = f"{row[key]:.6f}"
            row["lat"], row["lng"] = f"{row['lat']:.10f}", f"{row['lng']:.10f}"
            row["dropped"] = "true" if row["dropped"] else "false"
            self.samples.writerow(row)

    def api_event(self, data):
        self.transport.write(json.dumps({"is_simulated": True, **data}, sort_keys=True) + "\n")
        self.transport.flush()

    def metadata(self, data):
        (self.directory / "run.json").write_text(json.dumps({"is_simulated": True, **data}, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    def close(self):
        for handle in self.handles:
            handle.close()
