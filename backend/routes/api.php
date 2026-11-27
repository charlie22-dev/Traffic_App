<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DriverTripController;
use App\Http\Controllers\DriverReportController;
use App\Http\Controllers\PingController;
use App\Http\Controllers\LiveController;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'CommuteLive API',
]))->name('api.health');

Route::get('/client-config', fn () => response()->json(['data' => config('commutelive.commuter') + [
    'vehicle_stale_seconds' => config('commutelive.vehicle_stale_seconds'),
    'traffic_stale_seconds' => config('commutelive.traffic.stats_stale_seconds'),
]]));

Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum');

Route::get('/routes', [RouteController::class, 'index']);
Route::get('/routes/{route}', [RouteController::class, 'show'])->whereNumber('route');
Route::get('/routes/{route}/segments', [RouteController::class, 'segments'])->whereNumber('route');

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/vehicles', [LiveController::class, 'vehicles']);
Route::get('/incidents', [LiveController::class, 'incidents']);
Route::get('/traffic', [\App\Http\Controllers\TrafficController::class, 'traffic']);
Route::get('/stops/{stop}/arrivals', [\App\Http\Controllers\TrafficController::class, 'arrivals'])->whereNumber('stop')->middleware('throttle:60,1');
Route::get('/privacy/tracking', fn () => response()->json(['data' => [
    'version' => config('commutelive.privacy_notice_version'),
    'pings_retention_days' => config('commutelive.pings_retention_days'),
    'notice' => 'Tracking starts only with your explicit consent. While ON, we store vehicle GPS coordinates, speed, heading, accuracy and trip times. Vehicle positions, route, occupancy and incident reports are public; driver name and email are excluded from public feeds. Ending the trip or signing out turns tracking OFF and hides the vehicle. Buffered pings recorded during the consented trip may upload afterward, but do not make an ended trip live again. Incident reports remain public until they expire. GPS retention is enforced by the scheduled purge command. Commuter location is not collected by these endpoints.',
]]));

Route::prefix('driver')->middleware(['auth:sanctum', 'role:driver'])->group(function () {
    Route::get('/vehicles', [DriverTripController::class, 'vehicles']);
    Route::get('/trips/active', [DriverTripController::class, 'active']);
    Route::post('/trips/start', [DriverTripController::class, 'start'])->middleware('throttle:driver-updates');
    Route::post('/trips/{trip}/end', [DriverTripController::class, 'end'])->whereNumber('trip')->middleware('throttle:driver-updates');
    Route::post('/pings', [PingController::class, 'store'])->middleware('throttle:ingestion');
    Route::put('/status', [DriverReportController::class, 'status'])->middleware('throttle:driver-updates');
    Route::post('/incidents', [DriverReportController::class, 'incident'])->middleware('throttle:driver-updates');
});
