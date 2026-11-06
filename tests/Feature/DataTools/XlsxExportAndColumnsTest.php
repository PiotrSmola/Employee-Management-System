<?php

namespace Tests\Feature\DataTools;

use App\Enums\ExportFormat;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Services\EmployeeExportService;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BuildsDirectory;
use Tests\TestCase;

class XlsxExportAndColumnsTest extends TestCase
{
    use ActsAsAdmin;
    use BuildsDirectory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDirectory();
    }

    /**
     * Read the first sheet of an XLSX file: list of rows (cell values), plus the cells themselves.
     *
     * @return array{0: array<int, array<int, mixed>>, 1: array<int, array<int, \OpenSpout\Common\Entity\Cell>>}
     */
    private function readXlsx(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $values = $cells = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values[] = $row->toArray();
                $cells[] = $row->cells;
            }
            break;
        }
        $reader->close();

        return [$values, $cells];
    }

    private function exportXlsx(array $payload = []): array
    {
        $response = $this->post(route('employees.export'), $payload + ['format' => 'xlsx', 'selectAll' => '1']);
        $response->assertOk();

        $this->assertSame(ExportFormat::Xlsx->mimeType(), $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/employees_export_selected_\d+_[\d_-]+\.xlsx/', $response->headers->get('Content-Disposition'));

        return $this->readXlsx($response->baseResponse->getFile()->getPathname());
    }

    // ------------------------------------------------------------------ XLSX

    public function test_xlsx_contains_a_header_and_typed_rows(): void
    {
        [$rows] = $this->exportXlsx();

        $this->assertSame([
            'Employee ID', 'First Name', 'Last Name', 'Full Name', 'Department', 'Job Title',
            'Current Salary', 'Total Earnings', 'Hire Date', 'Gender', 'Employment Status',
        ], $rows[0]);
        $this->assertCount(8, $rows);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_column(array_slice($rows, 1), 0));

        $anna = $rows[1];
        $this->assertSame(1, $anna[0]);
        $this->assertSame(['Anna', 'Smith', 'Anna Smith', 'Alpha', 'Engineer'], array_slice($anna, 1, 5));
        $this->assertSame(50000, $anna[6]);
        $this->assertGreaterThan(50000, $anna[7]);
        $this->assertInstanceOf(DateTimeInterface::class, $anna[8]);
        $this->assertSame('1990-03-01', $anna[8]->format('Y-m-d'));
        $this->assertSame(['Female', 'Active'], array_slice($anna, 9, 2));
    }

    public function test_xlsx_rows_of_former_employees_and_employees_without_history(): void
    {
        [$rows] = $this->exportXlsx();
        $byNo = collect(array_slice($rows, 1))->keyBy(0);

        $this->assertSame(['Former - Mid', 'Staff (Last)', ''], [$byNo[3][4], $byNo[3][5], $byNo[3][6] ?? '']);
        $this->assertSame('Former', $byNo[3][10]);
        $this->assertSame(['N/A', 'N/A'], [$byNo[5][4], $byNo[5][5]]);
    }

    public function test_xlsx_follows_filters_selection_and_sort_order(): void
    {
        [$rows] = $this->exportXlsx(['sortBy' => 'name', 'sortDirection' => 'desc', 'excludedEmployees' => '5']);
        $this->assertSame([7, 4, 3, 2, 6, 1], array_column(array_slice($rows, 1), 0));

        [$rows] = $this->exportXlsx(['selectAll' => '0', 'selectedEmployees' => '7,2', 'gender' => 'M']);
        $this->assertSame([2, 7], array_column(array_slice($rows, 1), 0));
    }

    public function test_xlsx_never_turns_text_into_formulas(): void
    {
        Employee::factory()->current(Department::factory()->create(['dept_name' => '=HYPERLINK("x")']))->create([
            'emp_no' => 60, 'first_name' => '=1+2', 'last_name' => '@SUM(A1)', 'hire_date' => '1999-01-01',
        ]);

        $response = $this->post(route('employees.export'), ['format' => 'xlsx', 'selectAll' => '0', 'selectedEmployees' => '60']);
        $path = $response->baseResponse->getFile()->getPathname();
        [$rows] = $this->readXlsx($path);

        $this->assertSame(['=1+2', '@SUM(A1)', '=1+2 @SUM(A1)', '=HYPERLINK("x")'], array_slice($rows[1], 1, 4));

        // The sheet holds them as text (inline strings), with no formula element at all.
        $zip = new \ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString('=1+2', $sheet);
    }

    public function test_xlsx_keeps_non_ascii_text(): void
    {
        Employee::factory()->current($this->alpha)->create(['emp_no' => 51, 'first_name' => 'Zoë', 'last_name' => 'Łukasiewicz']);

        [$rows] = $this->exportXlsx(['search' => 'Łuk']);

        $this->assertSame(['Zoë', 'Łukasiewicz'], array_slice($rows[1], 1, 2));
    }

    public function test_xlsx_of_nothing_redirects_back_with_an_error(): void
    {
        $this->from(route('employees.index'))
            ->post(route('employees.export'), ['format' => 'xlsx', 'selectAll' => '1', 'search' => 'nobody-like-this'])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('error', 'No employees found for export.');
    }

    public function test_xlsx_export_is_audited(): void
    {
        $this->exportXlsx(['gender' => 'F']);

        $entry = ActivityLog::where('action', 'data.exported')->sole();
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame(ActivityLog::SUBJECT_EXPORT, $entry->subject_type);
        $this->assertNull($entry->subject_id);
        $this->assertSame('xlsx', $entry->changes['format']);
        $this->assertSame(3, $entry->changes['rows']);
        $this->assertFalse($entry->changes['queued']);
        $this->assertSame('F', $entry->changes['filters']['gender']);
    }

    // ------------------------------------------------------- column chooser

    public function test_the_column_chooser_applies_to_csv(): void
    {
        $response = $this->post(route('employees.export'), [
            'format' => 'csv', 'selectAll' => '1', 'columns' => 'last_name,emp_no,status,bogus',
        ])->assertOk();

        $lines = explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent())));
        $this->assertSame('"Employee ID","Last Name","Employment Status"', $lines[0]);
        $this->assertSame('1,Smith,Active', $lines[1], 'fixed column order, unknown keys ignored');
    }

    public function test_the_column_chooser_applies_to_xlsx(): void
    {
        [$rows] = $this->exportXlsx(['columns' => ['full_name', 'salary']]);

        $this->assertSame(['Full Name', 'Current Salary'], $rows[0]);
        $this->assertSame(['Anna Smith', 50000], $rows[1]);
    }

    public function test_the_column_chooser_applies_to_pdf(): void
    {
        $data = [];
        View::composer('exports.employees-pdf', function ($view) use (&$data) {
            $data = $view->getData();
        });

        $this->post(route('employees.export'), ['format' => 'pdf', 'selectAll' => '1', 'columns' => 'emp_no,title'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame(['emp_no', 'title'], $data['columns']);
        $this->assertFalse($data['withSalaries']);
    }

    public function test_pdf_default_columns(): void
    {
        $data = [];
        View::composer('exports.employees-pdf', function ($view) use (&$data) {
            $data = $view->getData();
        });

        $this->post(route('employees.export'), ['format' => 'pdf', 'selectAll' => '1'])->assertOk();

        $this->assertSame(EmployeeExportService::PDF_DEFAULT_COLUMNS, $data['columns']);
    }

    public function test_no_valid_column_means_all_columns(): void
    {
        $service = new EmployeeExportService;

        $this->assertSame(array_keys(EmployeeExportService::COLUMNS), $service->columns(true, 'nope,'));
        $this->assertSame(array_keys(EmployeeExportService::COLUMNS), $service->columns(true, [['x']]));
        $this->assertSame(['emp_no', 'gender'], $service->columns(true, 'gender, emp_no'));
    }

    public function test_salary_columns_are_never_offered_or_exported_without_view_salaries(): void
    {
        $this->assertArrayHasKey('salary', EmployeeExportService::availableColumns(true));
        $this->assertArrayNotHasKey('salary', EmployeeExportService::availableColumns(false));
        $this->assertArrayNotHasKey('earnings', EmployeeExportService::availableColumns(false));

        $service = new EmployeeExportService;
        $this->assertSame(['emp_no'], $service->columns(false, 'emp_no,salary,earnings'));
        $this->assertNotContains('salary', $service->columns(false, 'salary,earnings'), 'only salary columns -> the defaults without them');
    }

    public function test_a_manager_gets_salary_columns_but_only_their_department(): void
    {
        $this->signInAs(Role::Manager, $this->alpha);

        [$rows] = $this->exportXlsx(['columns' => 'emp_no,salary']);

        $this->assertSame(['Employee ID', 'Current Salary'], $rows[0]);
        $this->assertSame([1, 4, 6], array_column(array_slice($rows, 1), 0));
    }

    public function test_viewers_cannot_export_at_all(): void
    {
        $this->signInAs(Role::Viewer);

        $this->post(route('employees.export'), ['format' => 'xlsx', 'selectAll' => '1', 'columns' => 'salary'])->assertForbidden();
    }

    public function test_requested_salary_columns_are_dropped_server_side_without_view_salaries(): void
    {
        // Direct use of the service as for a user without view-salaries (the endpoint is gated).
        $path = tempnam(sys_get_temp_dir(), 'x');
        $service = new EmployeeExportService;
        $rows = $service->writeFile(ExportFormat::Xlsx, \App\Queries\EmployeeSearchQuery::make([]), $service->columns(false, 'emp_no,salary,earnings'), $path, 10);

        [$values] = $this->readXlsx($path);
        $this->assertSame(7, $rows);
        $this->assertSame(['Employee ID'], $values[0]);
        @unlink($path);
    }

    public function test_the_export_modal_offers_formats_and_columns(): void
    {
        $this->get(route('employees.index'))
            ->assertOk()
            ->assertSee('data-format="xlsx"', false)
            ->assertSee('id="exportColumn-salary"', false)
            ->assertSee('data-export-url="'.route('employees.export').'"', false)
            ->assertSee('data-csrf-token=', false);

        $this->assertStringContainsString("addHiddenInput(form, 'columns'", (string) file_get_contents(public_path('js/export-modal.js')));

        $this->signInAs(Role::Manager, $this->alpha);
        $this->get(route('employees.index'))->assertSee('id="exportColumn-earnings"', false);
    }
}
