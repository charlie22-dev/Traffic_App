<?php

namespace App\Http\Controllers;

use App\Models\EtaPrediction;
use App\Models\Route;
use App\Models\Stop;
use App\Models\Trip;
use App\Services\EtaEngine;
use App\Services\TrafficEngine;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrafficController extends Controller
{
    public function traffic(Request $request, TrafficEngine $traffic): JsonResponse
    {
        $filter = $request->validate(['route_id' => 'sometimes|integer|min:1|exists:routes,id', 'is_simulated' => 'sometimes|boolean']);
        $at = CarbonImmutable::now();
        $data = [];
        foreach (Route::where('is_active', true)->when(isset($filter['route_id']), fn ($q) => $q->whereKey($filter['route_id']))->with('segments')->get() as $route) {
            $simulated = $route->is_simulated || (bool) ($filter['is_simulated'] ?? false);
            foreach ($route->segments as $segment) $data[] = ['route_id' => $route->id] + $traffic->segment($segment, $route->mode, $simulated, $at);
        }
        return response()->json(['data' => $data, 'as_of' => $at->toIso8601String(),
            'window_seconds' => config('commutelive.traffic.window_seconds'), 'contains_simulated_data' => collect($data)->contains('is_simulated', true)]);
    }

    public function arrivals(Stop $stop, TrafficEngine $traffic, EtaEngine $eta): JsonResponse
    {
        $at = CarbonImmutable::now();
        $trips = Trip::whereNull('ended_at')->whereHas('route', fn ($q) => $q->where('is_active', true)->whereHas('stops', fn ($s) => $s->where('stops.id', $stop->id)))
            ->with(['route.stops', 'route.segments', 'vehicle.status', 'latestMatchedPing'])->get();
        $data = [];
        $cache = [];
        foreach ($trips as $trip) {
            $ping = $trip->latestMatchedPing;
            if (! $ping || $ping->recorded_at->lt($at->subSeconds(config('commutelive.vehicle_stale_seconds'))) || $ping->recorded_at->gt($at)) continue;
            $target = (float) $trip->route->stops->firstWhere('id', $stop->id)->pivot->distance_along_route_m;
            if ($target <= $ping->distance_along_route_m || DB::table('stop_arrivals')->where('trip_id', $trip->id)->where('stop_id', $stop->id)->exists()) continue;
            $key = $trip->route_id.':'.(int) $trip->is_simulated;
            $segments = $cache[$key] ??= $trip->route->segments->map(fn ($s) => $traffic->segment($s, $trip->route->mode, $trip->is_simulated, $at))->all();
            try {
                $estimate = $eta->predict($ping->distance_along_route_m, $target, $segments, $trip->route->stops->map(fn ($s) => (float) $s->pivot->distance_along_route_m)->all());
            } catch (\InvalidArgumentException) { continue; }
            // Account for the age of the last position without inventing a new GPS position.
            $age = max(0, $ping->recorded_at->diffInSeconds($at));
            $seconds = max(0, $estimate['eta_seconds'] - $age);
            $status = $trip->vehicle->status;
            $occupancyValid = $status && $status->trip_id === $trip->id && $status->updated_at->gte($at->subSeconds(config('commutelive.occupancy_stale_seconds')));
            $data[] = ['vehicle_id' => $trip->vehicle_id, 'route_id' => $trip->route_id, 'label' => $trip->vehicle->label,
                'stop_id' => $stop->id, 'eta_seconds' => (int) ceil($seconds), 'predicted_arrival_at' => $at->addSeconds($seconds)->toIso8601String(),
                'occupancy' => $occupancyValid ? $status->occupancy : 'unknown', 'confidence' => $estimate['confidence'],
                'confidence_basis' => $estimate['confidence_basis'], 'freshness_seconds' => (int) $age,
                'baseline_sources' => array_values(array_unique(array_column($segments, 'baseline_source'))),
                'is_simulated' => $trip->is_simulated, '_trip' => $trip->id, '_estimate' => $estimate];
        }
        usort($data, fn ($a, $b) => [$a['eta_seconds'], $a['vehicle_id']] <=> [$b['eta_seconds'], $b['vehicle_id']]);
        $data = array_slice($data, 0, config('commutelive.eta.arrivals_limit'));
        DB::transaction(function () use (&$data, $at) {
            foreach ($data as &$row) {
                $prediction = EtaPrediction::create(['vehicle_id' => $row['vehicle_id'], 'trip_id' => $row['_trip'], 'stop_id' => $row['stop_id'],
                    'predicted_at' => $at, 'predicted_arrival_at' => $row['predicted_arrival_at'],
                    'naive_arrival_at' => $at->addSeconds(max(0, $row['_estimate']['distance_m'] * 3.6 / config('commutelive.eta.naive_speed_kph') - $row['freshness_seconds'])),
                    'method' => $row['_estimate']['method'], 'is_simulated' => $row['is_simulated'],
                    'context' => $row['_estimate'] + ['baseline_sources' => $row['baseline_sources'], 'freshness_seconds' => $row['freshness_seconds']]]);
                $row['prediction_id'] = $prediction->id;
                unset($row['_trip'], $row['_estimate']);
            }
        });
        return response()->json(['data' => $data, 'as_of' => $at->toIso8601String(), 'contains_simulated_data' => collect($data)->contains('is_simulated', true)]);
    }
}
