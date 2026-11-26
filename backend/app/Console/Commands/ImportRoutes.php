<?php

namespace App\Console\Commands;

use App\Services\RouteImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportRoutes extends Command
{
    protected $signature = 'commutelive:import-routes {--data-dir= : Folder containing routes/*.geojson and stops.csv}';

    protected $description = 'Validate and transactionally import source-backed routes and ordered stops';

    public function handle(RouteImporter $importer): int
    {
        try {
            $result = $importer->import($this->option('data-dir') ?: config('commutelive.route_data_path'));
            $this->info("Imported {$result['routes']} directions and {$result['route_stops']} route stops. Run commutelive:build-segments next.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
