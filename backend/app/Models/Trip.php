<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Trip extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'consented_at' => 'immutable_datetime', 'is_simulated' => 'boolean'];
    }

    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function route(): BelongsTo { return $this->belongsTo(Route::class); }
    public function latestMatchedPing(): HasOne
    {
        return $this->hasOne(LocationPing::class)->ofMany(['recorded_at' => 'max', 'id' => 'max'], fn ($query) => $query->where('is_off_route', false));
    }
}
