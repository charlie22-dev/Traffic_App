<?php

namespace Tests\Feature;

use App\Models\Route;
use App\Models\RouteSegment;
use App\Models\Stop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RouteDataTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/routes-'.bin2hex(random_bytes(6)));
        File::makeDirectory($this->directory.'/routes', 0755, true);
        $this->writeRoute();
        $this->writeStops();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function writeRoute(array $coordinates = [[120.95, 14.65], [120.96, 14.65]]): void
    {
        file_put_contents($this->directory.'/routes/test.geojson', json_encode([
            'type' => 'Feature', 'properties' => ['code' => 'TEST-OUT', 'name' => 'Synthetic test fixture',
                'mode' => 'jeepney', 'direction' => 'outbound', 'color' => '#123456',
                'verification_status' => 'needs_review',
                'source_metadata' => ['source' => 'unit-test-fixture-only', 'license' => 'test']],
            'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
        ], JSON_THROW_ON_ERROR));
    }

    private function writeStops(string $tail = ''): void
    {
        file_put_contents($this->directory.'/stops.csv', "route_code,stop_code,name,lat,lng,sequence,source,verification_status\n"
            ."TEST-OUT,stop-a,Start,14.65,120.95,1,test,needs_review\n"
            ."TEST-OUT,stop-b,End,14.65,120.96,2,test,needs_review\n".$tail);
    }

    private function import(): void
    {
        $this->artisan('commutelive:import-routes', ['--data-dir' => $this->directory])->assertSuccessful();
    }

    public function test_import_and_segment_build_are_idempotent_with_stable_ids(): void
    {
        $this->import();
        $this->artisan('commutelive:build-segments')->assertSuccessful();
        $routeId = Route::first()->id;
        $stopIds = Stop::orderBy('id')->pluck('id')->all();
        $segmentIds = RouteSegment::orderBy('sequence')->pluck('id')->all();
        $this->import();
        $this->artisan('commutelive:build-segments')->assertSuccessful();
        $this->assertDatabaseCount('routes', 1);
        $this->assertDatabaseCount('stops', 2);
        $this->assertDatabaseCount('route_stops', 2);
        $this->assertSame($routeId, Route::first()->id);
        $this->assertSame($stopIds, Stop::orderBy('id')->pluck('id')->all());
        $this->assertSame($segmentIds, RouteSegment::orderBy('sequence')->pluck('id')->all());
        $this->assertEqualsWithDelta(Route::first()->length_m, RouteSegment::sum('length_m'), 1e-6);
        $this->assertEqualsWithDelta(0, DB::table('route_stops')->where('sequence', 1)->value('distance_along_route_m'), 0.01);
        $this->assertEqualsWithDelta(Route::first()->length_m, DB::table('route_stops')->where('sequence', 2)->value('distance_along_route_m'), 0.01);
    }

    public function test_route_endpoints_expose_ordered_geometry_stops_segments_and_provenance(): void
    {
        $this->import();
        $this->artisan('commutelive:build-segments')->assertSuccessful();
        $id = Route::first()->id;
        $this->getJson('/api/routes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'TEST-OUT')
            ->assertJsonPath('data.0.stops_count', 2)->assertJsonMissingPath('data.0.geometry_geojson');
        $this->getJson("/api/routes/{$id}")->assertOk()->assertJsonPath('data.geometry_geojson.type', 'LineString')
            ->assertJsonPath('data.stops.0.sequence', 1)->assertJsonPath('data.stops.1.sequence', 2)
            ->assertJsonPath('data.verification_status', 'needs_review');
        $this->getJson("/api/routes/{$id}/segments")->assertOk()->assertJsonPath('data.0.sequence', 1)
            ->assertJsonPath('data.0.length_m', 250)->assertJsonPath('data.0.polyline.type', 'LineString');
        $this->getJson('/api/routes/999999')->assertNotFound();
        $this->getJson('/api/routes/999999/segments')->assertNotFound();
        $this->getJson('/api/routes/not-a-number')->assertNotFound();
    }

    public function test_inactive_routes_are_not_public(): void
    {
        $this->import();
        $route = Route::first();
        $route->update(['is_active' => false]);
        $this->getJson('/api/routes')->assertJsonCount(0, 'data');
        $this->getJson("/api/routes/{$route->id}")->assertNotFound();
        $this->getJson("/api/routes/{$route->id}/segments")->assertNotFound();
    }

    public function test_invalid_stop_aborts_entire_import_without_touching_existing_data(): void
    {
        $this->import();
        $before = DB::table('route_stops')->get()->toJson();
        $this->writeStops("TEST-OUT,far,Far away,15.0,121.5,3,test,needs_review\n");
        $this->artisan('commutelive:import-routes', ['--data-dir' => $this->directory])->assertFailed();
        $this->assertDatabaseCount('stops', 2);
        $this->assertSame($before, DB::table('route_stops')->get()->toJson());
    }

    public function test_duplicate_sequence_and_reversed_stop_order_are_rejected(): void
    {
        $this->writeStops("TEST-OUT,other,Duplicate sequence,14.65,120.96,2,test,needs_review\n");
        $this->artisan('commutelive:import-routes', ['--data-dir' => $this->directory])->assertFailed();
        $this->assertDatabaseCount('routes', 0);
        $this->writeStops();
        $this->writeRoute([[120.96, 14.65], [120.95, 14.65]]);
        $this->artisan('commutelive:import-routes', ['--data-dir' => $this->directory])->assertFailed();
        $this->assertDatabaseCount('routes', 0);
    }

    public function test_changed_geometry_invalidates_old_segments(): void
    {
        $this->import();
        $this->artisan('commutelive:build-segments')->assertSuccessful();
        $this->writeRoute([[120.95, 14.65], [120.955, 14.6501], [120.96, 14.65]]);
        $this->import();
        $this->assertDatabaseCount('route_segments', 0);
        $this->artisan('commutelive:build-segments')->assertSuccessful();
        $this->assertGreaterThan(0, RouteSegment::count());
    }

    public function test_segment_size_is_configurable_and_stale_tail_is_removed(): void
    {
        $this->import();
        config(['commutelive.segment_length_m' => 100]);
        $this->artisan('commutelive:build-segments')->assertSuccessful();
        $count = RouteSegment::count();
        config(['commutelive.segment_length_m' => 400]);
        $this->artisan('commutelive:build-segments')->assertSuccessful();
        $this->assertLessThan($count, RouteSegment::count());
        $this->assertEqualsWithDelta(400, RouteSegment::first()->length_m, 1e-6);
    }

    public function test_missing_data_and_invalid_segment_length_fail_without_partial_writes(): void
    {
        $this->artisan('commutelive:import-routes', ['--data-dir' => $this->directory.'/missing'])->assertFailed();
        $this->artisan('commutelive:build-segments')->assertFailed();
        $this->import();
        config(['commutelive.segment_length_m' => -1]);
        $this->artisan('commutelive:build-segments')->assertFailed();
        $this->assertDatabaseCount('route_segments', 0);
    }
}
