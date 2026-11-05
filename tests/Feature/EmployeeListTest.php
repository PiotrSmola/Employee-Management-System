<?php

namespace Tests\Feature;

use App\Livewire\EmployeeList;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class EmployeeListTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_employee_index_page_renders_the_livewire_components(): void
    {
        $employee = Employee::factory()->current()->create();

        $this->get(route('employees.index'))
            ->assertOk()
            ->assertSeeLivewire('employee-filter')
            ->assertSeeLivewire('employee-list')
            ->assertSee($employee->last_name);
    }

    public function test_list_filters_by_department_including_former_employees_last_department(): void
    {
        $sales = Department::factory()->create(['dept_name' => 'Sales']);
        $finance = Department::factory()->create(['dept_name' => 'Finance']);

        $inSales = Employee::factory()->current($sales)->create(['last_name' => 'CurrentSales']);
        $leftSales = Employee::factory()->former($sales, previousDepartment: $finance)->create(['last_name' => 'FormerSales']);
        $inFinance = Employee::factory()->current($finance, previousDepartment: $sales)->create(['last_name' => 'NowFinance']);

        Livewire::test(EmployeeList::class)
            ->assertSee([$inSales->last_name, $leftSales->last_name, $inFinance->last_name])
            ->call('updateFilters', [
                'search' => '', 'employmentStatus' => 'all', 'gender' => 'all', 'department' => $sales->dept_no,
                'salaryMin' => '', 'salaryMax' => '', 'jobTitle' => 'all',
            ])
            ->assertSee([$inSales->last_name, $leftSales->last_name])
            ->assertDontSee($inFinance->last_name)
            ->assertSee('2 employee(s) found')
            ->assertSee('Former Employee - Sales');
    }

    public function test_sorting_by_department_uses_last_department_of_former_employees(): void
    {
        $alpha = Department::factory()->create(['dept_name' => 'Alpha']);
        $omega = Department::factory()->create(['dept_name' => 'Omega']);

        $omegaCurrent = Employee::factory()->current($omega)->create();
        $alphaFormer = Employee::factory()->former($alpha, previousDepartment: $omega)->create();

        $component = Livewire::test(EmployeeList::class)->call('sortByField', 'department');

        $this->assertSame(
            [$alphaFormer->emp_no, $omegaCurrent->emp_no],
            collect($component->viewData('employees')->items())->pluck('emp_no')->all()
        );

        $component->call('sortByField', 'department');

        $this->assertSame(
            [$omegaCurrent->emp_no, $alphaFormer->emp_no],
            collect($component->viewData('employees')->items())->pluck('emp_no')->all()
        );
    }

    public function test_employment_status_filter_counts_employees_without_current_department_as_former(): void
    {
        Employee::factory()->count(2)->current()->create(['gender' => 'F']);
        Employee::factory()->former()->create(['gender' => 'F']);
        Employee::factory()->create(['gender' => 'F']); // no department history at all

        $filters = fn (string $status, string $gender = 'all') => [
            'search' => '', 'employmentStatus' => $status, 'gender' => $gender, 'department' => 'all',
            'salaryMin' => '', 'salaryMax' => '', 'jobTitle' => 'all',
        ];

        Livewire::test(EmployeeList::class)
            ->call('updateFilters', $filters('current'))
            ->assertSee('2 employee(s) found')
            ->call('updateFilters', $filters('former'))
            ->assertSee('2 employee(s) found')
            ->call('updateFilters', $filters('former', 'F'))
            ->assertSee('2 employee(s) found');
    }

    public function test_selection_count_follows_select_all_and_exclusions(): void
    {
        $employees = Employee::factory()->count(3)->current()->create();

        Livewire::test(EmployeeList::class)
            ->assertSee('Selected 3 of 3 records')
            ->call('toggleEmployee', $employees[0]->emp_no)
            ->assertSee('Selected 2 of 3 records')
            ->set('selectAll', false)
            ->assertSee('Selected 0 of 3 records')
            ->call('toggleEmployee', $employees[1]->emp_no)
            ->assertSee('Selected 1 of 3 records');
    }
}
