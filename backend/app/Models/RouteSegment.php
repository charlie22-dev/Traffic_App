<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteSegment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['polyline' => 'array', 'start_m' => 'float', 'end_m' => 'float', 'length_m' => 'float'];
    }
}
