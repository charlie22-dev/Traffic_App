<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['geometry_geojson' => 'array', 'source_metadata' => 'array', 'is_active' => 'boolean', 'is_simulated' => 'boolean', 'length_m' => 'float'];
    }

    public function stops(): BelongsToMany
    {
        return $this->belongsToMany(Stop::class, 'route_stops')->withPivot('sequence', 'distance_along_route_m', 'offset_m')->orderByPivot('sequence');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(RouteSegment::class)->orderBy('sequence');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
