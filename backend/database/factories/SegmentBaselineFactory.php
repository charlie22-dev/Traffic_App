<?php

namespace Database\Factories;

use App\Models\SegmentBaseline;
use App\Models\RouteSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

class SegmentBaselineFactory extends Factory
{
    protected $model = SegmentBaseline::class;

    public function definition(): array
    {
        return ['segment_id' => RouteSegment::factory(), 'day_type' => 'weekday', 'hour' => 8, 'avg_speed_kph' => 20, 'sample_count' => 10, 'is_simulated' => true];
    }
}

