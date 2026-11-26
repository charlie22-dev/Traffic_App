<?php

namespace Database\Factories;

use App\Models\Stop;
use Illuminate\Database\Eloquent\Factories\Factory;

class StopFactory extends Factory
{
    protected $model = Stop::class;

    public function definition(): array
    {
        return ['code' => 'TEST-STOP-'.fake()->unique()->numerify('########'), 'name' => 'Synthetic test stop', 'lat' => 14.65, 'lng' => 120.95, 'source' => 'test fixture', 'verification_status' => 'needs_review', 'is_simulated' => true];
    }
}

