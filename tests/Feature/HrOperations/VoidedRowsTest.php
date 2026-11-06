<?php

namespace Tests\Feature\HrOperations;

use App\Enums\Role;
use App\Livewire\EmployeeFilter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Queries\DepartmentMemberSearch;
use App\Reports\ReportCatalog;
use App\Reports\ReportContext;
use App\Services\DashboardService;
use App\Services\DepartmentManagementService;
use App\Services\DepartmentStatistics;
use App\Services\EmployeeImportService;
use App\Services\EmployeeOperationsService;
use App\Services\EmployeeProfileService;
use App\Support\EmployeeVisibility;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/**
 * Voided rows (undone HR operations) stay in the tables but do not exist for the
 * application: list, filters, sorts, counts, profile, charts, PDF, CSV, dashboard,
 * departments, reports, API, import and the rules of the HR operations.
 */
class VoidedRowsTest extends HrOperationsTestCase
{
    private const GHOST_TITLE = 'Ghost Title';

    private const GHOST_SALARY = 9876543;

    private const VOID = 999;

    private Department $sales;

    private Department $ghost;

    private Employee $current;

    private Employee $former;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sales = Department::factory()->create(['dept_name' => 'Sales']);
        $this->ghost = Department::factory()->create(['dept_name' => 'Ghost Department']);
        $this->current = $this->currentEmployee($this->sales, 'Engineer', 60000);
        $this->former = $this->formerEmployee($this->sales, '2000-06-30');

