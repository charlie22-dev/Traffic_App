"""Fetch pilot transit relations from Overpass, caching the original response.

OSM data is ODbL 1.0: https://www.openstreetmap.org/copyright
This queries transit relations only; it does not invent missing route geometry.
"""

import argparse
import json
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
QUERY = '''[out:json][timeout:90];
(
relation["type"="route"]["name"~"Malabon|Navotas|Gasak|Monumento",i](14.58,120.91,14.73,121.02);
relation["type"="route"]["ref"~"T104|T107"](14.58,120.91,14.73,121.02);
relation(11246064); relation(11246065);
);
out body geom;
'''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--refresh", action="store_true")
    parser.add_argument("--endpoint", default="https://overpass-api.de/api/interpreter")
    args = parser.parse_args()
    target = ROOT / "sources" / "osm-pilot-candidates.json"
    target.parent.mkdir(parents=True, exist_ok=True)
    if args.refresh or not target.exists():
        request = Request(args.endpoint, data=urlencode({"data": QUERY}).encode(),
                          headers={"User-Agent": "CommuteLive-capstone/0.1 (local route data research)"})
        with urlopen(request, timeout=120) as response:
            payload = json.load(response)
        if "remark" in payload:
            raise RuntimeError(payload["remark"])
        target.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        (target.parent / "pilot-query.overpassql").write_text(QUERY, encoding="utf-8")
    payload = json.loads(target.read_text(encoding="utf-8"))
    for relation in payload["elements"]:
        tags = relation.get("tags", {})
        print(json.dumps({"id": relation["id"], "tags": tags, "members": len(relation.get("members", []))}))


if __name__ == "__main__":
    main()
