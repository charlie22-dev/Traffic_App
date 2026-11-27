<?php

namespace App\Services;

use App\Models\EtaPrediction;
use App\Models\LocationPing;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;

final class ArrivalMatcher
{
    public function reconcile(Trip $trip): void
    {
        $pings = LocationPing::where('trip_id', $trip->id)->where('is_off_route', false)->whereNotNull('distance_along_route_m')
            ->orderBy('recorded_at')->orderBy('id')->get();
        foreach ($trip->route->stops as $stop) {
            $distance = (float) $stop->pivot->distance_along_route_m;
            $arrival = null;
            $source = null;
            $previous = null;
            foreach ($pings as $ping) {
                $along = $ping->distance_along_route_m;
                if ($previous && $previous->distance_along_route_m < $distance && $along >= $distance
                    && $previous->recorded_at->diffInSeconds($ping->recorded_at) <= config('commutelive.eta.arrival_max_gap_seconds')) {
                    $fraction = ($distance - $previous->distance_along_route_m) / ($along - $previous->distance_along_route_m);
                    $arrival = $previous->recorded_at->addSeconds($fraction * $previous->recorded_at->diffInSeconds($ping->recorded_at));
                    $source = 'gps_interpolated';
                } elseif (abs($along - $distance) <= config('commutelive.eta.arrival_radius_m')) {
                    $arrival = $ping->recorded_at;
                    $source = 'gps_proximity';
                }
                if ($arrival) break;
                $previous = $ping;
            }
            if (! $arrival) continue;
            DB::table('stop_arrivals')->updateOrInsert(['trip_id' => $trip->id, 'stop_id' => $stop->id],
                ['arrived_at' => $arrival, 'source' => $source, 'is_simulated' => $trip->is_simulated]);
            // Reconcile out-of-order batches too, but never attach an arrival before a prediction.
            EtaPrediction::where('trip_id', $trip->id)->where('stop_id', $stop->id)->where('predicted_at', '<=', $arrival)
                ->update(['actual_arrival_at' => $arrival, 'actual_arrival_source' => $source]);
            EtaPrediction::where('trip_id', $trip->id)->where('stop_id', $stop->id)->where('predicted_at', '>', $arrival)
                ->update(['actual_arrival_at' => null, 'actual_arrival_source' => null]);
        }
    }
}
