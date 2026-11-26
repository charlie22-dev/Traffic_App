"""Read ignored credentials; defaults come from Laravel provisioning, not a second constants file."""

import ast
import json
import os
from pathlib import Path


def read_env(path):
    values = {}
    if Path(path).exists():
        for line in Path(path).read_text(encoding="utf-8-sig").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            value = value.strip()
            if value.startswith(("'", '"')):
                value = ast.literal_eval(value)
            values[key.strip()] = value
    for key in ("SIM_API_URL", "SIM_DRIVERS_JSON", "SIM_PARAMETERS_JSON", "SIM_TRACKING_CONSENT", "SIM_ALLOW_SYNTHETIC_ROUTES"):
        if key in os.environ:
            values[key] = os.environ[key]
    return values


def load(path):
    values = read_env(path)
    if not values.get("SIM_PARAMETERS_JSON"):
        raise ValueError("Provision the simulator first: php artisan commutelive:provision-simulator --consent-simulated-tracking (see simulator/README.md)")
    parameters = json.loads(values["SIM_PARAMETERS_JSON"])
    credentials = json.loads(values.get("SIM_DRIVERS_JSON", "[]"))
    return values, parameters, credentials
