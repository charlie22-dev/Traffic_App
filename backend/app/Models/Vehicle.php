<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_simulated' => 'boolean'];
    }

    public function driver(): BelongsTo { return $this->belongsTo(User::class, 'driver_id'); }
    public function route(): BelongsTo { return $this->belongsTo(Route::class); }
    public function activeTrip(): HasOne { return $this->hasOne(Trip::class)->whereNull('ended_at'); }
    public function status(): HasOne { return $this->hasOne(VehicleStatus::class); }
}
