<?php

namespace App\Console\Commands;

use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StopSimulator extends Command
{
    protected $signature = 'commutelive:stop-simulator';
    protected $description = 'End local simulator-pool trips and revoke its tokens; never affects real drivers or demo/pilot vehicles';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Simulator pool cleanup is local/testing only.');

            return self::FAILURE;
        }
        $count = DB::transaction(function () {
            $drivers = User::where('is_simulated', true)->where('role', 'driver')->where('email', 'like', 'simulator-%@commutelive.test')->get();
            $vehicleIds = Vehicle::where('is_simulated', true)->where('label', 'like', 'SIMULATOR-%')->whereIn('driver_id', $drivers->pluck('id'))->pluck('id');
            $trips = Trip::where('is_simulated', true)->whereIn('vehicle_id', $vehicleIds)->whereIn('driver_id', $drivers->pluck('id'))->whereNull('ended_at')->lockForUpdate()->get();
            foreach ($trips as $trip) {
                $trip->update(['ended_at' => now(), 'active_driver_id' => null, 'active_vehicle_id' => null]);
            }
            foreach ($drivers as $driver) {
                $driver->tokens()->where('name', 'simulator')->delete();
            }

            return $trips->count();
        });
        $this->info("Tracking OFF for {$count} simulator-pool trips; simulator tokens revoked. Other vehicles/drivers were untouched.");

        return self::SUCCESS;
    }
}
