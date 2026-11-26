<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocationPing extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'speed_kph' => 'float', 'heading' => 'float', 'accuracy_m' => 'float',
            'distance_along_route_m' => 'float', 'offset_m' => 'float', 'recorded_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime', 'is_off_route' => 'boolean', 'is_simulated' => 'boolean'];
    }
}
