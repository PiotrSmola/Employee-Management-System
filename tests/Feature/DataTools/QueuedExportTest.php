<?php

namespace Tests\Feature\DataTools;

use App\Enums\ExportFormat;
use App\Enums\Role;
use App\Jobs\GenerateExport;
use App\Models\ActivityLog;
use App\Models\Export;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BuildsDirectory;
use Tests\TestCase;

class QueuedExportTest extends TestCase
{
    use ActsAsAdmin;
    use BuildsDirectory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDirectory();
        Storage::fake('local');
        config(['data_tools.exports.queue_threshold' => 3]);
    }

    private function requestExport(array $payload = []): Export
    {
        $this->post(route('employees.export'), $payload + ['format' => 'csv', 'selectAll' => '1'])
            ->assertRedirect(route('exports.index'))
            ->assertSessionHas('status');

        return Export::latest('id')->firstOrFail();
    }

    private function csvRows(Export $export): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', Storage::disk('local')->get($export->file_path));
        $handle = fopen('php://memory', 'w+');
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    // ------------------------------------------------------------- queueing

    public function test_exports_above_the_threshold_are_queued(): void
    {
        Queue::fake();

        $export = $this->requestExport(['gender' => 'M', 'columns' => 'emp_no,last_name']);

        Queue::assertPushed(GenerateExport::class, fn ($job) => $job->exportId === $export->id);
        $this->assertSame(Export::STATUS_PENDING, $export->status);
        $this->assertSame($this->user->id, $export->user_id);
        $this->assertSame(ExportFormat::Csv, $export->format);
        $this->assertSame(['emp_no', 'last_name'], $export->params['columns']);
        $this->assertSame('M', $export->params['selection']['filters']['gender']);
        $this->assertTrue($export->params['selection']['selectAll']);
    }

    public function test_exports_up_to_the_threshold_are_downloaded_directly(): void
    {
        Queue::fake();

        $this->post(route('employees.export'), ['format' => 'csv', 'selectAll' => '0', 'selectedEmployees' => '1,2,3'])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        Queue::assertNothingPushed();
        $this->assertSame(0, Export::count());
    }

    public function test_queueing_is_audited(): void
    {
        Queue::fake();

        $export = $this->requestExport(['format' => 'xlsx', 'excludedEmployees' => '2']);

        $entry = ActivityLog::where('action', 'data.exported')->sole();
        $this->assertSame(ActivityLog::SUBJECT_EXPORT, $entry->subject_type);
        $this->assertSame((string) $export->id, $entry->subject_id);
        $this->assertTrue($entry->changes['queued']);
        $this->assertSame('xlsx', $entry->changes['format']);
        $this->assertSame(1, $entry->changes['ids']);
    }

    // ------------------------------------------------------------ the job

    public function test_the_job_generates_the_file_and_completes_the_export(): void
    {
        $export = $this->requestExport(['excludedEmployees' => '5', 'columns' => 'emp_no,full_name,salary']);

        $export->refresh();
        $this->assertSame(Export::STATUS_COMPLETED, $export->status);
        $this->assertSame(6, $export->row_count);
        $this->assertNotNull($export->started_at);
        $this->assertNotNull($export->finished_at);
        $this->assertNull($export->error);
        $this->assertStringStartsWith('exports/'.$export->id.'_', $export->file_path);
        Storage::disk('local')->assertExists($export->file_path);
        $this->assertSame(Storage::disk('local')->size($export->file_path), $export->file_size);
        $this->assertMatchesRegularExpression('/^employees_export_selected_6_[\d_-]+\.csv$/', $export->file_name);

        $rows = $this->csvRows($export);
        $this->assertSame(['Employee ID', 'Full Name', 'Current Salary'], $rows[0]);
        $this->assertSame(['1', 'Anna Smith', '50000.00'], $rows[1]);
        $this->assertSame([1, 2, 3, 4, 6, 7], array_map('intval', array_column(array_slice($rows, 1), 0)));
    }

    public function test_a_queued_xlsx_export(): void
    {
        $export = $this->requestExport(['format' => 'xlsx', 'sortBy' => 'name']);
        $export->refresh();

        $this->assertSame(Export::STATUS_COMPLETED, $export->status);
        $reader = new Reader;
        $reader->open(Storage::disk('local')->path($export->file_path));
        $ids = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $i => $row) {
                if ($i > 1) {
                    $ids[] = $row->toArray()[0];
                }
            }
        }
        $reader->close();

        $this->assertSame([1, 6, 2, 3, 4, 5, 7], $ids);
    }

    public function test_the_job_applies_the_owners_current_scope(): void
    {
        Queue::fake();
        config(['data_tools.exports.queue_threshold' => 2]);
        $manager = $this->signInAs(Role::Manager, $this->alpha);
        $export = $this->requestExport(['department' => $this->omega->dept_no, 'columns' => 'emp_no,salary']);

        (new GenerateExport($export->id))->handle(app(\App\Services\EmployeeExportService::class));

        $export->refresh();
        $rows = $this->csvRows($export);
        $this->assertSame(['Employee ID', 'Current Salary'], $rows[0]);
        $this->assertSame([1, 4, 6], array_map('intval', array_column(array_slice($rows, 1), 0)), 'Alpha only, the URL department is ignored');
        $this->assertSame($manager->id, $export->user_id);
    }

    public function test_the_job_fails_when_the_owner_lost_export_data(): void
    {
        Queue::fake();
        $export = $this->requestExport();
        $this->user->update(['role' => Role::Viewer]);

        (new GenerateExport($export->id))->handle(app(\App\Services\EmployeeExportService::class));

        $export->refresh();
        $this->assertSame(Export::STATUS_FAILED, $export->status);
        $this->assertSame('You are no longer allowed to export data.', $export->error);
        $this->assertNull($export->file_path);
        $this->assertSame([], Storage::disk('local')->allFiles('exports'));
    }

    public function test_the_job_fails_for_a_deactivated_owner(): void
    {
        Queue::fake();
        $export = $this->requestExport();
        $this->user->update(['is_active' => false]);

        (new GenerateExport($export->id))->handle(app(\App\Services\EmployeeExportService::class));

        $this->assertSame(Export::STATUS_FAILED, $export->refresh()->status);
    }

    public function test_a_selection_that_became_empty_fails_with_a_message(): void
    {
        Queue::fake();
        $export = $this->requestExport();
        \Illuminate\Support\Facades\DB::table('employees')->delete();

        (new GenerateExport($export->id))->handle(app(\App\Services\EmployeeExportService::class));

        $export->refresh();
        $this->assertSame(Export::STATUS_FAILED, $export->status);
        $this->assertSame('No employees found for export.', $export->error);
    }

    public function test_writer_errors_are_recorded_without_details_and_leave_no_file(): void
    {
        Queue::fake();
        $export = $this->requestExport();

        $this->app->instance(\App\Services\EmployeeExportService::class, new class extends \App\Services\EmployeeExportService
        {
            public function writeFile(ExportFormat $format, \App\Queries\EmployeeSearchQuery $query, array $columns, string $path, int $pdfLimit): int
            {
                file_put_contents($path, 'partial');

                throw new RuntimeException('SQLSTATE secret details');
            }
        });

        (new GenerateExport($export->id))->handle(app(\App\Services\EmployeeExportService::class));

        $export->refresh();
        $this->assertSame(Export::STATUS_FAILED, $export->status);
        $this->assertStringNotContainsString('SQLSTATE', $export->error);
        $this->assertSame([], Storage::disk('local')->allFiles('exports'));
    }

    public function test_the_failed_handler_marks_an_unfinished_export_as_failed(): void
    {
        $pending = Export::factory()->for($this->user)->create();
        $done = Export::factory()->for($this->user)->completed('exports/x.csv')->create();

        (new GenerateExport($pending->id))->failed(new RuntimeException('timeout'));
        (new GenerateExport($done->id))->failed(new RuntimeException('timeout'));

        $this->assertSame(Export::STATUS_FAILED, $pending->refresh()->status);
        $this->assertNotNull($pending->error);
        $this->assertSame(Export::STATUS_COMPLETED, $done->refresh()->status, 'a finished export is left alone');
    }

    public function test_a_deleted_or_already_handled_export_is_skipped(): void
    {
        Queue::fake();
        $export = $this->requestExport();
        $export->delete();

        (new GenerateExport($export->id))->handle(app(\App\Services\EmployeeExportService::class));
        $this->assertSame([], Storage::disk('local')->allFiles('exports'));

        $done = Export::factory()->for($this->user)->completed('exports/keep.csv')->create();
        (new GenerateExport($done->id))->handle(app(\App\Services\EmployeeExportService::class));
        $this->assertSame('exports/keep.csv', $done->refresh()->file_path);
    }

    public function test_job_settings(): void
    {
        $job = new GenerateExport(1);

        $this->assertSame(1, $job->tries);
        $this->assertSame(1800, $job->timeout);
    }

    // ---------------------------------------------------------- My exports

    public function test_my_exports_lists_only_the_users_own_exports(): void
    {
        $mine = Export::factory()->for($this->user)->completed('exports/mine.csv', 1234)->create();
        $other = Export::factory()->for(User::factory()->hr())->completed('exports/other.csv', 999)->create();

        $this->get(route('exports.index'))
            ->assertOk()
            ->assertSee('My exports')
            ->assertSee(route('exports.download', $mine->id))
            ->assertSee('1,234')
            ->assertDontSee(route('exports.download', $other->id));
    }

    public function test_my_exports_refreshes_while_an_export_is_unfinished(): void
    {
        $this->get(route('exports.index'))->assertDontSee('exportsAutoRefresh');

        Export::factory()->for($this->user)->create();
        $this->get(route('exports.index'))->assertSee('exportsAutoRefresh')->assertSee('Queued');
    }

    public function test_download_of_an_own_completed_export(): void
    {
        Storage::disk('local')->put('exports/7_abc.csv', "a,b\n");
        $export = Export::factory()->for($this->user)->completed('exports/7_abc.csv')->create(['file_name' => 'employees_export_selected_10.csv']);

        $response = $this->get(route('exports.download', $export->id));

        $response->assertOk();
        $this->assertStringContainsString('employees_export_selected_10.csv', $response->headers->get('Content-Disposition'));
        $this->assertSame("a,b\n", $response->streamedContent());
    }

    public function test_unfinished_failed_or_missing_files_cannot_be_downloaded(): void
    {
        $pending = Export::factory()->for($this->user)->create();
        $missing = Export::factory()->for($this->user)->completed('exports/gone.csv')->create();

        $this->get(route('exports.download', $pending->id))->assertNotFound();
        $this->get(route('exports.download', $missing->id))->assertNotFound();
    }

    public function test_other_users_exports_are_not_found(): void
    {
        Storage::disk('local')->put('exports/other.csv', 'secret');
        $other = Export::factory()->for(User::factory()->hr())->completed('exports/other.csv')->create();

        $this->get(route('exports.download', $other->id))->assertNotFound();
        $this->delete(route('exports.destroy', $other->id))->assertNotFound();

        $this->assertModelExists($other);
        Storage::disk('local')->assertExists('exports/other.csv');
    }

    public function test_deleting_an_export_removes_its_file(): void
    {
        Storage::disk('local')->put('exports/1_x.csv', 'x');
        $export = Export::factory()->for($this->user)->completed('exports/1_x.csv')->create();

        $this->delete(route('exports.destroy', $export->id))
            ->assertRedirect(route('exports.index'))
            ->assertSessionHas('status');

        $this->assertModelMissing($export);
        Storage::disk('local')->assertMissing('exports/1_x.csv');
    }

    public function test_my_exports_requires_export_data(): void
    {
        $export = Export::factory()->for($this->user)->create();
        $this->signInAs(Role::Viewer);

        $this->get(route('exports.index'))->assertForbidden();
        $this->get(route('exports.download', $export->id))->assertForbidden();
        $this->delete(route('exports.destroy', $export->id))->assertForbidden();
    }

    public function test_managers_and_hr_can_open_my_exports(): void
    {
        $this->signInAs(Role::Manager, $this->alpha);
        $this->get(route('exports.index'))->assertOk();

        $this->signInAs(Role::Hr);
        $this->get(route('exports.index'))->assertOk();
    }

    // -------------------------------------------------------------- cleanup

    public function test_prune_deletes_old_exports_files_and_orphans(): void
    {
        $disk = Storage::disk('local');
        $disk->put('exports/old.csv', 'x');
        $disk->put('exports/new.csv', 'x');
        $disk->put('exports/orphan.csv', 'x');
        touch($disk->path('exports/orphan.csv'), now()->subDays(10)->getTimestamp());
        $disk->put('exports/fresh-orphan.csv', 'x');

        $old = Export::factory()->for($this->user)->completed('exports/old.csv')->create(['created_at' => now()->subDays(8)]);
        $oldFailed = Export::factory()->for($this->user)->create(['status' => Export::STATUS_FAILED, 'created_at' => now()->subDays(30)]);
        $new = Export::factory()->for($this->user)->completed('exports/new.csv')->create(['created_at' => now()->subDays(6)]);

        $this->artisan('exports:prune')
            ->expectsOutputToContain('Deleted 2 export(s) and 1 orphaned file(s) older than 7 day(s).')
            ->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelMissing($oldFailed);
        $this->assertModelExists($new);
        $disk->assertMissing('exports/old.csv');
        $disk->assertMissing('exports/orphan.csv');
        $disk->assertExists('exports/new.csv');
        $disk->assertExists('exports/fresh-orphan.csv'); // a recent orphan may still be written
    }

    public function test_prune_accepts_a_number_of_days_of_at_least_one(): void
    {
        $export = Export::factory()->for($this->user)->create(['status' => Export::STATUS_FAILED, 'created_at' => now()->subDays(2)]);

        $this->artisan('exports:prune', ['--days' => 3])->assertSuccessful();
        $this->assertModelExists($export);

        $this->artisan('exports:prune', ['--days' => 0])->assertFailed();
        $this->artisan('exports:prune', ['--days' => 'abc'])->assertFailed();
        $this->assertModelExists($export);

        $this->artisan('exports:prune', ['--days' => 1])->assertSuccessful();
        $this->assertModelMissing($export);
    }

    public function test_prune_never_deletes_unfinished_exports_or_their_files(): void
    {
        $disk = Storage::disk('local');
        $running = Export::factory()->for($this->user)->create([
            'status' => Export::STATUS_PROCESSING, 'started_at' => now()->subMinutes(5), 'created_at' => now()->subDays(30),
        ]);
        $waiting = Export::factory()->for($this->user)->create(['created_at' => now()->subHours(25)]);
        $disk->put('exports/'.$running->id.'_abc.xlsx', 'being written');
        touch($disk->path('exports/'.$running->id.'_abc.xlsx'), now()->subDays(30)->getTimestamp());

        $this->artisan('exports:prune')->assertSuccessful();

        $this->assertModelExists($running);
        $this->assertModelExists($waiting);
        $this->assertSame(Export::STATUS_PROCESSING, $running->refresh()->status);
        $disk->assertExists('exports/'.$running->id.'_abc.xlsx');
    }

    public function test_prune_marks_stalled_exports_as_failed(): void
    {
        $overdue = Export::factory()->for($this->user)->create([
            'status' => Export::STATUS_PROCESSING, 'started_at' => now()->subSeconds(GenerateExport::TIMEOUT + 601),
        ]);
        $running = Export::factory()->for($this->user)->create([
            'status' => Export::STATUS_PROCESSING, 'started_at' => now()->subSeconds(GenerateExport::TIMEOUT + 100),
        ]);
        $forgotten = Export::factory()->for($this->user)->create(['created_at' => now()->subHours(25)]);
        $waiting = Export::factory()->for($this->user)->create(['created_at' => now()->subHours(23)]);

        $this->artisan('exports:prune')
            ->expectsOutputToContain('Marked 2 stalled export(s) as failed.')
            ->assertSuccessful();

        $this->assertSame(Export::STATUS_FAILED, $overdue->refresh()->status);
        $this->assertNotNull($overdue->error);
        $this->assertNotNull($overdue->finished_at);
        $this->assertSame(Export::STATUS_FAILED, $forgotten->refresh()->status);
        $this->assertSame(Export::STATUS_PROCESSING, $running->refresh()->status);
        $this->assertSame(Export::STATUS_PENDING, $waiting->refresh()->status);
    }

    // --------------------------------------------------------------- limits

    public function test_the_number_of_unfinished_exports_per_user_is_limited(): void
    {
        Queue::fake();
        Export::factory()->for($this->user)->count(2)->create();
        Export::factory()->for($this->user)->create(['status' => Export::STATUS_PROCESSING]);
        Export::factory()->for($this->user)->create(['status' => Export::STATUS_FAILED]);
        Export::factory()->for(User::factory()->hr())->count(3)->create();

        $this->from(route('employees.index'))
            ->post(route('employees.export'), ['format' => 'csv', 'selectAll' => '1'])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('error', 'You already have 3 exports in progress. Wait until one of them has finished (see My exports).');

        Queue::assertNothingPushed();
        $this->assertSame(4, Export::ownedBy($this->user)->count());
        $this->assertSame(0, ActivityLog::where('action', 'data.exported')->count());
    }

    public function test_finished_exports_do_not_count_against_the_limit(): void
    {
        Queue::fake();
        Export::factory()->for($this->user)->count(5)->create(['status' => Export::STATUS_FAILED]);

        $this->requestExport();

        Queue::assertPushed(GenerateExport::class, 1);
    }

    public function test_the_export_endpoint_is_rate_limited(): void
    {
        Queue::fake();
        $payload = ['format' => 'csv', 'selectAll' => '0', 'selectedEmployees' => '1'];

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('employees.export'), $payload)->assertOk();
        }

        $this->post(route('employees.export'), $payload)->assertStatus(429);
    }

    // -------------------------------------------------- files and audit

    public function test_downloads_are_audited(): void
    {
        Storage::disk('local')->put('exports/7_abc.csv', 'x');
        $export = Export::factory()->for($this->user)->completed('exports/7_abc.csv', 42)->create();

        $this->get(route('exports.download', $export->id))->assertOk();

        $entry = ActivityLog::where('action', 'data.export_downloaded')->sole();
        $this->assertSame((string) $export->id, $entry->subject_id);
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertEquals(['format' => 'csv', 'rows' => 42], $entry->changes);
    }

    public function test_exports_on_a_non_local_disk_are_written_through_a_temporary_file(): void
    {
        config(['data_tools.exports.disk' => 'remote-exports']);
        Storage::fake('remote-exports');

        $export = $this->requestExport(['columns' => 'emp_no'])->refresh();

        $this->assertSame(Export::STATUS_COMPLETED, $export->status);
        Storage::disk('remote-exports')->assertExists($export->file_path);
        $this->assertStringContainsString('Employee ID', Storage::disk('remote-exports')->get($export->file_path));
        $this->assertSame(7, $export->row_count);
    }

    public function test_a_failed_direct_xlsx_export_leaves_no_temporary_file(): void
    {
        $before = glob(sys_get_temp_dir().'/xlsx*') ?: [];
        $query = new class(new \App\Queries\EmployeeFilters) extends \App\Queries\EmployeeSearchQuery
        {
            public function rowsFor(array $ids): \Illuminate\Support\Collection
            {
                throw new RuntimeException('database gone');
            }
        };

        try {
            (new \App\Services\EmployeeExportService)->xlsx($query, true, null, [1, 2]);
            $this->fail('The error must not be swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('database gone', $e->getMessage());
        }

        $this->assertSame([], array_diff(glob(sys_get_temp_dir().'/xlsx*') ?: [], $before));
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'exports:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('0 3 * * *', $events->first()->expression);
    }
}
