<?php

namespace Tests\Feature\Reports;

use App\Models\DeptEmp;
use App\Models\Employee;
use App\Models\User;
use App\Reports\ReportContext;
use App\Reports\ReportTable;
use App\Reports\WorkforceReports;
use App\Support\EmployeeVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportData;
use Tests\TestCase;

/**
 * Exact figures of the workforce reports on the dataset of BuildsReportData.
 */
class WorkforceReportsTest extends TestCase
{
    use BuildsReportData;
    use RefreshDatabase;

    private WorkforceReports $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReportData();
        $this->reports = app(WorkforceReports::class);
    }

    private function context(?string $department = null, ?string $asOf = null): ReportContext
    {
        return ReportContext::make(EmployeeVisibility::unrestricted(), $department, $asOf ? CarbonImmutable::parse($asOf) : null);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rowsBy(ReportTable $table, string $column): array
    {
        return collect($table->rows)->keyBy($column)->all();
    }

    public function test_headcount_per_year_and_department(): void
    {
        $rows = $this->rowsBy($this->reports->headcount($this->context()), 'year');

        $this->assertSame(['1990', '1991', '1992', '1993', '1994'], array_map('strval', array_keys($rows)));
        $expected = [
            // year => [Sales, Development, total]
            1990 => [2, 0, 2],
            1991 => [2, 1, 3],
            1992 => [1, 2, 3], // E2 transferred to Development
            1993 => [1, 2, 3], // E4 hired, E3 left
            1994 => [1, 3, 4],
        ];
        foreach ($expected as $year => [$sales, $development, $total]) {
            $this->assertSame($sales, $rows[$year]['d_d001'], "Sales {$year}");
            $this->assertSame($development, $rows[$year]['d_d002'], "Development {$year}");
            $this->assertSame($total, $rows[$year]['total'], "Total {$year}");
            $this->assertSame($year.'-12-31', $rows[$year]['date']);
        }
    }

    public function test_hires_leavers_and_turnover_per_year(): void
    {
        $rows = $this->rowsBy($this->reports->hiresAndLeavers($this->context()), 'year');

        $this->assertSame(['hires' => 2, 'leavers' => 0, 'in' => 0, 'out' => 0, 'start' => 0, 'end' => 2],
            array_intersect_key($rows[1990], array_flip(['hires', 'leavers', 'in', 'out', 'start', 'end'])));
        $this->assertSame([1, 1], [$rows[1992]['in'], $rows[1992]['out']]);
        $this->assertSame(0, $rows[1992]['hires']);
        $this->assertSame([1, 1, 3, 3, 0], [$rows[1993]['hires'], $rows[1993]['leavers'], $rows[1993]['start'], $rows[1993]['end'], $rows[1993]['net']]);
        // 1 leaver / average headcount (3 + 3) / 2
        $this->assertEqualsWithDelta(100 / 3, $rows[1993]['turnover'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $rows[1990]['turnover'], 1e-9);
        $this->assertSame([1, 0, 4], [$rows[1994]['hires'], $rows[1994]['leavers'], $rows[1994]['end']]);
    }

    public function test_hires_use_the_hire_date_even_when_the_first_department_row_starts_later(): void
    {
        $late = Employee::factory()->create(['hire_date' => '1995-03-01', 'gender' => 'F', 'birth_date' => '1970-01-01']);
        DeptEmp::create(['emp_no' => $late->emp_no, 'dept_no' => 'd001', 'from_date' => '1996-02-01', 'to_date' => '9999-01-01']);

        $rows = $this->rowsBy($this->reports->hiresAndLeavers($this->context()), 'year');
        $this->assertSame([1, 4, 4], [$rows[1995]['hires'], $rows[1995]['start'], $rows[1995]['end']]);
        $this->assertSame([0, 4, 5], [$rows[1996]['hires'], $rows[1996]['start'], $rows[1996]['end']]);

        // Hired, but not in a department yet on the as-of date.
        $asOf = $this->rowsBy($this->reports->hiresAndLeavers($this->context(asOf: '1995-06-30')), 'year');
        $this->assertSame([1, 4], [$asOf[1995]['hires'], $asOf[1995]['end']]);
    }

    public function test_two_department_rows_starting_on_the_same_day_are_one_hire(): void
    {
        $employee = Employee::factory()->create(['hire_date' => '1996-05-01']);
        DeptEmp::create(['emp_no' => $employee->emp_no, 'dept_no' => 'd001', 'from_date' => '1996-05-01', 'to_date' => '1996-05-01']);
        DeptEmp::create(['emp_no' => $employee->emp_no, 'dept_no' => 'd002', 'from_date' => '1996-05-01', 'to_date' => '9999-01-01']);

        $rows = $this->rowsBy($this->reports->hiresAndLeavers($this->context()), 'year');
        $this->assertSame(1, $rows[1996]['hires']);
    }

    public function test_as_of_date_cuts_the_history_on_that_day(): void
    {
        $context = $this->context(asOf: '1993-03-31');

        $headcount = $this->rowsBy($this->reports->headcount($context), 'year');
        $this->assertSame(['1990', '1991', '1992', '1993'], array_map('strval', array_keys($headcount)));
        // E4 hired 1993-01-01, E3 still employed until 1993-07-01
        $this->assertSame([1, 3, 4], [$headcount[1993]['d_d001'], $headcount[1993]['d_d002'], $headcount[1993]['total']]);
        $this->assertSame('1993-03-31', $headcount[1993]['date']);

        $flows = $this->rowsBy($this->reports->hiresAndLeavers($context), 'year');
        $this->assertSame([1, 0], [$flows[1993]['hires'], $flows[1993]['leavers']]);

        $summary = $this->reports->summary($context);
        $this->assertSame(4, $summary['headcount']);
        $this->assertSame(1992, $summary['turnoverYear']);
    }

    public function test_department_filter_counts_that_departments_rows(): void
    {
        $rows = $this->rowsBy($this->reports->hiresAndLeavers($this->context('d002')), 'year');

        $this->assertSame(['1991', '1992', '1993', '1994'], array_map('strval', array_keys($rows)));
        $this->assertSame([1, 0, 0, 1], [$rows[1991]['hires'], $rows[1991]['in'], $rows[1991]['start'], $rows[1991]['end']]);
        $this->assertSame([0, 1, 2], [$rows[1992]['hires'], $rows[1992]['in'], $rows[1992]['end']]);
        // E3 left: 1 / ((2 + 2) / 2)
        $this->assertEqualsWithDelta(50.0, $rows[1993]['turnover'], 1e-9);
        $this->assertSame(3, $rows[1994]['end']);

        $headcount = $this->reports->headcount($this->context('d001'));
        $this->assertSame(['year', 'date', 'd_d001'], array_keys($headcount->columns));
        $this->assertSame([2, 2, 1, 1, 1], array_column($headcount->rows, 'd_d001'));
    }

    public function test_a_manager_only_counts_the_employees_of_their_department(): void
    {
        // E2 moved to Development, so the Sales manager only sees E1.
        $manager = User::factory()->manager($this->sales)->create();
        $context = ReportContext::make(EmployeeVisibility::for($manager), 'd002');

        $this->assertSame('d001', $context->department);
        $rows = $this->reports->headcount($context)->rows;
        // The history still runs up to the company's last change (1994).
        $this->assertSame(['1990', '1991', '1992', '1993', '1994'], array_column($rows, 'year'));
        $this->assertSame([1, 1, 1, 1, 1], array_column($rows, 'd_d001'));
        $this->assertSame(1, $this->reports->hiresAndLeavers($context)->rows[0]['hires']);
        $this->assertSame(1, $this->reports->summary($context)['headcount']);
    }

    public function test_a_manager_without_department_gets_empty_reports(): void
    {
        $manager = User::factory()->manager()->create(['dept_no' => null]);
        $context = ReportContext::make(EmployeeVisibility::for($manager));

        foreach (WorkforceReports::KEYS as $key) {
            $this->assertTrue($this->reports->table($key, $context)->isEmpty(), $key);
        }
        $this->assertSame(0, $this->reports->summary($context)['headcount']);
    }

    public function test_average_tenure_on_the_as_of_date(): void
    {
        $table = $this->reports->tenure($this->context(asOf: '2000-01-01'));
        $rows = $this->rowsBy($table, 'department');

        // Days since the hire date: E1 3652; E2 3501, E4 2556, E5 2191
        $this->assertSame(1, $rows['Sales']['employees']);
        $this->assertEqualsWithDelta(3652 / 365.25, $rows['Sales']['tenure'], 1e-9);
        $this->assertSame(3, $rows['Development']['employees']);
        $this->assertEqualsWithDelta((3501 + 2556 + 2191) / 3 / 365.25, $rows['Development']['tenure'], 1e-9);
        $this->assertSame(4, $table->totals[0]['employees']);
        $this->assertEqualsWithDelta(11900 / 4 / 365.25, $table->totals[0]['tenure'], 1e-9);
    }

    public function test_gender_distribution_overall_per_department_and_title(): void
    {
        $rows = collect($this->reports->gender($this->context(asOf: '2000-01-01'))->rows)
            ->keyBy(fn ($r) => $r['breakdown'].'/'.$r['group'])
            ->map(fn ($r) => [$r['female'], $r['male']])
            ->all();

        $this->assertSame([
            'Overall/All employees' => [2, 2],
            'Department/Sales' => [1, 0],
            'Department/Development' => [1, 2],
            'Title/Engineer' => [0, 2],
            'Title/Senior Engineer' => [1, 0],
            'Title/Staff' => [1, 0],
        ], $rows);

        $this->assertEqualsWithDelta(50.0, $this->reports->summary($this->context())['femaleShare'], 1e-9);
    }

    public function test_age_histogram_in_five_year_bands(): void
    {
        $rows = $this->reports->age($this->context(asOf: '2000-01-01'))->rows;

        // Ages on 2000-01-01: E1 39, E2 30, E4 19, E5 24 - empty bands in between are listed too.
        $this->assertSame([
            ['band' => '15–19', 'female' => 1, 'male' => 0, 'total' => 1],
            ['band' => '20–24', 'female' => 0, 'male' => 1, 'total' => 1],
            ['band' => '25–29', 'female' => 0, 'male' => 0, 'total' => 0],
            ['band' => '30–34', 'female' => 0, 'male' => 1, 'total' => 1],
            ['band' => '35–39', 'female' => 1, 'male' => 0, 'total' => 1],
        ], $rows);
    }

    public function test_overall_figures_count_a_person_with_two_open_departments_once(): void
    {
        // E1 (Sales) also gets an open row in Development.
        DeptEmp::create(['emp_no' => $this->people['E1']->emp_no, 'dept_no' => 'd002', 'from_date' => '1999-01-01', 'to_date' => '9999-01-01']);
        $context = $this->context(asOf: '2000-01-01');

        $summary = $this->reports->summary($context);
        $this->assertSame(4, $summary['headcount']);
        $this->assertEqualsWithDelta(50.0, $summary['femaleShare'], 1e-9);
        $this->assertEqualsWithDelta(11900 / 4 / 365.25, $summary['tenure'], 1e-9);

        $tenure = $this->reports->tenure($context);
        $this->assertSame(4, $tenure->totals[0]['employees']);
        $this->assertEqualsWithDelta(11900 / 4 / 365.25, $tenure->totals[0]['tenure'], 1e-9);
        // In the rows of both departments.
        $this->assertSame([1, 4], array_column($tenure->rows, 'employees'));

        $gender = collect($this->reports->gender($context)->rows)->keyBy('group');
        $this->assertSame([2, 2], [$gender['All employees']['female'], $gender['All employees']['male']]);
        $this->assertSame([2, 2], [$gender['Development']['female'], $gender['Development']['male']]);

        $this->assertSame(4, array_sum(array_column($this->reports->age($context)->rows, 'total')));
    }

    public function test_a_department_filter_counts_a_person_with_two_open_departments(): void
    {
        DeptEmp::create(['emp_no' => $this->people['E1']->emp_no, 'dept_no' => 'd002', 'from_date' => '1999-01-01', 'to_date' => '9999-01-01']);

        // Lowest dept_no is Sales, but in the Development filter E1 still counts.
        $this->assertSame(4, $this->reports->summary($this->context('d002', '2000-01-01'))['headcount']);
        $this->assertSame(1, $this->reports->summary($this->context('d001', '2000-01-01'))['headcount']);
    }

    public function test_charts_describe_the_rows(): void
    {
        $table = $this->reports->headcount($this->context());

        $chart = $table->charts[0];
        $this->assertSame('bar', $chart['type']);
        $this->assertTrue($chart['stacked']);
        $this->assertSame(['1990', '1991', '1992', '1993', '1994'], $chart['labels']);
        $this->assertSame(['Sales', 'Development'], array_column($chart['datasets'], 'label'));
        $this->assertSame([0, 1, 2, 2, 3], $chart['datasets'][1]['data']);
    }
}
