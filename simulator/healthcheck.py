"""Read the local health endpoint. This does not create vehicles or GPS pings."""

import argparse
import json
from urllib.request import Request, urlopen


def check_health(base_url="http://127.0.0.1:8000"):
    request = Request(base_url.rstrip("/") + "/api/health", headers={"Accept": "application/json"})
    with urlopen(request, timeout=8) as response:
        payload = json.load(response)
    if payload != {"status": "ok", "service": "CommuteLive API"}:
        raise ValueError("Unexpected health response")
    return payload


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--api-url", default="http://127.0.0.1:8000")
    args = parser.parse_args()
    print(json.dumps(check_health(args.api_url)))
