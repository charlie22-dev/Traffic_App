<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

final class DriverTrips
{
    /** Must be called inside a transaction when updating tracking state. */
    public function owned(User $driver, int $id, bool $active = false): Trip
    {
        $trip = Trip::lockForUpdate()->findOrFail($id);
        abort_unless($trip->driver_id === $driver->id, 403, 'This trip belongs to another driver.');
        abort_if($active && $trip->ended_at !== null, 409, 'Tracking is OFF for this trip. Start a new trip first.');

        return $trip;
    }

    public function start(User $driver, array $data): Trip
    {
        return DB::transaction(function () use ($driver, $data) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($data['vehicle_id']);
            abort_unless($vehicle->driver_id === $driver->id, 403, 'This vehicle is not assigned to you.');
            // Share the route lock with import/build commands to prevent mid-trip geometry changes.
            $route = $vehicle->route()->lockForUpdate()->firstOrFail();
            abort_unless($route->is_active && $route->segments()->exists(), 409, 'The route must be active with built segments before tracking.');
            abort_if(Trip::where('active_driver_id', $driver->id)->exists() || Trip::where('active_vehicle_id', $vehicle->id)->exists(), 409, 'An active trip already exists for this driver or vehicle.');

            return Trip::create([
                'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'route_id' => $route->id,
                'active_driver_id' => $driver->id, 'active_vehicle_id' => $vehicle->id,
                'started_at' => now(), 'consented_at' => now(), 'consent_notice_version' => config('commutelive.privacy_notice_version'),
                'start_battery_pct' => $data['start_battery_pct'] ?? null,
                'is_simulated' => $vehicle->is_simulated || $route->is_simulated || $driver->is_simulated,
            ]);
        });
    }
}
