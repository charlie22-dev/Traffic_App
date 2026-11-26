<?php

namespace Database\Factories;

use App\Models\LocationPing;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class LocationPingFactory extends Factory
{
    protected $model = LocationPing::class;

    public function definition(): array
    {
        return ['client_ping_uuid' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()),
            'trip_id' => Trip::factory(), 'vehicle_id' => fn (array $data) => Trip::findOrFail($data['trip_id'])->vehicle_id,
            'lat' => 14.65, 'lng' => 120.951, 'accuracy_m' => 5, 'speed_kph' => 20, 'heading' => 90,
            'recorded_at' => now(), 'received_at' => now(), 'offset_m' => 0, 'distance_along_route_m' => 107,
            'segment_id' => null, 'is_off_route' => false, 'is_simulated' => true];
    }
}
