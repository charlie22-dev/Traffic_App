<?php

namespace App\Services;

final class EtaEngine
{
    /** Pure distance/speed integration. Only overlapping segment lengths contribute. */
    public function predict(float $from, float $to, array $segments, array $stopDistances): array
    {
        if ($to <= $from) throw new \InvalidArgumentException('The stop must be ahead of the vehicle.');
        $seconds = $covered = $liveDistance = 0.0;
        $allHistorical = true;
        foreach ($segments as $s) {
            $length = max(0, min($to, $s['end_m']) - max($from, $s['start_m']));
            if ($length === 0.0) continue;
            $known = $s['live_speed_kph'] !== null && $s['sample_count'] >= config('commutelive.traffic.min_samples');
            $weight = $known ? $s['sample_count'] / ($s['sample_count'] + config('commutelive.eta.blend_prior_samples')) : 0;
            $speed = $weight * ($s['live_speed_kph'] ?? 0) + (1 - $weight) * $s['baseline_speed_kph'];
            $seconds += $length * 3.6 / max(config('commutelive.eta.minimum_speed_kph'), $speed);
            $covered += $length;
            $liveDistance += $known ? $length : 0;
            $allHistorical = $allHistorical && $s['baseline_source'] === 'historical';
        }
        if (abs($covered - ($to - $from)) > 0.01) throw new \InvalidArgumentException('Route segment coverage is incomplete.');
        $intermediate = count(array_filter($stopDistances, fn ($d) => $d > $from && $d < $to));
        $seconds += $intermediate * config('commutelive.eta.dwell_seconds');
        $coverage = $liveDistance / $covered;
        $confidence = $coverage >= config('commutelive.eta.confidence_live_coverage') ? ($allHistorical ? 'high' : 'medium') : 'low';
        return ['eta_seconds' => $seconds, 'distance_m' => $covered, 'intermediate_stops' => $intermediate,
            'confidence' => $confidence, 'confidence_basis' => 'heuristic_sample_coverage_not_calibrated_probability', 'live_distance_fraction' => $coverage,
            'speed_floor_kph' => config('commutelive.eta.minimum_speed_kph'), 'method' => 'segment_blend_v1'];
    }
}
