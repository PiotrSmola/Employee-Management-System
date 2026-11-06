<?php

namespace App\Services;

use App\Enums\ExportFormat;
use App\Jobs\GenerateExport;
use App\Models\ActivityLog;
use App\Models\Export;
use App\Models\User;
use App\Support\ExportSelection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creating, auditing and cleaning up bulk exports ("My exports").
 */
class QueuedExportService
{
    /** Seconds a job may run past its timeout before its export counts as stalled. */
    private const STALLED_MARGIN = 600;

    /**
     * Record a large export and hand it to the queue worker. Returns null when the user
     * already has the maximum number of unfinished exports (checked under a lock on the
     * user's row, so parallel requests cannot exceed it).
     *
     * @param  string[]  $columns
     */
    public function queue(User $user, ExportFormat $format, ExportSelection $selection, array $columns): ?Export
    {
        $export = DB::transaction(function () use ($user, $format, $selection, $columns) {
            User::whereKey($user->getKey())->lockForUpdate()->first();

            $active = Export::ownedBy($user)->whereIn('status', Export::ACTIVE_STATUSES)->count();
            if ($active >= (int) config('data_tools.exports.max_active_per_user', 3)) {
                return null;
            }

            $export = Export::create([
                'user_id' => $user->getKey(),
                'format' => $format,
                'params' => ['selection' => $selection->toArray(), 'columns' => $columns],
                'status' => Export::STATUS_PENDING,
            ]);

            $this->audit($user, $format, $selection, $columns, null, $export);

            return $export;
        });

        if ($export === null) {
            return null;
        }

        // After the commit, so that a worker never picks up a job for an uncommitted row.
        GenerateExport::dispatch($export->id);

        return $export->refresh();
    }

    /**
     * Audit entry `data.exported` for a direct download ($rows known) or a queued export.
     *
     * @param  string[]  $columns
     */
    public function audit(User $user, ExportFormat $format, ExportSelection $selection, array $columns, ?int $rows, ?Export $export = null): void
    {
        AuditLogger::log('data.exported', ActivityLog::SUBJECT_EXPORT, $export?->id, array_filter([
            'format' => $format->value,
            'queued' => $export !== null,
            'rows' => $rows,
            'columns' => $columns,
            'filters' => $selection->filters,
            'select_all' => $selection->selectAll,
            'ids' => count($selection->ids),
        ], fn ($value) => $value !== null && $value !== []), $user);
    }

    /**
     * Delete finished exports (entries and files) created before $before, plus files in
     * the exports directory that no entry refers to and that are older than $before.
     * Unfinished exports and their files are never touched here ({@see failStalled()}).
     *
     * @return array{exports: int, files: int}
     */
    public function prune(Carbon $before): array
    {
        $deleted = 0;
        Export::where('created_at', '<', $before)
            ->whereNotIn('status', Export::ACTIVE_STATUSES)
            ->chunkById(200, function ($exports) use (&$deleted) {
                foreach ($exports as $export) {
                    $export->deleteWithFile();
                    $deleted++;
                }
            });

        $disk = Export::disk();
        $known = Export::whereNotNull('file_path')->pluck('file_path')->flip();
        $active = Export::whereIn('status', Export::ACTIVE_STATUSES)->pluck('id')->flip();
        $orphans = 0;

        foreach ($disk->files(config('data_tools.exports.directory', 'exports')) as $file) {
            if (str_ends_with($file, '.gitignore') || $known->has($file)) {
                continue;
            }

            // Generated files are named <export id>_<random>.<extension>.
            if ($active->has((int) strtok(basename($file), '_'))) {
                continue;
            }

            if ($disk->lastModified($file) < $before->getTimestamp()) {
                $disk->delete($file);
                $orphans++;
            }
        }

        return ['exports' => $deleted, 'files' => $orphans];
    }

    /**
     * Mark exports the worker will never finish as failed: running for longer than the
     * job's timeout allows (worker killed), or waiting for much too long (no worker).
     *
     * @return int the number of exports marked as failed
     */
    public function failStalled(): int
    {
        $failed = [
            'status' => Export::STATUS_FAILED,
            'error' => __('The export could not be generated (it took too long or the worker stopped). Please try again or export fewer employees.'),
            'finished_at' => now(),
        ];

        $stalled = Export::where('status', Export::STATUS_PROCESSING)
            ->where('started_at', '<', now()->subSeconds(GenerateExport::TIMEOUT + self::STALLED_MARGIN))
            ->update($failed);

        return $stalled + Export::where('status', Export::STATUS_PENDING)
            ->where('created_at', '<', now()->subHours((int) config('data_tools.exports.pending_timeout_hours', 24)))
            ->update($failed);
    }
}
