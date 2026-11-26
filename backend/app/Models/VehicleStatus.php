<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleStatus extends Model
{
    use HasFactory;
    protected $table = 'vehicle_status';
    protected $primaryKey = 'vehicle_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['updated_at' => 'immutable_datetime', 'is_simulated' => 'boolean'];
    }
}
