<?php

namespace Tests\Feature\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Services\EmployeeProfileService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeProfileService $service;

    private Department $research;

    private Department $sales;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2020-06-15 12:00:00');
        $this->service = new EmployeeProfileService;
        $this->research = Department::factory()->create(['dept_name' => 'Research']);
        $this->sales = Department::factory()->create(['dept_name' => 'Sales']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Current employee hired 2010-03-10: Research -> Sales on 2013-01-01,
     * Staff -> Senior Staff on 2012-06-01, three salaries.
     */
    private function currentEmployee(int $empNo = 100): Employee
    {
        $employee = Employee::factory()->create(['emp_no' => $empNo, 'hire_date' => '2010-03-10']);

        DB::table('dept_emp')->insert([
            ['emp_no' => $empNo, 'dept_no' => $this->research->dept_no, 'from_date' => '2010-03-10', 'to_date' => '2013-01-01'],
            ['emp_no' => $empNo, 'dept_no' => $this->sales->dept_no, 'from_date' => '2013-01-01', 'to_date' => '9999-01-01'],
        ]);
        DB::table('titles')->insert([
            ['emp_no' => $empNo, 'title' => 'Staff', 'from_date' => '2010-03-10', 'to_date' => '2012-06-01'],
            ['emp_no' => $empNo, 'title' => 'Senior Staff', 'from_date' => '2012-06-01', 'to_date' => '9999-01-01'],
        ]);
        DB::table('salaries')->insert([
            ['emp_no' => $empNo, 'salary' => 40000, 'from_date' => '2010-03-10', 'to_date' => '2011-03-10'],
            ['emp_no' => $empNo, 'salary' => 50000, 'from_date' => '2011-03-10', 'to_date' => '2012-12-01'],
            ['emp_no' => $empNo, 'salary' => 60000, 'from_date' => '2012-12-01', 'to_date' => '9999-01-01'],
        ]);

        return $employee;
    }

    public function test_build_returns_the_employee_with_current_relations(): void
    {
        $this->currentEmployee();

        $profile = $this->service->build(100);

        $this->assertSame(
            ['employee', 'departmentHistory', 'titleHistory', 'salaryHistory', 'combinedHistory', 'stats', 'salaryChartData', 'canSeeSalaries'],
            array_keys($profile)
        );
        $employee = $profile['employee'];
        $this->assertSame(100, $employee->emp_no);
        $this->assertSame('Sales', $employee->currentDepartment->first()->dept_name);
        $this->assertSame('Senior Staff', $employee->currentTitle->title);
        $this->assertEquals(60000, $employee->currentSalary->salary);
    }

    public function test_build_accepts_a_numeric_string(): void
    {
        $this->currentEmployee();

        $this->assertSame(100, $this->service->build('100')['employee']->emp_no);
    }

    public function test_unknown_employee_throws_model_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->build(424242);
    }

    public function test_department_history_is_ordered_by_start_date_and_includes_names(): void
    {
        $this->currentEmployee();

        $history = $this->service->build(100)['departmentHistory'];

        $this->assertSame(['Research', 'Sales'], $history->pluck('dept_name')->all());
        $this->assertSame([$this->research->dept_no, $this->sales->dept_no], $history->pluck('dept_no')->all());
        $this->assertSame(['2010-03-10', '2013-01-01'], $history->pluck('from_date')->all());
        $this->assertSame(['2013-01-01', '9999-01-01'], $history->pluck('to_date')->all());
    }

    public function test_title_and_salary_history_are_ordered_by_start_date(): void
    {
        $this->currentEmployee();
        $profile = $this->service->build(100);

        $this->assertSame(['Staff', 'Senior Staff'], $profile['titleHistory']->pluck('title')->all());
        $this->assertSame([40000, 50000, 60000], $profile['salaryHistory']->pluck('salary')->map(fn ($s) => (int) $s)->all());
        $this->assertSame(['2010-03-10', '2011-03-10', '2012-12-01'], $profile['salaryHistory']->pluck('from_date')->all());
    }

    public function test_history_contains_only_the_employees_own_rows(): void
    {
        $this->currentEmployee(100);
        $this->currentEmployee(101);

        $profile = $this->service->build(100);

        $this->assertCount(2, $profile['departmentHistory']);
        $this->assertCount(2, $profile['titleHistory']);
        $this->assertCount(3, $profile['salaryHistory']);
    }

    // ------------------------------------------------------ combined history

    public function test_combined_history_has_one_row_per_salary_newest_first_with_department_and_title(): void
    {
        $this->currentEmployee();

        $combined = $this->service->build(100)['combinedHistory']->values();

        $this->assertCount(3, $combined);
        $this->assertSame(['2012-12-01', '2011-03-10', '2010-03-10'], $combined->pluck('from_date')->all());
        $this->assertSame([60000, 50000, 40000], $combined->pluck('salary')->map(fn ($s) => (int) $s)->all());

        // Salary 40000 (2010-03-10 .. 2011-03-10): Research + Staff
        $this->assertSame('Research', $combined[2]->department_name);
        $this->assertSame('Staff', $combined[2]->title);

        // Salary 50000 (2011-03-10 .. 2012-12-01) spans the promotion on 2012-06-01: the earlier title is matched
        $this->assertSame('Research', $combined[1]->department_name);
        $this->assertSame('Staff', $combined[1]->title);

        // Current salary 60000 starts 2012-12-01: still Research for one more month, Senior Staff by title
        $this->assertSame('Senior Staff', $combined[0]->title);
    }

    public function test_combined_history_marks_only_the_open_ended_salary_as_current(): void
    {
        $this->currentEmployee();

        $combined = $this->service->build(100)['combinedHistory']->values();

        $this->assertSame([true, false, false], $combined->pluck('is_current')->all());
        $this->assertSame('9999-01-01', $combined[0]->to_date);
    }

    public function test_combined_history_uses_unknown_when_nothing_overlaps(): void
    {
        $departments = collect([(object) ['dept_name' => 'Late', 'from_date' => '2000-01-01', 'to_date' => '2000-12-31']]);
        $titles = collect([(object) ['title' => 'Late title', 'from_date' => '2000-01-01', 'to_date' => '2000-12-31']]);
        $salaries = collect([(object) ['salary' => 1000, 'from_date' => '1990-01-01', 'to_date' => '1991-01-01']]);

        $combined = $this->service->combinedHistory($departments, $titles, $salaries)->values();

        $this->assertSame('Unknown', $combined[0]->department_name);
        $this->assertSame('Unknown', $combined[0]->title);
        $this->assertFalse($combined[0]->is_current);
    }

    public function test_combined_history_with_open_ended_periods_on_both_sides(): void
    {
        $departments = collect([(object) ['dept_name' => 'Old', 'from_date' => '2000-01-01', 'to_date' => '2005-01-01'],
            (object) ['dept_name' => 'New', 'from_date' => '2005-01-01', 'to_date' => '9999-01-01']]);
        $titles = collect([(object) ['title' => 'T', 'from_date' => '2000-01-01', 'to_date' => '9999-01-01']]);
        $salaries = collect([(object) ['salary' => 1, 'from_date' => '2010-01-01', 'to_date' => '9999-01-01']]);

        $row = $this->service->combinedHistory($departments, $titles, $salaries)->first();

        $this->assertSame('New', $row->department_name);
        $this->assertSame('T', $row->title);
    }

    public function test_combined_history_of_an_employee_without_salaries_is_empty(): void
    {
        $this->assertTrue($this->service->combinedHistory(collect(), collect(), collect())->isEmpty());
    }

    // ------------------------------------------------------------------ stats

    public function test_stats_of_a_current_employee_count_until_today(): void
    {
        $this->currentEmployee();

        $stats = $this->service->build(100)['stats'];

        // 2010-03-10 -> 2020-06-15: 10 years + 97 days (3 leap days inside the 10 years)
        $this->assertTrue($stats['isCurrentEmployee']);
        $this->assertSame(10, $stats['yearsEmployed']);
        $this->assertSame(97, $stats['remainingDays']);
        $this->assertSame(3750, $stats['totalDays']);
        $this->assertNull($stats['employmentEndDate']);
    }

    public function test_stats_of_a_former_employee_count_until_the_last_department_end_date(): void
    {
        $employee = Employee::factory()->create(['emp_no' => 200, 'hire_date' => '1990-01-01']);
        DB::table('dept_emp')->insert([
            ['emp_no' => 200, 'dept_no' => $this->research->dept_no, 'from_date' => '1990-01-01', 'to_date' => '1993-01-01'],
            ['emp_no' => 200, 'dept_no' => $this->sales->dept_no, 'from_date' => '1993-01-01', 'to_date' => '1995-07-01'],
        ]);

        $stats = $this->service->build($employee->emp_no)['stats'];

        // 1990-01-01 -> 1995-07-01: 5 years + 181 days = 2007 days
        $this->assertFalse($stats['isCurrentEmployee']);
        $this->assertSame(5, $stats['yearsEmployed']);
        $this->assertSame(181, $stats['remainingDays']);
        $this->assertSame(2007, $stats['totalDays']);
        $this->assertSame('1995-07-01', $stats['employmentEndDate']->toDateString());
    }

    public function test_the_end_date_of_a_former_employee_is_the_latest_department_end_regardless_of_row_order(): void
    {
        Employee::factory()->create(['emp_no' => 201, 'hire_date' => '1990-01-01']);
        DB::table('dept_emp')->insert([
            ['emp_no' => 201, 'dept_no' => $this->sales->dept_no, 'from_date' => '1990-01-01', 'to_date' => '1999-12-31'],
            ['emp_no' => 201, 'dept_no' => $this->research->dept_no, 'from_date' => '1992-01-01', 'to_date' => '1994-01-01'],
        ]);

        $stats = $this->service->build(201)['stats'];

        $this->assertSame('1999-12-31', $stats['employmentEndDate']->toDateString());
        $this->assertSame(9, $stats['yearsEmployed']);
    }

    public function test_a_former_employee_without_department_history_is_measured_until_today(): void
    {
        Employee::factory()->create(['emp_no' => 202, 'hire_date' => '2019-06-15']);

        $stats = $this->service->build(202)['stats'];

        $this->assertFalse($stats['isCurrentEmployee']);
        $this->assertNull($stats['employmentEndDate']);
        $this->assertSame(1, $stats['yearsEmployed']);
        $this->assertSame(0, $stats['remainingDays']);
        $this->assertSame(366, $stats['totalDays']); // 2019-06-15 -> 2020-06-15 includes 2020-02-29
    }

    public function test_hire_date_in_the_future_gives_zero_tenure(): void
    {
        $employee = Employee::factory()->create(['emp_no' => 203, 'hire_date' => '2030-01-01']);
        DB::table('dept_emp')->insert(['emp_no' => 203, 'dept_no' => $this->sales->dept_no, 'from_date' => '2030-01-01', 'to_date' => '9999-01-01']);

        $stats = $this->service->build($employee->emp_no)['stats'];

        $this->assertSame(0, $stats['yearsEmployed']);
        $this->assertSame(0, $stats['remainingDays']);
        $this->assertSame(0, $stats['totalDays']);
    }

    public function test_total_and_average_earnings_and_change_counters(): void
    {
        $this->currentEmployee();

        $stats = $this->service->build(100)['stats'];

        $this->assertEquals(150000, $stats['totalEarnings']);
        $this->assertEquals(50000, $stats['avgSalary']);
        $this->assertSame(2, $stats['departmentChanges']);
        $this->assertSame(2, $stats['titleChanges']);
        $this->assertSame(3, $stats['salaryChanges']);
    }

    public function test_stats_without_any_history_are_zero(): void
    {
        Employee::factory()->create(['emp_no' => 300, 'hire_date' => '2020-06-15']);

        $stats = $this->service->build(300)['stats'];

        $this->assertEquals(0, $stats['totalEarnings']);
        $this->assertEquals(0, $stats['avgSalary']);
        $this->assertSame(0, $stats['departmentChanges']);
        $this->assertSame(0, $stats['titleChanges']);
        $this->assertSame(0, $stats['salaryChanges']);
        $this->assertFalse($stats['isCurrentEmployee']);
    }

    public function test_a_factory_built_former_employee_has_consistent_stats(): void
    {
        $employee = Employee::factory()->former($this->sales, 'Staff', '1999-06-30')->create(['hire_date' => '1990-01-01']);

        $profile = $this->service->build($employee->emp_no);

        $this->assertFalse($profile['stats']['isCurrentEmployee']);
        $this->assertSame('1999-06-30', $profile['stats']['employmentEndDate']->toDateString());
        $this->assertSame(9, $profile['stats']['yearsEmployed']);
        $this->assertCount(0, $profile['employee']->currentDepartment);
        $this->assertNull($profile['employee']->currentSalary);
        $this->assertSame($profile['salaryHistory']->count(), $profile['combinedHistory']->count());
        $this->assertSame([false], $profile['combinedHistory']->pluck('is_current')->unique()->values()->all());
    }

    // ------------------------------------------------------------- chart data

    public function test_salary_chart_data_averages_salaries_per_start_year_oldest_first(): void
    {
        $salaries = collect([
            (object) ['salary' => 70000, 'from_date' => '2012-01-01', 'to_date' => '9999-01-01'],
            (object) ['salary' => 40000, 'from_date' => '2010-03-10', 'to_date' => '2010-09-01'],
            (object) ['salary' => 45001, 'from_date' => '2010-09-01', 'to_date' => '2012-01-01'],
        ]);

        $data = $this->service->salaryChartData($salaries);

        $this->assertSame([
            ['year' => 2010, 'salary' => 42501.0, 'count' => 2],
            ['year' => 2012, 'salary' => 70000.0, 'count' => 1],
        ], $data->all());
    }

    public function test_salary_chart_data_of_a_built_profile_and_of_no_salaries(): void
    {
        $this->currentEmployee();

        $this->assertSame(
            [2010, 2011, 2012],
            $this->service->build(100)['salaryChartData']->pluck('year')->all()
        );
        $this->assertSame([40000.0, 50000.0, 60000.0], $this->service->build(100)['salaryChartData']->pluck('salary')->all());
        $this->assertTrue($this->service->salaryChartData(collect())->isEmpty());
    }
}
