<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SegmentBaseline extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['avg_speed_kph' => 'float', 'is_simulated' => 'boolean'];
    }
}

