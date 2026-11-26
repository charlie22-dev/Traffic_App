<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(config('commutelive.auth_requests_per_minute'))->by('auth:'.$request->ip().':'.hash('sha256', strtolower((string) $request->input('email')))),
            Limit::perMinute(config('commutelive.auth_ip_requests_per_minute'))->by('auth-ip:'.$request->ip()),
        ]);
        RateLimiter::for('ingestion', fn (Request $request) => Limit::perMinute(config('commutelive.ingestion_requests_per_minute'))->by('pings:'.$request->user()->id));
        RateLimiter::for('driver-updates', fn (Request $request) => Limit::perMinute(config('commutelive.driver_updates_per_minute'))->by('updates:'.$request->user()->id));
    }
}
