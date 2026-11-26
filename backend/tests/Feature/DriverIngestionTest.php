<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\LocationPing;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DriverIngestionTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;
    private Route $route;
    private Vehicle $vehicle;
    private ?Trip $trip = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 26)->setTime(10, 0, 0));
        $this->driver = User::factory()->driver()->create();
        $this->route = Route::factory()->create();
        $this->artisan('commutelive:build-segments')->assertSuccessful();
        $this->vehicle = Vehicle::factory()->create(['driver_id' => $this->driver->id, 'route_id' => $this->route->id]);
        $this->actingAs($this->driver, 'sanctum');
    }

    private function trip(): Trip
    {
        return $this->trip ??= Trip::factory()->create(['vehicle_id' => $this->vehicle->id]);
    }

    private function ping(array $overrides = []): array
    {
        return array_replace(['client_ping_uuid' => (string) Str::uuid(), 'lat' => 14.65, 'lng' => 120.951,
            'speed_kph' => 20, 'heading' => 90, 'accuracy_m' => 5, 'recorded_at' => now()->toIso8601String()], $overrides);
    }

    private function send(array $pings)
    {
        return $this->postJson('/api/driver/pings', ['trip_id' => $this->trip()->id, 'pings' => $pings]);
    }

    private function consent(): array
    {
        return ['vehicle_id' => $this->vehicle->id, 'tracking_consent' => true, 'privacy_notice_version' => config('commutelive.privacy_notice_version'), 'start_battery_pct' => 90];
    }

    public function test_explicit_current_consent_is_required_before_tracking_starts(): void
    {
        $this->postJson('/api/driver/trips/start', ['vehicle_id' => $this->vehicle->id])->assertUnprocessable()->assertJsonValidationErrors('tracking_consent');
        $this->postJson('/api/driver/trips/start', array_replace($this->consent(), ['privacy_notice_version' => 'old']))->assertUnprocessable();
        $this->assertDatabaseCount('trips', 0);
        $response = $this->postJson('/api/driver/trips/start', $this->consent())->assertCreated()->assertJsonPath('tracking_on', true)->assertJsonPath('data.is_simulated', true);
        $this->assertNotNull(Trip::find($response->json('data.id'))->consented_at);
        $this->postJson('/api/driver/trips/start', $this->consent())->assertStatus(409);
    }

    public function test_one_active_trip_per_driver_even_with_multiple_owned_vehicles(): void
    {
        $this->trip();
        $second = Vehicle::factory()->create(['driver_id' => $this->driver->id, 'route_id' => $this->route->id]);
        $this->postJson('/api/driver/trips/start', array_replace($this->consent(), ['vehicle_id' => $second->id]))->assertStatus(409);
        $this->assertDatabaseCount('trips', 1);
    }

    public function test_trip_end_is_idempotent_and_turns_tracking_off(): void
    {
        $trip = $this->trip();
        $this->send([$this->ping()])->assertOk();
        $this->getJson('/api/vehicles')->assertJsonCount(1, 'data');
        $this->postJson("/api/driver/trips/{$trip->id}/end", ['end_battery_pct' => 80])->assertOk()->assertJsonPath('tracking_on', false);
        $ended = $trip->fresh()->ended_at;
        $this->travel(10)->seconds();
        $this->postJson("/api/driver/trips/{$trip->id}/end", ['end_battery_pct' => 70])->assertOk();
        $this->assertTrue($ended->eq($trip->fresh()->ended_at));
        $this->assertSame(80, $trip->fresh()->end_battery_pct);
        $this->getJson('/api/vehicles')->assertJsonCount(0, 'data');
        $this->postJson('/api/driver/trips/start', $this->consent())->assertCreated();
    }

    public function test_commuters_and_admins_cannot_use_driver_only_routes(): void
    {
        foreach (['commuter', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $this->postJson('/api/driver/pings', [])->assertForbidden();
            $this->postJson('/api/driver/trips/start', [])->assertForbidden();
            $this->putJson('/api/driver/status', [])->assertForbidden();
            $this->postJson('/api/driver/incidents', [])->assertForbidden();
        }
    }

    public function test_other_drivers_cannot_access_vehicle_trip_or_reports(): void
    {
        $trip = $this->trip();
        $this->actingAs(User::factory()->driver()->create(), 'sanctum');
        $this->postJson('/api/driver/trips/start', $this->consent())->assertForbidden();
        $this->send([$this->ping()])->assertForbidden();
        $this->postJson("/api/driver/trips/{$trip->id}/end")->assertForbidden();
        $this->putJson('/api/driver/status', ['trip_id' => $trip->id, 'occupancy' => 'full'])->assertForbidden();
        $this->postJson('/api/driver/incidents', ['trip_id' => $trip->id, 'type' => 'accident', 'lat' => 14.65, 'lng' => 120.951])->assertForbidden();
    }

    public function test_batch_is_matched_to_route_segments_and_visible_publicly_without_driver_identity(): void
    {
        $this->send([$this->ping(), $this->ping(['lng' => 120.955])])->assertOk()->assertJsonPath('data.counts.accepted', 2);
        $this->assertDatabaseCount('location_pings', 2);
        $ping = LocationPing::orderBy('id')->first();
        $this->assertNotNull($ping->segment_id);
        $this->assertGreaterThan(100, $ping->distance_along_route_m);
        $this->assertLessThan(1, $ping->offset_m);
        $this->assertTrue($ping->is_simulated);
        $this->getJson('/api/vehicles?route_id='.$this->route->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lng', 120.955)->assertJsonPath('data.0.freshness_seconds', 0)
            ->assertJsonPath('data.0.is_simulated', true)->assertJsonPath('contains_simulated_data', true)
            ->assertJsonMissingPath('data.0.driver_id')->assertJsonMissingPath('data.0.email');
        $this->getJson('/api/vehicles?route_id=999999')->assertJsonCount(0, 'data');
        $this->getJson('/api/vehicles?route_id=invalid')->assertUnprocessable();
    }

    public function test_duplicate_uuids_are_idempotent_within_a_batch_and_across_retries(): void
    {
        $row = $this->ping();
        $this->send([$row, $row])->assertJsonPath('data.counts.accepted', 1)->assertJsonPath('data.counts.duplicates', 1);
        $this->send([$row])->assertJsonPath('data.results.0.status', 'duplicate');
        $this->assertDatabaseCount('location_pings', 1);
        $this->send([array_replace($row, ['lng' => 120.952])])->assertJsonPath('data.results.0.code', 'uuid_conflict');
        $this->assertDatabaseCount('location_pings', 1);
    }

    public function test_duplicate_identity_normalizes_uuid_case_numeric_values_and_timezone(): void
    {
        $row = $this->ping();
        $this->send([$row])->assertJsonPath('data.counts.accepted', 1);
        $retry = array_replace($row, ['client_ping_uuid' => strtoupper($row['client_ping_uuid']),
            'lat' => '14.65', 'recorded_at' => now()->setTimezone('Asia/Manila')->toIso8601String()]);
        $this->send([$retry])->assertJsonPath('data.counts.duplicates', 1);
        $this->assertDatabaseCount('location_pings', 1);
    }

    public function test_a_uuid_cannot_be_reused_for_a_different_trip(): void
    {
        $row = $this->ping();
        $this->send([$row])->assertJsonPath('data.counts.accepted', 1);
        $this->postJson('/api/driver/trips/'.$this->trip()->id.'/end')->assertOk();
        $response = $this->postJson('/api/driver/trips/start', $this->consent())->assertCreated();
        $this->trip = Trip::findOrFail($response->json('data.id'));
        $this->send([$row])->assertJsonPath('data.results.0.code', 'uuid_conflict');
        $this->assertDatabaseCount('location_pings', 1);
    }

    public function test_bad_accuracy_is_rejected_but_valid_rows_in_the_same_batch_survive(): void
    {
        config(['commutelive.ping_max_accuracy_m' => 20]);
        $this->send([$this->ping(['accuracy_m' => 21]), $this->ping(['accuracy_m' => 20])])
            ->assertOk()->assertJsonPath('data.results.0.code', 'bad_accuracy')->assertJsonPath('data.results.1.status', 'accepted');
        $this->assertDatabaseCount('location_pings', 1);
    }

    public function test_off_route_ping_is_flagged_and_not_used_as_a_public_position(): void
    {
        $this->send([$this->ping(['lat' => 14.66])])->assertJsonPath('data.results.0.status', 'flagged_off_route');
        $ping = LocationPing::first();
        $this->assertTrue($ping->is_off_route);
        $this->assertNull($ping->segment_id);
        $this->assertNull($ping->distance_along_route_m);
        $this->assertGreaterThan(1000, $ping->offset_m);
        $this->getJson('/api/vehicles')->assertJsonCount(0, 'data');
    }

    public function test_server_flags_cannot_be_overridden_by_the_client(): void
    {
        $this->send([$this->ping(['is_simulated' => false])])->assertJsonPath('data.results.0.code', 'simulation_mismatch');
        $this->assertDatabaseCount('location_pings', 0);
        $this->send([$this->ping()])->assertJsonPath('data.results.0.is_simulated', true);
    }

    public function test_out_of_order_uploads_do_not_move_latest_position_backwards(): void
    {
        $newer = $this->ping(['lng' => 120.955]);
        $older = $this->ping(['lng' => 120.951, 'recorded_at' => now()->subSeconds(20)->toIso8601String()]);
        $this->send([$newer, $older])->assertJsonPath('data.counts.accepted', 2);
        $this->getJson('/api/vehicles')->assertJsonPath('data.0.lng', 120.955);
        $this->travel(60)->seconds();
        $this->getJson('/api/vehicles')->assertJsonCount(1, 'data')->assertJsonPath('data.0.freshness_seconds', 60);
        $this->travel(1)->seconds();
        $this->getJson('/api/vehicles')->assertJsonCount(0, 'data');
    }

    public function test_offline_upload_after_trip_end_is_accepted_only_for_recording_during_the_trip(): void
    {
        $trip = $this->trip();
        $row = $this->ping();
        $this->postJson("/api/driver/trips/{$trip->id}/end")->assertOk();
        $this->travel(10)->seconds();
        $this->send([$row, $this->ping()])->assertJsonPath('data.results.0.status', 'accepted')->assertJsonPath('data.results.1.code', 'outside_trip');
        $this->getJson('/api/vehicles')->assertJsonCount(0, 'data');
    }

    public function test_invalid_coordinates_timestamps_and_future_records_have_clear_row_errors(): void
    {
        $this->send([$this->ping(['lat' => 91]), $this->ping(['recorded_at' => 'yesterday']),
            $this->ping(['recorded_at' => now()->addMinute()->toIso8601String()]),
            $this->ping(['recorded_at' => now()->subHour()->toIso8601String()])])
            ->assertJsonPath('data.results.0.code', 'invalid_ping')->assertJsonPath('data.results.1.code', 'invalid_ping')
            ->assertJsonPath('data.results.2.code', 'future_ping')->assertJsonPath('data.results.3.code', 'outside_trip');
        $this->assertDatabaseCount('location_pings', 0);
    }

    public function test_batch_size_limit_and_ingestion_rate_limit_are_enforced(): void
    {
        config(['commutelive.ping_batch_limit' => 1, 'commutelive.ingestion_requests_per_minute' => 2]);
        $this->send([$this->ping(), $this->ping()])->assertUnprocessable()->assertJsonValidationErrors('pings');
        $this->send([$this->ping()])->assertOk();
        $this->send([$this->ping()])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_occupancy_is_validated_expires_and_is_not_carried_to_a_new_trip(): void
    {
        $trip = $this->trip();
        $this->putJson('/api/driver/status', ['trip_id' => $trip->id, 'occupancy' => 'overloaded'])->assertUnprocessable();
        $this->putJson('/api/driver/status', ['trip_id' => $trip->id, 'occupancy' => 'full'])->assertOk()->assertJsonPath('data.is_simulated', true);
        $this->send([$this->ping()]);
        $this->getJson('/api/vehicles')->assertJsonPath('data.0.occupancy', 'full');
        $this->travel(301)->seconds();
        $this->send([$this->ping()]);
        $this->getJson('/api/vehicles')->assertJsonPath('data.0.occupancy', 'unknown');
        $this->putJson('/api/driver/status', ['trip_id' => $trip->id, 'occupancy' => 'full'])->assertOk();
        $this->postJson("/api/driver/trips/{$trip->id}/end");
        $this->putJson('/api/driver/status', ['trip_id' => $trip->id, 'occupancy' => 'seats'])->assertStatus(409);
        $response = $this->postJson('/api/driver/trips/start', $this->consent())->assertCreated();
        $this->trip = Trip::findOrFail($response->json('data.id'));
        $this->send([$this->ping()])->assertOk();
        $this->getJson('/api/vehicles')->assertJsonPath('data.0.occupancy', 'unknown');
    }

    public function test_incidents_derive_identity_route_and_expiry_from_the_active_trip(): void
    {
        $data = ['trip_id' => $this->trip()->id, 'type' => 'road_closed', 'lat' => 14.65, 'lng' => 120.951];
        $this->postJson('/api/driver/incidents', $data + ['reporter_id' => 999])->assertUnprocessable();
        $this->postJson('/api/driver/incidents', $data)->assertCreated()->assertJsonPath('data.is_simulated', true);
        $this->assertSame($this->driver->id, IncidentReport::first()->reporter_id);
        $this->getJson('/api/incidents?route_id='.$this->route->id)->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.reporter_id');
        $this->travel(config('commutelive.incident_ttl_minutes'))->minutes();
        $this->getJson('/api/incidents')->assertJsonCount(0, 'data');
    }

    public function test_geometry_and_segment_layout_cannot_change_under_trip_history(): void
    {
        $this->trip();
        config(['commutelive.segment_length_m' => 100]);
        $this->artisan('commutelive:build-segments', ['--route' => $this->route->code])->assertFailed();
        config(['commutelive.segment_length_m' => 250]);
        $this->artisan('commutelive:build-segments', ['--route' => $this->route->code])->assertSuccessful();
    }

    public function test_real_classified_vehicle_pings_are_not_automatically_labeled_simulated(): void
    {
        $this->route->update(['is_simulated' => false]);
        $this->vehicle->update(['is_simulated' => false]);
        $response = $this->postJson('/api/driver/trips/start', $this->consent())->assertCreated()->assertJsonPath('data.is_simulated', false);
        $this->trip = Trip::findOrFail($response->json('data.id'));
        $this->send([$this->ping()])->assertJsonPath('data.results.0.is_simulated', false);
        $this->getJson('/api/vehicles')->assertJsonPath('contains_simulated_data', false)->assertJsonPath('data.0.is_simulated', false);
    }

    public function test_inactive_routes_and_missing_segments_cannot_start_tracking(): void
    {
        $this->route->update(['is_active' => false]);
        $this->postJson('/api/driver/trips/start', $this->consent())->assertStatus(409);
        $this->route->update(['is_active' => true]);
        $this->route->segments()->delete();
        $this->postJson('/api/driver/trips/start', $this->consent())->assertStatus(409);
        $this->assertDatabaseCount('trips', 0);
    }

    public function test_expired_uploads_and_impossible_speed_or_heading_are_rejected(): void
    {
        $this->send([$this->ping(['recorded_at' => now()->subDays(31)->toIso8601String()]),
            $this->ping(['speed_kph' => config('commutelive.ping_max_speed_kph') + 1]), $this->ping(['heading' => 360])])
            ->assertJsonPath('data.results.0.code', 'expired_ping')->assertJsonPath('data.results.1.code', 'invalid_ping')->assertJsonPath('data.results.2.code', 'invalid_ping');
        $this->assertDatabaseCount('location_pings', 0);
    }
}
