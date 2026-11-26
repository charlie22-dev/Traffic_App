<?php

namespace Tests\Feature;

use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SimulatorProvisionTest extends TestCase
{
    use RefreshDatabase;
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/simulator-'.bin2hex(random_bytes(6)));
        File::makeDirectory($this->directory, 0755, true);
        Route::factory()->create();
        $this->artisan('commutelive:build-segments')->assertSuccessful();
        $route = Route::first();
        foreach ([0, 1] as $index) {
            $stop = \App\Models\Stop::factory()->create(['lng' => 120.95 + $index * 0.01]);
            $route->stops()->attach($stop->id, ['sequence' => $index + 1, 'distance_along_route_m' => $index * $route->length_m, 'offset_m' => 0]);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function provisionOptions(): array
    {
        return ['--vehicles' => 3, '--allow-synthetic-route' => true, '--consent-simulated-tracking' => true, '--output' => $this->directory.'/.env'];
    }

    public function test_provisioning_is_simulated_idempotent_and_keeps_credentials_out_of_output(): void
    {
        $this->artisan('commutelive:provision-simulator', $this->provisionOptions())->assertSuccessful();
        $this->assertDatabaseCount('vehicles', 3);
        $this->assertSame(3, User::where('role', 'driver')->where('is_simulated', true)->count());
        $this->assertFileExists($this->directory.'/.env');
        $this->assertStringContainsString('SIM_DRIVERS_JSON=', file_get_contents($this->directory.'/.env'));
        $this->artisan('commutelive:provision-simulator', $this->provisionOptions())->assertSuccessful();
        $this->assertDatabaseCount('vehicles', 3);
        $this->assertDatabaseCount('personal_access_tokens', 3);
    }

    public function test_consent_synthetic_geometry_and_active_trip_guards(): void
    {
        $options = $this->provisionOptions();
        unset($options['--consent-simulated-tracking']);
        $this->artisan('commutelive:provision-simulator', $options)->assertFailed();
        $options = $this->provisionOptions();
        unset($options['--allow-synthetic-route']);
        $this->artisan('commutelive:provision-simulator', $options)->assertFailed();
        $this->artisan('commutelive:provision-simulator', $this->provisionOptions())->assertSuccessful();
        Trip::factory()->create(['vehicle_id' => Vehicle::first()->id]);
        $before = file_get_contents($this->directory.'/.env');
        $this->artisan('commutelive:provision-simulator', $this->provisionOptions())->assertFailed();
        $this->assertSame($before, file_get_contents($this->directory.'/.env'));
    }

    public function test_emergency_cleanup_is_limited_to_simulator_pool(): void
    {
        $this->artisan('commutelive:provision-simulator', $this->provisionOptions())->assertSuccessful();
        $owned = Trip::factory()->create(['vehicle_id' => Vehicle::first()->id]);
        $other = Trip::factory()->create();
        $this->artisan('commutelive:stop-simulator')->assertSuccessful();
        $this->assertNotNull($owned->fresh()->ended_at);
        $this->assertNull($other->fresh()->ended_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_private_active_trip_discovery_returns_only_current_driver_trip(): void
    {
        $trip = Trip::factory()->create();
        $driver = User::findOrFail($trip->driver_id);
        $this->actingAs($driver, 'sanctum')->getJson('/api/driver/trips/active')->assertOk()->assertJsonPath('data.id', $trip->id)->assertJsonPath('tracking_on', true);
        $other = User::factory()->driver()->create();
        $this->actingAs($other, 'sanctum')->getJson('/api/driver/trips/active')->assertOk()->assertJsonPath('data', null)->assertJsonPath('tracking_on', false);
    }
}
