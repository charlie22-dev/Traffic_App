<?php

namespace Database\Factories;

use App\Models\RouteSegment;
use App\Models\Route;
use App\Support\Geometry;
use Illuminate\Database\Eloquent\Factories\Factory;

class RouteSegmentFactory extends Factory
{
    protected $model = RouteSegment::class;

    public function definition(): array
    {
        return ['route_id' => Route::factory(), 'sequence' => 1, 'start_m' => 0, 'end_m' => 250, 'length_m' => 250,
            'polyline' => fn (array $data) => ['type' => 'LineString', 'coordinates' => [[120.95, 14.65], Geometry::interpolate([120.95, 14.65], [120.96, 14.65], 250 / Geometry::haversine([120.95, 14.65], [120.96, 14.65]))]], 'is_simulated' => true];
    }
}

