<?php

namespace Tests\Feature\Departments;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\DeptManager;
use App\Models\Employee;
use App\Services\DepartmentManagementService;
use App\Services\DepartmentStatistics;
use App\Support\EmployeeDataCache;
use App\Support\FigureCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class DepartmentManagerAssignmentTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    private const BAG = DepartmentManagementService::ASSIGN_BAG;

    private Department $dev;

    private Department $sales;

    private Employee $boss;

    private Employee $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dev = Department::factory()->create(['dept_no' => 'd005', 'dept_name' => 'Development']);
        $this->sales = Department::factory()->create(['dept_no' => 'd007', 'dept_name' => 'Sales']);
        $this->boss = Employee::factory()->current($this->dev, 'Manager')
            ->create(['first_name' => 'Bella', 'last_name' => 'Boss', 'hire_date' => '1990-01-01']);
        $this->engineer = Employee::factory()->current($this->dev, 'Engineer')
            ->create(['first_name' => 'Ed', 'last_name' => 'Engineer', 'hire_date' => '1994-01-01']);

        DeptManager::create(['emp_no' => $this->boss->emp_no, 'dept_no' => 'd005', 'from_date' => '1995-01-01', 'to_date' => '9999-01-01']);
    }

    public function test_assigning_a_manager_closes_the_current_period_and_opens_a_new_one(): void
    {
        $this->warmFigures();

        $this->post(route('departments.managers.store', $this->dev), ['emp_no' => $this->engineer->emp_no, 'from_date' => '2001-03-01'])
            ->assertRedirect(route('departments.show', 'd005'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame([
            [$this->boss->emp_no, '1995-01-01', '2001-03-01'],
            [$this->engineer->emp_no, '2001-03-01', '9999-01-01'],
        ], $this->periods('d005'));

        $log = ActivityLog::query()->where('action', 'department.manager_assigned')->sole();
        $this->assertSame(ActivityLog::SUBJECT_DEPARTMENT, $log->subject_type);
        $this->assertSame('d005', $log->subject_id);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertEquals([
            'emp_no' => $this->engineer->emp_no,
            'from_date' => '2001-03-01',
            'previous_emp_no' => $this->boss->emp_no,
        ], $log->changes);

        $this->assertFiguresStayCached();

        $this->get(route('departments.show', $this->dev))->assertOk()
            ->assertSeeInOrder(['Ed Engineer', '01.03.2001', 'Present', 'Bella Boss', '01.01.1995', '01.03.2001']);
    }

    public function test_hr_assigns_the_first_manager_of_a_department(): void
    {
        $this->signInAs(Role::Hr);
        $seller = Employee::factory()->current($this->sales)->create(['hire_date' => '1992-05-05']);

        $this->post(route('departments.managers.store', $this->sales), ['emp_no' => $seller->emp_no, 'from_date' => '1992-05-05'])
            ->assertSessionHasNoErrors();

        $this->assertSame([[$seller->emp_no, '1992-05-05', '9999-01-01']], $this->periods('d007'));
        $this->assertNull(ActivityLog::query()->where('action', 'department.manager_assigned')->sole()->changes['previous_emp_no']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidAssignmentProvider(): array
    {
        return [
            'employee of another department' => ['seller', '2001-01-01', 'emp_no'],
            'former employee of the department' => ['former', '2001-01-01', 'emp_no'],
            'unknown employee' => ['unknown', '2001-01-01', 'emp_no'],
            'already the current manager' => ['boss', '2001-01-01', 'emp_no'],
            'before the current manager started' => ['engineer', '1994-12-31', 'from_date'],
            'on the day the current manager started' => ['engineer', '1995-01-01', 'from_date'],
            'before joining the department' => ['transferred', '1996-01-01', 'from_date'],
            'in the future' => ['engineer', '2999-01-01', 'from_date'],
            'not a date' => ['engineer', '01.03.2001', 'from_date'],
            'no employee' => ['none', '2001-01-01', 'emp_no'],
        ];
    }

    #[DataProvider('invalidAssignmentProvider')]
    public function test_assignment_rules(string $who, string $date, string $field): void
    {
        $empNo = match ($who) {
            'seller' => Employee::factory()->current($this->sales)->create()->emp_no,
            'former' => Employee::factory()->former($this->dev, endDate: '2000-01-01')->create(['hire_date' => '1991-01-01'])->emp_no,
            'unknown' => 999999,
            'boss' => $this->boss->emp_no,
            'engineer' => $this->engineer->emp_no,
            // In Sales until ~1997, then in Development.
            'transferred' => Employee::factory()->current($this->dev, previousDepartment: $this->sales)->create(['hire_date' => '1992-01-01'])->emp_no,
            'none' => '',
        };
        $before = $this->periods('d005');

        $this->from(route('departments.show', $this->dev))
            ->post(route('departments.managers.store', $this->dev), ['emp_no' => $empNo, 'from_date' => $date])
            ->assertRedirect(route('departments.show', 'd005'))
            ->assertSessionHasErrorsIn(self::BAG, $field);

        $this->assertSame($before, $this->periods('d005'));
        $this->assertSame(0, ActivityLog::query()->where('action', 'department.manager_assigned')->count());
    }

    public function test_a_former_manager_can_manage_the_department_again(): void
    {
        $former = Employee::factory()->current($this->dev)->create(['first_name' => 'Fay', 'last_name' => 'Former', 'hire_date' => '1986-01-01']);
        DeptManager::create(['emp_no' => $former->emp_no, 'dept_no' => 'd005', 'from_date' => '1987-01-01', 'to_date' => '1995-01-01']);

        $this->post(route('departments.managers.store', $this->dev), ['emp_no' => $former->emp_no, 'from_date' => '2001-01-01'])
            ->assertSessionHasNoErrors();

        $this->assertSame([
            [$former->emp_no, '1987-01-01', '1995-01-01'],
            [$this->boss->emp_no, '1995-01-01', '2001-01-01'],
            [$former->emp_no, '2001-01-01', '9999-01-01'],
        ], $this->periods('d005'));

        $this->assertSame(['Fay Former' => 'current'], collect($this->candidates('Fay'))->pluck('manager', 'name')->all());
        $this->assertSame(2, collect(app(DepartmentStatistics::class)->managerHistory('d005'))->where('emp_no', $former->emp_no)->count());

        $this->post(route('departments.managers.store', $this->dev), ['emp_no' => $former->emp_no, 'from_date' => '2001-06-01'])
            ->assertSessionHasErrorsIn(self::BAG, 'emp_no');
    }

    public function test_two_rows_in_one_department(): void
    {
        // Left the department and came back: two dept_emp rows, one of them current.
        $returned = Employee::factory()->current($this->dev)->create(['first_name' => 'Rae', 'last_name' => 'Returned', 'hire_date' => '1990-01-01']);
        DB::table('dept_emp')->where('emp_no', $returned->emp_no)->update(['from_date' => '1999-01-01']);
        DB::table('dept_emp')->insert(['emp_no' => $returned->emp_no, 'dept_no' => 'd005', 'from_date' => '1990-01-01', 'to_date' => '1993-01-01']);

        $this->assertCount(1, $this->candidates('Rae'), 'Only the current row makes a candidate.');

        // The date is checked against the current row, not the earlier one.
        $this->post(route('departments.managers.store', $this->dev), ['emp_no' => $returned->emp_no, 'from_date' => '1998-01-01'])
            ->assertSessionHasErrorsIn(self::BAG, 'from_date');
        $this->post(route('departments.managers.store', $this->dev), ['emp_no' => $returned->emp_no, 'from_date' => '2000-01-01'])
            ->assertSessionHasNoErrors();

        // Two management periods of the same employee in the same department.
        $this->post(route('departments.managers.end', $this->dev), ['to_date' => '2001-01-01'])->assertSessionHasNoErrors();
        $this->post(route('departments.managers.store', $this->dev), ['emp_no' => $returned->emp_no, 'from_date' => '2002-01-01'])
            ->assertSessionHasNoErrors();

        $this->assertSame([
            [$this->boss->emp_no, '1995-01-01', '2000-01-01'],
            [$returned->emp_no, '2000-01-01', '2001-01-01'],
            [$returned->emp_no, '2002-01-01', '9999-01-01'],
        ], $this->periods('d005'));

        $this->get(route('departments.show', $this->dev))->assertOk();
        $this->assertSame($returned->emp_no, (int) $this->currentManagerOf('d005'));

        // Ending the management closes the current period only.
        $this->post(route('departments.managers.end', $this->dev), ['to_date' => '2002-06-01'])->assertSessionHasNoErrors();
        $this->assertNull($this->currentManagerOf('d005'));
        $this->assertSame([$returned->emp_no, '2000-01-01', '2001-01-01'], $this->periods('d005')[1]);
        $this->assertSame([$returned->emp_no, '2002-01-01', '2002-06-01'], $this->periods('d005')[2]);
    }

    public function test_validation_errors_are_shown_next_to_the_manager_form(): void
    {
        $this->from(route('departments.show', $this->dev))
            ->followingRedirects()
            ->post(route('departments.managers.store', $this->dev), ['emp_no' => $this->engineer->emp_no, 'from_date' => '1994-06-01'])
            ->assertOk()
            ->assertSee('The date must be after the start of the current manager&#039;s period (1995-01-01).', false);
    }

    public function test_ending_management_without_successor(): void
    {
        $this->warmFigures();

        $this->post(route('departments.managers.end', $this->dev), ['to_date' => '2000-12-31'])
            ->assertRedirect(route('departments.show', 'd005'))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$this->boss->emp_no, '1995-01-01', '2000-12-31']], $this->periods('d005'));
        $log = ActivityLog::query()->where('action', 'department.manager_ended')->sole();
        $this->assertSame('d005', $log->subject_id);
        $this->assertEquals(['emp_no' => $this->boss->emp_no, 'from_date' => '1995-01-01', 'to_date' => '2000-12-31'], $log->changes);
        $this->assertFiguresStayCached();

        $this->get(route('departments.show', $this->dev))->assertOk()->assertSee('none')->assertDontSee('End the management of');

        // Without a current manager there is nothing to end; a new period cannot start before the last one ended.
        $this->post(route('departments.managers.end', $this->dev), ['to_date' => '2001-01-01'])
            ->assertSessionHasErrorsIn(DepartmentManagementService::END_BAG, 'to_date');
        $this->post(route('departments.managers.store', $this->dev), ['emp_no' => $this->engineer->emp_no, 'from_date' => '2000-06-01'])
            ->assertSessionHasErrorsIn(self::BAG, 'from_date');
        $this->post(route('departments.managers.store', $this->dev), ['emp_no' => $this->engineer->emp_no, 'from_date' => '2001-01-01'])
            ->assertSessionHasNoErrors();
        $this->assertNull(ActivityLog::query()->where('action', 'department.manager_assigned')->sole()->changes['previous_emp_no']);
    }

    public function test_end_date_must_be_after_the_start_of_the_period(): void
    {
        foreach (['1995-01-01', '1994-01-01', '2999-01-01', 'yesterday'] as $date) {
            $this->post(route('departments.managers.end', $this->dev), ['to_date' => $date])
                ->assertSessionHasErrorsIn(DepartmentManagementService::END_BAG, 'to_date');
        }

        $this->assertSame([[$this->boss->emp_no, '1995-01-01', '9999-01-01']], $this->periods('d005'));
        $this->assertSame(0, ActivityLog::query()->where('action', 'department.manager_ended')->count());
    }

    public function test_candidate_search_lists_current_employees_of_the_department(): void
    {
        Employee::factory()->current($this->sales)->create(['first_name' => 'Edna', 'last_name' => 'Elsewhere']);
        Employee::factory()->former($this->dev)->create(['first_name' => 'Edgar', 'last_name' => 'Exited']);

        $names = fn (string $q) => collect($this->getJson(route('departments.managers.candidates', ['department' => 'd005', 'q' => $q]))
            ->assertOk()
            ->json('data'))->pluck('name')->all();

        $this->assertSame(['Bella Boss', 'Ed Engineer'], $names(''));
        $this->assertSame(['Ed Engineer'], $names('ed'));
        $this->assertSame(['Ed Engineer'], $names('Ed Eng'));
        $this->assertSame(['Bella Boss'], $names((string) $this->boss->emp_no));
        $this->assertSame([], $names('%'));

        $first = $this->getJson(route('departments.managers.candidates', ['department' => 'd005', 'q' => 'Engineer']))->json('data.0');
        $this->assertSame(['emp_no' => $this->engineer->emp_no, 'name' => 'Ed Engineer', 'title' => 'Engineer', 'since' => '1994-01-01', 'manager' => null], $first);
    }

    public function test_candidate_search_by_number_and_by_several_words(): void
    {
        Employee::factory()->current($this->dev)->create(['emp_no' => 777123, 'first_name' => 'Anna', 'last_name' => 'Smith']);
        Employee::factory()->current($this->dev)->create(['emp_no' => 777456, 'first_name' => 'Anna', 'last_name' => 'Jones']);
        Employee::factory()->current($this->dev)->create(['emp_no' => 888123, 'first_name' => 'Smith', 'last_name' => 'Anders']);

        $numbers = fn (string $q) => collect($this->candidates($q))->pluck('emp_no')->all();

        $this->assertEqualsCanonicalizing([777123, 777456], $numbers('777'));
        $this->assertSame([777123], $numbers('7771'));
        $this->assertSame([], $numbers('123'));              // a prefix, not any part of the number
        $this->assertSame([777123], $numbers('anna smith'));
        $this->assertSame([777123], $numbers('smith anna'));
        $this->assertSame([888123], $numbers('smith anders'));
    }

    public function test_candidate_search_treats_like_wildcards_as_text(): void
    {
        Employee::factory()->current($this->dev)->create(['first_name' => 'Jo_Ann', 'last_name' => 'Under']);
        Employee::factory()->current($this->dev)->create(['first_name' => 'JoxAnn', 'last_name' => 'Plain']);
        Employee::factory()->current($this->dev)->create(['first_name' => '50%Off', 'last_name' => 'Percent']);

        $names = fn (string $q) => collect($this->candidates($q))->pluck('name')->all();

        $this->assertSame(['Jo_Ann Under'], $names('Jo_'));
        $this->assertSame(['50%Off Percent'], $names('50%'));
        $this->assertSame([], $names('%%'));
        $this->assertSame([], $names('__'));
    }

    public function test_a_name_search_needs_two_characters(): void
    {
        $this->assertSame([], $this->candidates('e'));
        $this->assertCount(1, $this->candidates('ed'));
        $this->assertCount(2, $this->candidates(''));       // the first ones, as a start
    }

    public function test_candidates_mark_current_and_former_managers(): void
    {
        $former = Employee::factory()->current($this->dev)->create(['first_name' => 'Fay', 'last_name' => 'Former']);
        DeptManager::create(['emp_no' => $former->emp_no, 'dept_no' => 'd005', 'from_date' => '1990-01-01', 'to_date' => '1995-01-01']);

        $roles = collect($this->candidates(''))->pluck('manager', 'name')->all();

        $this->assertSame(['Bella Boss' => 'current', 'Ed Engineer' => null, 'Fay Former' => 'former'], $roles);
    }

    public function test_the_candidate_search_is_rate_limited(): void
    {
        $route = Route::getRoutes()->getByName('departments.managers.candidates');

        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function readOnlyRoleProvider(): array
    {
        return ['manager' => [Role::Manager], 'viewer' => [Role::Viewer]];
    }

    #[DataProvider('readOnlyRoleProvider')]
    public function test_roles_without_manage_departments_cannot_change_managers(Role $role): void
    {
        $this->signInAs($role, $this->dev);

        $this->post(route('departments.managers.store', $this->dev), ['emp_no' => $this->engineer->emp_no, 'from_date' => '2001-01-01'])->assertForbidden();
        $this->post(route('departments.managers.end', $this->dev), ['to_date' => '2001-01-01'])->assertForbidden();
        $this->getJson(route('departments.managers.candidates', $this->dev))->assertForbidden();

        $this->assertSame([[$this->boss->emp_no, '1995-01-01', '9999-01-01']], $this->periods('d005'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function candidates(string $q): array
    {
        return $this->getJson(route('departments.managers.candidates', ['department' => 'd005', 'q' => $q]))
            ->assertOk()
            ->json('data');
    }

    private function currentManagerOf(string $deptNo): ?int
    {
        return app(DepartmentStatistics::class)->currentManagers([$deptNo])->first()?->emp_no;
    }

    private function warmFigures(): void
    {
        $statistics = app(DepartmentStatistics::class);
        $statistics->refreshOverview();
        $statistics->refreshDetails('d005');
        Cache::put(EmployeeDataCache::DASHBOARD_STATS, ['kept']);
    }

    /**
     * Managers are read live: changing them leaves every cached figure in place.
     */
    private function assertFiguresStayCached(): void
    {
        $this->assertTrue(FigureCache::isFresh(EmployeeDataCache::DEPARTMENTS_OVERVIEW));
        $this->assertTrue(FigureCache::isFresh(EmployeeDataCache::departmentDetails('d005')));
        $this->assertTrue(Cache::has(EmployeeDataCache::DASHBOARD_STATS));
    }

    /**
     * @return array<int, array{int, string, string}>
     */
    private function periods(string $deptNo): array
    {
        return DB::table('dept_manager')
            ->where('dept_no', $deptNo)
            ->orderBy('from_date')
            ->get()
            ->map(fn ($row) => [(int) $row->emp_no, $row->from_date, $row->to_date])
            ->all();
    }
}