        foreach ([$this->current, $this->former] as $employee) {
            $this->voidedRows($employee->emp_no);
        }
    }

    /**
     * For each employee voided current rows, which would make them current,
     * change their department and title, and beat every date of the real history.
     */
    private function voidedRows(int $empNo): void
    {
        $row = ['emp_no' => $empNo, 'from_date' => '2020-01-01', 'to_date' => self::CURRENT, 'void_log_id' => self::VOID];

        DB::table('dept_emp')->insert([...$row, 'dept_no' => $this->ghost->dept_no]);
        DB::table('dept_manager')->insert([...$row, 'dept_no' => $this->ghost->dept_no]);
        DB::table('titles')->insert([...$row, 'title' => self::GHOST_TITLE]);
        DB::table('salaries')->insert([...$row, 'salary' => self::GHOST_SALARY]);
    }

    private function directory(array $filters = [])
    {
        return EmployeeVisibility::unrestricted()->query($filters);
    }

    public function test_the_directory_list_shows_the_active_rows_only(): void
    {
        $rows = $this->directory()->rowsFor([$this->current->emp_no, $this->former->emp_no]);

        $this->assertCount(2, $rows);
        $this->assertSame('Sales', $rows[0]->current_dept_name);
        $this->assertSame('Engineer', $rows[0]->current_title);
        $this->assertSame(60000, $rows[0]->current_salary);
        $this->assertNull($rows[1]->current_dept_name);
        $this->assertNull($rows[1]->current_title);
        $this->assertNull($rows[1]->current_salary);
        $this->assertSame('Sales', $rows[1]->last_dept_name);
        $this->assertSame('Staff', $rows[1]->last_job_title);
    }

    public function test_filters_and_counts_ignore_voided_rows(): void
    {
        $both = [$this->current->emp_no, $this->former->emp_no];

        foreach ([
            [['department' => $this->ghost->dept_no], []],
            [['jobTitle' => self::GHOST_TITLE], []],
            [['salaryMin' => 9000000], []],
            [['employmentStatus' => 'current'], [$this->current->emp_no]],
            [['employmentStatus' => 'former'], [$this->former->emp_no]],
            [['department' => $this->sales->dept_no], $both],
            [['employmentStatus' => 'former', 'department' => $this->sales->dept_no], [$this->former->emp_no]],
        ] as [$filters, $expected]) {
            $query = $this->directory($filters);

            $this->assertSame(count($expected), $query->count(), json_encode($filters));
            $this->assertEqualsCanonicalizing($expected, $query->ids(), json_encode($filters));
            $this->assertEqualsCanonicalizing($expected, $query->filtered()->pluck('e.emp_no')->all(), json_encode($filters));
        }
    }

    public function test_sorting_ignores_voided_rows(): void
    {
        $other = $this->currentEmployee($this->sales, 'Assistant', 70000);
        $expected = [
            'salary' => [$this->former->emp_no, $this->current->emp_no, $other->emp_no],
            'title' => [$other->emp_no, $this->current->emp_no, $this->former->emp_no],
        ];

        foreach ($expected as $sortBy => $ids) {
            $query = $this->directory(['sortBy' => $sortBy, 'sortDirection' => 'asc']);

            $this->assertSame($ids, $query->ids(), "segments, {$sortBy}");
            $this->assertSame($ids, $query->ids(10, 0, 3), "per-employee key, {$sortBy}");
        }

        // Nobody is left in the (voided) department, and nobody appears twice.
        $this->assertSame(3, count(array_unique($this->directory(['sortBy' => 'department'])->ids())));
    }

    public function test_the_profile_and_its_history_show_the_active_rows_only(): void
    {
        foreach ([$this->current, $this->former] as $employee) {
            $profile = app(EmployeeProfileService::class)->build(Employee::find($employee->emp_no));

            $this->assertNotContains($this->ghost->dept_no, $profile['departmentHistory']->pluck('dept_no')->all());
            $this->assertNotContains(self::GHOST_TITLE, $profile['titleHistory']->pluck('title')->all());
            $this->assertNotContains(self::GHOST_SALARY, $profile['salaryHistory']->pluck('salary')->all());
            $this->assertLessThan(self::GHOST_SALARY, $profile['stats']['totalEarnings']);
            $this->assertLessThan(self::GHOST_SALARY, $profile['salaryChartData']->max('salary'));
            $this->assertNotContains(2020, $profile['salaryChartData']->pluck('year')->all());
        }

        $this->assertFalse(app(EmployeeProfileService::class)->build(Employee::find($this->former->emp_no))['stats']['isCurrentEmployee']);

        foreach ([$this->current, $this->former] as $employee) {
            $this->get($this->profile($employee))
                ->assertOk()
                ->assertDontSee('Ghost Department (current)')
                ->assertDontSee(self::GHOST_TITLE)
                ->assertDontSee(number_format(self::GHOST_SALARY));
        }
        $this->get($this->profile($this->former))->assertSee('Former Employee');
    }

    public function test_the_pdf_contents_show_the_active_rows_only(): void
    {
        $profile = app(EmployeeProfileService::class)->build(Employee::find($this->current->emp_no));

        $html = view('exports.show-pdf', [...$profile, 'generatedAt' => now()])->render();

        $this->assertStringContainsString('Engineer', $html);
        $this->assertStringNotContainsString(self::GHOST_TITLE, $html);
        $this->assertStringNotContainsString('Ghost Department', $html);
        $this->assertStringNotContainsString(number_format(self::GHOST_SALARY), $html);
    }

    public function test_the_csv_export_ignores_voided_rows(): void
    {
        $response = $this->post(route('employees.export'), ['format' => 'csv', 'selectAll' => '1'])->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Engineer', $csv);
        $this->assertStringNotContainsString(self::GHOST_TITLE, $csv);
        $this->assertStringNotContainsString('Ghost Department', $csv);
        $this->assertStringNotContainsString((string) self::GHOST_SALARY, $csv);
    }

    public function test_the_dashboard_ignores_voided_rows(): void
    {
        $company = app(DashboardService::class)->build(EmployeeVisibility::unrestricted());
        $this->assertEquals(60000, $company['averageSalary']);
        $this->assertSame(['Sales'], $company['employeesByDepartment']->pluck('dept_name')->all());
        $this->assertSame(1, $company['employeesByDepartment']->first()->total);

        $manager = User::factory()->manager($this->ghost)->create();
        $ghost = app(DashboardService::class)->build(EmployeeVisibility::for($manager));
        $this->assertSame(0, $ghost['totalEmployees']);
        $this->assertNull($ghost['averageSalary']);
        $this->assertCount(0, $ghost['employeesByTitle']);

        $own = app(DashboardService::class)->build(EmployeeVisibility::for(User::factory()->manager($this->sales)->create()));
        $this->assertSame(1, $own['totalEmployees']);
        $this->assertEquals(60000, $own['averageSalary']);
        $this->assertSame(['Engineer'], $own['employeesByTitle']->pluck('title')->all());
    }

    public function test_the_manager_scope_ignores_voided_rows(): void
    {
        $visibility = EmployeeVisibility::for(User::factory()->manager($this->ghost)->create());

        $this->assertFalse($visibility->canView($this->current));
        $this->assertFalse($visibility->canView($this->former));
        $this->assertSame([], $visibility->query()->ids());

        $this->assertTrue(EmployeeVisibility::for(User::factory()->manager($this->sales)->create())->canView($this->former));
    }

    public function test_the_job_title_lists_ignore_voided_rows(): void
    {
        Livewire::test(EmployeeFilter::class)->assertSee('Engineer')->assertDontSee(self::GHOST_TITLE);

        $this->signInAs(Role::Manager, $this->sales);
        Livewire::test(EmployeeFilter::class)->assertSee('Engineer')->assertDontSee(self::GHOST_TITLE);

        $this->assertNotContains(self::GHOST_TITLE, app(EmployeeOperationsService::class)->titleOptions());
    }

    public function test_the_models_and_relations_ignore_voided_rows(): void
    {
        $employee = Employee::find($this->current->emp_no);

        $this->assertSame([$this->sales->dept_no], $employee->departments->pluck('dept_no')->all());
        $this->assertSame([$this->sales->dept_no], $employee->currentDepartment->pluck('dept_no')->all());
        $this->assertSame('Engineer', $employee->currentTitle->title);
        $this->assertSame(60000, $employee->currentSalary->salary);
        $this->assertNotContains(self::GHOST_TITLE, $employee->titles->pluck('title')->all());
        $this->assertNotContains(self::GHOST_SALARY, $employee->salaries->pluck('salary')->all());
        $this->assertSame(0, $this->ghost->employees()->count());
        $this->assertSame(1, Employee::active()->count());
    }

    public function test_department_figures_ignore_voided_rows(): void
    {
        $statistics = app(DepartmentStatistics::class);
        $visibility = EmployeeVisibility::unrestricted();

        $overview = $statistics->overview(Department::query()->orderBy('dept_no')->get(), $visibility)['departments']->keyBy('dept_no');
        $this->assertSame(1, $overview[$this->sales->dept_no]->headcount);
        $this->assertSame(0, $overview[$this->ghost->dept_no]->headcount);
        $this->assertEquals(60000, $overview[$this->sales->dept_no]->avg_salary);
        $this->assertNull($overview[$this->ghost->dept_no]->manager);

        $details = $statistics->details($this->sales, $visibility);
        $this->assertSame(1, $details['headcount']);
        $this->assertEquals(60000, $details['salary']['max']);
        $this->assertSame(['Engineer'], $details['titles']->pluck('title')->all());
        $this->assertNull(collect($details['recentLeavers'])->firstWhere('moved_to', 'Ghost Department'));

        $ghost = $statistics->details($this->ghost, $visibility);
        $this->assertSame(0, $ghost['headcount']);
        $this->assertSame([], $ghost['recentJoiners']->all());
        $this->assertSame([], $ghost['managerHistory']->all());
        $this->assertSame([], $ghost['headcountByYear']->all());

        $this->assertSame([], $statistics->forEmployee($this->current)['managedPeriods']->all());
    }

    public function test_the_manager_rules_ignore_voided_rows(): void
    {
        $departments = app(DepartmentManagementService::class);
        $search = app(DepartmentMemberSearch::class);

        $this->assertSame([], $search->search($this->ghost->dept_no, '')->all());
        $this->assertSame('Engineer', $search->search($this->sales->dept_no, (string) $this->current->emp_no)->first()->title);
        $this->assertNull($search->search($this->sales->dept_no, (string) $this->current->emp_no)->first()->manager);
        $this->assertNull($departments->deletionBlockedReason($this->ghost));

        $this->expectExceptionMessage('The employee is not a current employee of this department.');
        $departments->assignManager($this->ghost, $this->current->emp_no, '2021-01-01');
    }

    public function test_reports_ignore_voided_rows(): void
    {
        $catalog = app(ReportCatalog::class);
        $context = ReportContext::make(EmployeeVisibility::unrestricted());

        $this->assertSame(1, $catalog->summary(ReportCatalog::WORKFORCE, $context)['headcount']);
        $compensation = $catalog->summary(ReportCatalog::COMPENSATION, $context);
        $this->assertSame(1, $compensation['employees']);
        $this->assertEquals(60000, $compensation['average']);

        foreach ([ReportCatalog::WORKFORCE, ReportCatalog::COMPENSATION] as $section) {
            foreach ($catalog->tables($section, $context) as $table) {
                $content = json_encode($table->csvRows());
                $this->assertStringNotContainsString(self::GHOST_TITLE, $content, $table->key);
                $this->assertStringNotContainsString('Ghost Department', $content, $table->key);
                $this->assertStringNotContainsString((string) self::GHOST_SALARY, $content, $table->key);
            }
        }

        $ghost = ReportContext::make(EmployeeVisibility::unrestricted(), $this->ghost->dept_no);
        $this->assertSame(0, $catalog->summary(ReportCatalog::WORKFORCE, $ghost)['headcount']);
        $this->assertSame(0, $catalog->summary(ReportCatalog::COMPENSATION, $ghost)['employees']);
    }

    public function test_the_api_ignores_voided_rows(): void
    {
        Sanctum::actingAs($this->user, ['read']);

        $list = $this->getJson(route('api.v1.employees.index', ['sort' => 'emp_no', 'status' => 'current']))->assertOk()->json('data');
        $this->assertSame([$this->current->emp_no], array_column($list, 'emp_no'));
        $this->assertSame('Sales', $list[0]['department']);
        $this->assertSame('Engineer', $list[0]['title']);
        $this->assertSame(60000, $list[0]['salary']);

        $employee = $this->getJson(route('api.v1.employees.show', $this->former->emp_no))->assertOk()->json('data');
        $this->assertSame('former', $employee['status']);
        $this->assertNull($employee['title']);
        $this->assertNotContains('Ghost Department', array_column($employee['history']['departments'], 'name'));
        $this->assertNotContains(self::GHOST_TITLE, array_column($employee['history']['titles'], 'title'));
        $this->assertNotContains(self::GHOST_SALARY, array_column($employee['history']['salaries'], 'salary'));

        $departments = collect($this->getJson(route('api.v1.departments.index'))->assertOk()->json('data'))->pluck('current_employees', 'dept_no');
        $this->assertSame(1, $departments[$this->sales->dept_no]);
        $this->assertSame(0, $departments[$this->ghost->dept_no]);
    }

    public function test_the_import_knows_no_voided_titles(): void
    {
        $row = fn (string $title) => [['line' => 2, 'values' => [
            'first_name' => 'Jane', 'last_name' => 'Doe', 'birth_date' => '1980-01-02', 'gender' => 'F',
            'hire_date' => '2020-01-01', 'department' => $this->sales->dept_no, 'title' => $title, 'salary' => 50000,
        ]]];
        $imports = app(EmployeeImportService::class);

        $this->assertSame([], $imports->validate($row('Engineer'))[0]['errors']);
        $this->assertNotSame([], $imports->validate($row(self::GHOST_TITLE))[0]['errors']);
        $this->get(route('employees.import.create'))->assertOk()->assertDontSee(self::GHOST_TITLE);
    }

    public function test_the_hr_rules_ignore_voided_rows(): void
    {
        $operations = app(EmployeeOperationsService::class);
        $context = $operations->formContext($this->current);

        $this->assertSame([$this->sales->dept_no], $context['currentDepartments']);
        $this->assertSame([], $context['allManagedDepartments']);
        $this->assertSame('Engineer', $context['currentTitle']);
        $this->assertSame(60000, $context['currentSalary']);
        $this->assertSame('2002-03-02', $context['minDates']['salary'], 'The voided 2020 row does not move the earliest date.');
        $this->assertFalse($operations->formContext($this->former)['isCurrent']);
        $this->assertSame('2000-07-01', $operations->formContext($this->former)['minDates']['rehire']);

        // A date before and one equal to the voided rows' start are both fine.
        $operations->changeSalary($this->current, 61000, null, '2010-01-01');
        $operations->changeSalary($this->current, 62000, null, '2020-01-01');
        $operations->transfer($this->current, $this->ghost->dept_no, '2020-01-01');
        $operations->changeTitle($this->current, self::GHOST_TITLE, '2020-01-01');
        $operations->terminate($this->current, '2020-01-02');

        $this->assertSame(1, DB::table('dept_manager')->where('emp_no', $this->current->emp_no)->where('to_date', self::CURRENT)->count(), 'Voided rows stay as they are.');
        $this->assertSame(2, DB::table('salaries')->where('emp_no', $this->current->emp_no)->where('from_date', '2020-01-01')->count());
    }
}
