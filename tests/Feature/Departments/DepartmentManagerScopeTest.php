<?php

namespace Tests\Feature\Departments;

use App\Enums\Role;
use App\Models\Department;
use App\Models\DeptManager;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/**
 * A manager on their own department's pages sees the department's history, but
 * not who the people outside their scope are.
 */
class DepartmentManagerScopeTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    private Department $dev;

    private Department $sales;

    private Employee $boss;

    /** Worked in Development, now in Sales. */
    private Employee $moved;

    /** Managed Development, now in Sales. */
    private Employee $movedManager;

    /** Left the company, last department Development. */
    private Employee $leaver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dev = Department::factory()->create(['dept_no' => 'd005', 'dept_name' => 'Development']);
        $this->sales = Department::factory()->create(['dept_no' => 'd007', 'dept_name' => 'Sales']);

        $this->boss = Employee::factory()->current($this->dev, 'Manager')
            ->create(['first_name' => 'Bella', 'last_name' => 'Boss', 'hire_date' => '1990-01-01']);
        $this->moved = Employee::factory()->current($this->sales, previousDepartment: $this->dev)
            ->create(['first_name' => 'Tom', 'last_name' => 'Transfer', 'hire_date' => '1992-01-01']);
        $this->movedManager = Employee::factory()->current($this->sales, previousDepartment: $this->dev)
            ->create(['first_name' => 'Marta', 'last_name' => 'Moved', 'hire_date' => '1986-01-01']);
        $this->leaver = Employee::factory()->former($this->dev, 'Staff', '2000-06-30')
            ->create(['first_name' => 'Lea', 'last_name' => 'Leaver', 'hire_date' => '1991-01-01']);

        DeptManager::create(['emp_no' => $this->movedManager->emp_no, 'dept_no' => 'd005', 'from_date' => '1987-01-01', 'to_date' => '1995-01-01']);
        DeptManager::create(['emp_no' => $this->boss->emp_no, 'dept_no' => 'd005', 'from_date' => '1995-01-01', 'to_date' => '9999-01-01']);
    }

    public function test_a_manager_does_not_see_who_left_for_another_department(): void
    {
        $this->signInAs(Role::Manager, $this->dev);

        $response = $this->get(route('departments.show', $this->dev))->assertOk()
            ->assertSee('Employee moved to another department')
            ->assertDontSee('Tom Transfer')
            ->assertDontSee('#'.$this->moved->emp_no)
            ->assertDontSee(route('employees.show', $this->moved->emp_no))
            ->assertDontSee('Moved to Sales')
            ->assertDontSee('Sales');

        $hidden = $response->viewData('recentLeavers')->firstWhere('hidden', true);
        $this->assertNull($hidden->emp_no);
        $this->assertNull($hidden->first_name);
        $this->assertNull($hidden->moved_to);
    }

    public function test_a_manager_still_sees_the_employees_in_scope(): void
    {
        $this->signInAs(Role::Manager, $this->dev);

        $this->get(route('departments.show', $this->dev))->assertOk()
            ->assertSee('Bella Boss')
            ->assertSee(route('employees.show', $this->boss->emp_no))
            ->assertSee('Lea Leaver')             // left the company, last department Development
            ->assertSee(route('employees.show', $this->leaver->emp_no))
            ->assertSee('Left the company');
    }

    public function test_former_managers_keep_their_name_only_when_visible(): void
    {
        $this->signInAs(Role::Manager, $this->dev);

        $response = $this->get(route('departments.show', $this->dev))->assertOk()
            ->assertSee('A manager outside your department')
            ->assertSee('01.01.1987')             // the period stays
            ->assertDontSee('Marta Moved')
            ->assertDontSee('#'.$this->movedManager->emp_no);

        $this->assertCount(2, $response->viewData('managerHistory'));
    }

    public function test_the_shared_cache_does_not_carry_one_users_view_to_another(): void
    {
        $this->signInAs(Role::Manager, $this->dev);
        $this->get(route('departments.show', $this->dev))->assertOk()->assertDontSee('Tom Transfer');

        $this->signInAs(Role::Admin);
        $this->get(route('departments.show', $this->dev))->assertOk()
            ->assertSee('Tom Transfer')
            ->assertSee('Marta Moved')
            ->assertSee('Moved to Sales');

        $this->signInAs(Role::Manager, $this->dev);
        $this->get(route('departments.show', $this->dev))->assertOk()->assertDontSee('Tom Transfer');
    }

    public function test_other_roles_see_everybody(): void
    {
        foreach ([Role::Hr, Role::Viewer] as $role) {
            $this->signInAs($role);

            $this->get(route('departments.show', $this->dev))->assertOk()
                ->assertSee('Tom Transfer')
                ->assertSee('Marta Moved')
                ->assertDontSee('Employee moved to another department');
        }
    }

    public function test_a_current_manager_outside_the_scope_is_not_named(): void
    {
        DeptManager::query()->where('dept_no', 'd005')->where('to_date', '9999-01-01')->delete();
        $outsider = Employee::factory()->current($this->sales)->create(['first_name' => 'Otto', 'last_name' => 'Outsider']);
        DeptManager::create(['emp_no' => $outsider->emp_no, 'dept_no' => 'd005', 'from_date' => '1996-01-01', 'to_date' => '9999-01-01']);

        $this->signInAs(Role::Manager, $this->dev);

        $this->get(route('departments.index'))->assertOk()
            ->assertSee('outside your department')
            ->assertDontSee('Otto Outsider');
        $this->get(route('departments.show', $this->dev))->assertOk()
            ->assertSee('outside your department')
            ->assertDontSee('Otto Outsider')
            ->assertDontSee(route('employees.show', $outsider->emp_no));

        $this->get(route('employees.show', $this->boss->emp_no))->assertOk()
            ->assertSee('id="department-management"', false)
            ->assertDontSee('Otto Outsider');
    }

    public function test_an_administrator_sees_that_manager_on_the_profile(): void
    {
        $this->get(route('employees.show', $this->boss->emp_no))->assertOk()->assertSee('Bella Boss');
    }

    public function test_a_manager_without_a_department_sees_nothing(): void
    {
        $this->actingAs(User::factory()->manager()->create(['dept_no' => null]));

        $this->get(route('departments.index'))->assertOk()->assertSee('No department is assigned to your account.');
        $this->get(route('departments.show', $this->dev))->assertForbidden();
    }

    public function test_a_manager_of_a_department_that_no_longer_exists_sees_nothing(): void
    {
        $this->signInAs(Role::Manager, 'd999');

        $this->get(route('departments.index'))->assertOk()->assertSee('No department is assigned to your account.');
        $this->get(route('departments.show', $this->dev))->assertForbidden();
        $this->get(route('employees.show', $this->boss->emp_no))->assertForbidden();
    }
}
