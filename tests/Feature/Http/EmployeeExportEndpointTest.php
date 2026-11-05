<?php

namespace Tests\Feature\Http;

use App\Models\Department;
use App\Models\Employee;
use App\Services\EmployeeExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\Concerns\BuildsDirectory;
use Tests\TestCase;

class EmployeeExportEndpointTest extends TestCase
{
    use BuildsDirectory;
    use RefreshDatabase;

    private const HEADER = [
        'Employee ID', 'First Name', 'Last Name', 'Full Name', 'Department', 'Job Title',
        'Current Salary', 'Total Earnings', 'Hire Date', 'Gender', 'Employment Status',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDirectory();
    }

    /**
     * POST the export and return [response, parsed rows without the header, raw content].
     *
     * @return array{0: \Illuminate\Testing\TestResponse, 1: array<int, array<int, string>>, 2: string}
     */
    private function exportCsv(array $payload = []): array
    {
        $response = $this->post(route('employees.export'), $payload + ['format' => 'csv', 'selectAll' => '1']);
        $response->assertOk();
        $content = $response->streamedContent();

        $handle = fopen('php://memory', 'w+');
        fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $content));
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        $header = array_shift($rows);
        $this->assertSame(self::HEADER, $header);

        return [$response, $rows, $content];
    }

    private function ids(array $rows): array
    {
        return array_map(fn ($row) => (int) $row[0], $rows);
    }

    // -------------------------------------------------------------- CSV format

    public function test_csv_response_headers_filename_and_bom(): void
    {
        [$response, , $content] = $this->exportCsv();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertMatchesRegularExpression('/^attachment; filename="?employees_export_selected_7_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.csv"?$/', $disposition);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'UTF-8 BOM so that Excel detects the encoding');
        $this->assertSame(1, substr_count($content, "\xEF\xBB\xBF"));
    }

    public function test_csv_contains_one_row_per_employee_with_eleven_columns(): void
    {
        [, $rows] = $this->exportCsv();

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->ids($rows));
        foreach ($rows as $row) {
            $this->assertCount(11, $row);
        }
    }

    public function test_csv_row_of_a_current_employee(): void
    {
        [, $rows] = $this->exportCsv();
        $row = collect($rows)->firstWhere(0, '1');

        $earnings = DB::table('salaries')->where('emp_no', 1)->sum('salary');

        $this->assertSame([
            '1', 'Anna', 'Smith', 'Anna Smith', 'Alpha', 'Engineer', '50000.00',
            number_format($earnings, 2, '.', ''), '1990-03-01', 'Female', 'Active',
        ], $row);
        $this->assertGreaterThan(50000, $earnings, 'total earnings is the sum of all salary rows, not the current salary');
    }

    public function test_csv_row_of_a_former_employee_uses_last_department_and_title_markers(): void
    {
        [, $rows] = $this->exportCsv();
        $row = collect($rows)->firstWhere(0, '3');

        $this->assertSame('Former - Mid', $row[4]);
        $this->assertSame('Staff (Last)', $row[5]);
        $this->assertSame('N/A', $row[6]);
        $this->assertSame(number_format(DB::table('salaries')->where('emp_no', 3)->sum('salary'), 2, '.', ''), $row[7]);
        $this->assertSame('Male', $row[9]);
        $this->assertSame('Former', $row[10]);
    }

    public function test_csv_row_of_an_employee_without_any_history(): void
    {
        [, $rows] = $this->exportCsv();

        $this->assertSame(
            ['5', 'Eve', 'Nobody', 'Eve Nobody', 'N/A', 'N/A', 'N/A', '0.00', '1999-09-09', 'Female', 'Former'],
            collect($rows)->firstWhere(0, '5')
        );
    }

    public function test_csv_status_column_matches_the_employment_status_filter(): void
    {
        [, $current] = $this->exportCsv(['employmentStatus' => 'current']);
        [, $former] = $this->exportCsv(['employmentStatus' => 'former']);

        $this->assertSame(['Active'], collect($current)->pluck(10)->unique()->values()->all());
        $this->assertSame(['Former'], collect($former)->pluck(10)->unique()->values()->all());
        $this->assertSame([1, 2, 6, 7], $this->ids($current));
        $this->assertSame([3, 4, 5], $this->ids($former));
    }

    public function test_csv_escapes_commas_quotes_and_newlines(): void
    {
        Employee::factory()->current($this->alpha)->create([
            'emp_no' => 50, 'first_name' => 'Mary, "Jo"', 'last_name' => "O'Neil\nJr", 'hire_date' => '1999-01-01',
        ]);

        [, $rows] = $this->exportCsv(['search' => 'Mary']);

        $this->assertCount(1, $rows);
        $this->assertSame('Mary, "Jo"', $rows[0][1]);
        $this->assertSame("O'Neil\nJr", $rows[0][2]);
        $this->assertSame("Mary, \"Jo\" O'Neil\nJr", $rows[0][3]);
    }

    public function test_csv_keeps_non_ascii_names_intact(): void
    {
        Employee::factory()->current($this->alpha)->create([
            'emp_no' => 51, 'first_name' => 'Zoë', 'last_name' => 'Łukasiewicz', 'hire_date' => '1999-01-01',
        ]);

        [, $rows] = $this->exportCsv(['search' => 'Łuk']);

        $this->assertSame(['Zoë', 'Łukasiewicz', 'Zoë Łukasiewicz'], array_slice($rows[0], 1, 3));
    }

    // -------------------------------------------------- filters and sorting

    public function test_csv_applies_filters(): void
    {
        [, $rows] = $this->exportCsv(['department' => $this->alpha->dept_no, 'gender' => 'F']);
        $this->assertSame([1, 4], $this->ids($rows));

        [, $rows] = $this->exportCsv(['search' => 'Smith', 'jobTitle' => 'Engineer']);
        $this->assertSame([1, 6], $this->ids($rows));

        [, $rows] = $this->exportCsv(['salaryMin' => '60000', 'salaryMax' => '100000']);
        $this->assertSame([2, 7], $this->ids($rows));
    }

    public function test_csv_follows_the_requested_sort_order(): void
    {
        [, $rows] = $this->exportCsv(['sortBy' => 'name', 'sortDirection' => 'desc']);
        $this->assertSame([7, 5, 4, 3, 2, 6, 1], $this->ids($rows));

        [, $rows] = $this->exportCsv(['sortBy' => 'department', 'sortDirection' => 'asc']);
        $this->assertSame([5, 1, 4, 6, 3, 7, 2], $this->ids($rows));

        [, $rows] = $this->exportCsv(['sortBy' => 'salary', 'sortDirection' => 'desc']);
        $this->assertSame([2, 7, 6, 1, 5, 4, 3], $this->ids($rows));
    }

    public function test_invalid_sort_input_falls_back_to_emp_no_order(): void
    {
        [, $rows] = $this->exportCsv(['sortBy' => 'salary; DROP TABLE employees', 'sortDirection' => 'desc; --']);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->ids($rows));
        $this->assertSame(7, DB::table('employees')->count());
    }

    public function test_malicious_filter_values_are_harmless(): void
    {
        $response = $this->post(route('employees.export'), [
            'format' => 'csv', 'selectAll' => '1',
            'search' => "'; DROP TABLE employees; --", 'department' => "d001' OR '1'='1", 'jobTitle' => "x' OR '1'='1",
        ]);

        $response->assertRedirect(); // nothing matches -> back with an error
        $this->assertSame(7, DB::table('employees')->count());
    }

    public function test_array_valued_filter_and_sort_params_fall_back_to_the_defaults(): void
    {
        [, $rows] = $this->exportCsv([
            'search' => ['Smith'], 'employmentStatus' => ['current'], 'gender' => ['F'], 'department' => [$this->alpha->dept_no],
            'jobTitle' => ['Engineer'], 'salaryMin' => ['60000'], 'salaryMax' => ['x' => 1], 'sortBy' => ['name'],
            'sortDirection' => ['desc'], 'format' => ['pdf'],
        ]);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->ids($rows), 'no filter, emp_no order, CSV');
    }

    public function test_array_valued_selection_params_are_ignored(): void
    {
        [, $rows] = $this->exportCsv(['selectAll' => ['1'], 'selectedEmployees' => [[1], 2, ['x' => 3]]]);

        $this->assertSame([2], $this->ids($rows), 'selectAll[] is not "on"; nested ids are dropped');
    }

    // ------------------------------------------------------ selection modes

    public function test_select_all_with_exclusions(): void
    {
        [$response, $rows] = $this->exportCsv(['selectAll' => '1', 'excludedEmployees' => [2, 5, 999]]);

        $this->assertSame([1, 3, 4, 6, 7], $this->ids($rows));
        $this->assertStringContainsString('employees_export_selected_5_', $response->headers->get('Content-Disposition'));
    }

    public function test_select_all_with_exclusions_and_filters(): void
    {
        [, $rows] = $this->exportCsv(['selectAll' => '1', 'gender' => 'M', 'excludedEmployees' => [3]]);

        $this->assertSame([2, 6, 7], $this->ids($rows));
    }

    public function test_select_all_as_true_boolean_string(): void
    {
        [, $rows] = $this->exportCsv(['selectAll' => 'true', 'excludedEmployees' => [1]]);

        $this->assertSame([2, 3, 4, 5, 6, 7], $this->ids($rows));
    }

    public function test_explicit_selection(): void
    {
        [, $rows] = $this->exportCsv(['selectAll' => '0', 'selectedEmployees' => [7, 3, 1]]);

        $this->assertSame([1, 3, 7], $this->ids($rows), 'exported in list order, not in the order of the ids');
    }

    public function test_explicit_selection_is_intersected_with_the_filters(): void
    {
        [, $rows] = $this->exportCsv(['selectAll' => '0', 'selectedEmployees' => [1, 2, 3, 4], 'gender' => 'F']);

        $this->assertSame([1, 4], $this->ids($rows));
    }

    public function test_explicit_selection_ignores_the_exclusion_list(): void
    {
        [, $rows] = $this->exportCsv(['selectAll' => '0', 'selectedEmployees' => [1, 2], 'excludedEmployees' => [1]]);

        $this->assertSame([1, 2], $this->ids($rows));
    }

    public function test_legacy_payload_with_only_selected_employees_still_works(): void
    {
        $response = $this->post(route('employees.export'), ['format' => 'csv', 'selectedEmployees' => [2, 4]]);
        $response->assertOk();

        $this->assertStringContainsString('Jones', $response->streamedContent());
    }

    public function test_legacy_payload_without_any_format_defaults_to_csv(): void
    {
        $response = $this->post(route('employees.export'), ['selectedEmployees' => [2]]);

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_selected_ids_are_sanitized(): void
    {
        [, $rows] = $this->exportCsv(['selectAll' => '0', 'selectedEmployees' => ['2', 'abc', '3; DROP TABLE employees', '2']]);

        $this->assertSame([2], $this->ids($rows));
        $this->assertSame(7, DB::table('employees')->count());
    }

    public function test_empty_selection_redirects_back_with_an_error(): void
    {
        $this->from(route('employees.index'))
            ->post(route('employees.export'), ['format' => 'csv', 'selectAll' => '0'])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('error', 'No employees were selected for export.');

        $this->from(route('employees.index'))
            ->post(route('employees.export'), ['format' => 'csv', 'selectAll' => '0', 'selectedEmployees' => []])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('error');

        $this->from(route('employees.index'))
            ->post(route('employees.export'), ['format' => 'pdf'])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('error');
    }

    public function test_selection_that_matches_nothing_redirects_back_with_an_error(): void
    {
        $this->from(route('employees.index'))
            ->post(route('employees.export'), ['format' => 'csv', 'selectAll' => '0', 'selectedEmployees' => [999]])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('error', 'No employees found for export.');

        $this->from(route('employees.index'))
            ->post(route('employees.export'), ['format' => 'csv', 'selectAll' => '1', 'search' => 'nobody-like-this'])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('error', 'No employees found for export.');

        $this->from(route('employees.index'))
            ->post(route('employees.export'), ['format' => 'csv', 'selectAll' => '1', 'excludedEmployees' => [1, 2, 3, 4, 5, 6, 7]])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('error', 'No employees found for export.');
    }

    public function test_the_export_endpoint_only_accepts_post(): void
    {
        // /employees/export would otherwise be swallowed by /employees/{emp_no}
        $this->get('/employees/export')->assertNotFound();
    }

    // ------------------------------------------------------------------ PDF

    public function test_pdf_export_downloads_a_pdf(): void
    {
        $response = $this->post(route('employees.export'), ['format' => 'pdf', 'selectAll' => '1', 'excludedEmployees' => [5]]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression(
            '/filename="?employees_selected_6_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.pdf"?/',
            $response->headers->get('Content-Disposition')
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_pdf_export_of_an_explicit_selection_and_with_filters(): void
    {
        $this->post(route('employees.export'), ['format' => 'pdf', 'selectAll' => '0', 'selectedEmployees' => [1, 3]])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->post(route('employees.export'), ['format' => 'pdf', 'selectAll' => '1', 'employmentStatus' => 'former', 'sortBy' => 'title'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pdf_export_of_nothing_redirects_back_with_an_error(): void
    {
        $this->from(route('employees.index'))
            ->post(route('employees.export'), ['format' => 'pdf', 'selectAll' => '1', 'search' => 'nobody-like-this'])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('error', 'No employees found for export.');
    }

    public function test_pdf_export_is_limited_to_the_first_thousand_employees(): void
    {
        $this->assertSame(1000, EmployeeExportService::PDF_LIMIT);
    }

    /**
     * Export a PDF with a PDF limit of 3 and return [response, data passed to the PDF view].
     *
     * @return array{0: \Illuminate\Testing\TestResponse, 1: array<string, mixed>}
     */
    private function exportPdfWithLimitOfThree(array $payload): array
    {
        $this->app->instance(EmployeeExportService::class, new class extends EmployeeExportService
        {
            public const PDF_LIMIT = 3;
        });

        $data = [];
        View::composer('exports.employees-pdf', function ($view) use (&$data) {
            $data = $view->getData();
        });

        $response = $this->post(route('employees.export'), $payload + ['format' => 'pdf', 'selectAll' => '1']);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

        return [$response, $data];
    }

    public function test_limited_pdf_reports_the_real_selection_total(): void
    {
        [$response, $data] = $this->exportPdfWithLimitOfThree(['excludedEmployees' => [2]]);

        $this->assertSame([1, 3, 4], $data['employees']->pluck('emp_no')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(3, $data['totalCount']);
        $this->assertSame(6, $data['selectedCount'], 'all 7 employees except one, not the capped row count');
        $this->assertTrue($data['isLimited']);
        $this->assertStringContainsString('employees_selected_6_', $response->headers->get('Content-Disposition'));
    }

    public function test_pdf_with_exactly_the_limit_is_not_marked_as_limited(): void
    {
        [$response, $data] = $this->exportPdfWithLimitOfThree(['selectAll' => '0', 'selectedEmployees' => [1, 4, 7]]);

        $this->assertSame(3, $data['totalCount']);
        $this->assertSame(3, $data['selectedCount']);
        $this->assertFalse($data['isLimited']);
        $this->assertStringContainsString('employees_selected_3_', $response->headers->get('Content-Disposition'));
    }

    public function test_pdf_below_the_limit_is_not_marked_as_limited(): void
    {
        [, $data] = $this->exportPdfWithLimitOfThree(['gender' => 'F', 'employmentStatus' => 'former']);

        $this->assertSame(2, $data['selectedCount']);
        $this->assertFalse($data['isLimited']);
    }

    // ------------------------------------------------------ total earnings

    public function test_total_earnings_helper_sums_all_salary_rows_per_employee(): void
    {
        $service = new EmployeeExportService;
        $earnings = $service->totalEarnings([1, 3, 5]);

        $this->assertEquals(DB::table('salaries')->where('emp_no', 1)->sum('salary'), $earnings[1]);
        $this->assertEquals(DB::table('salaries')->where('emp_no', 3)->sum('salary'), $earnings[3]);
        $this->assertArrayNotHasKey(5, $earnings, 'no salary rows -> no entry');
        $this->assertSame([], $service->totalEarnings([]));
    }

    public function test_csv_export_of_a_larger_set_spans_several_chunks_in_order(): void
    {
        $dept = Department::factory()->create(['dept_name' => 'Bulk']);
        Employee::factory()->count(30)->current($dept, 'Staff', 45000)->create();

        [, $rows] = $this->exportCsv(['department' => $dept->dept_no]);

        $this->assertCount(30, $rows);
        $this->assertSame($this->ids($rows), collect($this->ids($rows))->sort()->values()->all());
        $this->assertSame(['Bulk'], collect($rows)->pluck(4)->unique()->values()->all());
    }
}
