<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EtaPrediction extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['predicted_at' => 'immutable_datetime', 'predicted_arrival_at' => 'immutable_datetime', 'actual_arrival_at' => 'immutable_datetime',
            'naive_arrival_at' => 'immutable_datetime', 'context' => 'array', 'is_simulated' => 'boolean'];
    }
}

