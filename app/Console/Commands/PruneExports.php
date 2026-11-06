<?php

namespace App\Console\Commands;

use App\Services\QueuedExportService;
use Illuminate\Console\Command;

/**
 * Deletes old exports ("My exports" entries and their files). Scheduled daily
 * (routes/console.php); the scheduler runs in the `scheduler` container.
 */
class PruneExports extends Command
{
    protected $signature = 'exports:prune
        {--days= : Delete exports older than this many days (default: data_tools.exports.retention_days)}';

    protected $description = 'Delete generated exports (files and list entries) older than the retention period';

    public function handle(QueuedExportService $exports): int
    {
        $days = $this->option('days') ?? config('data_tools.exports.retention_days', 7);

        if (! is_numeric($days) || (int) $days < 0) {
            $this->error('--days must be a number of days (0 or more).');

            return self::INVALID;
        }

        $result = $exports->prune(now()->subDays((int) $days));

        $this->info(sprintf('Deleted %d export(s) and %d orphaned file(s) older than %d day(s).', $result['exports'], $result['files'], (int) $days));

        return self::SUCCESS;
    }
}
