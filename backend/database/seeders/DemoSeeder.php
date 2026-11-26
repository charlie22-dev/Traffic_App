<?php

namespace Database\Seeders;

use App\Models\Route;
use App\Models\Stop;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Geometry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Synthetic demo seeding is allowed only in local/testing environments.');
        }
        $password = config('commutelive.demo_password');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Set DEMO_PASSWORD to at least 12 characters in the ignored backend/.env before seeding.');
        }
        $accounts = ['admin' => 'admin', 'driver1' => 'driver', 'driver2' => 'driver', 'commuter' => 'commuter'];
        foreach ($accounts as $name => $role) {
            if (User::where('email', $name.'@commutelive.test')->where('is_simulated', false)->exists()) {
                throw new RuntimeException('A reserved demo email belongs to a non-demo account; no demo users were changed.');
            }
        }
        DB::transaction(function () use ($accounts, $password) {
            foreach ($accounts as $name => $role) {
                $user = User::firstOrNew(['email' => $name.'@commutelive.test']);
                $user->forceFill(['name' => 'Synthetic demo '.$name, 'role' => $role,
                    'password' => $password, 'is_simulated' => true])->save();
            }
            $coordinates = [[120.95, 14.65], [120.96, 14.65]];
            $route = Route::firstOrCreate(['code' => 'DEMO-SYNTHETIC-OUT'], [
                'name' => 'Synthetic demo line (not a pilot service)', 'direction' => 'Synthetic A to B', 'mode' => 'jeepney',
                'color' => '#7b1fa2', 'geometry_geojson' => ['type' => 'LineString', 'coordinates' => $coordinates],
                'length_m' => Geometry::length($coordinates), 'is_active' => true, 'is_simulated' => true,
                'verification_status' => 'needs_review', 'source_metadata' => ['source' => 'Synthetic developer fixture',
                    'license' => 'project-test-fixture', 'notes' => 'Simulated data. This straight test line is not a real jeepney route or boarding recommendation.'],
            ]);
            if (! $route->is_simulated) {
                throw new RuntimeException('The reserved demo code belongs to a non-simulated route.');
            }
            foreach ($coordinates as $index => $coordinate) {
                $stop = Stop::updateOrCreate(['code' => 'DEMO-STOP-'.($index + 1)], [
                    'name' => 'Synthetic demo stop '.($index + 1), 'lng' => $coordinate[0], 'lat' => $coordinate[1],
                    'source' => 'Synthetic developer fixture', 'verification_status' => 'needs_review', 'is_simulated' => true,
                ]);
                DB::table('route_stops')->updateOrInsert(['route_id' => $route->id, 'sequence' => $index + 1], [
                    'stop_id' => $stop->id, 'distance_along_route_m' => $index ? $route->length_m : 0, 'offset_m' => 0, 'is_simulated' => true,
                ]);
            }
            foreach (['driver1', 'driver2'] as $index => $name) {
                Vehicle::updateOrCreate(['label' => 'SIM-JEEP-0'.($index + 1)], [
                    'driver_id' => User::where('email', $name.'@commutelive.test')->firstOrFail()->id,
                    'route_id' => $route->id, 'mode' => 'jeepney', 'is_simulated' => true,
                ]);
            }
            if (Artisan::call('commutelive:build-segments', ['--route' => $route->code]) !== 0) {
                throw new RuntimeException('Demo segments could not be built: '.Artisan::output());
            }
        });
        $this->command?->info('Seeded 1 synthetic admin, 2 synthetic drivers, 1 synthetic commuter and 2 simulated vehicles. No pilot imports were modified.');
    }
}
