<?php

namespace Tests\Feature;

use App\Models\LocationPing;
use App\Models\Route;
use App\Models\SegmentBaseline;
use App\Models\SegmentSpeedStat;
use App\Models\EtaPrediction;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetentionAndDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_dry_run_boundary_and_actual_delete(): void
    {
        $this->travelTo(now()->startOfSecond());
        $trip = Trip::factory()->create();
        LocationPing::factory()->create(['trip_id' => $trip->id, 'recorded_at' => now()->subDays(31), 'received_at' => now()->subDays(31)]);
        LocationPing::factory()->create(['trip_id' => $trip->id, 'recorded_at' => now()->subDays(30), 'received_at' => now()->subDays(30)]);
        LocationPing::factory()->create(['trip_id' => $trip->id]);
        $this->artisan('commutelive:purge-pings', ['--dry-run' => true])->expectsOutput('Would purge 1 pings older than 30 days.')->assertSuccessful();
        $this->assertDatabaseCount('location_pings', 3);
        $this->artisan('commutelive:purge-pings')->assertSuccessful();
        $this->assertDatabaseCount('location_pings', 2);
        config(['commutelive.pings_retention_days' => 1]);
        $this->artisan('commutelive:purge-pings')->assertSuccessful();
        $this->assertDatabaseCount('location_pings', 1);
        config(['commutelive.pings_retention_days' => 0]);
        $this->artisan('commutelive:purge-pings')->assertFailed();
        $this->assertDatabaseCount('location_pings', 1);
    }

    public function test_demo_seeder_is_idempotent_and_does_not_impersonate_pilot_data(): void
    {
        config(['commutelive.demo_password' => 'test-only-demo-password']);
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);
        $this->assertDatabaseCount('users', 4);
        $this->assertSame(2, User::where('role', 'driver')->count());
        $this->assertDatabaseCount('vehicles', 2);
        $this->assertDatabaseCount('routes', 1);
        $this->assertDatabaseCount('stops', 2);
        $this->assertDatabaseCount('route_segments', 5);
        $this->assertSame(2, Vehicle::where('is_simulated', true)->count());
        $this->assertTrue(Route::first()->is_simulated);
        $this->getJson('/api/routes')->assertJsonPath('data.0.is_simulated', true);
        Trip::factory()->create(['vehicle_id' => Vehicle::first()->id]);
        $this->seed(DemoSeeder::class);
        $this->assertDatabaseCount('vehicles', 2);
    }

    public function test_remaining_data_model_factories_can_create_simulated_evaluation_records(): void
    {
        $this->assertTrue(SegmentBaseline::factory()->create()->is_simulated);
        $this->assertTrue(SegmentSpeedStat::factory()->create()->is_simulated);
        $this->assertTrue(EtaPrediction::factory()->create()->is_simulated);
    }

    public function test_purge_is_scheduled_daily(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'commutelive:purge-pings'));
        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }

    public function test_demo_seeder_refuses_to_overwrite_reserved_non_demo_data(): void
    {
        config(['commutelive.demo_password' => 'test-only-demo-password']);
        \App\Models\Stop::factory()->create(['code' => 'DEMO-STOP-1', 'is_simulated' => false]);
        try {
            $this->seed(DemoSeeder::class);
            $this->fail('Demo seeding must reject a non-demo identity collision.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('non-demo data', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('stops', 1);
    }
}
