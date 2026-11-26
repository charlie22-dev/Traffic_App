"""Pure seeded motion. No wall clock, API IDs, network calls or transport UUIDs affect truth."""

from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from hashlib import sha256
from math import isfinite, sqrt
from random import Random

from .geometry import Polyline, noisy_point


def rng(seed, label, stream):
    return Random(int.from_bytes(sha256(f"{seed}|{label}|{stream}".encode()).digest(), "big"))


class Route:
    def __init__(self, data, segments, tolerance):
        self.data = data
        self.code = data["code"]
        self.mode = data["mode"]
        if data["geometry_geojson"]["type"] != "LineString":
            raise ValueError("Route geometry must be a LineString")
        self.geometry = Polyline(data["geometry_geojson"]["coordinates"])
        self.length = float(data["length_m"])
        if abs(self.geometry.length - self.length) > tolerance:
            raise ValueError(f"Geometry length mismatch: {self.code}")
        self.stops = sorted(data["stops"], key=lambda item: item["sequence"])
        if len(self.stops) < 2:
            raise ValueError(f"Missing stops: {self.code}")
        previous = -1.0
        for index, stop in enumerate(self.stops):
            distance = float(stop["distance_along_route_m"])
            if stop["sequence"] != index + 1 or distance < previous or distance < 0 or distance > self.length + tolerance:
                raise ValueError(f"Invalid stop order/distance: {self.code}")
            previous = distance
        self.segments = sorted(segments, key=lambda item: item["sequence"])
        end = 0.0
        for index, segment in enumerate(self.segments):
            if segment["sequence"] != index + 1 or abs(segment["start_m"] - end) > tolerance or segment["end_m"] <= segment["start_m"]:
                raise ValueError(f"Invalid segment coverage: {self.code}")
            end = segment["end_m"]
        if not self.segments or abs(end - self.length) > tolerance:
            raise ValueError(f"Incomplete segment coverage: {self.code}")

    def segment(self, distance):
        return next((segment for segment in self.segments if distance < segment["end_m"] - 1e-8), self.segments[-1])


@dataclass
class Vehicle:
    label: str
    route: Route
    spawn_at: float
    behavior: Random
    noise: Random
    dropout: Random
    speed_factor: float
    dwells: list
    distance: float = 0.0
    speed: float = 0.0
    started: bool = False
    finished: bool = False
    stop_index: int = 0
    dwell_until: float = 0.0
    next_ping: float = 0.0
    next_occupancy: float = 0.0
    sample_index: int = 0
    occupancy: str = "empty"


