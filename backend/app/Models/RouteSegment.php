<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RouteSegment extends Model
{
    use HasFactory;
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['polyline' => 'array', 'start_m' => 'float', 'end_m' => 'float', 'length_m' => 'float', 'is_simulated' => 'boolean'];
    }
}
