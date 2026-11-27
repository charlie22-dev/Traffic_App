<?php

// Local, disposable, fixed-clock experiment. Never connects to the configured application DB.
use App\Http\Controllers\TrafficController;
use App\Models\{EtaPrediction, Route, Stop, Trip, User, Vehicle};
use App\Services\{EtaEngine, PingIngestor, TrafficEngine};
use App\Support\Geometry;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Artisan, DB};
use Illuminate\Support\Str;

require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
    'cache.default' => 'array', 'session.driver' => 'array', 'hashing.bcrypt.rounds' => 4]);
DB::purge('sqlite');
if (DB::connection()->getDatabaseName() !== ':memory:') throw new RuntimeException('Evaluation requires disposable in-memory SQLite.');

if (($argv[1] ?? '') === '--snapshot') {
    $line = [[120.95, 14.65], [120.96, 14.65]];
    $length = Geometry::length($line);
    $parameters = config('commutelive.simulator');
    $parameters['ping_batch_limit'] = config('commutelive.ping_batch_limit');
    $stops = [];
    foreach ([0, 400, $length] as $i => $m) {
        $point = Geometry::interpolate($line[0], $line[1], $m / $length);
        $stops[] = ['code' => 'EVAL-STOP-'.($i + 1), 'name' => 'Synthetic evaluation stop '.($i + 1), 'sequence' => $i + 1,
            'distance_along_route_m' => $m, 'lng' => $point[0], 'lat' => $point[1], 'is_simulated' => true];
    }
    $segments = [];
    foreach (Geometry::segments($line, config('commutelive.segment_length_m')) as $i => $s) $segments[] = ['sequence' => $i + 1] + $s;
    echo json_encode(['is_simulated' => true, 'parameters' => $parameters, 'routes' => [['data' => [
        'id' => 1, 'code' => 'EVAL-SYNTHETIC', 'mode' => 'jeepney', 'is_simulated' => true,
        'length_m' => $length, 'geometry_geojson' => ['type' => 'LineString', 'coordinates' => $line], 'stops' => $stops], 'segments' => $segments]],
        'assignments' => array_map(fn ($n) => ['label' => sprintf('EVAL-%04d', $n), 'route_code' => 'EVAL-SYNTHETIC', 'is_simulated' => true], range(1, 10))], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    exit;
}

$directory = realpath($argv[1] ?? '');
if (! $directory || ! is_file($directory.'/routes.json') || ! is_file($directory.'/samples.csv')) throw new RuntimeException('Provide an evaluation directory containing routes.json and samples.csv.');
$snapshot = json_decode(file_get_contents($directory.'/routes.json'), true, flags: JSON_THROW_ON_ERROR);
if (! ($snapshot['is_simulated'] ?? false)) throw new RuntimeException('Only explicitly simulated experiments are allowed.');
Artisan::call('migrate', ['--force' => true]);
$origin = CarbonImmutable::parse($snapshot['parameters']['start_time'])->utc();
$setClock = function ($seconds) use ($origin) {
    $time = $origin->addSeconds($seconds);
    Carbon::setTestNow($time);
    CarbonImmutable::setTestNow($time);
    return $time;
};
$setClock(0);
$routes = $stops = [];
foreach ($snapshot['routes'] as $input) {
    $d = $input['data'];
    if (! $d['is_simulated']) throw new RuntimeException('Real route experiment inputs are not supported here.');
    $route = Route::create(['code' => $d['code'], 'name' => 'Synthetic evaluation only', 'mode' => $d['mode'], 'direction' => 'fictional outbound',
        'color' => '#7b1fa2', 'geometry_geojson' => $d['geometry_geojson'], 'length_m' => $d['length_m'],
        'is_active' => true, 'is_simulated' => true, 'verification_status' => 'needs_review', 'source_metadata' => ['source' => 'synthetic evaluation fixture']]);
    foreach ($d['stops'] as $s) {
        $stop = Stop::create(['code' => $s['code'], 'name' => $s['name'], 'lat' => $s['lat'], 'lng' => $s['lng'],
            'source' => 'synthetic evaluation fixture', 'verification_status' => 'needs_review', 'is_simulated' => true]);
        $route->stops()->attach($stop->id, ['sequence' => $s['sequence'], 'distance_along_route_m' => $s['distance_along_route_m'], 'offset_m' => 0, 'is_simulated' => true]);
        $stops[$stop->code] = $stop;
    }
    Artisan::call('commutelive:build-segments', ['--route' => $route->code]);
    $routes[$route->code] = $route;
}
$rows = [];
$handle = fopen($directory.'/samples.csv', 'r');
$header = fgetcsv($handle);
while (($values = fgetcsv($handle)) !== false) {
    $row = array_combine($header, $values);
    if ($row['is_simulated'] !== 'true') throw new RuntimeException('Unflagged sample.');
    $rows[(int) round((float) $row['elapsed_seconds'])][] = $row;
}
fclose($handle);
$traffic = app(TrafficEngine::class);
$eta = app(EtaEngine::class);
$controller = app(TrafficController::class);
$ingestor = app(PingIngestor::class);
$drivers = $trips = [];
$trafficLog = [];
$duration = (int) $snapshot['evaluation']['duration_seconds'];
for ($tick = 0; $tick <= $duration; $tick += $snapshot['parameters']['ping_interval_seconds']) {
    $at = $setClock($tick);
    foreach ($rows[$tick] ?? [] as $row) {
        $label = $row['vehicle'];
        if (! isset($trips[$label])) {
            $driver = User::create(['name' => 'Synthetic evaluator', 'email' => strtolower($label).'@evaluation.test', 'password' => hash('sha256', $label), 'role' => 'driver', 'is_simulated' => true]);
            $route = $routes[$row['route_code']];
            $vehicle = Vehicle::create(['driver_id' => $driver->id, 'route_id' => $route->id, 'label' => $label, 'mode' => $route->mode, 'is_simulated' => true]);
            $trips[$label] = Trip::create(['vehicle_id' => $vehicle->id, 'route_id' => $route->id, 'driver_id' => $driver->id, 'active_driver_id' => $driver->id,
                'active_vehicle_id' => $vehicle->id, 'started_at' => $at, 'consented_at' => $at, 'consent_notice_version' => config('commutelive.privacy_notice_version'), 'is_simulated' => true]);
            $drivers[$label] = $driver;
        }
        if ($row['dropped'] === 'true') continue;
        $result = $ingestor->ingest($drivers[$label], $trips[$label]->id, [[
            'client_ping_uuid' => (string) Str::uuid(), 'lat' => (float) $row['lat'], 'lng' => (float) $row['lng'],
            'accuracy_m' => (float) $row['accuracy_m'], 'speed_kph' => (float) $row['speed_kph'], 'heading' => (float) $row['heading'],
            'recorded_at' => $at->toIso8601String(), 'is_simulated' => true,
        ]]);
        if ($result['counts']['rejected'] || $result['counts']['flagged_off_route']) throw new RuntimeException('Evaluation ingestion failed: '.json_encode($result));
    }
    if ($tick % 30 === 0) {
        $traffic->aggregate($at);
        foreach ($routes as $route) foreach ($route->segments as $segment) $trafficLog[] = ['elapsed_seconds' => $tick, 'route_code' => $route->code] + $traffic->segment($segment, $route->mode, true, $at);
    }
    if ($tick % 15 === 0) foreach ($stops as $stop) $controller->arrivals($stop, $traffic, $eta);
}
$predictions = EtaPrediction::withCasts(['predicted_at' => 'immutable_datetime'])->orderBy('id')->get()->map(function ($p) use ($trips, $stops, $origin) {
    $label = array_search($p->trip_id, array_map(fn ($t) => $t->id, $trips), true);
    $code = array_search($p->stop_id, array_map(fn ($s) => $s->id, $stops), true);
    return ['vehicle' => $label, 'stop' => $code, 'predicted_at' => $p->predicted_at->toIso8601String(),
        'elapsed_seconds' => $origin->diffInSeconds($p->predicted_at), 'predicted_arrival_at' => $p->predicted_arrival_at->toIso8601String(),
        'naive_arrival_at' => $p->naive_arrival_at->toIso8601String(), 'gps_arrival_at' => $p->actual_arrival_at?->toIso8601String(),
        'gps_arrival_source' => $p->actual_arrival_source, 'context' => $p->context, 'is_simulated' => $p->is_simulated];
});
file_put_contents($directory.'/predictions.json', json_encode(['is_simulated' => true, 'predictions' => $predictions,
    'traffic' => $trafficLog, 'config' => ['traffic' => config('commutelive.traffic'), 'eta' => config('commutelive.eta')]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo 'Replayed '.count($trips).' simulated trips, '.$predictions->count()." logged commuter ETAs.\n";
