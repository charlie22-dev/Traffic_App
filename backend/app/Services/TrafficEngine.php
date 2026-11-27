<?php

namespace App\Services;

use App\Models\LocationPing;
use App\Models\RouteSegment;
use App\Models\SegmentBaseline;
use App\Models\SegmentSpeedStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class TrafficEngine
{
    public static function status(?float $speed, float $baseline, int $samples): string
    {
        if ($speed === null || $samples < config('commutelive.traffic.min_samples')) return 'unknown';
        $ratio = $speed / $baseline;
        return $ratio >= config('commutelive.traffic.green_ratio') ? 'green'
            : ($ratio >= config('commutelive.traffic.amber_ratio') ? 'amber' : 'red');
    }

    public function aggregate(?CarbonImmutable $at = null): int
    {
        $at ??= CarbonImmutable::now();
        $start = $at->subSeconds(config('commutelive.traffic.window_seconds'));
        $groups = LocationPing::where('is_off_route', false)->whereNotNull('segment_id')->whereNotNull('speed_kph')
            ->whereBetween('recorded_at', [$start, $at])->where('received_at', '<=', $at)
            ->selectRaw('segment_id, is_simulated, AVG(speed_kph) as speed, COUNT(*) as samples')
            ->groupBy('segment_id', 'is_simulated')->get();
        DB::transaction(function () use ($groups, $start, $at) {
            foreach ($groups as $g) {
                SegmentSpeedStat::updateOrCreate(['segment_id' => $g->segment_id, 'window_start' => $start, 'is_simulated' => $g->is_simulated],
                    ['avg_speed_kph' => $g->speed, 'sample_count' => $g->samples, 'computed_at' => $at]);
            }
            SegmentSpeedStat::where('computed_at', '<', $at->subSeconds(config('commutelive.traffic.stats_stale_seconds')))->delete();
        });
        return $groups->count();
    }

    public function buildBaselines(?CarbonImmutable $at = null): int
    {
        $at ??= CarbonImmutable::now();
        $buckets = [];
        // Exclude the live window: baseline training must not consume the event being classified.
        foreach (LocationPing::where('is_off_route', false)->whereNotNull('segment_id')->whereNotNull('speed_kph')
            ->where('recorded_at', '>=', $at->subDays(config('commutelive.traffic.history_days')))
            ->where('recorded_at', '<', $at->subSeconds(config('commutelive.traffic.window_seconds')))
            ->where('received_at', '<=', $at)->cursor() as $ping) {
            $local = $ping->recorded_at->setTimezone(config('commutelive.traffic.timezone'));
            $day = $local->isWeekend() ? 'weekend' : 'weekday';
            $key = "$ping->segment_id|$day|$local->hour|".(int) $ping->is_simulated;
            if (! isset($buckets[$key])) $buckets[$key] = ['segment_id' => $ping->segment_id, 'day_type' => $day, 'hour' => $local->hour,
                'is_simulated' => $ping->is_simulated, 'sum' => 0, 'count' => 0, 'days' => []];
            $buckets[$key]['sum'] += $ping->speed_kph;
            $buckets[$key]['count']++;
            $buckets[$key]['days'][$local->toDateString()] = true;
        }
        DB::transaction(function () use ($buckets, $at) {
            SegmentBaseline::query()->delete();
            foreach ($buckets as $b) SegmentBaseline::create([
                'segment_id' => $b['segment_id'], 'day_type' => $b['day_type'], 'hour' => $b['hour'], 'is_simulated' => $b['is_simulated'],
                'avg_speed_kph' => $b['sum'] / $b['count'], 'sample_count' => $b['count'], 'day_count' => count($b['days']), 'computed_at' => $at,
            ]);
        });
        return count($buckets);
    }

    public function segment(RouteSegment $segment, string $mode, bool $simulated, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $local = $at->setTimezone(config('commutelive.traffic.timezone'));
        $baseline = SegmentBaseline::where('segment_id', $segment->id)->where('is_simulated', $simulated)
            ->where('day_type', $local->isWeekend() ? 'weekend' : 'weekday')->where('hour', $local->hour)
            ->where('computed_at', '>=', $at->subHours(config('commutelive.traffic.baseline_stale_hours')))->where('computed_at', '<=', $at)
            ->where('sample_count', '>=', config('commutelive.traffic.baseline_min_samples'))
            ->where('day_count', '>=', config('commutelive.traffic.baseline_min_days'))->where('avg_speed_kph', '>', 0)->first();
        $base = $baseline?->avg_speed_kph ?? (float) config("commutelive.traffic.free_flow_kph.$mode");
        $stat = SegmentSpeedStat::where('segment_id', $segment->id)->where('is_simulated', $simulated)
            ->where('computed_at', '<=', $at)->where('computed_at', '>=', $at->subSeconds(config('commutelive.traffic.stats_stale_seconds')))
            ->orderByDesc('computed_at')->first();
        $n = $stat?->sample_count ?? 0;
        $live = $n >= config('commutelive.traffic.min_samples') ? $stat?->avg_speed_kph : null;
        return ['segment_id' => $segment->id, 'sequence' => $segment->sequence, 'start_m' => $segment->start_m, 'end_m' => $segment->end_m,
            'length_m' => $segment->length_m, 'polyline' => $segment->polyline, 'live_speed_kph' => $live, 'sample_count' => $n,
            'baseline_speed_kph' => $base, 'baseline_source' => $baseline ? 'historical' : 'mode_free_flow_default',
            'baseline_sample_count' => $baseline?->sample_count ?? 0, 'baseline_day_count' => $baseline?->day_count ?? 0,
            'ratio' => $live === null ? null : $live / $base, 'status' => self::status($live, $base, $n),
            'computed_at' => $stat?->computed_at?->toIso8601String(), 'window_start' => $stat?->window_start?->toIso8601String(),
            'is_simulated' => $simulated];
    }
}
