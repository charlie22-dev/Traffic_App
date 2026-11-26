import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from export_osm import connected_coordinates


def way(ref, coordinates, role=""):
    return {"type": "way", "ref": ref, "role": role,
            "geometry": [{"lon": lng, "lat": lat} for lng, lat in coordinates]}


class OsmAssemblyTest(unittest.TestCase):
    def test_orients_ways_without_reordering_or_bridging(self):
        relation = {"members": [way(1, [[1, 0], [0, 0]]), way(2, [[1, 0], [2, 0]])]}
        self.assertEqual(connected_coordinates(relation), [[0, 0], [1, 0], [2, 0]])

    def test_disconnected_geometry_is_rejected(self):
        relation = {"members": [way(1, [[0, 0], [1, 0]]), way(2, [[2, 0], [3, 0]])]}
        with self.assertRaisesRegex(ValueError, "Disconnected"):
            connected_coordinates(relation)

    def test_explicit_backward_and_platform_way_are_handled(self):
        relation = {"members": [way(99, [[10, 0], [11, 0]], "platform"),
                                way(1, [[1, 0], [0, 0]], "backward"), way(2, [[1, 0], [2, 0]], "forward")]}
        self.assertEqual(connected_coordinates(relation), [[0, 0], [1, 0], [2, 0]])

    def test_missing_geometry_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "lacks geometry"):
            connected_coordinates({"members": [way(1, [])]})
