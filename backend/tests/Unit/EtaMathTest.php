<?php

namespace Tests\Unit;

use App\Services\EtaEngine;
use App\Services\TrafficEngine;
use Tests\TestCase;

class EtaMathTest extends TestCase
{
    private function segment(float $start, float $end, ?float $live = 18, int $n = 10): array
    {
        return ['start_m' => $start, 'end_m' => $end, 'live_speed_kph' => $live, 'sample_count' => $n,
            'baseline_speed_kph' => 36, 'baseline_source' => 'mode_free_flow_default'];
    }

    public function test_exact_traffic_thresholds_and_insufficient_samples(): void
    {
        $this->assertSame('green', TrafficEngine::status(28, 35, 3));
        $this->assertSame('amber', TrafficEngine::status(17.5, 35, 3));
        $this->assertSame('red', TrafficEngine::status(17.499, 35, 3));
        $this->assertSame('red', TrafficEngine::status(0, 35, 3));
        $this->assertSame('unknown', TrafficEngine::status(28, 35, 2));
        $this->assertSame('unknown', TrafficEngine::status(null, 35, 10));
    }

    public function test_partial_segments_blend_and_intermediate_dwell_by_hand(): void
    {
        // w=10/(10+10)=0.5, blend=27km/h=7.5m/s. 300m/7.5 + 20s dwell=60s.
        $r = (new EtaEngine)->predict(100, 400, [$this->segment(0, 250), $this->segment(250, 500)], [0, 100, 250, 400, 500]);
        $this->assertEqualsWithDelta(60, $r['eta_seconds'], 1e-9);
        $this->assertSame(1, $r['intermediate_stops']);
        $this->assertSame('medium', $r['confidence']);
    }

    public function test_unknown_live_uses_baseline_and_more_samples_increase_live_weight(): void
    {
        $engine = new EtaEngine;
        $fallback = $engine->predict(0, 250, [$this->segment(0, 250, 0, 2)], []);
        $this->assertEqualsWithDelta(25, $fallback['eta_seconds'], 1e-9);
        $this->assertSame('low', $fallback['confidence']);
        $a = $engine->predict(0, 250, [$this->segment(0, 250, 18, 10)], []);
        $b = $engine->predict(0, 250, [$this->segment(0, 250, 18, 90)], []);
        $this->assertGreaterThan($a['eta_seconds'], $b['eta_seconds']);
    }

    public function test_zero_speed_is_finite_and_floor_is_explicit(): void
    {
        $s = $this->segment(0, 250, 0, 100000);
        $s['baseline_speed_kph'] = 0.1;
        $r = (new EtaEngine)->predict(0, 250, [$s], []);
        $this->assertEqualsWithDelta(900, $r['eta_seconds'], 1e-9);
    }

    public function test_missing_segment_coverage_fails_instead_of_underestimating(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EtaEngine)->predict(0, 500, [$this->segment(0, 250)], []);
    }

    public function test_stop_behind_vehicle_is_not_an_arrival_candidate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EtaEngine)->predict(250, 200, [$this->segment(0, 250)], []);
    }
}
