<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RouteController;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'CommuteLive API',
]))->name('api.health');

Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum');

Route::get('/routes', [RouteController::class, 'index']);
Route::get('/routes/{route}', [RouteController::class, 'show'])->whereNumber('route');
Route::get('/routes/{route}/segments', [RouteController::class, 'segments'])->whereNumber('route');
