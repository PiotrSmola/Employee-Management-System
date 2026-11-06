<?php

namespace App\Jobs;

use App\Enums\ExportFormat;
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

    /** Seconds; the worker's --timeout and the queue's retry_after must be larger (docker-compose.yml). */
    public const TIMEOUT = 1800;

    /** A failed export is not retried; the user can start it again. */
    public int $tries = 1;

    public int $timeout = self::TIMEOUT;

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
        $memoryLimit = (string) ini_get('memory_limit');
        if ($export->format === ExportFormat::Pdf) {
            ini_set('memory_limit', '2G'); // DomPDF keeps the whole document in memory; CSV / XLSX are streamed
        }

        $visibility = EmployeeVisibility::for($user);
        $query = ExportSelection::fromArray($export->params['selection'] ?? [])->query($visibility);
        $columns = $service->columns($visibility->canSeeSalaries(), $export->params['columns'] ?? [], $export->format);

        $disk = Export::disk();
        $directory = config('data_tools.exports.directory', 'exports');
        $disk->makeDirectory($directory);
        $path = $directory.'/'.$export->id.'_'.Str::random(16).'.'.$export->format->extension();

        // The writers need a local path: on another disk the file is written to a temporary file and copied.
        $isLocalDisk = config('filesystems.disks.'.Export::diskName().'.driver') === 'local';
        $target = $isLocalDisk ? $disk->path($path) : (string) tempnam(sys_get_temp_dir(), 'export');

        try {
            $rows = $service->writeFile($export->format, $query, $columns, $target, (int) config('data_tools.exports.queued_pdf_limit', 5000));

            if (! $isLocalDisk && $rows > 0) {
                $stream = fopen($target, 'r');
                $disk->writeStream($path, $stream);
                is_resource($stream) && fclose($stream);
            }
        } catch (Throwable $e) {
            $disk->delete($path);
            report($e);
            $this->markFailed($export, __('The export could not be generated. Please try again or export fewer employees.'));

            return;
        } finally {
            // The worker process lives on: do not keep the PDF's memory limit for the next jobs.
            // Best effort: PHP refuses a limit below the memory still in use, which must not fail
            // a finished export (the worker restarts itself above its --memory limit anyway).
            gc_collect_cycles();
            @ini_set('memory_limit', $memoryLimit);

            if (! $isLocalDisk) {
                @unlink($target);
            }
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
