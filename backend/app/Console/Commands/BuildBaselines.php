<?php

namespace App\Console\Commands;

use App\Services\TrafficEngine;
use Illuminate\Console\Command;

class BuildBaselines extends Command
{
    protected $signature = 'commutelive:build-baselines';
    protected $description = 'Rebuild Manila weekday/weekend hourly historical speed baselines';

    public function handle(TrafficEngine $engine): int
    {
        $this->info('Built '.$engine->buildBaselines().' historical buckets.');
        return self::SUCCESS;
    }
}
