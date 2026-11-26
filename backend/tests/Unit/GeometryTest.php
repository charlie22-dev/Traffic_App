<?php

namespace Tests\Unit;

use App\Support\Geometry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GeometryTest extends TestCase
{
    public function test_haversine_known_equatorial_distance_and_symmetry(): void
    {
        $this->assertEqualsWithDelta(111195.0802, Geometry::haversine([0, 0], [1, 0]), 0.001);
        $this->assertSame(0.0, Geometry::haversine([121, 14.6], [121, 14.6]));
        $this->assertEqualsWithDelta(Geometry::haversine([120.9, 14.5], [121, 14.6]), Geometry::haversine([121, 14.6], [120.9, 14.5]), 1e-8);
    }

    public function test_projection_returns_along_distance_and_perpendicular_offset(): void
    {
        $result = Geometry::project([0.5, 0.1], [[0, 0], [1, 0]]);
        $this->assertEqualsWithDelta(55597.5401, $result['distance_along_route_m'], 0.001);
        $this->assertEqualsWithDelta(11119.508, $result['offset_m'], 0.001);
        $this->assertEqualsWithDelta(0.5, $result['point'][0], 1e-9);
    }

    public function test_before_start_and_after_end_are_clamped(): void
    {
        $line = [[0, 0], [1, 0]];
        $before = Geometry::project([-1, 0], $line);
        $after = Geometry::project([2, 0], $line);
        $this->assertSame(0.0, $before['distance_along_route_m']);
        $this->assertEqualsWithDelta(111195.0802, $after['distance_along_route_m'], 0.001);
        $this->assertEqualsWithDelta(111195.0802, $before['offset_m'], 0.001);
        $this->assertEqualsWithDelta(111195.0802, $after['offset_m'], 0.001);
    }

    public function test_far_point_keeps_a_large_offset_instead_of_claiming_a_match(): void
    {
        $result = Geometry::project([0.5, 10], [[0, 0], [1, 0]]);
        $this->assertGreaterThan(1000000, $result['offset_m']);
        $this->assertEqualsWithDelta(55597.5401, $result['distance_along_route_m'], 0.001);
    }

    public function test_multiedge_repeated_vertices_and_singleton(): void
    {
        $line = [[0, 0], [0, 0], [0.01, 0], [0.01, 0.01]];
        $result = Geometry::project([0.01, 0.005], $line);
        $this->assertEqualsWithDelta(1667.9262, $result['distance_along_route_m'], 0.001);
        $this->assertLessThan(0.001, $result['offset_m']);
        $singleton = Geometry::project([1, 0], [[0, 0]]);
        $this->assertSame(0.0, $singleton['distance_along_route_m']);
        $this->assertGreaterThan(111000, $singleton['offset_m']);
    }

    public function test_antimeridian_and_high_latitude(): void
    {
        $result = Geometry::project([180, 0.1], [[179, 0], [-179, 0]]);
        $this->assertEqualsWithDelta(111195.0802, $result['distance_along_route_m'], 0.001);
        $this->assertEqualsWithDelta(11119.508, $result['offset_m'], 0.001);
        $midpoint = Geometry::interpolate([10, 80], [11, 80], 0.5);
        $this->assertLessThan(0.001, Geometry::project($midpoint, [[10, 80], [11, 80]])['offset_m']);
    }

    public function test_segmenter_preserves_bends_and_total_length_with_short_last_segment(): void
    {
        $line = [[0, 0], [0.003, 0], [0.003, 0.004]];
        $segments = Geometry::segments($line, 250);
        $this->assertCount(4, $segments);
        $this->assertEqualsWithDelta(Geometry::length($line), array_sum(array_column($segments, 'length_m')), 1e-6);
        foreach ($segments as $index => $segment) {
            $this->assertEqualsWithDelta($segment['length_m'], Geometry::length($segment['polyline']['coordinates']), 1e-5);
            if ($index > 0) {
                $previous = $segments[$index - 1]['polyline']['coordinates'];
                $this->assertSame($previous[array_key_last($previous)], $segment['polyline']['coordinates'][0]);
            }
        }
        $this->assertContains([0.003, 0], $segments[1]['polyline']['coordinates']);
        $this->assertLessThan(250, $segments[3]['length_m']);
    }

    public function test_short_route_is_one_segment(): void
    {
        $this->assertCount(1, Geometry::segments([[0, 0], [0.0001, 0]], 250));
    }

    public function test_empty_polyline_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Geometry::project([0, 0], []);
    }

    public function test_invalid_coordinate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Geometry::haversine([INF, 0], [0, 0]);
    }

    public function test_antipodal_interpolation_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Geometry::interpolate([0, 0], [180, 0], 0.5);
    }

    public function test_zero_length_route_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Geometry::segments([[0, 0], [0, 0]], 250);
    }

    public function test_invalid_segment_size_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Geometry::segments([[0, 0], [1, 0]], 0);
    }
}
