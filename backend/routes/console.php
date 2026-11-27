<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('commutelive:purge-pings')->daily()->withoutOverlapping();
Schedule::command('commutelive:update-traffic')->everyThirtySeconds()->withoutOverlapping(2);
Schedule::command('commutelive:build-baselines')->dailyAt('00:05')->timezone('Asia/Manila')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
