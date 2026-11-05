<?php

namespace Tests\Feature\Database;

use App\Models\Department;
use App\Models\Employee;
use App\Queries\EmployeeSearchQuery;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_nine_departments_and_the_requested_number_of_employees(): void
    {
        (new DemoDataSeeder)->run(40);

        $this->assertSame(9, Department::count());
        $this->assertEqualsCanonicalizing(
            ['Marketing', 'Finance', 'Human Resources', 'Production', 'Development', 'Quality Management', 'Sales', 'Research', 'Customer Service'],
            Department::pluck('dept_name')->all()
        );
        $this->assertSame(40, Employee::count());
    }

    public function test_every_department_has_exactly_one_current_manager_who_works_there(): void
    {
        (new DemoDataSeeder)->run(30);

        $managers = DB::table('dept_manager')->get();
        $this->assertCount(9, $managers);
        $this->assertCount(9, $managers->pluck('dept_no')->unique());
        $this->assertSame(['9999-01-01'], $managers->pluck('to_date')->unique()->values()->all());

        foreach ($managers as $manager) {
            $this->assertTrue(
                DB::table('dept_emp')->where(['emp_no' => $manager->emp_no, 'dept_no' => $manager->dept_no, 'to_date' => '9999-01-01'])->exists(),
                'manager works in the department they manage'
            );
            $this->assertSame('Manager', DB::table('titles')->where(['emp_no' => $manager->emp_no, 'to_date' => '9999-01-01'])->value('title'));
            $this->assertGreaterThanOrEqual($manager->from_date, $manager->to_date);
        }
    }

    public function test_every_employee_has_a_consistent_history(): void
    {
        (new DemoDataSeeder)->run(60);

        foreach (Employee::all() as $employee) {
            $deptRows = DB::table('dept_emp')->where('emp_no', $employee->emp_no)->orderBy('from_date')->get();
            $titleRows = DB::table('titles')->where('emp_no', $employee->emp_no)->orderBy('from_date')->get();
            $salaryRows = DB::table('salaries')->where('emp_no', $employee->emp_no)->orderBy('from_date')->get();

            $this->assertNotEmpty($deptRows, "employee {$employee->emp_no} has a department");
            $this->assertNotEmpty($titleRows, "employee {$employee->emp_no} has a title");
            $this->assertNotEmpty($salaryRows, "employee {$employee->emp_no} has a salary");

            // histories start at the hire date, periods are well formed and non overlapping
            $this->assertSame($employee->hire_date, $deptRows->first()->from_date);
            $this->assertSame($employee->hire_date, $titleRows->first()->from_date);
            $this->assertSame($employee->hire_date, $salaryRows->first()->from_date);

            foreach ([$deptRows, $titleRows, $salaryRows] as $rows) {
                foreach ($rows as $i => $row) {
                    $this->assertGreaterThanOrEqual($row->from_date, $row->to_date);
                    if ($i > 0) {
                        $this->assertSame($rows[$i - 1]->to_date, $row->from_date, 'periods are contiguous');
                    }
                }
            }

            // current <=> everything ends on 9999-01-01 together; former <=> nothing does
            $current = $deptRows->last()->to_date === '9999-01-01';
            $this->assertSame($current, $titleRows->last()->to_date === '9999-01-01');
            $this->assertSame($current, $salaryRows->last()->to_date === '9999-01-01');
            $this->assertSame($current ? 1 : 0, $salaryRows->where('to_date', '9999-01-01')->count());
            $this->assertSame($current ? 1 : 0, $deptRows->where('to_date', '9999-01-01')->count());
        }
    }

    public function test_most_employees_are_current_and_some_are_former(): void
    {
        (new DemoDataSeeder)->run(120);

        $current = EmployeeSearchQuery::make(['employmentStatus' => 'current'])->count();
        $former = EmployeeSearchQuery::make(['employmentStatus' => 'former'])->count();

        $this->assertSame(120, $current + $former);
        $this->assertGreaterThan($former, $current);
        $this->assertGreaterThan(0, $former);
    }

    public function test_seeded_data_works_with_the_directory_query(): void
    {
        (new DemoDataSeeder)->run(30);

        $query = EmployeeSearchQuery::make(['sortBy' => 'salary', 'sortDirection' => 'desc']);

        $this->assertSame(30, $query->count());
        $this->assertCount(30, $query->ids());
        $this->assertSame($query->ids(), $query->ids(null, 0, 30), 'both readers agree on the seeded data');
        $this->assertSame(30, EmployeeSearchQuery::make(['department' => 'd005'])->count()
            + collect(['d001', 'd002', 'd003', 'd004', 'd006', 'd007', 'd008', 'd009'])
                ->sum(fn ($d) => EmployeeSearchQuery::make(['department' => $d])->count()), 'every employee belongs to exactly one department');
    }

    public function test_existing_departments_are_reused(): void
    {
        Department::factory()->create(['dept_no' => 'd001', 'dept_name' => 'Marketing']);

        (new DemoDataSeeder)->run(12);

        $this->assertSame(9, Department::count());
        $this->assertSame(12, Employee::count());
    }

    public function test_database_seeder_seeds_an_empty_database(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(200, Employee::count());
        $this->assertSame(9, Department::count());
    }

    public function test_database_seeder_leaves_a_populated_database_alone(): void
    {
        $existing = Employee::factory()->current()->create();
        $employees = Employee::count();
        $departments = Department::count();
        $salaries = DB::table('salaries')->count();

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($employees, Employee::count());
        $this->assertSame($departments, Department::count());
        $this->assertSame($salaries, DB::table('salaries')->count());
        $this->assertTrue(Employee::whereKey($existing->emp_no)->exists());
    }
}
