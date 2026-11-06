<?php

namespace Tests\Feature\Departments;

use App\Enums\Role;
use App\Livewire\EmployeeFilter;
use App\Livewire\EmployeeList;
use App\Models\Department;
use App\Models\DeptManager;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/**
 * The department pieces outside the department pages: the profile partial and
 * the directory opened pre-filtered to a department.
 */
class EmployeeProfileDepartmentTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

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
            ->create(['first_name' => 'Bella', 'last_name' => 'Boss', 'hire_date' => '1986-01-01']);
        $this->engineer = Employee::factory()->current($this->dev, 'Engineer')
            ->create(['first_name' => 'Ed', 'last_name' => 'Engineer']);

        DeptManager::create(['emp_no' => $this->boss->emp_no, 'dept_no' => 'd005', 'from_date' => '1995-01-01', 'to_date' => '9999-01-01']);
        DeptManager::create(['emp_no' => $this->boss->emp_no, 'dept_no' => 'd007', 'from_date' => '1988-02-01', 'to_date' => '1994-12-31']);
    }

    public function test_profile_shows_the_manager_of_the_current_department(): void
    {
        $this->get(route('employees.show', $this->engineer->emp_no))->assertOk()
            ->assertSee('id="department-management"', false)
            ->assertSeeInOrder(['Department manager', 'Development', 'Bella Boss'])
            ->assertSee(route('employees.show', $this->boss->emp_no))
            ->assertSee(route('departments.show', 'd005'))
            ->assertDontSee('Former department manager');
    }

    public function test_profile_of_a_manager_shows_the_badge_with_every_period(): void
    {
        $this->get(route('employees.show', $this->boss->emp_no))->assertOk()
            ->assertSee('this employee')
            ->assertSeeInOrder(['Department manager', 'Development', '01.01.1995', 'Present', 'Sales', '01.02.1988', '31.12.1994']);
    }

    public function test_profile_of_a_former_manager_shows_the_former_badge(): void
    {
        $former = Employee::factory()->former($this->sales, 'Manager', '1999-01-01')->create(['hire_date' => '1987-01-01']);
        DeptManager::create(['emp_no' => $former->emp_no, 'dept_no' => 'd007', 'from_date' => '1995-01-01', 'to_date' => '1999-01-01']);

        $this->get(route('employees.show', $former->emp_no))->assertOk()
            ->assertSee('Former department manager')
            ->assertSee('01.01.1995');
    }

    public function test_profile_of_an_employee_without_department_or_management_has_no_partial(): void
    {
        $nobody = Employee::factory()->create();

        $this->get(route('employees.show', $nobody->emp_no))->assertOk()
            ->assertDontSee('id="department-management"', false);
    }

    public function test_a_manager_does_not_get_links_to_other_departments(): void
    {
        $this->signInAs(Role::Manager, $this->dev);

        $this->get(route('employees.show', $this->boss->emp_no))->assertOk()
            ->assertSee(route('departments.show', 'd005'))
            ->assertDontSee(route('departments.show', 'd007'))
            ->assertSee('Sales');
    }

    public function test_viewer_sees_the_partial_too(): void
    {
        $this->signInAs(Role::Viewer);

        $this->get(route('employees.show', $this->engineer->emp_no))->assertOk()->assertSee('Bella Boss');
    }

    public function test_directory_can_be_opened_filtered_to_a_department(): void
    {
        Employee::factory()->current($this->sales)->create();

        Livewire::withQueryParams(['department' => 'd005'])->test(EmployeeFilter::class)
            ->assertSet('department', 'd005')
            ->assertSet('showFilters', true);

        $list = Livewire::withQueryParams(['department' => 'd005'])->test(EmployeeList::class)
            ->assertSet('department', 'd005');
        $this->assertEqualsCanonicalizing(
            [$this->boss->emp_no, $this->engineer->emp_no],
            $list->viewData('employees')->pluck('emp_no')->all(),
        );

        $this->get(route('employees.index', ['department' => 'd005']))->assertOk();
    }

    public function test_unknown_department_parameters_are_ignored(): void
    {
        foreach (['d999', '', 'all', 'd005-x'] as $value) {
            Livewire::withQueryParams(['department' => $value])->test(EmployeeList::class)->assertSet('department', 'all');
            Livewire::withQueryParams(['department' => $value])->test(EmployeeFilter::class)
                ->assertSet('department', 'all')
                ->assertSet('showFilters', false);
        }

        Livewire::withQueryParams(['department' => ['d005']])->test(EmployeeList::class)->assertSet('department', 'all');
    }

    public function test_the_manager_scope_wins_over_the_parameter(): void
    {
        $this->signInAs(Role::Manager, $this->sales);
        Employee::factory()->current($this->sales)->create();

        Livewire::withQueryParams(['department' => 'd005'])->test(EmployeeFilter::class)->assertSet('department', 'd007');

        $list = Livewire::withQueryParams(['department' => 'd005'])->test(EmployeeList::class);
        $this->assertSame('d007', $list->instance()->filters()->department);
        $this->assertNotContains($this->engineer->emp_no, $list->viewData('employees')->pluck('emp_no')->all());
    }
}
