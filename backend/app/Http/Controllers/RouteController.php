<?php

namespace App\Http\Controllers;

use App\Models\Route;
use Illuminate\Http\JsonResponse;

class RouteController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Route::where('is_active', true)
            ->select(['id', 'code', 'name', 'mode', 'direction', 'color', 'length_m', 'verification_status', 'source_metadata'])
            ->withCount(['stops', 'segments'])->orderBy('code')->get()]);
    }

    public function show(Route $route): JsonResponse
    {
        abort_unless($route->is_active, 404);
        $data = $route->toArray();
        $data['stops'] = $route->stops->map(fn ($stop) => [
            'id' => $stop->id, 'code' => $stop->code, 'name' => $stop->name,
            'lat' => $stop->lat, 'lng' => $stop->lng, 'sequence' => (int) $stop->pivot->sequence,
            'distance_along_route_m' => (float) $stop->pivot->distance_along_route_m,
            'offset_m' => (float) $stop->pivot->offset_m, 'verification_status' => $stop->verification_status, 'source' => $stop->source,
        ]);

        return response()->json(['data' => $data]);
    }

    public function segments(Route $route): JsonResponse
    {
        abort_unless($route->is_active, 404);

        return response()->json(['route_id' => $route->id, 'verification_status' => $route->verification_status,
            'data' => $route->segments()->get()]);
    }
}
