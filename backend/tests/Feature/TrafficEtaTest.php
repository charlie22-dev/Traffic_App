<?php

namespace Tests\Feature;

use App\Models\{EtaPrediction, LocationPing, Route, SegmentBaseline, SegmentSpeedStat, Stop, Trip, Vehicle};
use App\Services\{ArrivalMatcher, TrafficEngine};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrafficEtaTest extends TestCase
{
    use RefreshDatabase;
    private Route $route;
    private Trip $trip;
    private Stop $stop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-27T02:00:00Z')); // Manila Friday 10am
        $this->route = Route::factory()->create();
        $this->artisan('commutelive:build-segments')->assertSuccessful();
        $this->trip = Trip::factory()->create(['vehicle_id' => Vehicle::factory()->create(['route_id' => $this->route->id])->id]);
        $this->stop = Stop::factory()->create();
        $this->route->stops()->attach($this->stop->id, ['sequence' => 1, 'distance_along_route_m' => 500, 'offset_m' => 0, 'is_simulated' => true]);
    }

    private function ping(array $attributes = []): LocationPing
    {
        return LocationPing::factory()->create(array_replace(['trip_id' => $this->trip->id, 'vehicle_id' => $this->trip->vehicle_id,
            'segment_id' => $this->route->segments->first()->id], $attributes));
    }

    public function test_average_includes_zero_excludes_bad_old_future_missing_and_separates_simulation(): void
    {
        foreach ([0, 10, 20] as $speed) $this->ping(['speed_kph' => $speed]);
        $this->ping(['speed_kph' => 100, 'is_off_route' => true]);
        $this->ping(['speed_kph' => 100, 'recorded_at' => now()->subSeconds(301)]);
        $this->ping(['speed_kph' => 100, 'recorded_at' => now()->addSecond()]);
        $this->ping(['speed_kph' => null]);
        $this->ping(['speed_kph' => 40, 'is_simulated' => false]);
        $this->artisan('commutelive:update-traffic')->assertSuccessful();
        $this->artisan('commutelive:update-traffic')->assertSuccessful();
        $this->assertDatabaseCount('segment_speed_stats', 2);
        $sim = SegmentSpeedStat::where('is_simulated', true)->first();
        $this->assertSame(3, $sim->sample_count);
        $this->assertEqualsWithDelta(10, $sim->avg_speed_kph, 1e-9);
        $this->getJson('/api/traffic?route_id='.$this->route->id)->assertOk()->assertJsonPath('data.0.status', 'red')
            ->assertJsonPath('data.0.baseline_source', 'mode_free_flow_default')->assertJsonPath('contains_simulated_data', true);
        $this->travel(91)->seconds();
        $this->getJson('/api/traffic?route_id='.$this->route->id)->assertJsonPath('data.0.status', 'unknown');
    }

    public function test_real_route_traffic_never_uses_simulated_samples_by_default(): void
    {
        $this->route->update(['is_simulated' => false]);
        foreach ([1, 1, 1] as $speed) $this->ping(['speed_kph' => $speed]);
        app(TrafficEngine::class)->aggregate();
        $url = '/api/traffic?route_id='.$this->route->id;
        $this->getJson($url)->assertJsonPath('data.0.status', 'unknown')->assertJsonPath('contains_simulated_data', false);
        $this->getJson($url.'&is_simulated=1')->assertJsonPath('data.0.status', 'red')->assertJsonPath('contains_simulated_data', true);
    }

    public function test_baselines_use_historical_local_hour_and_require_multiple_days(): void
    {
        foreach ([1, 2, 3] as $days) for ($n = 0; $n < 10; $n++) $this->ping([
            'speed_kph' => 18, 'recorded_at' => now()->subDays($days),
        ]);
        $this->ping(['speed_kph' => 160]); // live event excluded
        $this->ping(['speed_kph' => 160, 'is_off_route' => true, 'recorded_at' => now()->subDay()]);
        $this->artisan('commutelive:build-baselines')->assertSuccessful();
        $this->artisan('commutelive:build-baselines')->assertSuccessful();
        $this->assertDatabaseCount('segment_baselines', 1);
        $b = SegmentBaseline::first();
        $this->assertSame('weekday', $b->day_type);
        $this->assertSame(10, $b->hour);
        $this->assertSame(30, $b->sample_count);
        $this->assertSame(3, $b->day_count);
        $this->assertSame(18.0, $b->avg_speed_kph);
        $this->getJson('/api/traffic?route_id='.$this->route->id)->assertJsonPath('data.0.baseline_source', 'historical');
        $b->update(['day_count' => 1]);
        $this->getJson('/api/traffic?route_id='.$this->route->id)->assertJsonPath('data.0.baseline_source', 'mode_free_flow_default');
    }

    public function test_arrivals_log_only_returned_predictions_count_down_and_hide_stale_or_ended_trips(): void
    {
        $ping = $this->ping(['distance_along_route_m' => 100]);
        $url = '/api/stops/'.$this->stop->id.'/arrivals';
        $first = $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.confidence', 'low')
            ->assertJsonPath('data.0.occupancy', 'unknown')->assertJsonPath('contains_simulated_data', true)->json('data.0.eta_seconds');
        $this->travel(10)->seconds();
        $ping->update(['distance_along_route_m' => 200, 'recorded_at' => now()]);
        $second = $this->getJson($url)->json('data.0.eta_seconds');
        $this->assertLessThan($first, $second);
        $this->assertDatabaseCount('eta_predictions', 2);
        $this->assertSame($this->trip->id, EtaPrediction::first()->trip_id);
        $this->assertNotNull(EtaPrediction::first()->naive_arrival_at);
        $this->travel(61)->seconds();
        $this->getJson($url)->assertJsonCount(0, 'data');
        $ping->update(['recorded_at' => now()]);
        $this->trip->update(['ended_at' => now(), 'active_driver_id' => null, 'active_vehicle_id' => null]);
        $this->getJson($url)->assertJsonCount(0, 'data');
    }

    public function test_arrival_crossing_interpolates_and_never_updates_another_trip(): void
    {
        $prediction = EtaPrediction::factory()->create(['vehicle_id' => $this->trip->vehicle_id, 'stop_id' => $this->stop->id,
            'trip_id' => $this->trip->id, 'predicted_at' => now()->subMinute()]);
        $other = EtaPrediction::factory()->create(['vehicle_id' => $this->trip->vehicle_id, 'stop_id' => $this->stop->id, 'trip_id' => null]);
        $this->ping(['distance_along_route_m' => 450, 'recorded_at' => now()->subSeconds(10)]);
        $this->ping(['distance_along_route_m' => 550]);
        app(ArrivalMatcher::class)->reconcile($this->trip);
        $this->assertTrue($prediction->fresh()->actual_arrival_at->eq(now()->subSeconds(5)));
        $this->assertSame('gps_interpolated', $prediction->fresh()->actual_arrival_source);
        $this->assertNull($other->fresh()->actual_arrival_at);
        app(ArrivalMatcher::class)->reconcile($this->trip);
        $this->assertDatabaseCount('stop_arrivals', 1);
        $this->getJson('/api/stops/'.$this->stop->id.'/arrivals')->assertJsonCount(0, 'data');
    }

    public function test_long_gap_is_not_invented_as_an_arrival_and_offroute_is_excluded(): void
    {
        $this->ping(['distance_along_route_m' => 450, 'recorded_at' => now()->subSeconds(121)]);
        $this->ping(['distance_along_route_m' => 550]);
        $this->ping(['distance_along_route_m' => 500, 'is_off_route' => true]);
        app(ArrivalMatcher::class)->reconcile($this->trip);
        $this->assertDatabaseCount('stop_arrivals', 0);
    }

    public function test_invalid_filters_and_unknown_stop_have_clear_errors(): void
    {
        $this->getJson('/api/traffic?route_id=bad')->assertUnprocessable();
        $this->getJson('/api/traffic?is_simulated=banana')->assertUnprocessable();
        $this->getJson('/api/stops/999999/arrivals')->assertNotFound();
    }

    public function test_ingestion_reconciles_arrivals_after_out_of_order_batch_and_retry(): void
    {
        $prediction = EtaPrediction::factory()->create(['vehicle_id' => $this->trip->vehicle_id, 'trip_id' => $this->trip->id,
            'stop_id' => $this->stop->id, 'predicted_at' => now()->subMinute()]);
        $coordinates = $this->route->geometry_geojson['coordinates'];
        $rows = [];
        foreach ([[550, 0], [450, 10]] as [$distance, $age]) {
            $point = \App\Support\Geometry::interpolate($coordinates[0], $coordinates[1], $distance / $this->route->length_m);
            $rows[] = ['client_ping_uuid' => (string) \Illuminate\Support\Str::uuid(), 'lat' => $point[1], 'lng' => $point[0],
                'accuracy_m' => 5, 'speed_kph' => 36, 'heading' => 90, 'recorded_at' => now()->subSeconds($age)->toIso8601String()];
        }
        $this->actingAs($this->trip->vehicle->driver, 'sanctum');
        $payload = ['trip_id' => $this->trip->id, 'pings' => $rows];
        $this->postJson('/api/driver/pings', $payload)->assertOk()->assertJsonPath('data.counts.accepted', 2);
        $this->assertEqualsWithDelta(5, $prediction->fresh()->actual_arrival_at->diffInSeconds(now()), 1);
        $this->postJson('/api/driver/pings', $payload)->assertOk()->assertJsonPath('data.counts.duplicates', 2);
        $this->assertDatabaseCount('stop_arrivals', 1);
        $this->assertTrue($prediction->fresh()->is_simulated);
    }

    public function test_history_expires_and_baseline_respects_weekend_and_source_partition(): void
    {
        $segment = $this->route->segments->first();
        SegmentBaseline::create(['segment_id' => $segment->id, 'day_type' => 'weekday', 'hour' => 10, 'avg_speed_kph' => 12,
            'sample_count' => 100, 'day_count' => 3, 'computed_at' => now(), 'is_simulated' => false]);
        $url = '/api/traffic?route_id='.$this->route->id;
        $this->getJson($url)->assertJsonPath('data.0.baseline_source', 'mode_free_flow_default');
        $b = SegmentBaseline::create(['segment_id' => $segment->id, 'day_type' => 'weekday', 'hour' => 10, 'avg_speed_kph' => 16,
            'sample_count' => 100, 'day_count' => 3, 'computed_at' => now(), 'is_simulated' => true]);
        $this->getJson($url)->assertJsonPath('data.0.baseline_source', 'historical')->assertJsonPath('data.0.baseline_speed_kph', 16);
        $b->update(['computed_at' => now()->subHours(37)]);
        $this->getJson($url)->assertJsonPath('data.0.baseline_source', 'mode_free_flow_default');
        $b->update(['computed_at' => now(), 'day_type' => 'weekend']);
        $this->getJson($url)->assertJsonPath('data.0.baseline_source', 'mode_free_flow_default');
    }

    public function test_arrivals_limit_logs_only_displayed_rows_and_fresh_occupancy_is_returned(): void
    {
        $this->ping(['distance_along_route_m' => 100]);
        \App\Models\VehicleStatus::create(['vehicle_id' => $this->trip->vehicle_id, 'trip_id' => $this->trip->id,
            'occupancy' => 'seats', 'updated_at' => now(), 'is_simulated' => true]);
        $second = Trip::factory()->create(['vehicle_id' => Vehicle::factory()->create(['route_id' => $this->route->id])->id]);
        LocationPing::factory()->create(['trip_id' => $second->id, 'vehicle_id' => $second->vehicle_id, 'distance_along_route_m' => 50]);
        config(['commutelive.eta.arrivals_limit' => 1]);
        $this->getJson('/api/stops/'.$this->stop->id.'/arrivals')->assertJsonCount(1, 'data')->assertJsonPath('data.0.occupancy', 'seats');
        $this->assertDatabaseCount('eta_predictions', 1);
    }

    public function test_scheduler_registers_thirty_second_job(): void
    {
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $event = collect($events)->first(fn ($e) => str_contains($e->command ?? '', 'commutelive:update-traffic'));
        $this->assertNotNull($event);
        $this->assertSame(30, $event->repeatSeconds);
        $this->assertTrue($event->withoutOverlapping);
    }
}
