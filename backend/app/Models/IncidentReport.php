<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncidentReport extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'expires_at' => 'immutable_datetime', 'is_simulated' => 'boolean'];
    }
}
