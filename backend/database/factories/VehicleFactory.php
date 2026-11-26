<?php

namespace Database\Factories;

use App\Models\Route;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return ['driver_id' => User::factory()->driver(), 'route_id' => Route::factory(),
            'label' => 'SIM-'.fake()->unique()->numerify('########'), 'mode' => 'jeepney', 'is_simulated' => true];
    }
}
