<?php

namespace App\Console\Commands;

use App\Models\LocationPing;
use Illuminate\Console\Command;

class PurgePings extends Command
{
    protected $signature = 'commutelive:purge-pings {--dry-run : Count expired pings without deleting them}';
    protected $description = 'Purge GPS pings older than the configured retention window';

    public function handle(): int
    {
        $days = config('commutelive.pings_retention_days');
        if (! is_int($days) || $days < 1) {
            $this->error('Pings retention must be a positive whole number of days.');

            return self::FAILURE;
        }
        $cutoff = now()->subDays($days);
        $query = LocationPing::where(fn ($query) => $query->where('recorded_at', '<', $cutoff)->orWhere('received_at', '<', $cutoff));
        $count = $this->option('dry-run') ? $query->count() : $query->delete();
        $this->info(($this->option('dry-run') ? 'Would purge ' : 'Purged ').$count." pings older than {$days} days.");

        return self::SUCCESS;
    }
}