class World:
    def __init__(self, routes, assignments, parameters, scenario, seed):
        self.parameters = parameters
        self.scenario = scenario
        self.seed = seed
        self.time = 0.0
        self.origin = datetime.fromisoformat(parameters["start_time"])
        if self.origin.tzinfo is None:
            raise ValueError("Simulation start_time must include a timezone")
        self.incident = dict(scenario["incident"]) if scenario.get("incident") else None
        if self.incident:
            if self.incident["route_code"] is None:
                self.incident["route_code"] = sorted(routes)[0]
            route = routes.get(self.incident["route_code"])
            if not route or not any(segment["sequence"] == self.incident["segment_sequence"] for segment in route.segments):
                raise ValueError("Incident references an unavailable route/segment; edit the scenario explicitly")
        self.vehicles = []
        route_counts = {}
        for assignment in sorted(assignments, key=lambda item: item["label"]):
            route = routes[assignment["route_code"]]
            index = route_counts.get(route.code, 0)
            route_counts[route.code] = index + 1
            label = assignment["label"]
            behavior = rng(seed, label, "behavior")
            spawn = index * parameters["headway_seconds"]
            factor = behavior.uniform(parameters["vehicle_speed_factor_min"], parameters["vehicle_speed_factor_max"])
            dwells = [behavior.uniform(parameters["dwell_min_seconds"], parameters["dwell_max_seconds"]) for _ in route.stops]
            self.vehicles.append(Vehicle(label, route, spawn, behavior, rng(seed, label, "noise"), rng(seed, label, "dropout"), factor, dwells, next_ping=spawn, next_occupancy=spawn))

    def timestamp(self, elapsed):
        return (self.origin + timedelta(seconds=elapsed)).astimezone(timezone.utc).isoformat(timespec="microseconds")

    def _event(self, kind, vehicle, elapsed, **data):
        return {"type": kind, "vehicle": vehicle.label, "route_code": vehicle.route.code,
                "elapsed_seconds": elapsed, "is_simulated": True, **data}

    def multiplier(self, route, segment, elapsed):
        hour = (self.origin + timedelta(seconds=elapsed)).hour
        value = self.parameters["hourly_speed_factors"][hour] * self.scenario["traffic_multiplier"]
        multipliers = self.scenario.get("segment_multipliers", {})
        for key in ("*", route.code):
            value *= multipliers.get(key, {}).get(str(segment["sequence"]), 1)
        incident = self.incident
        if incident and route.code == incident["route_code"] and segment["sequence"] == incident["segment_sequence"] and incident["start_seconds"] <= elapsed < incident["start_seconds"] + incident["duration_seconds"]:
            value *= incident["speed_multiplier"]
        return value

    def _move(self, vehicle, start, end, events):
        cursor = max(start, vehicle.spawn_at)
        if not vehicle.started:
            vehicle.started = True
            events.append(self._event("spawn", vehicle, cursor))
        while cursor < end - 1e-9 and not vehicle.finished:
            if vehicle.dwell_until > cursor + 1e-9:
                vehicle.speed = 0
                cursor = min(end, vehicle.dwell_until)
                continue
            route = vehicle.route
            stop = route.stops[vehicle.stop_index] if vehicle.stop_index < len(route.stops) else None
            if stop and stop["distance_along_route_m"] <= vehicle.distance + 1e-7:
                events.append(self._event("arrival", vehicle, cursor, stop=stop["code"], stop_sequence=stop["sequence"], actual_arrival_at=self.timestamp(cursor)))
                vehicle.dwell_until = cursor + vehicle.dwells[vehicle.stop_index]
                vehicle.stop_index += 1
                vehicle.speed = 0
                continue
            if vehicle.distance >= route.length - 1e-7:
                vehicle.finished = True
                vehicle.speed = 0
                events.append(self._event("finish", vehicle, cursor))
                break
            segment = route.segment(vehicle.distance)
            speed = self.parameters["free_flow_kph"][route.mode] * vehicle.speed_factor * self.multiplier(route, segment, cursor)
            target = min(route.length, segment["end_m"])
            if stop:
                target = min(target, stop["distance_along_route_m"])
                gap = stop["distance_along_route_m"] - vehicle.distance
                if gap <= self.parameters["approach_radius_m"] + 1e-7:
                    speed = min(speed, self.parameters["approach_speed_kph"])
                else:
                    target = min(target, stop["distance_along_route_m"] - self.parameters["approach_radius_m"])
            vehicle.speed = speed
            if speed <= 0:
                cursor = end
                continue
            seconds = min(end - cursor, (target - vehicle.distance) / (speed / 3.6))
            if seconds <= 1e-10:
                vehicle.distance = target
                cursor = min(end, cursor + 1e-9)
                continue
            vehicle.distance = min(target, vehicle.distance + speed / 3.6 * seconds)
            cursor += seconds
        # Process a stop reached exactly at the tick boundary without a one-tick delay.
        route = vehicle.route
        if vehicle.stop_index < len(route.stops) and vehicle.dwell_until <= end + 1e-9:
            stop = route.stops[vehicle.stop_index]
            if abs(stop["distance_along_route_m"] - vehicle.distance) < 1e-7:
                events.append(self._event("arrival", vehicle, end, stop=stop["code"], stop_sequence=stop["sequence"], actual_arrival_at=self.timestamp(end)))
                vehicle.dwell_until = end + vehicle.dwells[vehicle.stop_index]
                vehicle.stop_index += 1
                vehicle.speed = 0

    def advance(self, elapsed):
        if elapsed < self.time or not isfinite(elapsed):
            raise ValueError("Simulation time must advance monotonically")
        events = []
        for vehicle in self.vehicles:
            if vehicle.finished or elapsed < vehicle.spawn_at:
                continue
            self._move(vehicle, self.time, elapsed, events)
            if not vehicle.started or vehicle.finished:
                continue
            if elapsed >= vehicle.next_occupancy - 1e-9:
                weights = self.parameters["occupancy_weights"]
                vehicle.occupancy = vehicle.behavior.choices(list(weights), list(weights.values()), k=1)[0]
                vehicle.next_occupancy = elapsed + self.parameters["occupancy_interval_seconds"]
                events.append(self._event("occupancy", vehicle, elapsed, occupancy=vehicle.occupancy))
            if elapsed >= vehicle.next_ping - 1e-9:
                coordinate = vehicle.route.geometry.at(vehicle.distance)
                sigma = self.parameters["gps_noise_rms_m"] / sqrt(2)
                noisy = noisy_point(coordinate, vehicle.noise.gauss(0, sigma), vehicle.noise.gauss(0, sigma))
                dropped = vehicle.dropout.random() < self.parameters["dropout_probability"]
                events.append(self._event("ping", vehicle, elapsed, sample_index=vehicle.sample_index,
                    true_distance_m=vehicle.distance, lat=noisy[1], lng=noisy[0], speed_kph=vehicle.speed,
                    heading=vehicle.route.geometry.heading(vehicle.distance), accuracy_m=self.parameters["reported_accuracy_m"],
                    dropped=dropped, segment_sequence=vehicle.route.segment(vehicle.distance)["sequence"], occupancy=vehicle.occupancy))
                vehicle.sample_index += 1
                vehicle.next_ping = elapsed + self.parameters["ping_interval_seconds"]
        self.time = elapsed
        order = {"spawn": 0, "arrival": 1, "occupancy": 2, "ping": 3, "finish": 4}
        return sorted(events, key=lambda event: (event["elapsed_seconds"], event["vehicle"], order[event["type"]]))

    @property
    def finished(self):
        return all(vehicle.finished for vehicle in self.vehicles)
