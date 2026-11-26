"""Real HTTP driver API. Tokens are read from .env and never logged."""

import json
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import Request, urlopen


class ApiError(RuntimeError):
    pass


class Api:
    def __init__(self, base_url, parameters, token=None, sleep=time.sleep):
        parsed = urlparse(base_url)
        if parsed.scheme not in ("http", "https") or not parsed.hostname or parsed.username or parsed.password:
            raise ValueError("Use an HTTP(S) origin without embedded credentials")
        self.base_url = base_url.rstrip("/")
        self.parameters = parameters
        self.token = token
        self.sleep = sleep

    def request(self, path, method="GET", body=None, attempts=None):
        attempts = attempts or self.parameters["http_retry_attempts"]
        for attempt in range(attempts):
            request = Request(self.base_url + "/api" + path, method=method,
                              data=json.dumps(body).encode() if body is not None else None,
                              headers={"Accept": "application/json", "Content-Type": "application/json",
                                       **({"Authorization": "Bearer " + self.token} if self.token else {})})
            try:
                with urlopen(request, timeout=self.parameters["http_timeout_seconds"]) as response:
                    return json.load(response)
            except HTTPError as error:
                if error.code not in (429, 500, 502, 503, 504) or attempt + 1 == attempts:
                    raise ApiError(f"{method} {path} failed (HTTP {error.code}); check auth/ownership/limits and backend validation") from None
                retry = error.headers.get("Retry-After")
                delay = float(retry) if retry and retry.isdigit() else self.parameters["http_retry_delay_seconds"] * 2 ** attempt
            except (URLError, TimeoutError, OSError):
                if attempt + 1 == attempts:
                    raise ApiError(f"{method} {path} could not reach the API after bounded retries") from None
                delay = self.parameters["http_retry_delay_seconds"] * 2 ** attempt
            self.sleep(delay)
        raise ApiError("HTTP request did not complete")


class DriverClient:
    def __init__(self, api, credential):
        self.api = api
        self.credential = credential
        self.trip_id = None
        self.start_attempted = False

    def start(self, notice_version):
        vehicles = self.api.request("/driver/vehicles")["data"]
        vehicle = next((item for item in vehicles if item["id"] == self.credential["vehicle_id"]), None)
        if not vehicle or not vehicle["is_simulated"] or vehicle["route_id"] != self.credential["route_id"]:
            raise ApiError("Simulator refuses to track an unassigned, real or re-routed vehicle")
        if self.api.request("/driver/trips/active")["data"] is not None:
            raise ApiError("Simulator driver already has an active trip; this run will not take it over")
        self.start_attempted = True
        try:
            result = self.api.request("/driver/trips/start", "POST", {
                "vehicle_id": vehicle["id"], "tracking_consent": True, "privacy_notice_version": notice_version,
            }, attempts=1)
            trip = result["data"]
        except ApiError:
            # A lost response must not create another trip or leave an unknown trip ON.
            trip = self.api.request("/driver/trips/active")["data"]
            if not trip:
                raise
        if trip["vehicle_id"] != vehicle["id"] or not trip["is_simulated"]:
            raise ApiError("Recovered trip does not match the simulated vehicle")
        self.trip_id = trip["id"]

    def close(self):
        if self.trip_id is None and self.start_attempted:
            trip = self.api.request("/driver/trips/active")["data"]
            if trip and trip["vehicle_id"] == self.credential["vehicle_id"] and trip["is_simulated"]:
                self.trip_id = trip["id"]
        if self.trip_id is not None:
            self.api.request(f"/driver/trips/{self.trip_id}/end", "POST", {})
            self.trip_id = None
            self.start_attempted = False
