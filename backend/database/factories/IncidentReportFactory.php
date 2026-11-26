<?php

namespace Database\Factories;

use App\Models\IncidentReport;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncidentReportFactory extends Factory
{
    protected $model = IncidentReport::class;

    public function definition(): array
    {
        return ['trip_id' => Trip::factory(), 'route_id' => fn (array $data) => Trip::findOrFail($data['trip_id'])->route_id,
            'reporter_id' => fn (array $data) => Trip::findOrFail($data['trip_id'])->driver_id,
            'type' => 'heavy_traffic', 'lat' => 14.65, 'lng' => 120.951, 'expires_at' => now()->addMinutes(30), 'is_simulated' => true];
    }
}

