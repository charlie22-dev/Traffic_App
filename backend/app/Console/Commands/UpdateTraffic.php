<?php

namespace App\Console\Commands;

use App\Services\TrafficEngine;
use Illuminate\Console\Command;

class UpdateTraffic extends Command
{
    protected $signature = 'commutelive:update-traffic';
    protected $description = 'Aggregate matched recent speeds separately for real and simulated observations';

    public function handle(TrafficEngine $engine): int
    {
        $this->info('Updated '.$engine->aggregate().' segment/data-source windows.');
        return self::SUCCESS;
    }
}
