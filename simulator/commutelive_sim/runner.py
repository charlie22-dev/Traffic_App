"""Wall-clock pacing, real driver API transport, output and cleanup."""

import json
import math
import os
import signal
import time
import platform
from hashlib import sha256
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path
from uuid import uuid4, uuid5

from .client import Api, ApiError, DriverClient
from .config import load, read_env
from .engine import Route, World
from .output import Logs


def validate(parameters, scenario):
    positive = ["headway_seconds", "ping_interval_seconds", "step_seconds", "reported_accuracy_m", "approach_radius_m",
                "approach_speed_kph", "occupancy_interval_seconds", "duration_seconds", "http_timeout_seconds",
                "flush_interval_wall_seconds", "status_interval_wall_seconds", "queue_limit", "ping_batch_limit", "http_retry_attempts"]
    for key in positive:
        if not math.isfinite(float(parameters[key])) or parameters[key] <= 0:
            raise ValueError(f"Invalid simulator parameter: {key}")
    if parameters["step_seconds"] > parameters["ping_interval_seconds"]:
        raise ValueError("step_seconds cannot exceed the GPS interval")
    if not math.isfinite(parameters["dropout_probability"]) or not 0 <= parameters["dropout_probability"] <= 1 or not math.isfinite(parameters["gps_noise_rms_m"]) or parameters["gps_noise_rms_m"] < 0:
        raise ValueError("Invalid dropout/noise settings")
    if not all(math.isfinite(parameters[key]) for key in ("dwell_min_seconds", "dwell_max_seconds")) or not 0 <= parameters["dwell_min_seconds"] <= parameters["dwell_max_seconds"]:
        raise ValueError("Invalid dwell interval")
    if not all(math.isfinite(parameters[key]) for key in ("vehicle_speed_factor_min", "vehicle_speed_factor_max")) or not 0 < parameters["vehicle_speed_factor_min"] <= parameters["vehicle_speed_factor_max"]:
        raise ValueError("Invalid speed-factor range")
    if len(parameters["hourly_speed_factors"]) != 24 or any(not math.isfinite(value) or value < 0 for value in parameters["hourly_speed_factors"]):
        raise ValueError("Hourly profile must contain 24 nonnegative finite factors")
    if not scenario.get("is_simulated") or not math.isfinite(scenario["traffic_multiplier"]) or scenario["traffic_multiplier"] < 0:
        raise ValueError("Scenario must be explicitly simulated with a valid traffic multiplier")
    for factors in scenario.get("segment_multipliers", {}).values():
        if any(not math.isfinite(value) or value < 0 for value in factors.values()):
            raise ValueError("Invalid segment multiplier")
    incident = scenario.get("incident")
    if incident and (not all(math.isfinite(incident[key]) for key in ("start_seconds", "duration_seconds", "speed_multiplier")) or incident["start_seconds"] < 0 or incident["duration_seconds"] <= 0 or not 0 <= incident["speed_multiplier"] <= 1):
        raise ValueError("Invalid incident interval/multiplier")
    weights = parameters["occupancy_weights"]
    if set(weights) != {"empty", "seats", "full"} or any(not math.isfinite(value) or value < 0 for value in weights.values()) or sum(weights.values()) <= 0:
        raise ValueError("Invalid occupancy weights")
    if any(not math.isfinite(value) or value <= 0 for value in parameters["free_flow_kph"].values()):
        raise ValueError("Free-flow speeds must be positive finite assumptions")


def select_fleet(assignments, requested, scenario):
    if requested > len(assignments):
        raise ValueError("Not enough provisioned vehicles; provision the requested fleet first")
    route_count = len({item["route_code"] for item in assignments})
    count = min(requested, max(route_count, scenario.get("vehicle_limit", requested)))
    if count < route_count:
        raise ValueError("Choose at least one vehicle per selected route direction")
    chosen = sorted(assignments, key=lambda item: item["label"])[:count]
    if len({item["route_code"] for item in chosen}) != route_count:
        raise ValueError("Fleet assignments must cover every selected direction")
    return chosen


