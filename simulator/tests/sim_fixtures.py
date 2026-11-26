"""Explicit small test inputs, not production defaults (those come from Laravel)."""

from commutelive_sim.geometry import haversine


def parameters(**overrides):
    result = {
        "free_flow_kph": {"jeepney": 36, "bus": 48}, "headway_seconds": 20,
        "dwell_min_seconds": 10, "dwell_max_seconds": 10, "ping_interval_seconds": 5, "step_seconds": 1,
        "gps_noise_rms_m": 5, "reported_accuracy_m": 15, "dropout_probability": 0.02,
        "approach_radius_m": 30, "approach_speed_kph": 36,
        "vehicle_speed_factor_min": 1, "vehicle_speed_factor_max": 1,
        "occupancy_interval_seconds": 20, "occupancy_weights": {"empty": 1, "seats": 1, "full": 1},
        "hourly_speed_factors": [1] * 24, "start_time": "2026-11-23T07:00:00+08:00", "duration_seconds": 300,
        "http_timeout_seconds": 2, "http_retry_attempts": 2, "http_retry_delay_seconds": 0,
        "flush_interval_wall_seconds": 1.2, "status_interval_wall_seconds": 5, "queue_limit": 1000,
        "ping_batch_limit": 200, "max_vehicles": 50, "geometry_tolerance_m": 0.1,
    }
    result.update(overrides)
    return result


def route_input(code="TEST", identifier=1):
    length = haversine([0, 0], [0.01, 0])
    segments = []
    start = 0
    while start < length:
        end = min(length, start + 250)
        segments.append({"sequence": len(segments) + 1, "start_m": start, "end_m": end, "length_m": end - start})
        start = end
    return {"data": {"id": identifier, "code": code, "mode": "jeepney", "is_simulated": True,
        "geometry_geojson": {"type": "LineString", "coordinates": [[0, 0], [0.01, 0]]}, "length_m": length,
        "stops": [{"code": f"{code}-{index}", "sequence": index + 1, "distance_along_route_m": distance}
                  for index, distance in enumerate([0, 200, length])]}, "segments": segments}


def normal():
    return {"name": "normal", "traffic_multiplier": 1, "segment_multipliers": {}, "incident": None, "is_simulated": True}
