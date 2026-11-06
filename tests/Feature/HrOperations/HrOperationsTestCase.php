<?php

namespace Tests\Feature\HrOperations;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\DeptManager;
use App\Models\Employee;
use App\Support\EmployeeDataCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/**
 * Shared fixtures of the HR operation tests (signed in as an administrator).
 *
 * A current employee from currentEmployee() was hired on 1995-03-01; the
 * department and title rows start that day, the current salary row on
 * 2002-03-01 (yearly salary rows, EmployeeFactory).
 */
abstract class HrOperationsTestCase extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    protected const HIRED = '1995-03-01';

    protected const CURRENT_SALARY_FROM = '2002-03-01';

    protected const CURRENT = '9999-01-01';

    protected function currentEmployee(?Department $department = null, string $title = 'Engineer', int $salary = 60000, ?Department $previousDepartment = null): Employee
    {
        return Employee::factory()
            ->current($department ?? Department::factory()->create(), $title, $salary, previousDepartment: $previousDepartment)
            ->create(['hire_date' => self::HIRED, 'birth_date' => '1965-05-20', 'first_name' => 'Anna', 'last_name' => 'Nowak']);
    }

    protected function formerEmployee(Department $department, string $endDate = '2000-06-30'): Employee
    {
        return Employee::factory()
            ->former($department, 'Staff', endDate: $endDate, salary: 50000)
            ->create(['hire_date' => self::HIRED, 'birth_date' => '1965-05-20', 'first_name' => 'Jan', 'last_name' => 'Kowalski']);
    }

    protected function makeManager(Employee $employee, string $deptNo, string $from = '1998-01-01'): void
    {
        DeptManager::create(['emp_no' => $employee->emp_no, 'dept_no' => $deptNo, 'from_date' => $from, 'to_date' => self::CURRENT]);
    }

    /**
     * Active rows of $table for the employee, oldest first, as arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rows(string $table, int $empNo, array $columns = ['*']): array
    {
        return DB::table($table)->where('emp_no', $empNo)->where('void_log_id', 0)->orderBy('from_date')->get($columns)
            ->map(fn ($row) => (array) $row)->all();
    }

    protected function current(string $table, int $empNo): ?object
    {
        return DB::table($table)->where('emp_no', $empNo)->where('void_log_id', 0)->where('to_date', self::CURRENT)->first();
    }

    /**
     * The only audit entry with $action (fails when there is none or more than one).
     */
    /**
     * The only audit entry with $action (fails when there is none or more than one);
     * `changes` without the internal undo data.
     */
    protected function onlyEntry(string $action): ActivityLog
    {
        $entries = ActivityLog::where('action', $action)->get();
        $this->assertCount(1, $entries, "Expected exactly one {$action} entry.");

        $entry = $entries->first();
        $entry->setAttribute('changes', $this->withoutUndo($entry->changes));

        return $entry;
    }

    /**
     * Put a value under every cache key of $departments (and the company-wide keys).
     *
     * @param  string[]  $departments
     */
    protected function primeCache(array $departments): void
    {
        foreach (EmployeeDataCache::keys($departments) as $key) {
            Cache::put($key, ['cached']);
        }
    }

    /**
     * @param  string[]  $departments
     */
    protected function assertCacheFlushed(array $departments): void
    {
        foreach (EmployeeDataCache::keys($departments) as $key) {
            $this->assertFalse(Cache::has($key), "{$key} should have been flushed.");
        }
    }

    /**
     * @param  string[]  $departments
     */
    protected function assertCacheKept(array $departments): void
    {
        foreach ($departments as $deptNo) {
            $this->assertTrue(Cache::has(EmployeeDataCache::departmentStats($deptNo)), "Cache of {$deptNo} should be kept.");
        }
    }

    /**
     * The audit `changes` without the internal undo data.
     *
     * @param  array<string, mixed>|null  $changes
     * @return array<string, mixed>|null
     */
    protected function withoutUndo(?array $changes): ?array
    {
        unset($changes['_undo']);

        return $changes;
    }

    /**
     * The hidden fields of the salary correction form: the latest row it shows.
     *
     * @return array<string, mixed>
     */
    public function salaryRow(Employee $employee): array
    {
        $row = DB::table('salaries')->where('emp_no', $employee->emp_no)->where('void_log_id', 0)->orderByDesc('from_date')->first();

        return ['row_from_date' => $row->from_date, 'row_salary' => $row->salary];
    }

    /**
     * The hidden fields of the title correction form: the latest row it shows.
     *
     * @return array<string, mixed>
     */
    public function titleRow(Employee $employee): array
    {
        $row = DB::table('titles')->where('emp_no', $employee->emp_no)->where('void_log_id', 0)->orderByDesc('from_date')->first();

        return ['row_from_date' => $row->from_date, 'row_title' => $row->title];
    }

    protected function profile(Employee $employee): string
    {
        return route('employees.show', $employee->emp_no);
    }
}
