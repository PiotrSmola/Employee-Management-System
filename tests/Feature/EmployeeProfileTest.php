<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class EmployeeProfileTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_profile_shows_current_employment_and_history(): void
    {
        $research = Department::factory()->create(['dept_name' => 'Research']);
        $employee = Employee::factory()
            ->current($research, 'Senior Engineer', 85000, previousTitle: 'Engineer')
            ->create();

        $this->get(route('employees.show', $employee->emp_no))
            ->assertOk()
            ->assertSee($employee->first_name.' '.$employee->last_name)
            ->assertSee('Research')
            ->assertSee('Senior Engineer')
            ->assertSee('85,000')
            ->assertViewHas('stats', fn (array $stats) => $stats['isCurrentEmployee'] === true
                && $stats['titleChanges'] === 2);
    }

    public function test_profile_of_former_employee_shows_end_date(): void
    {
        $employee = Employee::factory()->former(endDate: '1999-06-30')->create(['hire_date' => '1990-01-01']);

        $this->get(route('employees.show', $employee->emp_no))
            ->assertOk()
            ->assertSee('Former Employee')
            ->assertSee('30.06.1999');
    }

    public function test_unknown_employee_returns_404(): void
    {
        $this->get(route('employees.show', 999999))->assertNotFound();
    }
}
