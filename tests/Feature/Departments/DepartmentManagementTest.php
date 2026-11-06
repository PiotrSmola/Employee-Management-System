<?php

namespace Tests\Feature\Departments;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\DeptEmp;
use App\Models\DeptManager;
use App\Models\Employee;
use App\Models\User;
use App\Services\DepartmentStatistics;
use App\Support\EmployeeDataCache;
use App\Support\FigureCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_admin_creates_a_department(): void
    {
        Department::factory()->create(['dept_no' => 'd009', 'dept_name' => 'Customer Service']);
        Cache::put(EmployeeDataCache::DASHBOARD_STATS, ['stale']);

        $this->get(route('departments.create'))->assertOk()->assertSee('value="d010"', false);

        $this->post(route('departments.store'), ['dept_no' => ' D010 ', 'dept_name' => '  Data   Science '])
            ->assertRedirect(route('departments.show', 'd010'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('departments', ['dept_no' => 'd010', 'dept_name' => 'Data Science']);

        $log = ActivityLog::query()->where('action', 'department.created')->sole();
        $this->assertSame(ActivityLog::SUBJECT_DEPARTMENT, $log->subject_type);
        $this->assertSame('d010', $log->subject_id);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertEquals(['after' => ['dept_no' => 'd010', 'dept_name' => 'Data Science']], $log->changes);

        $this->assertFalse(Cache::has(EmployeeDataCache::DASHBOARD_STATS));
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidDepartmentProvider(): array
    {
        return [
            'code without d' => [['dept_no' => '0100', 'dept_name' => 'New'], 'dept_no'],
            'code too short' => [['dept_no' => 'd10', 'dept_name' => 'New'], 'dept_no'],
            'code with letters' => [['dept_no' => 'dx10', 'dept_name' => 'New'], 'dept_no'],
            'code taken' => [['dept_no' => 'd005', 'dept_name' => 'New'], 'dept_no'],
            'name missing' => [['dept_no' => 'd010', 'dept_name' => '   '], 'dept_name'],
            'name too long' => [['dept_no' => 'd010', 'dept_name' => str_repeat('x', 41)], 'dept_name'],
            'name taken' => [['dept_no' => 'd010', 'dept_name' => 'Development'], 'dept_name'],
        ];
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('invalidDepartmentProvider')]
    public function test_create_validation(array $input, string $field): void
    {
        Department::factory()->create(['dept_no' => 'd005', 'dept_name' => 'Development']);

        $this->from(route('departments.create'))
            ->post(route('departments.store'), $input)
            ->assertRedirect(route('departments.create'))
            ->assertSessionHasErrors($field);

        $this->assertSame(1, Department::count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'department.created')->count());
    }

    public function test_hr_renames_a_department(): void
    {
        $this->signInAs(Role::Hr);
        $department = Department::factory()->create(['dept_no' => 'd005', 'dept_name' => 'Development']);
        Cache::put(EmployeeDataCache::EMPLOYEES_BY_DEPARTMENT, ['stale']);
        Cache::put(EmployeeDataCache::DASHBOARD_STATS, ['kept']);
        app(DepartmentStatistics::class)->refreshDetails('d005');

        $this->get(route('departments.edit', $department))->assertOk()->assertSee('Development');

        $this->put(route('departments.update', $department), ['dept_name' => 'Engineering'])
            ->assertRedirect(route('departments.show', 'd005'));

        $this->assertSame('Engineering', $department->fresh()->dept_name);
        $log = ActivityLog::query()->where('action', 'department.renamed')->sole();
        $this->assertSame('d005', $log->subject_id);
        $this->assertEquals(['before' => ['dept_name' => 'Development'], 'after' => ['dept_name' => 'Engineering']], $log->changes);
        // Only what holds department names is cleared.
        $this->assertFalse(Cache::has(EmployeeDataCache::EMPLOYEES_BY_DEPARTMENT));
        $this->assertTrue(Cache::has(EmployeeDataCache::DASHBOARD_STATS));
        $this->assertTrue(FigureCache::isFresh(EmployeeDataCache::departmentDetails('d005')));
    }

    public function test_a_name_differing_only_in_case_collides_with_another_department(): void
    {
        $department = Department::factory()->create(['dept_no' => 'd005', 'dept_name' => 'Development']);
        Department::factory()->create(['dept_no' => 'd007', 'dept_name' => 'Sales']);

        $this->put(route('departments.update', $department), ['dept_name' => 'SALES'])->assertSessionHasErrors('dept_name');
        $this->post(route('departments.store'), ['dept_no' => 'd010', 'dept_name' => 'sales'])->assertSessionHasErrors('dept_name');

        // Correcting the case of the department's own name is a rename.
        $this->put(route('departments.update', $department), ['dept_name' => 'DEVELOPMENT'])->assertSessionHasNoErrors();

        $this->assertSame('DEVELOPMENT', $department->fresh()->dept_name);
        $this->assertSame(1, ActivityLog::query()->where('action', 'department.renamed')->count());
    }

    public function test_renaming_keeps_the_code_and_the_unique_name_rule(): void
    {
        $department = Department::factory()->create(['dept_no' => 'd005', 'dept_name' => 'Development']);
        Department::factory()->create(['dept_no' => 'd007', 'dept_name' => 'Sales']);

        $this->put(route('departments.update', $department), ['dept_name' => 'Sales'])->assertSessionHasErrors('dept_name');
        $this->put(route('departments.update', $department), ['dept_name' => 'X', 'dept_no' => 'd008'])->assertSessionHasErrors('dept_no');

        // Saving the unchanged name is fine and is not logged.
        $this->put(route('departments.update', $department), ['dept_name' => 'Development'])->assertSessionHasNoErrors();

        $this->assertSame('Development', $department->fresh()->dept_name);
        $this->assertDatabaseMissing('departments', ['dept_no' => 'd008']);
        $this->assertSame(0, ActivityLog::query()->where('action', 'department.renamed')->count());
    }

    public function test_a_department_that_never_had_employees_can_be_deleted(): void
    {
        $department = Department::factory()->create(['dept_no' => 'd010', 'dept_name' => 'Short-lived']);
        app(DepartmentStatistics::class)->refreshDetails('d010');

        $this->delete(route('departments.destroy', $department))
            ->assertRedirect(route('departments.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('departments', ['dept_no' => 'd010']);
        $log = ActivityLog::query()->where('action', 'department.deleted')->sole();
        $this->assertSame('d010', $log->subject_id);
        $this->assertEquals(['before' => ['dept_no' => 'd010', 'dept_name' => 'Short-lived']], $log->changes);
        $this->assertFalse(FigureCache::isFresh(EmployeeDataCache::departmentDetails('d010')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function usedDepartmentProvider(): array
    {
        return [
            'current employee' => ['current'],
            'former employee' => ['former'],
            'manager history only' => ['manager'],
            'manager account' => ['account'],
            'deactivated manager account' => ['inactive account'],
        ];
    }

    #[DataProvider('usedDepartmentProvider')]
    public function test_a_department_in_use_cannot_be_deleted(string $usage): void
    {
        $department = Department::factory()->create(['dept_no' => 'd010', 'dept_name' => 'Busy']);

        match ($usage) {
            'current' => Employee::factory()->current($department)->create(),
            'former' => Employee::factory()->former($department)->create(),
            'manager' => DeptManager::create([
                'emp_no' => Employee::factory()->current()->create()->emp_no,
                'dept_no' => 'd010', 'from_date' => '1990-01-01', 'to_date' => '1991-01-01',
            ]),
            'account' => User::factory()->manager($department)->create(),
            'inactive account' => User::factory()->manager($department)->inactive()->create(),
        };

        $this->get(route('departments.show', $department))->assertOk()->assertSee('disabled', false);

        $this->delete(route('departments.destroy', $department))
            ->assertRedirect(route('departments.show', 'd010'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('departments', ['dept_no' => 'd010']);
        $this->assertSame(0, ActivityLog::query()->where('action', 'department.deleted')->count());
    }

    public function test_deleting_does_not_touch_history_of_other_departments(): void
    {
        $kept = Department::factory()->create(['dept_no' => 'd001']);
        Employee::factory()->current($kept)->create();
        $empty = Department::factory()->create(['dept_no' => 'd010']);

        $this->delete(route('departments.destroy', $empty))->assertRedirect(route('departments.index'));

        $this->assertSame(1, DeptEmp::query()->where('dept_no', 'd001')->count());
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function readOnlyRoleProvider(): array
    {
        return ['manager' => [Role::Manager], 'viewer' => [Role::Viewer]];
    }

    #[DataProvider('readOnlyRoleProvider')]
    public function test_roles_without_manage_departments_cannot_change_departments(Role $role): void
    {
        $department = Department::factory()->create(['dept_no' => 'd010', 'dept_name' => 'Mine']);
        $this->signInAs($role, $department);

        $this->get(route('departments.create'))->assertForbidden();
        $this->post(route('departments.store'), ['dept_no' => 'd011', 'dept_name' => 'New'])->assertForbidden();
        $this->get(route('departments.edit', $department))->assertForbidden();
        $this->put(route('departments.update', $department), ['dept_name' => 'Renamed'])->assertForbidden();
        $this->delete(route('departments.destroy', $department))->assertForbidden();

        $this->get(route('departments.show', $department))->assertOk()
            ->assertDontSee(route('departments.edit', $department))
            ->assertDontSee('Delete the department');

        $this->assertSame('Mine', $department->fresh()->dept_name);
        $this->assertDatabaseMissing('departments', ['dept_no' => 'd011']);
        $this->assertSame(0, ActivityLog::query()->where('subject_type', ActivityLog::SUBJECT_DEPARTMENT)->count());
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->app['auth']->forgetGuards();

        $this->get(route('departments.index'))->assertRedirect(route('login'));
        $this->post(route('departments.store'), ['dept_no' => 'd011', 'dept_name' => 'New'])->assertRedirect(route('login'));
    }
}
