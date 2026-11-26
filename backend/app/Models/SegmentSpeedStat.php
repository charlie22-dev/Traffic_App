<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SegmentSpeedStat extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['window_start' => 'immutable_datetime', 'avg_speed_kph' => 'float', 'is_simulated' => 'boolean'];
    }
}

