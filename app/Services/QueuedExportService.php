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
    /**
     * Record a large export and hand it to the queue worker.
     *
     * @param  string[]  $columns
     */
    public function queue(User $user, ExportFormat $format, ExportSelection $selection, array $columns): Export
    {
        $export = DB::transaction(function () use ($user, $format, $selection, $columns) {
            $export = Export::create([
                'user_id' => $user->getKey(),
                'format' => $format,
                'params' => ['selection' => $selection->toArray(), 'columns' => $columns],
                'status' => Export::STATUS_PENDING,
            ]);

            $this->audit($user, $format, $selection, $columns, null, $export);

            return $export;
        });

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
     * Delete exports (entries and files) created before $before, plus files in the
     * exports directory that no entry refers to and that are older than $before.
     *
     * @return array{exports: int, files: int}
     */
    public function prune(Carbon $before): array
    {
        $deleted = 0;
        Export::where('created_at', '<', $before)->orderBy('id')->chunkById(200, function ($exports) use (&$deleted) {
            foreach ($exports as $export) {
                $export->deleteWithFile();
                $deleted++;
            }
        });

        $disk = Export::disk();
        $known = Export::whereNotNull('file_path')->pluck('file_path')->flip();
        $orphans = 0;

        foreach ($disk->files(config('data_tools.exports.directory', 'exports')) as $file) {
            if (str_ends_with($file, '.gitignore') || $known->has($file)) {
                continue;
            }

            if ($disk->lastModified($file) < $before->getTimestamp()) {
                $disk->delete($file);
                $orphans++;
            }
        }

        return ['exports' => $deleted, 'files' => $orphans];
    }
}
