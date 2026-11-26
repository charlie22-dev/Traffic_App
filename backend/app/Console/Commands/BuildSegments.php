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
                    $route = Route::whereKey($route->id)->lockForUpdate()->firstOrFail();
                    $segments = Geometry::segments($route->geometry_geojson['coordinates'], (float) config('commutelive.segment_length_m'));
                    if ($route->trips()->exists()) {
                        $existing = $route->segments()->get()->map(fn ($segment) => [
                            'start_m' => $segment->start_m, 'end_m' => $segment->end_m,
                            'length_m' => $segment->length_m, 'polyline' => $segment->polyline,
                        ])->all();
                        if (! $this->sameLayout($existing, $segments)) {
                            throw new \RuntimeException('This route has trip history. Create a new route version/code before changing its segment layout.');
                        }
                    }
                    foreach ($segments as $index => $segment) {
                        $route->segments()->updateOrCreate(['sequence' => $index + 1], $segment + ['is_simulated' => $route->is_simulated]);
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

    private function sameLayout(array $existing, array $target): bool
    {
        if (count($existing) !== count($target)) {
            return false;
        }
        foreach ($existing as $index => $segment) {
            foreach (['start_m', 'end_m', 'length_m'] as $field) {
                // Database/JSON round trips can differ below numerical precision.
                if (abs($segment[$field] - $target[$index][$field]) > 1e-5) {
                    return false;
                }
            }
            $coordinates = $segment['polyline']['coordinates'];
            $other = $target[$index]['polyline']['coordinates'];
            if (count($coordinates) !== count($other)) {
                return false;
            }
            foreach ($coordinates as $vertex => $point) {
                if (Geometry::haversine($point, $other[$vertex]) > 1e-5) {
                    return false;
                }
            }
        }

        return true;
    }
}
