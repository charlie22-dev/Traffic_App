<?php

namespace Database\Factories;

use App\Models\VehicleStatus;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleStatusFactory extends Factory
{
    protected $model = VehicleStatus::class;

    public function definition(): array
    {
        return ['trip_id' => Trip::factory(), 'vehicle_id' => fn (array $data) => Trip::findOrFail($data['trip_id'])->vehicle_id, 'occupancy' => 'seats', 'updated_at' => now(), 'is_simulated' => true];
    }
}

