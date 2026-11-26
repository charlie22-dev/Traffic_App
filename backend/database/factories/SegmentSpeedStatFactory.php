<?php

namespace Database\Factories;

use App\Models\SegmentSpeedStat;
use App\Models\RouteSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

class SegmentSpeedStatFactory extends Factory
{
    protected $model = SegmentSpeedStat::class;

    public function definition(): array
    {
        return ['segment_id' => RouteSegment::factory(), 'window_start' => now()->startOfMinute(), 'avg_speed_kph' => 15, 'sample_count' => 10, 'is_simulated' => true];
    }
}

