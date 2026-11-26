<?php

namespace App\Services;

use App\Models\Route;
use App\Models\Stop;
use App\Support\Geometry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class RouteImporter
{
    public function import(string $directory): array
    {
        $paths = glob($directory.'/routes/*.geojson') ?: [];
        sort($paths);
        if ($paths === []) {
            throw new InvalidArgumentException('No route GeoJSON files found. Supply source-backed route geometry first.');
        }
        $features = [];
        foreach ($paths as $path) {
            $feature = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            Validator::make($feature, [
                'type' => 'required|in:Feature', 'geometry.type' => 'required|in:LineString',
                'geometry.coordinates' => 'required|array|min:2',
                'properties.code' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
                'properties.name' => 'required|string|max:255', 'properties.mode' => 'required|in:jeepney,bus',
                'properties.direction' => 'required|string|max:120',
                'properties.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'properties.verification_status' => 'required|in:needs_review,osm_mapped,field_verified',
                'properties.source_metadata' => 'required|array',
                'properties.source_metadata.source' => 'required|string',
                'properties.source_metadata.license' => 'required|string',
            ])->validate();
            $code = $feature['properties']['code'];
            if (isset($features[$code])) {
                throw new InvalidArgumentException("Duplicate route code in input: {$code}");
            }
            if (Geometry::length($feature['geometry']['coordinates']) <= 1e-6) {
                throw new InvalidArgumentException("Route {$code} has zero length.");
            }
            $features[$code] = $feature;
        }
        $stopPath = $directory.'/stops.csv';
        if (! is_file($stopPath) || ($handle = fopen($stopPath, 'r')) === false) {
            throw new InvalidArgumentException('Cannot read stops.csv.');
        }
        $rows = [];
        $stopIdentities = [];
        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            $required = ['route_code', 'stop_code', 'name', 'lat', 'lng', 'sequence', 'source', 'verification_status'];
            if ($header === false || count(array_unique($header)) !== count($header) || array_diff($required, $header)) {
                throw new InvalidArgumentException('stops.csv must include unique headers: '.implode(', ', $required));
            }
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($header) !== count($values)) {
                    throw new InvalidArgumentException('Malformed CSV row.');
                }
                $row = array_combine($header, $values);
                Validator::make($row, [
                    'route_code' => 'required|string', 'stop_code' => 'required|string|max:100',
                    'name' => 'required|string|max:255', 'lat' => 'required|numeric|between:-90,90',
                    'lng' => 'required|numeric|between:-180,180', 'sequence' => 'required|integer|min:1',
                    'source' => 'required|string|max:255',
                    'verification_status' => 'required|in:needs_review,osm_mapped,field_verified',
                ])->validate();
                $code = $row['route_code'];
                $sequence = (int) $row['sequence'];
                if (! isset($features[$code]) || isset($rows[$code][$sequence])) {
                    throw new InvalidArgumentException("Unknown route or duplicate stop sequence: {$code}/{$sequence}");
                }
                $identity = [$row['name'], (float) $row['lat'], (float) $row['lng'], $row['source'], $row['verification_status']];
                if (isset($stopIdentities[$row['stop_code']]) && $stopIdentities[$row['stop_code']] !== $identity) {
                    throw new InvalidArgumentException("Conflicting shared stop: {$row['stop_code']}");
                }
                $stopIdentities[$row['stop_code']] = $identity;
                $projection = Geometry::project([(float) $row['lng'], (float) $row['lat']], $features[$code]['geometry']['coordinates']);
                if ($projection['offset_m'] > (float) config('commutelive.stop_max_offset_m')) {
                    throw new InvalidArgumentException("Stop {$row['stop_code']} is too far from route {$code}: ".round($projection['offset_m']).' m');
                }
                $rows[$code][$sequence] = $row + $projection;
            }
        } finally {
            fclose($handle);
        }
        foreach ($features as $code => $feature) {
            if (count($rows[$code] ?? []) < 2) {
                throw new InvalidArgumentException("Route {$code} needs at least two source-backed stops.");
            }
            ksort($rows[$code]);
            $previous = -1.0;
            $sequence = 1;
            foreach ($rows[$code] as $row) {
                if ((int) $row['sequence'] !== $sequence++ || $row['distance_along_route_m'] < $previous - 1e-6) {
                    throw new InvalidArgumentException("Stop order must be contiguous and follow route travel: {$code}");
                }
                $previous = $row['distance_along_route_m'];
            }
        }

        return DB::transaction(function () use ($features, $rows) {
            foreach ($features as $code => $feature) {
                $properties = $feature['properties'];
                $existing = Route::where('code', $code)->lockForUpdate()->first();
                $geometryChanged = $existing && $existing->geometry_geojson !== $feature['geometry'];
                if ($geometryChanged && $existing->trips()->exists()) {
                    throw new InvalidArgumentException('This route has trip history. Use a new route version/code instead of changing its geometry.');
                }
                $route = Route::updateOrCreate(['code' => $code], [
                    'name' => $properties['name'], 'mode' => $properties['mode'],
                    'direction' => $properties['direction'], 'color' => $properties['color'],
                    'geometry_geojson' => $feature['geometry'], 'length_m' => Geometry::length($feature['geometry']['coordinates']),
                    'is_active' => true, 'verification_status' => $properties['verification_status'],
                    'source_metadata' => $properties['source_metadata'],
                ]);
                if ($geometryChanged) {
                    $route->segments()->delete();
                }
                $sequences = [];
                foreach ($rows[$code] as $row) {
                    $stop = Stop::updateOrCreate(['code' => $row['stop_code']], [
                        'name' => $row['name'], 'lat' => $row['lat'], 'lng' => $row['lng'],
                        'source' => $row['source'], 'verification_status' => $row['verification_status'],
                    ]);
                    DB::table('route_stops')->updateOrInsert(['route_id' => $route->id, 'sequence' => (int) $row['sequence']], [
                        'stop_id' => $stop->id, 'distance_along_route_m' => $row['distance_along_route_m'], 'offset_m' => $row['offset_m'],
                    ]);
                    $sequences[] = (int) $row['sequence'];
                }
                DB::table('route_stops')->where('route_id', $route->id)->whereNotIn('sequence', $sequences)->delete();
            }

            return ['routes' => count($features), 'route_stops' => array_sum(array_map('count', $rows))];
        });
    }
}
