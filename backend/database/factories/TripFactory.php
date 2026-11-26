<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class TripFactory extends Factory
{
    protected $model = Trip::class;

    public function definition(): array
    {
        return ['vehicle_id' => Vehicle::factory(),
            'driver_id' => fn (array $data) => Vehicle::findOrFail($data['vehicle_id'])->driver_id,
            'route_id' => fn (array $data) => Vehicle::findOrFail($data['vehicle_id'])->route_id,
            'active_driver_id' => fn (array $data) => $data['driver_id'],
            'active_vehicle_id' => fn (array $data) => $data['vehicle_id'],
            'started_at' => now()->subMinutes(5), 'consented_at' => now()->subMinutes(5),
            'consent_notice_version' => config('commutelive.privacy_notice_version'), 'is_simulated' => true];
    }
}
