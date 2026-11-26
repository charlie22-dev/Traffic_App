<?php

namespace App\Services;

use App\Models\LocationPing;
use App\Models\User;
use App\Support\Geometry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PingIngestor
{
    public function __construct(private DriverTrips $trips) {}

    public function ingest(User $driver, int $tripId, array $pings): array
    {
        return DB::transaction(function () use ($driver, $tripId, $pings) {
            $trip = $this->trips->owned($driver, $tripId);
            $route = $trip->route;
            $segments = $route->segments;
            abort_if($segments->isEmpty(), 409, 'Route segments are unavailable; rebuild them before ingestion.');
            $now = CarbonImmutable::now();
            $results = [];
            foreach ($pings as $index => $row) {
                $result = ['index' => $index, 'client_ping_uuid' => $row['client_ping_uuid'] ?? null, 'status' => 'rejected'];
                $validator = Validator::make($row, [
                    'client_ping_uuid' => 'required|uuid', 'lat' => 'required|numeric|between:-90,90',
                    'lng' => 'required|numeric|between:-180,180', 'accuracy_m' => 'required|numeric|min:0',
                    'speed_kph' => 'nullable|numeric|min:0|max:'.config('commutelive.ping_max_speed_kph'),
                    'heading' => 'nullable|numeric|min:0|lt:360', 'is_simulated' => 'sometimes|boolean',
                    'recorded_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],
                ]);
                if ($validator->fails()) {
                    $results[] = $result + ['code' => 'invalid_ping', 'errors' => $validator->errors()->toArray()];
                    continue;
                }
                $row['client_ping_uuid'] = strtolower($row['client_ping_uuid']);
                $result['client_ping_uuid'] = $row['client_ping_uuid'];
                $recorded = CarbonImmutable::parse($row['recorded_at'])->utc();
                $canonical = [
                    'trip_id' => $trip->id, 'lat' => (float) $row['lat'], 'lng' => (float) $row['lng'],
                    'accuracy_m' => (float) $row['accuracy_m'],
                    'speed_kph' => isset($row['speed_kph']) ? (float) $row['speed_kph'] : null,
                    'heading' => isset($row['heading']) ? (float) $row['heading'] : null,
                    'recorded_at' => $recorded->format('Y-m-d\TH:i:s.u\Z'), 'is_simulated' => $trip->is_simulated,
                ];
                $hash = hash('sha256', json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
                if (isset($row['is_simulated']) && (bool) $row['is_simulated'] !== $trip->is_simulated) {
                    $results[] = $result + ['code' => 'simulation_mismatch', 'message' => 'Simulation flags are assigned by the server from the trip.'];
                    continue;
                }
                $existing = LocationPing::where('client_ping_uuid', $row['client_ping_uuid'])->first();
                if ($existing) {
                    $results[] = $this->duplicate($result, $existing, $hash);
                    continue;
                }
                if ($canonical['accuracy_m'] > config('commutelive.ping_max_accuracy_m')) {
                    $results[] = $result + ['code' => 'bad_accuracy', 'message' => 'Accuracy exceeds the configured maximum.'];
                    continue;
                }
                if ($recorded->lt($now->subDays(config('commutelive.pings_retention_days')))) {
                    $results[] = $result + ['code' => 'expired_ping', 'message' => 'The ping is older than the retention window.'];
                    continue;
                }
                if ($recorded->gt($now->addSeconds(config('commutelive.ping_future_tolerance_seconds')))) {
                    $results[] = $result + ['code' => 'future_ping', 'message' => 'The recorded timestamp is too far ahead of server time.'];
                    continue;
                }
                if ($recorded->lt($trip->started_at) || ($trip->ended_at && $recorded->gt($trip->ended_at))) {
                    $results[] = $result + ['code' => 'outside_trip', 'message' => 'The ping was not recorded during the consented trip.'];
                    continue;
                }
                $projection = Geometry::project([$canonical['lng'], $canonical['lat']], $route->geometry_geojson['coordinates']);
                $offRoute = $projection['offset_m'] > config('commutelive.ping_max_offset_m');
                $along = $projection['distance_along_route_m'];
                $last = $segments->last();
                $segment = $offRoute ? null : $segments->first(fn ($segment) => $along >= $segment->start_m
                    && ($along < $segment->end_m || ($segment->id === $last->id && $along <= $segment->end_m + 1e-6)));
                if (! $offRoute && ! $segment) {
                    $results[] = $result + ['code' => 'segment_gap', 'message' => 'The route segment inventory does not cover the projected position.'];
                    continue;
                }
                $attributes = $canonical;
                $attributes['recorded_at'] = $recorded;
                $ping = LocationPing::firstOrCreate(['client_ping_uuid' => $row['client_ping_uuid']], $attributes + [
                    'payload_hash' => $hash, 'vehicle_id' => $trip->vehicle_id, 'received_at' => $now,
                    'distance_along_route_m' => $offRoute ? null : $along, 'segment_id' => $segment?->id,
                    'offset_m' => $projection['offset_m'], 'is_off_route' => $offRoute,
                ]);
                $results[] = $ping->wasRecentlyCreated
                    ? array_replace($result, ['status' => $offRoute ? 'flagged_off_route' : 'accepted', 'id' => $ping->id, 'is_simulated' => $ping->is_simulated])
                    : $this->duplicate($result, $ping, $hash);
            }

            return ['results' => $results, 'counts' => [
                'accepted' => count(array_filter($results, fn ($row) => $row['status'] === 'accepted')),
                'flagged_off_route' => count(array_filter($results, fn ($row) => $row['status'] === 'flagged_off_route')),
                'duplicates' => count(array_filter($results, fn ($row) => $row['status'] === 'duplicate')),
                'rejected' => count(array_filter($results, fn ($row) => $row['status'] === 'rejected')),
            ], 'is_simulated' => $trip->is_simulated];
        });
    }

    private function duplicate(array $result, LocationPing $existing, string $hash): array
    {
        return hash_equals($existing->payload_hash, $hash)
            ? array_replace($result, ['status' => 'duplicate', 'id' => $existing->id, 'is_simulated' => $existing->is_simulated])
            : $result + ['code' => 'uuid_conflict', 'message' => 'This UUID has already been used for a different ping.'];
    }
}
