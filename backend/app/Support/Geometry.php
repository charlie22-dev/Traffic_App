<?php

namespace App\Support;

use InvalidArgumentException;

/** Spherical geometry in meters. Coordinates always use GeoJSON [longitude, latitude]. */
final class Geometry
{
    public const EARTH_RADIUS_M = 6371008.8;

    public static function validatePoint(array $point): void
    {
        if (count($point) !== 2 || ! is_numeric($point[0] ?? null) || ! is_numeric($point[1] ?? null)
            || ! is_finite((float) $point[0]) || ! is_finite((float) $point[1])
            || abs((float) $point[0]) > 180 || abs((float) $point[1]) > 90) {
            throw new InvalidArgumentException('Expected a finite [longitude, latitude] coordinate in range.');
        }
    }

    public static function haversine(array $a, array $b): float
    {
        self::validatePoint($a);
        self::validatePoint($b);
        $lat1 = deg2rad($a[1]);
        $lat2 = deg2rad($b[1]);
        $h = sin(($lat2 - $lat1) / 2) ** 2
            + cos($lat1) * cos($lat2) * sin(deg2rad($b[0] - $a[0]) / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(sqrt(max(0, min(1, $h))));
    }

    private static function bearing(array $a, array $b): float
    {
        $lat1 = deg2rad($a[1]);
        $lat2 = deg2rad($b[1]);
        $lng = deg2rad($b[0] - $a[0]);

        return atan2(sin($lng) * cos($lat2), cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($lng));
    }

    public static function interpolate(array $a, array $b, float $fraction): array
    {
        if (! is_finite($fraction) || $fraction < 0 || $fraction > 1) {
            throw new InvalidArgumentException('Interpolation fraction must be between zero and one.');
        }
        $angle = self::haversine($a, $b) / self::EARTH_RADIUS_M;
        if ($angle < 1e-12) {
            return $a;
        }
        if (M_PI - $angle < 1e-8) {
            throw new InvalidArgumentException('Antipodal vertices do not define a unique route edge.');
        }
        $weightA = sin((1 - $fraction) * $angle) / sin($angle);
        $weightB = sin($fraction * $angle) / sin($angle);
        $lat1 = deg2rad($a[1]);
        $lat2 = deg2rad($b[1]);
        $lng1 = deg2rad($a[0]);
        $lng2 = deg2rad($b[0]);
        $x = $weightA * cos($lat1) * cos($lng1) + $weightB * cos($lat2) * cos($lng2);
        $y = $weightA * cos($lat1) * sin($lng1) + $weightB * cos($lat2) * sin($lng2);
        $z = $weightA * sin($lat1) + $weightB * sin($lat2);

        return [rad2deg(atan2($y, $x)), rad2deg(atan2($z, hypot($x, $y)))];
    }

    public static function length(array $line): float
    {
        if ($line === []) {
            throw new InvalidArgumentException('Polyline must have at least one point.');
        }
        $length = 0.0;
        foreach ($line as $index => $point) {
            self::validatePoint($point);
            if ($index > 0) {
                $length += self::haversine($line[$index - 1], $point);
            }
        }

        return $length;
    }

    /** Project to the nearest point on finite edges, clamping before/after their endpoints. */
    public static function project(array $point, array $line): array
    {
        self::validatePoint($point);
        self::length($line);
        $best = ['distance_along_route_m' => 0.0, 'offset_m' => self::haversine($point, $line[0]), 'point' => $line[0]];
        $cumulative = 0.0;
        for ($i = 1; $i < count($line); $i++) {
            $a = $line[$i - 1];
            $b = $line[$i];
            $length = self::haversine($a, $b);
            if ($length <= 1e-9) {
                continue;
            }
            $delta = self::haversine($a, $point) / self::EARTH_RADIUS_M;
            $bearingDiff = self::bearing($a, $point) - self::bearing($a, $b);
            $along = atan2(sin($delta) * cos($bearingDiff), cos($delta)) * self::EARTH_RADIUS_M;
            $along = max(0, min($length, $along));
            $projected = self::interpolate($a, $b, $along / $length);
            $offset = self::haversine($point, $projected);
            if ($offset < $best['offset_m'] - 1e-8) {
                $best = ['distance_along_route_m' => $cumulative + $along, 'offset_m' => $offset, 'point' => $projected];
            }
            $cumulative += $length;
        }

        return $best;
    }

    /** Split at exact cumulative distances, preserving all intermediate road vertices. */
    public static function segments(array $line, float $targetLength): array
    {
        $total = self::length($line);
        if (! is_finite($targetLength) || $targetLength <= 0 || $total <= 1e-6) {
            throw new InvalidArgumentException('Route and segment lengths must be positive.');
        }
        $distances = [0.0];
        for ($i = 1; $i < count($line); $i++) {
            $distances[] = $distances[$i - 1] + self::haversine($line[$i - 1], $line[$i]);
        }
        $at = static function (float $distance) use ($line, $distances): array {
            if ($distance <= 0) {
                return $line[0];
            }
            for ($i = 1; $i < count($line); $i++) {
                $length = $distances[$i] - $distances[$i - 1];
                if ($length > 0 && $distance <= $distances[$i]) {
                    return self::interpolate($line[$i - 1], $line[$i], ($distance - $distances[$i - 1]) / $length);
                }
            }

            return $line[array_key_last($line)];
        };
        $segments = [];
        for ($start = 0.0; $start < $total - 1e-6; $start += $targetLength) {
            $end = min($total, $start + $targetLength);
            $coordinates = [$at($start)];
            foreach ($distances as $index => $distance) {
                if ($distance > $start + 1e-8 && $distance < $end - 1e-8) {
                    $coordinates[] = $line[$index];
                }
            }
            $coordinates[] = $at($end);
            $segments[] = ['start_m' => $start, 'end_m' => $end, 'length_m' => $end - $start,
                'polyline' => ['type' => 'LineString', 'coordinates' => $coordinates]];
        }

        return $segments;
    }
}
