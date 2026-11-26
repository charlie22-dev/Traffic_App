"""Spherical distances match the Laravel helper; coordinates are [longitude, latitude]."""

from bisect import bisect_right
from math import asin, atan2, cos, degrees, hypot, isfinite, pi, radians, sin, sqrt

EARTH_RADIUS_M = 6371008.8


def point(value):
    if not isinstance(value, (list, tuple)) or len(value) != 2:
        raise ValueError("Expected [longitude, latitude]")
    lng, lat = map(float, value)
    if not isfinite(lng) or not isfinite(lat) or abs(lng) > 180 or abs(lat) > 90:
        raise ValueError("Coordinate outside WGS84 range")
    return [lng, lat]


def haversine(a, b):
    lng1, lat1 = map(radians, point(a))
    lng2, lat2 = map(radians, point(b))
    h = sin((lat2 - lat1) / 2) ** 2 + cos(lat1) * cos(lat2) * sin((lng2 - lng1) / 2) ** 2
    return 2 * EARTH_RADIUS_M * asin(sqrt(max(0, min(1, h))))


def interpolate(a, b, fraction):
    angle = haversine(a, b) / EARTH_RADIUS_M
    if angle < 1e-12:
        return list(a)
    if pi - angle < 1e-8:
        raise ValueError("Antipodal route edge is ambiguous")
    lng1, lat1 = map(radians, a)
    lng2, lat2 = map(radians, b)
    wa, wb = sin((1 - fraction) * angle) / sin(angle), sin(fraction * angle) / sin(angle)
    x = wa * cos(lat1) * cos(lng1) + wb * cos(lat2) * cos(lng2)
    y = wa * cos(lat1) * sin(lng1) + wb * cos(lat2) * sin(lng2)
    z = wa * sin(lat1) + wb * sin(lat2)
    return [degrees(atan2(y, x)), degrees(atan2(z, hypot(x, y)))]


def bearing(a, b):
    lng1, lat1 = map(radians, a)
    lng2, lat2 = map(radians, b)
    delta = lng2 - lng1
    return degrees(atan2(sin(delta) * cos(lat2), cos(lat1) * sin(lat2) - sin(lat1) * cos(lat2) * cos(delta))) % 360


def noisy_point(coordinate, east_m, north_m):
    lng, lat = map(radians, coordinate)
    delta = hypot(east_m, north_m) / EARTH_RADIUS_M
    angle = atan2(east_m, north_m)
    new_lat = asin(max(-1, min(1, sin(lat) * cos(delta) + cos(lat) * sin(delta) * cos(angle))))
    new_lng = lng + atan2(sin(angle) * sin(delta) * cos(lat), cos(delta) - sin(lat) * sin(new_lat))
    return [(degrees(new_lng) + 180) % 360 - 180, degrees(new_lat)]


class Polyline:
    def __init__(self, coordinates):
        if len(coordinates) < 2:
            raise ValueError("Route needs at least two coordinates")
        self.coordinates = [point(item) for item in coordinates]
        self.distances = [0.0]
        for a, b in zip(self.coordinates, self.coordinates[1:]):
            self.distances.append(self.distances[-1] + haversine(a, b))
        self.length = self.distances[-1]
        if self.length <= 0:
            raise ValueError("Route has zero length")

    def at(self, distance):
        distance = max(0, min(self.length, distance))
        index = min(len(self.coordinates) - 2, bisect_right(self.distances, distance) - 1)
        while index > 0 and self.distances[index + 1] == self.distances[index]:
            index -= 1
        length = self.distances[index + 1] - self.distances[index]
        fraction = (distance - self.distances[index]) / length if length else 0
        return interpolate(self.coordinates[index], self.coordinates[index + 1], fraction)

    def heading(self, distance):
        index = min(len(self.coordinates) - 2, max(0, bisect_right(self.distances, distance) - 1))
        return bearing(self.coordinates[index], self.coordinates[index + 1])
