"""Export one explicitly selected OSM route relation without guessing road gaps.

Run with --help. Fetches are cached; --refresh is explicit. OSM: ODbL 1.0.
Use a relation matching the actual pilot direction; never relabel another service.
"""

import argparse
import csv
import json
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
FIELDS = ["route_code", "stop_code", "name", "lat", "lng", "sequence", "source", "verification_status"]


def connected_coordinates(relation):
    """Keep relation member order and require exact shared way endpoints."""
    ways = [member for member in relation["members"]
            if member["type"] == "way" and member.get("role", "") in ("", "forward", "backward")]
    if not ways:
        raise ValueError("Relation has no route ways")

    def coordinates(way):
        result = [[point["lon"], point["lat"]] for point in way.get("geometry", [])]
        if len(result) < 2:
            raise ValueError(f"Way {way['ref']} lacks geometry")
        return result

    for reverse_first in (False, True):
        line = []
        for index, way in enumerate(ways):
            points = coordinates(way)
            role = way.get("role", "")
            if role == "backward" or (index == 0 and role == "" and reverse_first):
                points.reverse()
            if line:
                if points[0] != line[-1] and role == "" and points[-1] == line[-1]:
                    points.reverse()
                if points[0] != line[-1]:
                    break
                line.extend(points[1:])
            else:
                line.extend(points)
        else:
            return line
    raise ValueError("Disconnected or unordered route ways; no straight-line gap filling is allowed. Trace/repair the source first.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--relation-id", type=int, required=True)
    parser.add_argument("--code", required=True, help="A direction code listed in data/pilot.json")
    parser.add_argument("--refresh", action="store_true")
    parser.add_argument("--endpoint", default="https://overpass-api.de/api/interpreter")
    args = parser.parse_args()
    registry = json.loads((ROOT / "pilot.json").read_text(encoding="utf-8"))
    direction = next((item for item in registry["directions"] if item["code"] == args.code), None)
    if not direction:
        raise ValueError("Unknown pilot direction code")
    target = ROOT / "sources" / f"overpass-relation-{args.relation_id}.json"
    query = f"[out:json][timeout:60];relation({args.relation_id})->.r;.r out body geom;node(r.r);out body;"
    if args.refresh or not target.exists():
        request = Request(args.endpoint, data=urlencode({"data": query}).encode(),
                          headers={"User-Agent": "CommuteLive-capstone/0.1 (local route research)"})
        with urlopen(request, timeout=90) as response:
            payload = json.load(response)
        if "remark" in payload:
            raise ValueError(payload["remark"])
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
        target.with_suffix(".query.txt").write_text(query + "\n", encoding="utf-8")
    payload = json.loads(target.read_text(encoding="utf-8"))
    relation = next((item for item in payload["elements"] if item["type"] == "relation" and item["id"] == args.relation_id), None)
    if relation is None:
        raise ValueError("Requested relation was not returned")
    tags = relation.get("tags", {})
    if not all(word.lower() in tags.get("name", "").lower() for word in direction["required_name_terms"]):
        raise ValueError("OSM relation name does not match the pilot service; review its identity before export")
    if direction["from_term"].lower() not in tags.get("from", "").lower() or direction["to_term"].lower() not in tags.get("to", "").lower():
        raise ValueError("OSM from/to tags do not match the selected pilot direction; do not reverse or truncate automatically")
    line = connected_coordinates(relation)
    nodes = {item["id"]: item for item in payload["elements"] if item["type"] == "node"}
    platforms = [member for member in relation["members"] if member["type"] == "node" and member.get("role", "").startswith("platform")]
    stop_members = platforms or [member for member in relation["members"] if member["type"] == "node" and member.get("role", "").startswith("stop")]
    rows = []
    for member in stop_members:
        node = nodes.get(member["ref"])
        if not node:
            raise ValueError(f"Stop node {member['ref']} is missing")
        name = node.get("tags", {}).get("name")
        if not name:
            raise ValueError(f"Stop node {node['id']} has no name; supply a locally confirmed stop name rather than inventing one")
        rows.append(dict(zip(FIELDS, [args.code, f"osm-node-{node['id']}", name, node["lat"], node["lon"],
                                      len(rows) + 1, f"https://www.openstreetmap.org/node/{node['id']}", "osm_mapped"])))
    if len(rows) < 2:
        raise ValueError("Relation has fewer than two source-backed stops; supply the missing stops first")
    feature = {"type": "Feature", "properties": {
        "code": args.code, "name": direction["name"], "mode": "jeepney", "direction": direction["direction"],
        "color": direction["color"], "verification_status": "osm_mapped", "source_metadata": {
            "source": f"https://www.openstreetmap.org/relation/{args.relation_id}", "license": "ODbL-1.0",
            "osm_name": tags.get("name"), "osm_timestamp": payload.get("osm3s", {}).get("timestamp_osm_base"),
            "exported_at": datetime.now(timezone.utc).isoformat(),
            "notes": "OSM mapped; current service, terminals and boarding legality require local verification. " + direction.get("notes", ""),
        }}, "geometry": {"type": "LineString", "coordinates": line}}
    stop_path = ROOT / "stops.csv"
    with stop_path.open(encoding="utf-8-sig", newline="") as handle:
        existing = [row for row in csv.DictReader(handle) if row["route_code"] != args.code]
    (ROOT / "routes").mkdir(exist_ok=True)
    (ROOT / "routes" / f"{args.code}.geojson").write_text(json.dumps(feature, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    with stop_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=FIELDS)
        writer.writeheader()
        writer.writerows(existing + rows)
    print(f"Exported {args.code}: {len(line)} coordinates, {len(rows)} stops. Run the validating Laravel importer next.")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError) as error:
        raise SystemExit(str(error)) from error
