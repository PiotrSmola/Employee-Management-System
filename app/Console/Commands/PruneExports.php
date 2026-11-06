<?php

namespace App\Console\Commands;

use App\Services\QueuedExportService;
use Illuminate\Console\Command;

/**
 * Deletes old finished exports ("My exports" entries and their files) and marks
 * exports the worker will never finish as failed. Scheduled daily
 * (routes/console.php); the scheduler runs in the `scheduler` container.
 */
class PruneExports extends Command
{
    /** Younger files are never deleted: they may belong to an export being written. */
    private const MIN_DAYS = 1;

    protected $signature = 'exports:prune
        {--days= : Delete exports older than this many days, at least 1 (default: data_tools.exports.retention_days)}';

    protected $description = 'Delete finished exports (files and list entries) older than the retention period';

    public function handle(QueuedExportService $exports): int
    {
        $days = $this->option('days') ?? config('data_tools.exports.retention_days', 7);

        if (! is_numeric($days) || (int) $days < self::MIN_DAYS) {
            $this->error('--days must be a number of days (at least '.self::MIN_DAYS.').');

            return self::INVALID;
        }

        // Stalled exports are deleted by a later run, so their owners can see what happened.
        $result = $exports->prune(now()->subDays((int) $days));
        $stalled = $exports->failStalled();

        $this->info(sprintf(
            'Deleted %d export(s) and %d orphaned file(s) older than %d day(s). Marked %d stalled export(s) as failed.',
            $result['exports'], $result['files'], (int) $days, $stalled
        ));

        return self::SUCCESS;
    }
}
