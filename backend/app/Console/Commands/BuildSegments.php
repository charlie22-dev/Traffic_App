<?php

namespace App\Console\Commands;

use App\Models\Route;
use App\Support\Geometry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BuildSegments extends Command
{
    protected $signature = 'commutelive:build-segments {--route= : Route code (otherwise all imported routes)}';

    protected $description = 'Build configurable fixed-length route segments, keeping their stable sequence identities';

    public function handle(): int
    {
        try {
            $routes = Route::when($this->option('route'), fn ($query, $code) => $query->where('code', $code))->orderBy('id')->get();
            if ($routes->isEmpty()) {
                $this->error('No matching imported routes. Import source-backed route data first.');

                return self::FAILURE;
            }
            $count = DB::transaction(function () use ($routes) {
                $count = 0;
                foreach ($routes as $route) {
                    $segments = Geometry::segments($route->geometry_geojson['coordinates'], (float) config('commutelive.segment_length_m'));
                    foreach ($segments as $index => $segment) {
                        $route->segments()->updateOrCreate(['sequence' => $index + 1], $segment);
                    }
                    $route->segments()->where('sequence', '>', count($segments))->delete();
                    $count += count($segments);
                }

                return $count;
            });
            $this->info("Built {$count} segments across {$routes->count()} route directions.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
