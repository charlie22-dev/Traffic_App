<?php

namespace Database\Factories;

use App\Models\EtaPrediction;
use App\Models\Vehicle;
use App\Models\Stop;
use Illuminate\Database\Eloquent\Factories\Factory;

class EtaPredictionFactory extends Factory
{
    protected $model = EtaPrediction::class;

    public function definition(): array
    {
        return ['vehicle_id' => Vehicle::factory(), 'stop_id' => Stop::factory(), 'predicted_at' => now(), 'predicted_arrival_at' => now()->addMinutes(5), 'method' => 'test-fixture', 'is_simulated' => true];
    }
}

