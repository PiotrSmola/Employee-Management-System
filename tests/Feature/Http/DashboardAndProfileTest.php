<?php

namespace Tests\Feature\Http;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class DashboardAndProfileTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    // -------------------------------------------------------------- dashboard

    public function test_dashboard_renders_on_an_empty_database(): void
    {
        $this->get(route('index'))
            ->assertOk()
            ->assertViewHas('totalEmployees', 0)
            ->assertViewHas('totalDepartments', 0)
            ->assertViewHas('recentEmployees', fn ($c) => $c->isEmpty())
            ->assertViewHas('employeesByDepartment', fn ($c) => $c->isEmpty());
    }

    public function test_average_salary_only_counts_current_salaries(): void
    {
        $dept = Department::factory()->create();
        Employee::factory()->current($dept, 'Staff', 50000)->create();
        Employee::factory()->current($dept, 'Staff', 70000)->create();
        Employee::factory()->former($dept, 'Staff', salary: 200000)->create(); // no current salary row

        $this->get(route('index'))
            ->assertOk()
            ->assertViewHas('totalEmployees', 3)
            ->assertViewHas('averageSalary', fn ($avg) => (float) $avg === 60000.0);
    }

    public function test_employees_by_department_counts_current_assignments_biggest_first(): void
    {
        $big = Department::factory()->create(['dept_name' => 'Big']);
        $small = Department::factory()->create(['dept_name' => 'Small']);
        $empty = Department::factory()->create(['dept_name' => 'Empty']);

        Employee::factory()->count(3)->current($big)->create();
        Employee::factory()->count(1)->current($small)->create();
        Employee::factory()->count(2)->former($empty)->create(); // former employees do not count
        Employee::factory()->current($small, previousDepartment: $big)->create(); // only the current department counts

        $this->get(route('index'))
            ->assertOk()
            ->assertViewHas('totalDepartments', 3)
            ->assertViewHas('employeesByDepartment', function ($rows) {
                return $rows->pluck('dept_name')->all() === ['Big', 'Small']
                    && $rows->pluck('total')->map(fn ($n) => (int) $n)->all() === [3, 2];
            });
    }

    public function test_employees_by_department_is_limited_to_eight_departments(): void
    {
        foreach (range(1, 10) as $i) {
            Employee::factory()->current(Department::factory()->create(['dept_name' => "Dept $i"]))->create();
        }

        $this->get(route('index'))
            ->assertViewHas('totalDepartments', 10)
            ->assertViewHas('employeesByDepartment', fn ($rows) => $rows->count() === 8);
    }

    public function test_recent_employees_are_the_seven_latest_hires(): void
    {
        foreach (range(1, 9) as $month) {
            Employee::factory()->create([
                'emp_no' => $month,
                'hire_date' => sprintf('2001-%02d-01', $month),
                'last_name' => 'Hired'.$month,
            ]);
        }

        $this->get(route('index'))
            ->assertViewHas('recentEmployees', function ($rows) {
                return $rows->pluck('emp_no')->map(fn ($n) => (int) $n)->all() === [9, 8, 7, 6, 5, 4, 3];
            });
    }

    public function test_dashboard_statistics_are_cached(): void
    {
        $dept = Department::factory()->create();
        Employee::factory()->current($dept)->create();

        $this->get(route('index'))->assertViewHas('totalEmployees', 1);
        $this->assertTrue(Cache::has('dashboard_stats'));
        $this->assertTrue(Cache::has('employees_by_department'));

        Employee::factory()->current($dept)->create();

        // stale on purpose (15 minute cache) ...
        $this->get(route('index'))->assertViewHas('totalEmployees', 1);

        // ... until the cache is cleared
        Cache::forget('dashboard_stats');
        $this->get(route('index'))->assertViewHas('totalEmployees', 2);
    }

    // ---------------------------------------------------------------- profile

    public function test_profile_of_an_unknown_or_malformed_employee_number_is_404(): void
    {
        $this->get('/employees/999999')->assertNotFound();
        $this->get('/employees/abc')->assertNotFound();
        $this->get('/employees/999999/export-pdf')->assertNotFound();
    }

    public function test_profile_page_shows_name_stats_and_history_tables(): void
    {
        $sales = Department::factory()->create(['dept_name' => 'Sales']);
        $research = Department::factory()->create(['dept_name' => 'Research']);
        $employee = Employee::factory()
            ->current($sales, 'Senior Staff', 65000, previousDepartment: $research, previousTitle: 'Staff')
            ->create(['first_name' => 'Zelda', 'last_name' => 'Fitzgerald', 'gender' => 'F']);

        $this->get(route('employees.show', $employee->emp_no))
            ->assertOk()
            ->assertSee('Zelda Fitzgerald')
            ->assertSee('Sales')
            ->assertSee('Research')
            ->assertSee('Staff')
            ->assertSee('Senior Staff')
            ->assertViewHas('employee', fn ($e) => $e->emp_no === $employee->emp_no)
            ->assertViewHas('departmentHistory', fn ($h) => $h->count() === 2)
            ->assertViewHas('titleHistory', fn ($h) => $h->count() === 2)
            ->assertViewHas('combinedHistory', fn ($h) => $h->count() === $h->unique('from_date')->count())
            ->assertViewHas('salaryChartData')
            ->assertViewHas('stats', fn ($s) => $s['isCurrentEmployee'] && $s['departmentChanges'] === 2);
    }

    public function test_profile_of_an_employee_without_any_history_still_renders(): void
    {
        $employee = Employee::factory()->create(['first_name' => 'Nobody', 'last_name' => 'Special']);

        $this->get(route('employees.show', $employee->emp_no))
            ->assertOk()
            ->assertSee('Nobody Special')
            ->assertViewHas('stats', fn ($s) => $s['isCurrentEmployee'] === false && $s['salaryChanges'] === 0);
    }

    public function test_profile_pdf_is_downloaded_with_a_descriptive_filename(): void
    {
        $employee = Employee::factory()->current(null, 'Engineer', 55000)
            ->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $response = $this->get(route('employees.export-show-pdf', $employee->emp_no));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString(
            'employee_details_'.$employee->emp_no.'_Ada_Lovelace.pdf',
            $response->headers->get('Content-Disposition')
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_profile_pdf_of_a_former_employee_and_of_an_employee_without_history(): void
    {
        $former = Employee::factory()->former(null, 'Staff', '1999-06-30')->create();
        $bare = Employee::factory()->create();

        foreach ([$former, $bare] as $employee) {
            $response = $this->get(route('employees.export-show-pdf', $employee->emp_no));
            $response->assertOk();
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }
}
