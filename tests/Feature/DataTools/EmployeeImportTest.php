<?php

namespace Tests\Feature\DataTools;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Services\EmployeeImportService;
use App\Services\EmployeeNumberAllocator;
use App\Support\EmployeeDataCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BuildsDirectory;
use Tests\TestCase;

class EmployeeImportTest extends TestCase
{
    use ActsAsAdmin;
    use BuildsDirectory;
    use RefreshDatabase;

    private const HEADER = 'first_name,last_name,birth_date,gender,hire_date,department,title,salary';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDirectory();
    }

    private function csv(array $lines, string $header = self::HEADER): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('new-hires.csv', implode("\n", [$header, ...$lines])."\n");
    }

    private function line(array $overrides = []): string
    {
        // array_merge keeps the column order of the defaults.
        $values = array_merge([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'birth_date' => '1990-05-17', 'gender' => 'F',
            'hire_date' => '2026-01-15', 'department' => $this->alpha->dept_no, 'title' => 'Engineer', 'salary' => '60000',
        ], $overrides);

        return implode(',', $values);
    }

    /**
     * Upload and return [preview response, token].
     */
    private function upload(UploadedFile $file): array
    {
        $response = $this->post(route('employees.import.upload'), ['file' => $file]);
        $response->assertRedirect();
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));

        return [$this->get(route('employees.import.preview', $token)), $token];
    }

    private function staged(string $token): array
    {
        return app(EmployeeImportService::class)->staged($this->user, $token)['analysis'];
    }

    // ------------------------------------------------------------ access

    public function test_import_requires_import_data(): void
    {
        foreach ([Role::Admin, Role::Hr] as $role) {
            $this->signInAs($role);
            $this->get(route('employees.import.create'))->assertOk()->assertSee('Import employees');
        }

        foreach ([Role::Manager, Role::Viewer] as $role) {
            $this->signInAs($role, $role === Role::Manager ? $this->alpha : null);
            $this->get(route('employees.import.create'))->assertForbidden();
            $this->get(route('employees.import.template', 'csv'))->assertForbidden();
            $this->post(route('employees.import.upload'), ['file' => $this->csv([$this->line()])])->assertForbidden();
        }

        $this->assertSame(7, Employee::count());
    }

    public function test_the_navbar_links_to_the_import_only_with_import_data(): void
    {
        $this->get(route('index'))->assertSee(route('employees.import.create'))->assertSee(route('exports.index'));

        $this->signInAs(Role::Manager, $this->alpha);
        $this->get(route('index'))->assertDontSee(route('employees.import.create'))->assertSee(route('exports.index'));

        $this->signInAs(Role::Viewer);
        $this->get(route('index'))->assertDontSee('Data tools');
    }

    public function test_the_import_page_lists_departments_and_titles(): void
    {
        $this->get(route('employees.import.create'))
            ->assertSee($this->alpha->dept_no)
            ->assertSee('Senior Staff')
            ->assertSee(route('employees.import.template', 'xlsx'));
    }

    // --------------------------------------------------------- templates

    public function test_csv_template(): void
    {
        $response = $this->get(route('employees.import.template', 'csv'))->assertOk();
        $lines = explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent())));

        $this->assertSame(self::HEADER, $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('attachment; filename=employees_import_template.csv', $response->headers->get('Content-Disposition'));
    }

    public function test_the_xlsx_template_is_a_valid_import_file(): void
    {
        $response = $this->get(route('employees.import.template', 'xlsx'))->assertOk();
        $file = new UploadedFile($response->baseResponse->getFile()->getPathname(), 'template.xlsx', null, null, true);

        [$preview, $token] = $this->upload($file);

        $preview->assertOk()->assertSee('Import 1 employees');
        $this->assertSame(0, $this->staged($token)['invalid']);
    }

    // ---------------------------------------------------------- happy path

    public function test_a_valid_file_is_previewed_then_imported_with_a_full_current_history(): void
    {
        Cache::put(EmployeeDataCache::DASHBOARD_STATS, 'stale');

        [$preview, $token] = $this->upload($this->csv([
            $this->line(),
            $this->line(['first_name' => 'John', 'last_name' => 'Roe', 'gender' => 'M', 'department' => $this->omega->dept_no, 'title' => 'Manager', 'salary' => '75000']),
        ]));

        $preview->assertOk()->assertSee('new-hires.csv')->assertSee('Import 2 employees')->assertSee('Jane');
        $this->assertSame(7, Employee::count(), 'nothing is written before the confirmation');

        $this->post(route('employees.import.commit', $token))
            ->assertRedirect(route('employees.import.create'))
            ->assertSessionHas('status', '2 employees have been imported (employee numbers 10001 - 10002).');

        $jane = Employee::findOrFail(10001);
        $this->assertSame(['Jane', 'Doe', 'F', '1990-05-17', '2026-01-15'], [$jane->first_name, $jane->last_name, $jane->gender, $jane->birth_date, $jane->hire_date]);
        $this->assertDatabaseHas('dept_emp', ['emp_no' => 10001, 'dept_no' => $this->alpha->dept_no, 'from_date' => '2026-01-15', 'to_date' => '9999-01-01']);
        $this->assertDatabaseHas('titles', ['emp_no' => 10001, 'title' => 'Engineer', 'from_date' => '2026-01-15', 'to_date' => '9999-01-01']);
        $this->assertDatabaseHas('salaries', ['emp_no' => 10001, 'salary' => 60000, 'from_date' => '2026-01-15', 'to_date' => '9999-01-01']);
        $this->assertDatabaseHas('dept_emp', ['emp_no' => 10002, 'dept_no' => $this->omega->dept_no]);
        $this->assertDatabaseHas('salaries', ['emp_no' => 10002, 'salary' => 75000]);

        $this->assertNull(Cache::get(EmployeeDataCache::DASHBOARD_STATS), 'cached figures are flushed');
        $this->assertNull(app(EmployeeImportService::class)->staged($this->user, $token), 'the staged file is gone');

        // The new employees appear in the directory as current employees.
        $this->get(route('employees.show', 10001))->assertOk()->assertSee('Jane');
    }

    public function test_the_import_is_audited(): void
    {
        [, $token] = $this->upload($this->csv([$this->line(), $this->line(['first_name' => 'Joan'])]));
        $this->post(route('employees.import.commit', $token));

        $entry = ActivityLog::where('action', 'data.imported')->sole();
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame(ActivityLog::SUBJECT_IMPORT, $entry->subject_type);
        $this->assertEquals([
            'count' => 2, 'first_emp_no' => 10001, 'last_emp_no' => 10002, 'file' => 'new-hires.csv',
            'departments' => [$this->alpha->dept_no],
        ], $entry->changes);
    }

    public function test_employee_numbers_continue_after_the_highest_one(): void
    {
        Employee::factory()->create(['emp_no' => 499999]);

        [, $token] = $this->upload($this->csv([$this->line()]));
        $this->post(route('employees.import.commit', $token));

        $this->assertDatabaseHas('employees', ['emp_no' => 500000, 'first_name' => 'Jane']);
    }

    public function test_allocation_reads_the_highest_number_with_a_locking_read(): void
    {
        DB::enableQueryLog();
        DB::transaction(fn () => app(EmployeeNumberAllocator::class)->next());

        $sql = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'employees'));
        $this->assertStringContainsString('for update', strtolower($sql));
        $this->assertStringContainsString('order by `emp_no` desc', strtolower($sql));
    }

    public function test_semicolon_files_with_bom_header_aliases_and_loose_case(): void
    {
        $file = UploadedFile::fake()->createWithContent('excel.csv', "\xEF\xBB\xBF".implode("\n", [
            'First Name;Last Name;Birth Date;Gender;Hire Date;dept_no;Job Title;Salary',
            'Ann;Lee;1991-02-03;f;2025-03-01;'.strtoupper($this->mid->dept_no).';senior staff;55000,00',
        ]));

        [$preview, $token] = $this->upload($file);
        $preview->assertOk();
        $analysis = $this->staged($token);

        $this->assertSame([], $analysis['file_errors']);
        $this->assertSame([], $analysis['rows'][0]['errors']);
        $this->assertSame(['F', $this->mid->dept_no, 'Senior Staff', 55000], [
            $analysis['rows'][0]['values']['gender'], $analysis['rows'][0]['values']['department'],
            $analysis['rows'][0]['values']['title'], $analysis['rows'][0]['values']['salary'],
        ]);
    }

    public function test_xlsx_files_with_date_cells_and_numbers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(explode(',', self::HEADER)));
        $dates = (new Style)->withFormat('yyyy-mm-dd');
        $writer->addRow(new Row([
            Cell::fromValue('Kim'), Cell::fromValue('Park'), Cell::fromValue(new \DateTimeImmutable('1985-12-01'), $dates),
            Cell::fromValue('M'), Cell::fromValue(new \DateTimeImmutable('2024-06-30'), $dates),
            Cell::fromValue($this->omega->dept_no), Cell::fromValue('Staff'), Cell::fromValue(48000),
        ]));
        $writer->close();

        [, $token] = $this->upload(new UploadedFile($path, 'hires.xlsx', null, null, true));
        $row = $this->staged($token)['rows'][0];

        $this->assertSame([], $row['errors']);
        $this->assertSame(['1985-12-01', '2024-06-30', 48000], [$row['values']['birth_date'], $row['values']['hire_date'], $row['values']['salary']]);
        $this->assertSame(2, $row['line']);
    }

    // ------------------------------------------------------------ validation

    public function test_per_row_errors_are_shown_and_block_the_import(): void
    {
        $long = str_repeat('x', 15);
        [$preview, $token] = $this->upload($this->csv([
            $this->line(),                                                     // 2 valid
            $this->line(['first_name' => '', 'last_name' => $long.'yy']),      // 3
            $this->line(['birth_date' => '1990-02-30', 'hire_date' => 'soon']), // 4
            $this->line(['gender' => 'X']),                                    // 5
            $this->line(['department' => 'd999', 'title' => 'Wizard']),        // 6
            $this->line(['first_name' => 'Zero', 'salary' => '0']),           // 7
            $this->line(['first_name' => 'Neg', 'salary' => '-5']),           // 8
            $this->line(['first_name' => 'Frac', 'salary' => '100.5']),       // 9
            $this->line(['first_name' => 'Kid', 'birth_date' => '2020-01-01']), // 10
            $this->line(['first_name' => 'Future', 'hire_date' => now()->addYears(2)->format('Y-m-d')]), // 11
            $this->line(),                                                     // 12 duplicate of row 2
            $this->line(['first_name' => 'Anna', 'last_name' => 'Smith', 'birth_date' => Employee::find(1)->birth_date]), // 13 existing
            $this->line(['first_name' => $long]),                              // 14
        ]));

        $preview->assertOk()
            ->assertSee('First name is required.')
            ->assertSee('Last name may have at most 16 characters.')
            ->assertSee('Birth date must be a valid date (YYYY-MM-DD).')
            ->assertSee('Hire date must be a valid date (YYYY-MM-DD).')
            ->assertSee('Gender must be M or F.')
            ->assertSee('Unknown department code &quot;d999&quot;.', false)
            ->assertSee('Unknown job title &quot;Wizard&quot;.', false)
            ->assertSee('Salary must be a whole number greater than 0.')
            ->assertSee('The employee must be at least 14 years old on the hire date.')
            ->assertSee('Hire date may be at most one year in the future.')
            ->assertSee('Duplicate of row 2 of the file.')
            ->assertSee('An employee with this name and birth date already exists (#1).')
            ->assertSee('First name may have at most 14 characters.')
            ->assertSee('Nothing can be imported until every row is valid.')
            ->assertDontSee('Import 13 employees');

        $analysis = $this->staged($token);
        $this->assertSame(1, $analysis['valid']);
        $this->assertSame(12, $analysis['invalid']);
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14], array_column(array_filter($analysis['rows'], fn ($r) => $r['errors'] !== []), 'line'));

        $this->post(route('employees.import.commit', $token))
            ->assertRedirect(route('employees.import.preview', $token))
            ->assertSessionHas('error', 'The file has errors. Correct them and upload it again.');
        $this->assertSame(7, Employee::count());
    }

    public function test_missing_columns_are_a_file_error(): void
    {
        [$preview, $token] = $this->upload($this->csv(['Jane,Doe'], 'first_name,last_name'));

        $preview->assertOk()->assertSee('Missing columns: birth_date, gender, hire_date, department, title, salary.');
        $this->assertSame([], $this->staged($token)['rows']);
    }

    public function test_empty_files(): void
    {
        [$preview] = $this->upload(UploadedFile::fake()->createWithContent('empty.csv', "\n\n"));
        $preview->assertSee('The file is empty.');

        [$preview] = $this->upload($this->csv([]));
        $preview->assertSee('The file contains no employees.');
    }

    public function test_blank_lines_are_skipped_and_line_numbers_kept(): void
    {
        [, $token] = $this->upload($this->csv([$this->line(), ',,,,,,,', '', $this->line(['first_name' => 'Joe', 'gender' => 'Q'])]));

        $rows = $this->staged($token)['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame(5, $rows[1]['line']);
    }

    public function test_the_row_limit(): void
    {
        config(['data_tools.imports.max_rows' => 3]);

        [$preview, $token] = $this->upload($this->csv(array_map(fn ($i) => $this->line(['first_name' => 'P'.$i]), range(1, 4))));

        $preview->assertSee('The file has more than 3 rows. Split it into several files.');
        $this->assertSame([], $this->staged($token)['rows']);

        [, $token] = $this->upload($this->csv(array_map(fn ($i) => $this->line(['first_name' => 'P'.$i]), range(1, 3))));
        $this->assertSame(3, $this->staged($token)['valid']);
    }

    public function test_blank_rows_count_towards_the_row_limit(): void
    {
        config(['data_tools.imports.max_rows' => 3]);

        [$preview, $token] = $this->upload($this->csv([$this->line(), ...array_fill(0, 10, ',,,,,,,')]));

        $preview->assertSee('The file has more than 3 rows. Split it into several files.');
        $this->assertSame([], $this->staged($token)['rows']);
    }

    public function test_long_cells_are_cut_off_and_rejected_by_the_validation(): void
    {
        [, $token] = $this->upload($this->csv([$this->line(['first_name' => str_repeat('x', 5000)])]));
        $row = $this->staged($token)['rows'][0];

        $this->assertSame(200, mb_strlen($row['values']['first_name']));
        $this->assertSame(['First name may have at most 14 characters.'], $row['errors']);
    }

    public function test_extra_columns_are_ignored(): void
    {
        $extra = implode(',', array_map(fn ($i) => 'x'.$i, range(1, 100)));

        [, $token] = $this->upload($this->csv([$this->line().','.$extra], self::HEADER.','.$extra));

        $this->assertSame(1, $this->staged($token)['valid']);
    }

    public function test_the_unpacked_size_of_an_xlsx_file_is_limited(): void
    {
        config(['data_tools.imports.max_unpacked_kb' => 1]);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat(' ', 5000));
        $zip->close();

        [$preview, $token] = $this->upload(new UploadedFile($path, 'bomb.xlsx', null, null, true));

        $preview->assertSee('The file is too large when unpacked. Split it into several files.');
        $this->assertSame([], $this->staged($token)['rows']);
    }

    // -------------------------------------------------------------- encoding

    public function test_a_windows_1250_csv_from_a_polish_excel(): void
    {
        $line = implode(';', ['Łukasz', 'Żółć', '1991-02-03', 'M', '2025-03-01', $this->mid->dept_no, 'Staff', '55000']);
        $content = iconv('UTF-8', 'WINDOWS-1250', str_replace(',', ';', self::HEADER)."\r\n".$line."\r\n");
        $this->assertFalse(mb_check_encoding($content, 'UTF-8'), 'the test file really is not UTF-8');

        [, $token] = $this->upload(UploadedFile::fake()->createWithContent('polish.csv', $content));
        $row = $this->staged($token)['rows'][0];

        $this->assertSame([], $row['errors']);
        $this->assertSame(['Łukasz', 'Żółć'], [$row['values']['first_name'], $row['values']['last_name']]);

        $this->post(route('employees.import.commit', $token))->assertSessionHas('status');
        $this->assertDatabaseHas('employees', ['first_name' => 'Łukasz', 'last_name' => 'Żółć']);
    }

    public function test_a_utf_16_csv_with_a_byte_order_mark(): void
    {
        $content = "\xFF\xFE".iconv('UTF-8', 'UTF-16LE', self::HEADER."\n".$this->line(['first_name' => 'Łucja'])."\n");

        [, $token] = $this->upload(UploadedFile::fake()->createWithContent('unicode.csv', $content));

        $this->assertSame('Łucja', $this->staged($token)['rows'][0]['values']['first_name']);
    }

    public function test_utf_8_files_are_left_alone(): void
    {
        [, $token] = $this->upload($this->csv([$this->line(['first_name' => 'Łucja'])]));

        $this->assertSame('Łucja', $this->staged($token)['rows'][0]['values']['first_name']);
    }

    public function test_the_file_size_and_type_limits(): void
    {
        config(['data_tools.imports.max_file_kb' => 1]);

        $this->post(route('employees.import.upload'), ['file' => UploadedFile::fake()->create('big.csv', 2, 'text/csv')])
            ->assertSessionHasErrors('file');
        $this->post(route('employees.import.upload'), ['file' => UploadedFile::fake()->create('doc.pdf', 1, 'application/pdf')])
            ->assertSessionHasErrors('file');
        $this->post(route('employees.import.upload'), [])->assertSessionHasErrors('file');
    }

    public function test_unreadable_files_are_reported(): void
    {
        [$preview] = $this->upload(UploadedFile::fake()->createWithContent('broken.xlsx', 'not a zip'));

        $preview->assertSee('The file could not be read.');
    }

    // ----------------------------------------------------- commit safety

    public function test_rows_are_validated_again_when_confirming(): void
    {
        [, $token] = $this->upload($this->csv([$this->line()]));

        // Someone else creates the same employee in the meantime.
        Employee::factory()->create(['emp_no' => 900, 'first_name' => 'Jane', 'last_name' => 'Doe', 'birth_date' => '1990-05-17']);

        $this->post(route('employees.import.commit', $token))
            ->assertRedirect(route('employees.import.preview', $token))
            ->assertSessionHas('error');

        $this->assertSame(1, Employee::where('first_name', 'Jane')->count());
        $this->assertSame(0, ActivityLog::where('action', 'data.imported')->count());
    }

    public function test_a_database_error_is_reported_rolls_everything_back_and_keeps_the_upload(): void
    {
        [, $token] = $this->upload($this->csv([$this->line(), $this->line(['first_name' => 'Joan'])]));

        // The rows pass the validation, the dept_emp insert then fails on its foreign key
        // after the employees were inserted.
        $this->app->instance(EmployeeImportService::class, new class extends EmployeeImportService
        {
            public function validate(array $records): array
            {
                return array_map(function ($row) {
                    $row['values']['department'] = 'zzzz';

                    return $row;
                }, parent::validate($records));
            }
        });

        $this->post(route('employees.import.commit', $token))
            ->assertRedirect(route('employees.import.preview', $token))
            ->assertSessionHas('error', 'The employees could not be saved, nothing was imported. Please try again.');

        $this->assertSame(7, Employee::count(), 'the employees insert is rolled back');
        $this->assertSame(0, ActivityLog::where('action', 'data.imported')->count());
        $this->assertNotNull(app(EmployeeImportService::class)->staged($this->user, $token), 'the upload stays staged');
    }

    public function test_a_new_upload_replaces_the_staged_one(): void
    {
        [, $first] = $this->upload($this->csv([$this->line()]));
        [, $second] = $this->upload($this->csv([$this->line(['first_name' => 'Joan'])]));

        $this->get(route('employees.import.preview', $first))->assertNotFound();
        $this->get(route('employees.import.preview', $second))->assertOk();

        $this->signInAs(Role::Hr);
        [, $other] = $this->upload($this->csv([$this->line()]));
        $this->assertNotNull(app(EmployeeImportService::class)->staged($this->user, $other));
        $this->assertNull(app(EmployeeImportService::class)->staged($this->user, $second), 'not this user\'s');
    }

    public function test_other_users_and_expired_tokens_are_not_found(): void
    {
        [, $token] = $this->upload($this->csv([$this->line()]));

        $this->signInAs(Role::Hr);
        $this->get(route('employees.import.preview', $token))->assertNotFound();
        $this->post(route('employees.import.commit', $token))->assertNotFound();
        $this->delete(route('employees.import.cancel', $token))->assertNotFound();

        $this->get(route('employees.import.preview', '7d3c1f9e-3d33-4a6e-9d3a-1c1f2b3a4d5e'))->assertNotFound();
        $this->assertSame(7, Employee::count());
    }

    public function test_cancel_forgets_the_upload(): void
    {
        [, $token] = $this->upload($this->csv([$this->line()]));

        $this->delete(route('employees.import.cancel', $token))->assertRedirect(route('employees.import.create'));

        $this->get(route('employees.import.preview', $token))->assertNotFound();
        $this->assertSame(7, Employee::count());
    }

    public function test_a_token_can_be_confirmed_only_once(): void
    {
        [, $token] = $this->upload($this->csv([$this->line()]));

        $this->post(route('employees.import.commit', $token))->assertRedirect(route('employees.import.create'));
        $this->post(route('employees.import.commit', $token))->assertNotFound();

        $this->assertSame(1, Employee::where('first_name', 'Jane')->count());
    }
}
