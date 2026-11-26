<?php

namespace App\Http\Controllers;

use App\Models\IncidentReport;
use App\Models\VehicleStatus;
use App\Services\DriverTrips;
use App\Support\Geometry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DriverReportController extends Controller
{
    public function status(Request $request, DriverTrips $trips): JsonResponse
    {
        $data = $request->validate(['trip_id' => 'required|integer', 'occupancy' => 'required|in:empty,seats,full']);
        $status = DB::transaction(function () use ($request, $trips, $data) {
            $trip = $trips->owned($request->user(), $data['trip_id'], true);

            return VehicleStatus::updateOrCreate(['vehicle_id' => $trip->vehicle_id], [
                'trip_id' => $trip->id, 'occupancy' => $data['occupancy'], 'updated_at' => now(), 'is_simulated' => $trip->is_simulated,
            ]);
        });

        return response()->json(['data' => $status]);
    }

    public function incident(Request $request, DriverTrips $trips): JsonResponse
    {
        $data = $request->validate([
            'trip_id' => 'required|integer', 'type' => 'required|in:heavy_traffic,road_closed,accident',
            'lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180',
            'route_id' => 'prohibited', 'reporter_id' => 'prohibited', 'expires_at' => 'prohibited', 'is_simulated' => 'prohibited',
        ]);
        $incident = DB::transaction(function () use ($request, $trips, $data) {
            $trip = $trips->owned($request->user(), $data['trip_id'], true);
            $projection = Geometry::project([(float) $data['lng'], (float) $data['lat']], $trip->route->geometry_geojson['coordinates']);
            if ($projection['offset_m'] > config('commutelive.incident_max_offset_m')) {
                throw ValidationException::withMessages(['lat' => ['This incident is too far from the assigned route.']]);
            }

            return IncidentReport::create([
                'trip_id' => $trip->id, 'type' => $data['type'], 'lat' => $data['lat'], 'lng' => $data['lng'],
                'route_id' => $trip->route_id, 'reporter_id' => $request->user()->id,
                'expires_at' => now()->addMinutes(config('commutelive.incident_ttl_minutes')), 'is_simulated' => $trip->is_simulated,
            ]);
        });

        return response()->json(['data' => $incident], 201);
    }
}
