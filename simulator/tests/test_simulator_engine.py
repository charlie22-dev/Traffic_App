import csv
import json
import tempfile
import unittest
from argparse import Namespace
from pathlib import Path

from commutelive_sim.engine import Route, World
from commutelive_sim.geometry import Polyline, haversine
from commutelive_sim.runner import run, select_fleet, validate
from sim_fixtures import normal, parameters, route_input


def world(params=None, scenario=None, count=3, seed=42):
    data = route_input()
    route = Route(data["data"], data["segments"], 0.1)
    return World({route.code: route}, [{"label": f"SIM-{i}", "route_code": route.code} for i in range(count)], params or parameters(), scenario or normal(), seed)


def events_until(simulation, end=300):
    result = []
    for elapsed in range(end + 1):
        result.extend(simulation.advance(elapsed))
    return result


class EngineTest(unittest.TestCase):
    def test_known_geometry_interpolation_clamps_and_preserves_bend(self):
        line = Polyline([[0, 0], [0.001, 0], [0.001, 0.001]])
        self.assertAlmostEqual(haversine([0, 0], [1, 0]), 111195.0802, places=3)
        self.assertEqual(line.at(-10), [0, 0])
        self.assertLess(haversine(line.at(line.length + 10), [0.001, 0.001]), 1e-6)
        self.assertLess(haversine(line.at(line.distances[1]), [0.001, 0]), 1e-6)

    def test_headways_and_arrivals_have_known_times_without_noisy_gps_matching(self):
        simulation = world()
        events = events_until(simulation)
        self.assertEqual([event["elapsed_seconds"] for event in events if event["type"] == "spawn"], [0, 20, 40])
        arrivals = [event for event in events if event["type"] == "arrival" and event["vehicle"] == "SIM-0"]
        self.assertEqual([event["stop_sequence"] for event in arrivals], [1, 2, 3])
        self.assertAlmostEqual(arrivals[1]["elapsed_seconds"], 30, places=6)  # 10 s dwell + 200 m / 10 m/s
        self.assertEqual(len([event for event in events if event["type"] == "finish"]), 3)
        self.assertTrue(all(event["is_simulated"] for event in events))

    def test_seeded_dwell_noise_dropout_and_occupancy_are_reproducible(self):
        settings = parameters(dwell_max_seconds=30, vehicle_speed_factor_min=0.9, vehicle_speed_factor_max=1.1, dropout_probability=0.3)
        first = events_until(world(settings))
        self.assertEqual(first, events_until(world(settings)))
        self.assertNotEqual(first, events_until(world(settings, seed=99)))
        pings = [event for event in first if event["type"] == "ping"]
        self.assertTrue(any(event["dropped"] for event in pings))
        self.assertTrue(any(not event["dropped"] for event in pings))
        self.assertGreater(len({event["occupancy"] for event in pings}), 1)
        self.assertTrue(all(10 <= dwell <= 30 for vehicle in world(settings).vehicles for dwell in vehicle.dwells))

    def test_noise_changes_samples_but_never_changes_ground_truth_arrivals(self):
        clean = events_until(world(parameters(gps_noise_rms_m=0)))
        noisy = events_until(world(parameters(gps_noise_rms_m=50)))
        self.assertEqual([event for event in clean if event["type"] == "arrival"], [event for event in noisy if event["type"] == "arrival"])

    def test_incident_near_zero_speed_is_temporary_and_delays_arrivals(self):
        scenario = normal()
        scenario["incident"] = {"route_code": None, "segment_sequence": 2, "start_seconds": 40, "duration_seconds": 80, "speed_multiplier": 0.02}
        simulation = world(scenario=scenario, count=1)
        events = events_until(simulation, 400)
        slowed = [event for event in events if event["type"] == "ping" and event["segment_sequence"] == 2 and 50 <= event["elapsed_seconds"] < 120]
        self.assertTrue(slowed)
        self.assertTrue(all(event["speed_kph"] <= 0.72 + 1e-6 for event in slowed))
        baseline_end = [event for event in events_until(world(count=1), 400) if event["type"] == "arrival"][-1]["elapsed_seconds"]
        incident_end = [event for event in events if event["type"] == "arrival"][-1]["elapsed_seconds"]
        self.assertGreater(incident_end, baseline_end + 30)
        self.assertTrue(simulation.finished)

    def test_stop_approach_hour_and_segment_factors_reduce_speed(self):
        settings = parameters(approach_speed_kph=8)
        scenario = normal()
        scenario["segment_multipliers"] = {"*": {"2": 0.5}}
        simulation = world(settings, scenario, count=1)
        self.assertEqual(simulation.multiplier(simulation.vehicles[0].route, {"sequence": 2}, 0), 0.5)
        events = events_until(simulation)
        self.assertTrue(any(0 < event["speed_kph"] <= 8 for event in events if event["type"] == "ping"))

    def test_sparse_fleet_preserves_direction_coverage(self):
        assignments = [{"label": f"SIM-{i}", "route_code": "A" if i % 2 == 0 else "B"} for i in range(10)]
        self.assertEqual(len(select_fleet(assignments, 10, {"vehicle_limit": 3})), 3)
        with self.assertRaisesRegex(ValueError, "at least one"):
            select_fleet(assignments, 1, normal())

    def test_malformed_geometry_coverage_and_scenario_fail_clearly(self):
        data = route_input()
        with self.assertRaisesRegex(ValueError, "Incomplete"):
            Route(data["data"], data["segments"][:-1], 0.1)
        with self.assertRaisesRegex(ValueError, "dropout"):
            validate(parameters(dropout_probability=2), normal())
        with self.assertRaisesRegex(ValueError, "[Ii]ncident"):
            invalid = normal()
            invalid["incident"] = {"route_code": "missing", "segment_sequence": 1, "start_seconds": 0, "duration_seconds": 10, "speed_multiplier": 0.02}
            world(scenario=invalid)

    def test_offline_replays_write_byte_identical_ground_truth_and_samples(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            snapshot = {"parameters": parameters(dwell_max_seconds=30), "routes": [route_input()],
                        "assignments": [{"label": f"SIM-{i}", "route_code": "TEST"} for i in range(3)]}
            source = base / "routes.json"
            source.write_text(json.dumps(snapshot), encoding="utf-8")
            for index, speedup in enumerate([1, 20]):
                args = Namespace(env=base / ".env", offline=True, snapshot=source, allow_synthetic_route=True,
                                 vehicles=3, speedup=speedup, duration=300, seed=42, output=base / str(index), revoke_tokens=False)
                self.assertEqual(run(args, normal()), 0)
            for name in ("arrivals.csv", "samples.csv"):
                self.assertEqual((base / "0" / name).read_bytes(), (base / "1" / name).read_bytes())
            with (base / "0" / "arrivals.csv").open() as handle:
                rows = list(csv.DictReader(handle))
            self.assertEqual(len(rows), 9)
            self.assertTrue(all(row["is_simulated"] == "true" for row in rows))
