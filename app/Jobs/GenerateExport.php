<?php

namespace App\Jobs;

use App\Models\Export;
use App\Services\EmployeeExportService;
use App\Support\Ability;
use App\Support\EmployeeVisibility;
use App\Support\ExportSelection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generates a large bulk export ({@see Export}) in the queue worker.
 *
 * The export is built for its owner as they are *now*: a user who lost
 * `export-data` (or was deactivated) gets no file, one who lost `view-salaries`
 * gets no salary columns, and a manager only gets their department.
 */
class GenerateExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A failed export is not retried; the user can start it again. */
    public int $tries = 1;

    /** Seconds; the worker's --timeout and the queue's retry_after must be larger (docker-compose.yml). */
    public int $timeout = 1800;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue(config('data_tools.exports.queue', 'default'));
    }

    public function handle(EmployeeExportService $service): void
    {
        $export = Export::with('user')->find($this->exportId);

        // Deleted by its owner in the meantime, or already handled.
        if ($export === null || $export->status !== Export::STATUS_PENDING) {
            return;
        }

        $user = $export->user;
        if ($user === null || ! Ability::check($user, Ability::EXPORT_DATA)) {
            $this->markFailed($export, __('You are no longer allowed to export data.'));

            return;
        }

        $export->update(['status' => Export::STATUS_PROCESSING, 'started_at' => now()]);

        set_time_limit($this->timeout);
        ini_set('memory_limit', '2G'); // DomPDF keeps the whole document in memory

        $visibility = EmployeeVisibility::for($user);
        $query = ExportSelection::fromArray($export->params['selection'] ?? [])->query($visibility);
        $columns = $service->columns($visibility->canSeeSalaries(), $export->params['columns'] ?? [], $export->format);

        $disk = Export::disk();
        $directory = config('data_tools.exports.directory', 'exports');
        $disk->makeDirectory($directory);
        $path = $directory.'/'.$export->id.'_'.Str::random(16).'.'.$export->format->extension();

        try {
            $rows = $service->writeFile($export->format, $query, $columns, $disk->path($path), (int) config('data_tools.exports.queued_pdf_limit', 5000));
        } catch (Throwable $e) {
            $disk->delete($path);
            report($e);
            $this->markFailed($export, __('The export could not be generated. Please try again or export fewer employees.'));

            return;
        }

        if ($rows === 0) {
            $disk->delete($path);
            $this->markFailed($export, __('No employees found for export.'));

            return;
        }

        // The owner may have deleted the entry while the file was written.
        if (! Export::whereKey($export->id)->exists()) {
            $disk->delete($path);

            return;
        }

        $export->update([
            'status' => Export::STATUS_COMPLETED,
            'file_path' => $path,
            'file_name' => $service->filename($export->format, $rows),
            'file_size' => $disk->size($path),
            'row_count' => $rows,
            'error' => null,
            'finished_at' => now(),
        ]);
    }

    /**
     * The worker gave up (timeout, crash, too many attempts).
     */
    public function failed(?Throwable $exception): void
    {
        Export::whereKey($this->exportId)
            ->whereIn('status', [Export::STATUS_PENDING, Export::STATUS_PROCESSING])
            ->update([
                'status' => Export::STATUS_FAILED,
                'error' => __('The export could not be generated (it took too long or the worker stopped). Please try again or export fewer employees.'),
                'finished_at' => now(),
            ]);
    }

    private function markFailed(Export $export, string $message): void
    {
        $export->update([
            'status' => Export::STATUS_FAILED,
            'error' => $message,
            'finished_at' => now(),
        ]);
    }
}
