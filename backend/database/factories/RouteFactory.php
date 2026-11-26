<?php

namespace Database\Factories;

use App\Models\Route;
use App\Support\Geometry;
use Illuminate\Database\Eloquent\Factories\Factory;

class RouteFactory extends Factory
{
    protected $model = Route::class;

    public function definition(): array
    {
        $line = [[120.95, 14.65], [120.96, 14.65]];

        return ['code' => 'TEST-'.fake()->unique()->numerify('########'), 'name' => 'Synthetic test route',
            'mode' => 'jeepney', 'direction' => 'test outbound', 'color' => '#1565c0',
            'geometry_geojson' => ['type' => 'LineString', 'coordinates' => $line], 'length_m' => Geometry::length($line),
            'is_active' => true, 'verification_status' => 'needs_review', 'is_simulated' => true,
            'source_metadata' => ['source' => 'synthetic test fixture', 'license' => 'project-test-fixture', 'notes' => 'Not a real pilot route.']];
    }
}
