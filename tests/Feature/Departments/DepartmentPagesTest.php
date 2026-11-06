<?php

namespace Tests\Feature\Departments;

use App\Enums\Role;
use App\Models\Department;
use App\Models\DeptManager;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class DepartmentPagesTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    private Department $dev;

    private Department $sales;

    private Employee $boss;

    private Employee $formerBoss;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dev = Department::factory()->create(['dept_no' => 'd005', 'dept_name' => 'Development']);
        $this->sales = Department::factory()->create(['dept_no' => 'd007', 'dept_name' => 'Sales']);

        $this->boss = Employee::factory()->current($this->dev, 'Manager', 120000)
            ->create(['first_name' => 'Bella', 'last_name' => 'Boss', 'gender' => 'F', 'hire_date' => '1990-01-01']);
        Employee::factory()->current($this->dev, 'Engineer', 60000)
            ->create(['first_name' => 'Ed', 'last_name' => 'Engineer', 'gender' => 'M', 'hire_date' => '1995-01-01']);
        Employee::factory()->current($this->dev, 'Engineer', 80000)
            ->create(['first_name' => 'Finn', 'last_name' => 'Fixer', 'gender' => 'M', 'hire_date' => '1996-01-01']);
        Employee::factory()->current($this->sales, 'Staff', 50000)
            ->create(['first_name' => 'Sam', 'last_name' => 'Seller', 'hire_date' => '1993-01-01']);
        // Left the company from Development.
        Employee::factory()->former($this->dev, 'Engineer', '2000-06-30', 70000)
            ->create(['first_name' => 'Lea', 'last_name' => 'Leaver', 'hire_date' => '1991-01-01']);
        // Moved from Development to Sales.
        Employee::factory()->current($this->sales, 'Staff', 55000, previousDepartment: $this->dev)
            ->create(['first_name' => 'Tom', 'last_name' => 'Transfer', 'hire_date' => '1992-01-01']);

        $this->formerBoss = Employee::factory()->former($this->dev, 'Manager', '1995-06-01', 90000)
            ->create(['first_name' => 'Olga', 'last_name' => 'Oldboss', 'hire_date' => '1986-01-01']);

        DeptManager::create(['emp_no' => $this->formerBoss->emp_no, 'dept_no' => 'd005', 'from_date' => '1988-01-01', 'to_date' => '1995-01-01']);
        DeptManager::create(['emp_no' => $this->boss->emp_no, 'dept_no' => 'd005', 'from_date' => '1995-01-01', 'to_date' => '9999-01-01']);
    }

    public function test_admin_sees_every_department_with_figures_and_manager(): void
    {
        $response = $this->get(route('departments.index'))->assertOk();

        $response->assertSeeInOrder(['Development', 'd005', 'Sales', 'd007'])
            ->assertSee('Bella Boss')
            ->assertSee(route('employees.show', $this->boss->emp_no))
            ->assertSee('Avg. salary')
            ->assertSee('$86,667')
            ->assertSee('No current manager');

        $rows = $response->viewData('departments')->keyBy('dept_no');
        $this->assertSame(3, $rows['d005']->headcount);
        $this->assertSame(2, $rows['d005']->titles);
        $this->assertEqualsWithDelta(86666.67, $rows['d005']->avg_salary, 0.01);
        $this->assertSame($this->boss->emp_no, (int) $rows['d005']->manager->emp_no);
        $this->assertSame(2, $rows['d007']->headcount);
        $this->assertNull($rows['d007']->manager);
    }

    public function test_viewer_sees_departments_without_salaries(): void
    {
        $this->signInAs(Role::Viewer);

        $response = $this->get(route('departments.index'))->assertOk()
            ->assertSee('Development')
            ->assertSee('Sales')
            ->assertDontSee('Avg. salary')
            ->assertDontSee('$86,667');

        $this->assertNull($response->viewData('departments')->first()->avg_salary);
        $this->assertDontSeeCreateButton($response);
    }

    public function test_hr_sees_the_create_button_and_the_manager_forms(): void
    {
        $this->signInAs(Role::Hr);

        $this->get(route('departments.index'))->assertOk()->assertSee(route('departments.create'));
        $this->get(route('departments.show', $this->dev))->assertOk()
            ->assertSee('Assign a manager')
            ->assertSee('End management')
            ->assertSee(route('departments.edit', $this->dev));
    }

    public function test_manager_sees_only_their_own_department(): void
    {
        $this->signInAs(Role::Manager, $this->dev);

        $response = $this->get(route('departments.index'))->assertOk()
            ->assertSee('Development')
            ->assertDontSee('Sales')
            ->assertSee('$86,667');
        $this->assertSame(['d005'], $response->viewData('departments')->pluck('dept_no')->all());
        $this->assertDontSeeCreateButton($response);

        $this->get(route('departments.show', $this->dev))->assertOk()->assertDontSee('Assign a manager');
        $this->get(route('departments.show', $this->sales))->assertForbidden();
    }

    public function test_manager_without_department_sees_no_department(): void
    {
        $this->signInAs(Role::Manager, 'd999');

        $this->get(route('departments.index'))->assertOk()->assertSee('No department is assigned to your account.');
        $this->get(route('departments.show', $this->dev))->assertForbidden();
    }

    public function test_department_page_shows_statistics_managers_and_movements(): void
    {
        $response = $this->get(route('departments.show', $this->dev))->assertOk();

        $response->assertSee('Development')
            ->assertSee('Bella Boss')
            ->assertSeeInOrder(['Bella Boss', '01.01.1995', 'Present', 'Olga Oldboss', '01.01.1988', '01.01.1995'])
            ->assertSee('min $60,000')
            ->assertSee('max $120,000')
            ->assertSee('$86,667')
            ->assertSee('headcountChart')
            ->assertSee(route('employees.index', ['department' => 'd005']), false)
            ->assertSee('Left the company')
            ->assertSee('Moved to Sales')
            ->assertSee('Assign a manager');

        $this->assertSame(3, $response->viewData('headcount'));
        $this->assertSame(['M' => 2, 'F' => 1], $response->viewData('gender'));
        $this->assertSame(['Engineer' => 2, 'Manager' => 1], $response->viewData('titles')->pluck('total', 'title')->all());
        $this->assertSame($this->boss->emp_no, (int) $response->viewData('currentManager')->emp_no);
        $this->assertCount(2, $response->viewData('managerHistory'));

        $years = $response->viewData('headcountByYear')->keyBy('year');
        $this->assertSame(1986, $years->keys()->first());
        $this->assertSame(1, $years[1986]['headcount']);   // Olga
        $this->assertSame(2, $years[1990]['headcount']);   // + Bella
        $this->assertSame(4, $years[1992]['headcount']);   // + Lea (1991), Tom (1992)
        $this->assertSame(4, $years[1995]['headcount']);   // - Olga, + Ed
        $this->assertSame(5, $years[1996]['headcount']);   // + Finn
        $this->assertSame(4, $years[1997]['headcount']);   // - Tom (moved to Sales)
        $this->assertSame(2000, $years->keys()->last());   // last change: Lea left
        $this->assertSame(3, $years[2000]['headcount']);   // the three current employees

        $leavers = $response->viewData('recentLeavers')->keyBy('last_name');
        $this->assertNull($leavers['Leaver']->moved_to);
        $this->assertSame('Sales', $leavers['Transfer']->moved_to);
        $joiners = $response->viewData('recentJoiners')->keyBy('last_name');
        $this->assertTrue($joiners['Boss']->hired);
    }

    public function test_viewer_department_page_has_no_salary_figures(): void
    {
        $this->signInAs(Role::Viewer);

        $response = $this->get(route('departments.show', $this->dev))->assertOk()
            ->assertSee('Bella Boss')
            ->assertDontSee('Avg. salary')
            ->assertDontSee('$86,667')
            ->assertDontSee('max $120,000')
            ->assertDontSee('Assign a manager');

        $this->assertNull($response->viewData('salary'));
    }

    public function test_unknown_or_malformed_departments_are_not_found(): void
    {
        $this->get('/departments/d404')->assertNotFound();
        $this->get('/departments/abc')->assertNotFound();
        $this->get('/departments/D005')->assertNotFound();
    }

    public function test_department_without_employees_renders(): void
    {
        $empty = Department::factory()->create(['dept_no' => 'd010', 'dept_name' => 'Empty']);

        $this->get(route('departments.show', $empty))->assertOk()
            ->assertSee('No employees yet')
            ->assertSee('The department has never had a manager.')
            ->assertDontSee('headcountChart');
    }

    public function test_navbar_links_the_departments_for_every_role(): void
    {
        foreach ([Role::Admin, Role::Hr, Role::Viewer] as $role) {
            $this->signInAs($role);
            $this->get(route('index'))->assertOk()
                ->assertSee(route('departments.index'))
                ->assertSeeInOrder(['All Employees', 'Departments']);
        }

        $this->signInAs(Role::Manager, $this->dev);
        $this->get(route('index'))->assertOk()->assertSee(route('departments.index'));
    }

    private function assertDontSeeCreateButton($response): void
    {
        $response->assertDontSee(route('departments.create'));
    }
}
