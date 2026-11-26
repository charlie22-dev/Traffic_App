<?php

namespace App\Console\Commands;

use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProvisionSimulator extends Command
{
    protected $signature = 'commutelive:provision-simulator {--vehicles=10} {--route=* : Direction codes; default all active real directions} {--allow-synthetic-route} {--consent-simulated-tracking} {--api-url=http://127.0.0.1:8000} {--output= : Testing-only .env destination}';
    protected $description = 'Provision simulated drivers/vehicles and write tokens only into ignored simulator/.env';

    public function handle(): int
    {
        try {
            if (! app()->environment(['local', 'testing']) || ! $this->option('consent-simulated-tracking')) {
                throw new RuntimeException('Local/testing only. Pass --consent-simulated-tracking to authorize synthetic driver tracking.');
            }
            $count = filter_var($this->option('vehicles'), FILTER_VALIDATE_INT);
            if ($count === false || $count < 1 || $count > config('commutelive.simulator.max_vehicles')) {
                throw new RuntimeException('Vehicle count is outside the configured simulator limit.');
            }
            $url = rtrim($this->option('api-url'), '/');
            $parsed = parse_url($url);
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($parsed['scheme'] ?? '', ['http', 'https'], true)
                || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) {
                throw new RuntimeException('Use a plain HTTP(S) API origin without credentials, query or fragment.');
            }
            $codes = $this->option('route');
            $query = Route::where('is_active', true)->orderBy('code');
            if ($codes) {
                $query->whereIn('code', $codes);
            } elseif (! $this->option('allow-synthetic-route')) {
                $query->where('is_simulated', false);
            }
            $routes = $query->get();
            if ($routes->isEmpty() || ($codes && $routes->count() !== count(array_unique($codes)))) {
                throw new RuntimeException('No matching active route data. Import pilot directions first or explicitly select/allow the separate synthetic demo.');
            }
            if ($count < $routes->count()) {
                throw new RuntimeException('Choose at least one vehicle per selected direction.');
            }
            foreach ($routes as $route) {
                if (($route->is_simulated && ! $this->option('allow-synthetic-route')) || $route->stops()->count() < 2 || ! $route->segments()->exists()) {
                    throw new RuntimeException('Each route needs stops/segments; synthetic geometry requires --allow-synthetic-route.');
                }
            }
            $path = dirname(base_path()).'/simulator/.env';
            if ($this->option('output')) {
                $parent = realpath(dirname($this->option('output')));
                $allowed = realpath(storage_path('framework/testing'));
                if (! app()->environment('testing') || ! $parent || ! $allowed
                    || ! str_starts_with(strtolower($parent.DIRECTORY_SEPARATOR), strtolower($allowed.DIRECTORY_SEPARATOR))
                    || basename($this->option('output')) !== '.env') {
                    throw new RuntimeException('Custom output is restricted to a testing .env inside storage/framework/testing.');
                }
                $path = $this->option('output');
            }
            if (is_link($path)) {
                throw new RuntimeException('Refusing to replace a symbolic-link credentials file.');
            }
            $original = is_file($path) ? file_get_contents($path) : '';
            DB::transaction(function () use ($routes, $count, $path, $url, $original) {
                $drivers = [];
                for ($i = 0; $i < $count; $i++) {
                    $label = sprintf('SIMULATOR-%04d', $i + 1);
                    $email = strtolower($label).'@commutelive.test';
                    $driver = User::firstOrNew(['email' => $email]);
                    $vehicle = Vehicle::firstOrNew(['label' => $label]);
                    if (($driver->exists && (! $driver->is_simulated || $driver->role !== 'driver')) || ($vehicle->exists && ! $vehicle->is_simulated)) {
                        throw new RuntimeException('A simulator identity collides with non-simulator data.');
                    }
                    if (($driver->exists && Trip::where('active_driver_id', $driver->id)->exists()) || ($vehicle->exists && $vehicle->activeTrip()->exists())) {
                        throw new RuntimeException('A simulator driver/vehicle is already tracking. End that run before provisioning.');
                    }
                    if (! $driver->exists) {
                        $driver->forceFill(['name' => $label, 'role' => 'driver', 'is_simulated' => true, 'password' => Str::random(48)])->save();
                    }
                    $route = $routes[$i % $routes->count()];
                    $vehicle->fill(['driver_id' => $driver->id, 'route_id' => $route->id, 'mode' => $route->mode, 'is_simulated' => true])->save();
                    $driver->tokens()->where('name', 'simulator')->delete();
                    $token = $driver->createToken('simulator', ['driver'], now()->addMinutes(config('commutelive.token_lifetime_minutes')));
                    $drivers[] = ['vehicle_id' => $vehicle->id, 'label' => $label, 'route_id' => $route->id, 'token' => $token->plainTextToken, 'is_simulated' => true];
                }
                $parameters = config('commutelive.simulator');
                $parameters['ping_batch_limit'] = config('commutelive.ping_batch_limit');
                $parameters['flush_interval_wall_seconds'] = max($parameters['flush_interval_wall_seconds'], 60 / max(1, config('commutelive.ingestion_requests_per_minute')) + 0.1);
                $values = [
                    'SIM_API_URL' => $url, 'SIM_TRACKING_CONSENT' => 'true',
                    'SIM_ALLOW_SYNTHETIC_ROUTES' => $this->option('allow-synthetic-route') ? 'true' : 'false',
                    'SIM_DRIVERS_JSON' => json_encode($drivers, JSON_THROW_ON_ERROR),
                    'SIM_PARAMETERS_JSON' => json_encode($parameters, JSON_THROW_ON_ERROR),
                ];
                $text = $original;
                foreach ($values as $key => $value) {
                    $line = $key."='".str_replace("'", "\\'", $value)."'";
                    $text = preg_match('/^'.preg_quote($key, '/').'=/m', $text)
                        ? preg_replace_callback('/^'.preg_quote($key, '/').'=.*$/m', fn () => $line, $text)
                        : rtrim($text)."\n".$line."\n";
                }
                $temporary = $path.'.tmp-'.Str::random(8);
                if (file_put_contents($temporary, $text, LOCK_EX) === false || ! rename($temporary, $path)) {
                    @unlink($temporary);
                    throw new RuntimeException('Could not write the ignored simulator credentials file.');
                }
                @chmod($path, 0600);
            });
            $this->info("Provisioned {$count} simulated drivers/vehicles across {$routes->count()} directions. Credentials written to ignored .env; tokens are not printed.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
