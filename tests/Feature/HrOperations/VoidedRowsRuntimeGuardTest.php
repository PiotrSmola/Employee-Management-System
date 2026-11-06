<?php

namespace Tests\Feature\HrOperations;

use App\Enums\Role;
use App\Livewire\EmployeeFilter;
use App\Livewire\EmployeeList;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\EmployeeOperationsService;
use App\Services\EmployeeProfileService;
use App\Support\EmployeeVisibility;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runtime guard: runs the read paths of the application with DB::listen and fails
 * for SQL that touches a history table without excluding voided rows. Every
 * reference to one of the tables has to come with its own void_log_id condition.
 *
 * A new screen, report or API adds one line to readPaths(); at integration:
 * departments, reports, data tools and the API.
 */
class VoidedRowsRuntimeGuardTest extends HrOperationsTestCase
{
    private const TABLES = 'dept_emp|dept_manager|titles|salaries';

    private Department $sales;

    private Department $research;

    private Employee $employee;

    private Employee $former;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sales = Department::factory()->create(['dept_name' => 'Sales']);
        $this->research = Department::factory()->create(['dept_name' => 'Research']);
        $this->employee = $this->currentEmployee($this->sales, 'Engineer', 60000);
        $this->former = $this->formerEmployee($this->sales);
        $this->makeManager($this->employee, $this->sales->dept_no);
        Cache::flush();
    }

    /**
     * @return array<string, array{Closure(self): void}>
     */
    public static function readPaths(): array
    {
        $directory = fn (array $filters) => fn (self $t) => $t->readDirectory($filters);

        $paths = [
            'directory page' => [fn (self $t) => $t->get(route('employees.index'))->assertOk()],
            'directory list component' => [fn (self $t) => Livewire::test(EmployeeList::class)->assertOk()],
            'directory filter component' => [fn (self $t) => Livewire::test(EmployeeFilter::class)->assertOk()],
            'directory filter component, manager' => [function (self $t) {
                $t->signInAs(Role::Manager, $t->salesDepartment());
                Livewire::test(EmployeeFilter::class)->assertOk();
            }],
            'status count, current' => [$directory(['employmentStatus' => 'current'])],
            'status count, former' => [$directory(['employmentStatus' => 'former'])],
            'department' => [fn (self $t) => $t->readDirectory(['department' => $t->salesDepartment()->dept_no])],
            'department and status' => [fn (self $t) => $t->readDirectory(['department' => $t->salesDepartment()->dept_no, 'employmentStatus' => 'former'])],
            'job title' => [$directory(['jobTitle' => 'Engineer'])],
            'salary range' => [$directory(['salaryMin' => 1000, 'salaryMax' => 90000])],
            'search and every filter' => [fn (self $t) => $t->readDirectory([
                'search' => 'Anna', 'gender' => 'F', 'employmentStatus' => 'current', 'department' => $t->salesDepartment()->dept_no,
                'jobTitle' => 'Engineer', 'salaryMin' => 1000, 'salaryMax' => 90000,
            ])],
            'manager scope' => [function (self $t) {
                $visibility = EmployeeVisibility::for(User::factory()->manager($t->salesDepartment())->create());
                $visibility->query(['sortBy' => 'salary'])->ids();
                $visibility->query()->count();
                $visibility->canView($t->formerEmployeeRecord());
                $visibility->constrain(DB::table('employees'))->count();
            }],
            'profile page' => [fn (self $t) => $t->get(route('employees.show', $t->employeeRecord()->emp_no))->assertOk()],
            'former profile page' => [fn (self $t) => $t->get(route('employees.show', $t->formerEmployeeRecord()->emp_no))->assertOk()],
            'profile page, viewer' => [function (self $t) {
                $t->signInAs(Role::Viewer);
                $t->get(route('employees.show', $t->employeeRecord()->emp_no))->assertOk();
            }],
            'profile service' => [fn (self $t) => app(EmployeeProfileService::class)->build($t->employeeRecord()->emp_no)],
            'profile pdf' => [fn (self $t) => $t->get(route('employees.export-show-pdf', $t->employeeRecord()->emp_no))->assertOk()],
            'csv export' => [fn (self $t) => $t->post(route('employees.export'), ['format' => 'csv', 'selectAll' => '1'])->assertOk()->streamedContent()],
            'pdf export' => [fn (self $t) => $t->post(route('employees.export'), ['format' => 'pdf', 'selectAll' => '1'])->assertOk()],
            'dashboard page' => [fn (self $t) => $t->get(route('index'))->assertOk()],
            'company dashboard' => [fn () => app(DashboardService::class)->build(EmployeeVisibility::unrestricted())],
            'department dashboard' => [fn (self $t) => app(DashboardService::class)->build(EmployeeVisibility::for(User::factory()->manager($t->salesDepartment())->create()))],
            'models and relations' => [function (self $t) {
                $employee = Employee::with(['departments', 'currentDepartment', 'titles', 'currentTitle', 'salaries', 'currentSalary'])->find($t->employeeRecord()->emp_no);
                $employee->departments()->count();
                $t->salesDepartment()->employees()->count();
                Employee::active()->count();
            }],
            'hire form' => [fn (self $t) => $t->get(route('employees.create'))->assertOk()],
            'hr form data' => [fn (self $t) => app(EmployeeOperationsService::class)->formContext($t->employeeRecord())],
            'hr operations and undo' => [function (self $t) {
                $operations = app(EmployeeOperationsService::class);
                $employee = $t->employeeRecord();
                $operations->changeTitle($employee, 'Lead', '2023-01-01', 'promotion');
                $operations->changeSalary($employee, 61000, null, '2023-01-01');
                $operations->transfer($employee, $t->researchDepartment()->dept_no, '2023-02-01');
                $operations->undoLastChange($employee, ActivityLog::query()->latestFirst()->value('id'));
                $operations->terminate($employee, '2023-03-01');
                $operations->rehire($employee, $t->researchDepartment()->dept_no, 'Staff', 40000, '2023-04-01');
                $operations->correctLatestSalary($employee, 41000, 'Typo', '2023-04-01', 40000);
                $operations->correctLatestTitle($employee, 'Staff II', 'Typo', '2023-04-01', 'Staff');
            }],
        ];

        foreach (['name', 'hire_date', 'gender', 'department', 'title', 'salary'] as $sortBy) {
            foreach (['asc', 'desc'] as $direction) {
                $paths["sorted by {$sortBy} {$direction}"] = [$directory(['sortBy' => $sortBy, 'sortDirection' => $direction])];
            }
        }

        return $paths;
    }

    #[DataProvider('readPaths')]
    public function test_every_query_of_the_read_path_excludes_voided_rows(Closure $path): void
    {
        [$touched, $violations] = $this->record(fn () => $path($this));

        $this->assertGreaterThan(0, $touched, 'The path never touched a history table: it checks nothing.');
        $this->assertSame([], $violations);
    }

    public function test_the_check_notices_a_forgotten_filter(): void
    {
        foreach ([
            'select * from `salaries`',
            'select * from `titles` as `t` where `t`.`void_log_id` = 0 and exists (select 1 from `salaries` as `s`)',
            'select * from `salaries` where `from_date` > (select max(`from_date`) from `salaries` where `void_log_id` = 0)',
            'update `dept_emp` set `to_date` = 1',
            'select 1 from dept_manager',
        ] as $sql) {
            $this->assertFalse($this->excludesVoidedRows($sql), $sql);
        }

        foreach ([
            'select * from `salaries` where `void_log_id` = 0',
            'select * from `employees` left join `titles` as `t` on `t`.`void_log_id` = 0',
            'insert into `salaries` (`emp_no`) values (1)',
            'select * from `employees`',
        ] as $sql) {
            $this->assertTrue($this->excludesVoidedRows($sql), $sql);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function readDirectory(array $filters): void
    {
        $query = EmployeeVisibility::unrestricted()->query($filters);

        $ids = $query->ids();
        $query->ids(10, 0, count($ids));
        $query->count();
        $query->paginate(20, 1);
        $query->rowsFor([$this->employee->emp_no, $this->former->emp_no]);
    }

    public function employeeRecord(): Employee
    {
        return Employee::find($this->employee->emp_no);
    }

    public function formerEmployeeRecord(): Employee
    {
        return Employee::find($this->former->emp_no);
    }

    public function salesDepartment(): Department
    {
        return $this->sales;
    }

    public function researchDepartment(): Department
    {
        return $this->research;
    }

    /**
     * Run $path and collect what it sent to the database.
     *
     * @return array{int, string[]} queries that touched a history table, the ones without the filter
     */
    private function record(Closure $path): array
    {
        $touched = 0;
        $violations = [];

        DB::listen(function (QueryExecuted $query) use (&$touched, &$violations) {
            if ($this->historyReferences($query->sql) > 0) {
                $touched++;

                if (! $this->excludesVoidedRows($query->sql)) {
                    $violations[] = $query->sql;
                }
            }
        });

        $path();

        return [$touched, $violations];
    }

    private function excludesVoidedRows(string $sql): bool
    {
        if (preg_match('/^\s*insert\b/i', $sql)) {
            return true;
        }

        return substr_count(strtolower($sql), 'void_log_id') >= $this->historyReferences($sql);
    }

    private function historyReferences(string $sql): int
    {
        return (int) preg_match_all('/\b(?:from|join|update)\s+`?(?:'.self::TABLES.')`?(?=[\s,)]|$)/i', $sql);
    }
}
