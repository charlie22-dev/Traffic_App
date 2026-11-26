<?php

namespace App\Http\Controllers;

use App\Models\IncidentReport;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveController extends Controller
{
    public function vehicles(Request $request): JsonResponse
    {
        $filter = $request->validate(['route_id' => 'sometimes|integer|min:1']);
        $now = now();
        $vehicles = Vehicle::whereHas('activeTrip')->whereHas('route', fn ($query) => $query->where('is_active', true))
            ->when(isset($filter['route_id']), fn ($query) => $query->where('route_id', $filter['route_id']))
            ->with(['activeTrip.latestMatchedPing', 'status'])->orderBy('id')->get();
        $data = [];
        foreach ($vehicles as $vehicle) {
            $trip = $vehicle->activeTrip;
            $ping = $trip?->latestMatchedPing;
            if (! $ping || $ping->recorded_at->lt($now->copy()->subSeconds(config('commutelive.vehicle_stale_seconds')))) {
                continue;
            }
            $status = $vehicle->status;
            $occupancyValid = $status && $status->trip_id === $trip->id
                && $status->updated_at->gte($now->copy()->subSeconds(config('commutelive.occupancy_stale_seconds')));
            $data[] = [
                'id' => $vehicle->id, 'route_id' => $trip->route_id, 'label' => $vehicle->label, 'mode' => $vehicle->mode,
                'lat' => $ping->lat, 'lng' => $ping->lng, 'speed_kph' => $ping->speed_kph, 'heading' => $ping->heading,
                'accuracy_m' => $ping->accuracy_m, 'distance_along_route_m' => $ping->distance_along_route_m, 'segment_id' => $ping->segment_id,
                'occupancy' => $occupancyValid ? $status->occupancy : 'unknown',
                'recorded_at' => $ping->recorded_at->toIso8601String(), 'freshness_seconds' => max(0, (int) $ping->recorded_at->diffInSeconds($now, false)),
                'is_simulated' => $trip->is_simulated || $ping->is_simulated || $vehicle->is_simulated, 'tracking_on' => true,
            ];
        }

        return response()->json(['data' => $data, 'contains_simulated_data' => collect($data)->contains('is_simulated', true)]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $filter = $request->validate(['route_id' => 'sometimes|integer|min:1']);
        $data = IncidentReport::where('expires_at', '>', now())
            ->when(isset($filter['route_id']), fn ($query) => $query->where('route_id', $filter['route_id']))
            ->orderBy('id')->get(['id', 'type', 'lat', 'lng', 'route_id', 'expires_at', 'is_simulated']);

        return response()->json(['data' => $data, 'contains_simulated_data' => $data->contains('is_simulated', true)]);
    }
}
