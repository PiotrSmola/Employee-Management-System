<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_statistics_and_recent_hires(): void
    {
        $department = Department::factory()->create(['dept_name' => 'Development']);
        Employee::factory()->current($department, 'Engineer', 50000)->create();
        Employee::factory()->current($department, 'Engineer', 70000)->create();
        $newest = Employee::factory()->current($department)->create(['hire_date' => '2000-01-15', 'last_name' => 'Newcomer']);

        $this->get(route('index'))
            ->assertOk()
            ->assertViewHas('totalEmployees', 3)
            ->assertViewHas('totalDepartments', 1)
            ->assertSee('Development')
            ->assertSee($newest->last_name);
    }
}