def run(args, scenario):
    env = read_env(args.env)
    public = None
    credentials = []
    clients = {}
    if args.offline:
        if not args.snapshot:
            raise ValueError("Offline runs require --snapshot from a prior online run")
        snapshot = json.loads(Path(args.snapshot).read_text(encoding="utf-8"))
        parameters = snapshot["parameters"]
        route_inputs = snapshot["routes"]
        assignments = snapshot["assignments"]
    else:
        env, parameters, credentials = load(args.env)
        if env.get("SIM_TRACKING_CONSENT", "").lower() != "true":
            raise ValueError("Provision with explicit simulated tracking consent first")
        if not credentials or any(not item.get("is_simulated") for item in credentials):
            raise ValueError("The simulator requires explicitly simulated driver credentials")
        public = Api(env["SIM_API_URL"], parameters)
        route_inputs = []
        for route_id in sorted({item["route_id"] for item in credentials}):
            data = public.request(f"/routes/{route_id}")["data"]
            segments = public.request(f"/routes/{route_id}/segments")["data"]
            route_inputs.append({"data": data, "segments": segments})
        codes = {item["data"]["id"]: item["data"]["code"] for item in route_inputs}
        assignments = [{"label": item["label"], "route_code": codes[item["route_id"]]} for item in credentials]
    validate(parameters, scenario)
    if not args.allow_synthetic_route and env.get("SIM_ALLOW_SYNTHETIC_ROUTES", "").lower() != "true" and any(item["data"].get("is_simulated") for item in route_inputs):
        raise ValueError("Synthetic geometry requires --allow-synthetic-route (real-route validation remains pending)")
    if args.vehicles < 1 or args.vehicles > parameters["max_vehicles"] or not math.isfinite(args.speedup) or args.speedup <= 0:
        raise ValueError("Vehicle count/speedup is outside configured limits")
    if not args.offline and args.speedup * parameters["flush_interval_wall_seconds"] / parameters["ping_interval_seconds"] > parameters["ping_batch_limit"]:
        raise ValueError("Speedup exceeds per-driver batch throughput; reduce speedup or use offline mode")
    duration = args.duration if args.duration is not None else parameters["duration_seconds"]
    if not math.isfinite(duration) or duration <= 0:
        raise ValueError("Duration must be a positive number of simulated seconds")
    routes = {item["data"]["code"]: Route(item["data"], item["segments"], parameters["geometry_tolerance_m"]) for item in route_inputs}
    chosen = select_fleet(assignments, args.vehicles, scenario)
    world = World(routes, chosen, parameters, scenario, args.seed)
    fingerprint_routes = [{"code": route.code, "mode": route.mode, "coordinates": route.geometry.coordinates,
                           "stops": [{key: stop[key] for key in ("code", "sequence", "distance_along_route_m")} for stop in route.stops],
                           "segments": [{key: segment[key] for key in ("sequence", "start_m", "end_m")} for segment in route.segments]}
                          for route in sorted(routes.values(), key=lambda route: route.code)]
    fingerprint = sha256(json.dumps({"routes": fingerprint_routes, "assignments": chosen, "parameters": parameters, "scenario": scenario}, sort_keys=True).encode()).hexdigest()
    engine_directory = Path(__file__).parent
    engine_fingerprint = sha256((engine_directory / "engine.py").read_bytes() + (engine_directory / "geometry.py").read_bytes()).hexdigest()
    if (Path(args.output) / "unsent-pings.json").exists():
        raise ValueError("Output contains unsent pings from a failed run; preserve it and choose another --output directory")
    lock_path = Path(str(args.env) + ".run.lock")
    locked = False
    if not args.offline:
        try:
            descriptor = os.open(lock_path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        except FileExistsError:
            raise ValueError("Another run owns this credentials file. If a process crashed, confirm it stopped before removing the .run.lock file.") from None
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump({"pid": os.getpid(), "is_simulated": True}, handle)
        locked = True
    logs = None
    stopped = False
    previous_signals = {}
    def stop(_number, _frame):
        nonlocal stopped
        stopped = True
    for number in (signal.SIGINT, signal.SIGTERM):
        previous_signals[number] = signal.signal(number, stop)
    pending = {item["label"]: [] for item in chosen}
    trip_ids = {}
    desired_occupancy = {}
    last_occupancy = {}
    last_flush = {}
    last_status = {}
    in_flight = {}
    executor = None
    run_namespace = uuid4()  # Transport identity only; never enters deterministic truth files.
    counts = {"accepted": 0, "duplicates": 0, "flagged_off_route": 0, "intentional_dropouts": 0, "arrivals": 0, "spawned": 0, "finished": 0}
    wall_start = time.monotonic()
    completed = False
    cleanup_errors = []
    try:
        logs = Logs(args.output)
        executor = ThreadPoolExecutor(max_workers=len(chosen)) if not args.offline else None
        snapshot = {"is_simulated": True, "parameters": parameters, "routes": route_inputs, "assignments": assignments}
        (Path(args.output) / "routes.json").write_text(json.dumps(snapshot, indent=2) + "\n", encoding="utf-8")
        notice_version = public.request("/privacy/tracking")["data"]["version"] if public else None
        credential_by_label = {item["label"]: item for item in credentials}
        print(f"SIMULATED DATA: scenario={scenario['name']}, vehicles={len(chosen)}, seed={args.seed}, speedup={args.speedup:g}", flush=True)
        if world.incident:
            print("Synthetic incident: " + json.dumps(world.incident, sort_keys=True), flush=True)

        def send_job(client, batch, occupancy):
            response = client.api.request("/driver/pings", "POST", {"trip_id": client.trip_id, "pings": batch})["data"] if batch else None
            if occupancy is not None:
                client.api.request("/driver/status", "PUT", {"trip_id": client.trip_id, "occupancy": occupancy})
            return response

        def collect(label):
            job = in_flight.pop(label)
            try:
                response = job["future"].result()
                if response:
                    if response["counts"]["rejected"] or any(not item.get("is_simulated") for item in response["results"]):
                        codes = [item.get("code", item["status"]) for item in response["results"] if item["status"] == "rejected"]
                        raise ApiError("Simulator batch rejected: " + ", ".join(codes))
                    for key in ("accepted", "duplicates", "flagged_off_route"):
                        counts[key] += response["counts"][key]
                    logs.api_event({"type": "batch", "vehicle": label, "wall_recorded_at": datetime.now(timezone.utc).isoformat(),
                                    "simulation_elapsed_seconds": world.time, "counts": response["counts"]})
                if job["occupancy"] is not None:
                    last_occupancy[label] = job["occupancy"]
            except BaseException:
                pending[label][:0] = job["batch"]
                raise

        def flush(label, force=False):
            if args.offline or label not in clients or clients[label].trip_id is None:
                return
            client = clients[label]
            if label in in_flight:
                if not force and not in_flight[label]["future"].done():
                    return
                collect(label)
            while True:
                wall = time.monotonic()
                batch = pending[label][:parameters["ping_batch_limit"]] if force or wall - last_flush.get(label, -math.inf) >= parameters["flush_interval_wall_seconds"] else []
                occupancy = desired_occupancy.get(label)
                if occupancy == last_occupancy.get(label) or (not force and wall - last_status.get(label, -math.inf) < parameters["status_interval_wall_seconds"]):
                    occupancy = None
                if not batch and occupancy is None:
                    return
                del pending[label][:len(batch)]
                if batch:
                    last_flush[label] = wall
                if occupancy is not None:
                    last_status[label] = wall
                in_flight[label] = {"future": executor.submit(send_job, client, batch, occupancy), "batch": batch, "occupancy": occupancy}
                if not force:
                    return
                collect(label)
                if not pending[label]:
                    return

        elapsed = 0.0
        next_report = 0.0
        while not stopped:
            if not args.offline:
                delay = wall_start + elapsed / args.speedup - time.monotonic()
                while delay > 0 and not stopped:
                    time.sleep(min(delay, parameters["step_seconds"]))
                    delay = wall_start + elapsed / args.speedup - time.monotonic()
            if stopped:
                break
            for event in world.advance(elapsed):
                logs.event(event)
                label = event["vehicle"]
                if event["type"] == "arrival":
                    counts["arrivals"] += 1
                if event["type"] in ("spawn", "finish"):
                    counts["spawned" if event["type"] == "spawn" else "finished"] += 1
                if event["type"] == "ping" and event["dropped"]:
                    counts["intentional_dropouts"] += 1
                if args.offline:
                    continue
                if event["type"] == "spawn":
                    credential = credential_by_label[label]
                    client = DriverClient(Api(env["SIM_API_URL"], parameters, credential["token"]), credential)
                    clients[label] = client
                    client.start(notice_version)
                    trip_ids[label] = client.trip_id
                    print(f"Tracking ON: {label} (simulated)", flush=True)
                elif event["type"] == "occupancy":
                    desired_occupancy[label] = event["occupancy"]
                elif event["type"] == "ping":
                    if event["dropped"]:
                        continue
                    sample_key = f"{scenario['name']}|{args.seed}|{label}|{event['sample_index']}"
                    recorded_at = datetime.now(timezone.utc).isoformat(timespec="microseconds")
                    row = {key: event[key] for key in ("lat", "lng", "speed_kph", "heading", "accuracy_m", "is_simulated")}
                    row.update(client_ping_uuid=str(uuid5(run_namespace, sample_key)), recorded_at=recorded_at)
                    pending[label].append(row)
                    logs.api_event({"type": "sample_clock", "vehicle": label, "sample_index": event["sample_index"],
                                    "simulation_elapsed_seconds": event["elapsed_seconds"], "recorded_at": recorded_at,
                                    "client_ping_uuid": row["client_ping_uuid"]})
                    if len(pending[label]) > parameters["queue_limit"]:
                        raise ApiError("Simulator outbox exceeded its configured limit; reduce online speedup")
                elif event["type"] == "finish":
                    flush(label, True)
                    clients[label].close()
                    print(f"Tracking OFF: {label} (completed simulated direction)", flush=True)
            for label in pending:
                flush(label)
            if time.monotonic() >= next_report:
                print(f"Simulated elapsed={elapsed:.0f}s; accepted={counts['accepted']}; arrivals={counts['arrivals']}", flush=True)
                next_report = time.monotonic() + parameters["status_interval_wall_seconds"]
            if elapsed >= duration or world.finished:
                completed = True
                break
            elapsed = min(duration, elapsed + parameters["step_seconds"])
        for label in pending:
            flush(label, True)
    except BaseException:
        completed = False
        raise
    finally:
        if executor:
            executor.shutdown(wait=True)
        for label in list(in_flight):
            try:
                collect(label)
            except Exception as error:
                completed = False
                cleanup_errors.append(f"Upload for {label}: {error}")
        for label, client in clients.items():
            try:
                owned_tracking = client.trip_id is not None or client.start_attempted
                client.close()
                if owned_tracking:
                    print(f"Tracking OFF: {label}", flush=True)
            except ApiError as error:
                cleanup_errors.append(f"{label}: {error}")
        if args.revoke_tokens and public:
            for credential in credentials:
                try:
                    Api(env["SIM_API_URL"], parameters, credential["token"]).request("/logout", "POST", {})
                except ApiError as error:
                    cleanup_errors.append(f"Token cleanup for {credential['label']}: {error}")
        if logs:
            if any(pending.values()):
                outbox = {label: {"trip_id": trip_ids.get(label), "pings": rows, "is_simulated": True} for label, rows in pending.items() if rows}
                (Path(args.output) / "unsent-pings.json").write_text(json.dumps({"is_simulated": True, "vehicles": outbox}, indent=2) + "\n", encoding="utf-8")
            logs.metadata({"completed": completed and not cleanup_errors, "interrupted": stopped, "scenario": scenario["name"],
                           "seed": args.seed, "vehicles": len(chosen), "speedup": args.speedup, "simulation_elapsed_seconds": world.time,
                           "simulation_start_time": parameters["start_time"], "wall_elapsed_seconds": time.monotonic() - wall_start,
                           "all_vehicles_finished": world.finished,
                           "input_fingerprint_sha256": fingerprint, "python_version": platform.python_version(),
                           "engine_fingerprint_sha256": engine_fingerprint,
                           "time_basis": "arrivals.csv uses deterministic simulated time; API timestamps use wall recording time",
                           "counts": counts, "cleanup_errors": cleanup_errors, "incident": world.incident})
            logs.close()
        if locked:
            lock_path.unlink(missing_ok=True)
        for number, handler in previous_signals.items():
            signal.signal(number, handler)
    if cleanup_errors:
        raise ApiError("Tracking cleanup could not be confirmed: " + "; ".join(cleanup_errors))
    print(f"Simulation {'completed' if completed else 'interrupted'}; deterministic truth: {Path(args.output) / 'arrivals.csv'}", flush=True)
    return 0 if completed else 130
