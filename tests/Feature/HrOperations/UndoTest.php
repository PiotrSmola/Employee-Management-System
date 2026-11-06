<?php

namespace Tests\Feature\HrOperations;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Services\AuditLogger;
use App\Services\DepartmentManagementService;
use App\Services\DepartmentStatistics;
use App\Services\EmployeeOperationsService;
use App\Support\AuditLogPresenter;
use App\Support\EmployeeDataCache;
use App\Support\FigureCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class UndoTest extends HrOperationsTestCase
{
    /**
     * Everything stored about the employee, to compare before / after.
     *
     * @return array<string, mixed>
     */
    private function records(int $empNo): array
    {
        $records = ['employees' => DB::table('employees')->where('emp_no', $empNo)->get()->map(fn ($r) => (array) $r)->all()];
        foreach (['dept_emp', 'titles', 'salaries', 'dept_manager'] as $table) {
            $records[$table] = $this->rows($table, $empNo);
        }

        return $records;
    }

    private function latestEntryId(): int
    {
        return ActivityLog::query()->latestFirst()->value('id');
    }

    private function undo(Employee $employee, ?int $entryId = null)
    {
        return $this->post(route('employees.undo', $employee->emp_no), ['entry_id' => $entryId ?? $this->latestEntryId()]);
    }

    /**
     * @return array<string, array{\Closure(self, Employee, Department): void, string}>
     */
    public static function operationProvider(): array
    {
        return [
            'transfer as manager' => [function (self $t, Employee $e, Department $other) {
                $t->post(route('employees.transfer', $e->emp_no), ['dept_no' => $other->dept_no, 'effective_date' => '2024-05-01']);
            }, 'employee.transferred'],
            'promotion' => [function (self $t, Employee $e) {
                $t->post(route('employees.title', $e->emp_no), ['title' => 'Senior Engineer', 'kind' => 'promotion', 'effective_date' => '2024-01-01']);
            }, 'employee.promoted'],
            'salary change' => [function (self $t, Employee $e) {
                $t->post(route('employees.salary', $e->emp_no), ['mode' => 'percent', 'percent' => '5', 'shown_salary' => '60000', 'effective_date' => '2024-01-01']);
            }, 'employee.salary_changed'],
            'termination' => [function (self $t, Employee $e) {
                $t->post(route('employees.terminate', $e->emp_no), ['end_date' => '2024-09-30', 'reason' => 'Mistake']);
            }, 'employee.terminated'],
            'personal data' => [function (self $t, Employee $e) {
                $t->put(route('employees.update', $e->emp_no), [
                    'first_name' => 'Annie', 'last_name' => 'Nowak', 'gender' => 'F', 'birth_date' => '1965-05-21', 'hire_date' => '1995-02-01',
                    'snapshot' => EmployeeOperationsService::personalSnapshot($e),
                ]);
            }, 'employee.updated'],
            'salary correction' => [function (self $t, Employee $e) {
                $t->put(route('employees.salary.correct', $e->emp_no), ['salary' => '61000', 'reason' => 'Typo', ...$t->salaryRow($e)]);
            }, 'employee.salary_corrected'],
            'title correction' => [function (self $t, Employee $e) {
                $t->put(route('employees.title.correct', $e->emp_no), ['title' => 'Engineer II', 'reason' => 'Typo', ...$t->titleRow($e)]);
            }, 'employee.title_corrected'],
        ];
    }

    #[DataProvider('operationProvider')]
    public function test_the_last_operation_is_reversed_exactly(\Closure $operation, string $action): void
    {
        $department = Department::factory()->create();
        $other = Department::factory()->create();
        $employee = $this->currentEmployee($department);
        $this->makeManager($employee, $department->dept_no);
        $before = $this->records($employee->emp_no);

        $operation($this, $employee, $other);
        $entry = ActivityLog::where('action', $action)->sole();
        $this->assertNotSame($before, $this->records($employee->emp_no));
        $this->primeCache([$department->dept_no, $other->dept_no]);

        $this->get($this->profile($employee))->assertSee('Undo last change')->assertSee('name="entry_id" value="'.$entry->id.'"', false);

        $this->undo($employee, $entry->id)
            ->assertRedirect($this->profile($employee))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame($before, $this->records($employee->emp_no));
        $undone = $this->onlyEntry('employee.change_undone');
        $this->assertSame($entry->id, $undone->changes['undone_entry_id']);
        $this->assertEquals($entry->changes, ActivityLog::find($entry->id)->changes, 'The original entry stays untouched.');
        $this->assertSame($action, $undone->changes['undone_action']);
        $this->assertCacheFlushed([$department->dept_no]);

        // Only one level: the undo itself cannot be undone.
        $this->get($this->profile($employee))->assertDontSee('Undo last change');
        $this->undo($employee, $undone->id)->assertSessionHasErrorsIn('undo', ['undo' => 'The last change cannot be undone.']);
    }

    public function test_nothing_is_deleted_rows_are_voided_and_recorded(): void
    {
        $sales = Department::factory()->create(['dept_name' => 'Sales']);
        $research = Department::factory()->create(['dept_name' => 'Research']);
        $employee = $this->currentEmployee($sales, salary: 60000);
        $rowCounts = fn () => array_map(fn (string $t) => DB::table($t)->where('emp_no', $employee->emp_no)->count(), ['dept_emp', 'salaries', 'titles']);

        $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $research->dept_no, 'effective_date' => '2024-05-01'])->assertSessionHasNoErrors();
        $counts = $rowCounts();
        $this->undo($employee)->assertSessionHasNoErrors();

        $undo = ActivityLog::where('action', 'employee.change_undone')->sole();
        $this->assertSame($counts, $rowCounts(), 'No row is deleted.');
        $this->assertSame([$undo->id], DB::table('dept_emp')->where('emp_no', $employee->emp_no)->where('dept_no', $research->dept_no)->pluck('void_log_id')->all());
        $this->assertSame($sales->dept_no, $this->current('dept_emp', $employee->emp_no)->dept_no);
        $this->assertEquals([['table' => 'dept_emp', 'dept_no' => $research->dept_no, 'from_date' => '2024-05-01', 'to_date' => self::CURRENT]], $undo->changes['voided_rows']);
        $this->assertEquals([['table' => 'dept_emp', 'dept_no' => $sales->dept_no, 'from_date' => self::HIRED, 'previous_to_date' => '2024-05-01']], $undo->changes['reopened_rows']);
        $this->assertArrayNotHasKey('_undo', $undo->changes);
    }

    public function test_a_corrected_row_can_take_the_date_of_a_voided_one(): void
    {
        $employee = $this->currentEmployee(salary: 60000);
        $url = route('employees.salary', $employee->emp_no);

        $this->post($url, ['mode' => 'amount', 'salary' => '61000', 'effective_date' => '2024-01-01'])->assertSessionHasNoErrors();
        $this->undo($employee)->assertSessionHasNoErrors();
        $this->post($url, ['mode' => 'amount', 'salary' => '62000', 'effective_date' => '2024-01-01'])->assertSessionHasNoErrors();
        $this->undo($employee)->assertSessionHasNoErrors();
        $this->post($url, ['mode' => 'amount', 'salary' => '63000', 'effective_date' => '2024-01-01'])->assertSessionHasNoErrors();

        $this->assertSame(63000, $this->current('salaries', $employee->emp_no)->salary);
        $this->assertSame(3, DB::table('salaries')->where('emp_no', $employee->emp_no)->where('from_date', '2024-01-01')->count());
    }

    public function test_the_audit_log_shows_what_an_undo_did_without_salaries_for_those_not_allowed(): void
    {
        $employee = $this->currentEmployee(salary: 60000);
        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '61000', 'effective_date' => '2024-01-01'])->assertSessionHasNoErrors();
        $original = $this->latestEntryId();
        $this->undo($employee)->assertSessionHasNoErrors();
        $undo = $this->latestEntryId();

        foreach ([route('audit-log.index'), $this->profile($employee)] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Undone on')
                ->assertSee('(entry #'.$undo.')')
                ->assertSee('Voided records')
                ->assertSee('Salary 61,000, 01.01.2024 - present')
                ->assertSee('Reopened records')
                ->assertSee('end date 01.01.2024 removed');
        }

        // Voided rows are hidden from the profile's own history.
        $this->get($this->profile($employee))->assertViewHas('salaryHistory', fn ($rows) => ! $rows->contains('salary', 61000));

        $presenter = new AuditLogPresenter([], withSalaries: false);
        $text = collect($presenter->rows(ActivityLog::find($undo)->changes))->pluck('after')->implode(' ');
        $this->assertStringContainsString('Salary, 01.01.2024 - present', $text);
        $this->assertStringNotContainsString('61,000', $text);
        $this->assertNotNull($original);
    }

    public function test_a_rehire_is_undone(): void
    {
        $employee = $this->formerEmployee(Department::factory()->create());
        $before = $this->records($employee->emp_no);

        $this->post(route('employees.rehire', $employee->emp_no), ['dept_no' => Department::factory()->create()->dept_no, 'title' => 'Staff', 'salary' => '50000', 'effective_date' => '2024-01-01'])
            ->assertSessionHasNoErrors();
        $this->undo($employee)->assertSessionHasNoErrors();

        $this->assertSame($before, $this->records($employee->emp_no));
    }

    public function test_a_hire_cannot_be_undone(): void
    {
        $department = Department::factory()->create();
        $this->post(route('employees.store'), [
            'first_name' => 'Double', 'last_name' => 'Click', 'gender' => 'M', 'birth_date' => '1990-01-01', 'hire_date' => '2024-01-01',
            'dept_no' => $department->dept_no, 'title' => 'Staff', 'salary' => '40000', 'form_token' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $employee = Employee::sole();
        $entry = ActivityLog::where('action', 'employee.hired')->sole();
        $records = $this->records($employee->emp_no);

        $this->assertArrayNotHasKey('_undo', $entry->changes);
        $this->get($this->profile($employee))->assertOk()->assertDontSee('Undo last change')->assertDontSee('id="undoModal"', false);

        $this->undo($employee, $entry->id)->assertSessionHasErrorsIn('undo', [
            'undo' => 'A hire cannot be undone. Correct the personal data, or terminate the employment.',
        ]);

        $this->assertSame($records, $this->records($employee->emp_no));
        $this->assertSame(0, ActivityLog::where('action', 'employee.change_undone')->count());
    }

    public function test_a_hire_is_refused_even_with_forged_undo_data(): void
    {
        $employee = $this->currentEmployee();
        ActivityLog::create([
            'action' => 'employee.hired', 'subject_type' => 'employee', 'subject_id' => (string) $employee->emp_no,
            'changes' => ['_undo' => ['ops' => [['insert', 'salaries', ['emp_no' => $employee->emp_no, 'from_date' => self::CURRENT_SALARY_FROM]]], 'state' => 'x']],
            'created_at' => now(),
        ]);

        $this->undo($employee)->assertSessionHasErrorsIn('undo', ['undo']);

        $this->assertSame(1, Employee::whereKey($employee->emp_no)->count());
        $this->assertNotNull($this->current('salaries', $employee->emp_no));
    }

    public function test_the_operation_after_a_hire_can_be_undone(): void
    {
        $department = Department::factory()->create();
        $this->post(route('employees.store'), [
            'first_name' => 'Double', 'last_name' => 'Click', 'gender' => 'M', 'birth_date' => '1990-01-01', 'hire_date' => '2024-01-01',
            'dept_no' => $department->dept_no, 'title' => 'Staff', 'salary' => '40000', 'form_token' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $employee = Employee::sole();
        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '41000', 'effective_date' => '2024-06-01'])->assertSessionHasNoErrors();

        $this->undo($employee)->assertSessionHasNoErrors();

        $this->assertSame(40000, $this->current('salaries', $employee->emp_no)->salary);
        $this->assertSame(1, Employee::count());
    }

    public function test_only_the_most_recent_change_can_be_undone(): void
    {
        $employee = $this->currentEmployee();
        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '61000', 'effective_date' => '2024-01-01']);
        $first = $this->latestEntryId();
        $this->post(route('employees.title', $employee->emp_no), ['title' => 'Lead', 'kind' => 'promotion', 'effective_date' => '2024-01-01']);

        $this->undo($employee, $first)->assertSessionHasErrorsIn('undo', ['undo']);
        $this->assertSame(61000, $this->current('salaries', $employee->emp_no)->salary);
    }

    public function test_no_undo_after_24_hours(): void
    {
        $employee = $this->currentEmployee();
        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '61000', 'effective_date' => '2024-01-01']);
        ActivityLog::query()->update(['created_at' => now()->subHours(25)]);

        $this->get($this->profile($employee))->assertDontSee('Undo last change');
        $this->undo($employee)->assertSessionHasErrorsIn('undo', ['undo' => 'Changes can only be undone within 24 hours.']);
        $this->assertSame(61000, $this->current('salaries', $employee->emp_no)->salary);
    }

    public function test_no_undo_when_the_records_were_changed_afterwards(): void
    {
        $department = Department::factory()->create();
        $other = Department::factory()->create();
        $employee = $this->currentEmployee($department);
        $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $other->dept_no, 'effective_date' => '2024-05-01']);
        // e.g. the departments screen makes them manager of the new department (no employee.* entry)
        $this->makeManager($employee, $other->dept_no, '2024-06-01');

        $this->get($this->profile($employee))->assertDontSee('Undo last change');
        $this->undo($employee)->assertSessionHasErrorsIn('undo', [
            'undo' => 'The records of this employee were changed after the last change, so it cannot be undone any more.',
        ]);
        $this->assertSame($other->dept_no, $this->current('dept_emp', $employee->emp_no)->dept_no);
    }

    public function test_entries_without_undo_data_cannot_be_undone(): void
    {
        $employee = $this->currentEmployee();
        AuditLogger::log('employee.salary_changed', 'employee', $employee->emp_no, ['before' => ['salary' => 1], 'after' => ['salary' => 2]]);

        $this->undo($employee)->assertSessionHasErrorsIn('undo', ['undo' => 'The last change cannot be undone.']);
    }

    public function test_undoing_a_return_transfer_with_ended_management_restores_everything(): void
    {
        $sales = Department::factory()->create();
        $research = Department::factory()->create();
        $employee = $this->currentEmployee($research, previousDepartment: $sales);
        $this->makeManager($employee, $research->dept_no, '2001-01-01');
        $before = $this->records($employee->emp_no);

        $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $sales->dept_no, 'effective_date' => '2024-05-01'])->assertSessionHasNoErrors();
        $this->assertTrue(ActivityLog::where('action', 'employee.transferred')->sole()->changes['returning']);
        $this->assertNull($this->current('dept_manager', $employee->emp_no));

        $this->undo($employee)->assertSessionHasNoErrors();

        $this->assertSame($before, $this->records($employee->emp_no));
        $this->assertSame($research->dept_no, $this->current('dept_manager', $employee->emp_no)->dept_no);
        $this->assertSame(1, DB::table('dept_emp')->where('emp_no', $employee->emp_no)->where('dept_no', $sales->dept_no)->where('void_log_id', '<>', 0)->count());
        $this->assertSame(1, DB::table('dept_emp')->where('emp_no', $employee->emp_no)->where('dept_no', $sales->dept_no)->where('void_log_id', 0)->count());

        $changes = ActivityLog::where('action', 'employee.change_undone')->sole()->changes;
        $this->assertSame(['dept_emp', 'dept_manager'], collect($changes['reopened_rows'])->pluck('table')->all());
    }

    public function test_ending_a_management_writes_the_same_entry_from_every_screen(): void
    {
        $sales = Department::factory()->create();
        $research = Department::factory()->create();
        $employee = $this->currentEmployee($research);
        $this->makeManager($employee, $research->dept_no, '2001-01-01');

        $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $sales->dept_no, 'effective_date' => '2024-05-01'])->assertSessionHasNoErrors();

        $ended = ActivityLog::where('action', 'department.manager_ended')->sole();
        $this->assertSame($research->dept_no, $ended->subject_id);
        $this->assertEquals(['emp_no' => $employee->emp_no, 'from_date' => '2001-01-01', 'to_date' => '2024-05-01', 'cause' => 'employee.transferred'], $ended->changes);

        $this->undo($employee)->assertSessionHasNoErrors();

        $restored = ActivityLog::where('action', 'department.manager_restored')->sole();
        $this->assertSame($research->dept_no, $restored->subject_id);
        $this->assertEquals(['emp_no' => $employee->emp_no, 'from_date' => '2001-01-01', 'cause' => 'employee.transferred'], $restored->changes);
    }

    public function test_a_management_taken_over_by_another_manager_blocks_the_undo(): void
    {
        $sales = Department::factory()->create();
        $research = Department::factory()->create();
        $employee = $this->currentEmployee($research);
        $successor = $this->currentEmployee($research);
        $this->makeManager($employee, $research->dept_no, '2001-01-01');

        $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $sales->dept_no, 'effective_date' => '2024-05-01'])->assertSessionHasNoErrors();
        app(DepartmentManagementService::class)->assignManager($research, $successor->emp_no, '2024-05-01');

        $this->undo($employee)->assertSessionHasErrorsIn('undo', ['undo' => 'The department got another manager after this change, so it cannot be undone any more.']);

        $this->assertSame(1, DB::table('dept_manager')->where('dept_no', $research->dept_no)->where('void_log_id', 0)->where('to_date', self::CURRENT)->count());
        $this->assertSame($sales->dept_no, $this->current('dept_emp', $employee->emp_no)->dept_no);
    }

    public function test_an_undo_flushes_every_cached_figure(): void
    {
        $sales = Department::factory()->create();
        $research = Department::factory()->create();
        $employee = $this->currentEmployee($sales);
        app(DepartmentStatistics::class)->refreshOverview();
        app(DepartmentStatistics::class)->refreshDetails($sales->dept_no);
        $version = EmployeeDataCache::reportsVersion();
        Cache::put(EmployeeDataCache::DASHBOARD_STATS, ['cached']);
        Cache::put(EmployeeDataCache::departmentStats($sales->dept_no), ['cached']);

        $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $research->dept_no, 'effective_date' => '2024-05-01'])->assertSessionHasNoErrors();
        Cache::put(EmployeeDataCache::DASHBOARD_STATS, ['cached']);
        Cache::put(EmployeeDataCache::departmentStats($sales->dept_no), ['cached']);
        $this->assertTrue(FigureCache::isFresh(EmployeeDataCache::DEPARTMENTS_OVERVIEW), 'Other changes leave the overview to its short lifetime.');

        $this->undo($employee)->assertSessionHasNoErrors();

        $this->assertFalse(Cache::has(EmployeeDataCache::DASHBOARD_STATS));
        $this->assertFalse(Cache::has(EmployeeDataCache::departmentStats($sales->dept_no)));
        $this->assertFalse(FigureCache::isFresh(EmployeeDataCache::DEPARTMENTS_OVERVIEW));
        $this->assertFalse(FigureCache::isFresh(EmployeeDataCache::departmentDetails($sales->dept_no)));
        $this->assertFalse(FigureCache::isFresh(EmployeeDataCache::departmentDetails($research->dept_no)));
        $this->assertNotSame($version, EmployeeDataCache::reportsVersion());
    }

    /**
     * @return array<string, array{\Closure(array, int): array}>
     */
    public static function forgedUndoProvider(): array
    {
        return [
            'another table' => [fn (array $ops, int $empNo) => [['set', 'users', ['id' => 1], ['role' => 'admin'], ['role' => 'viewer']]]],
            'another employee' => [fn (array $ops, int $empNo) => [['insert', 'salaries', ['emp_no' => $empNo + 1, 'from_date' => '2024-01-01']]]],
            'wrong key columns' => [fn (array $ops, int $empNo) => [['insert', 'salaries', ['emp_no' => $empNo]]]],
            'extra key column' => [fn (array $ops, int $empNo) => [['close', 'salaries', ['emp_no' => $empNo, 'from_date' => '2024-01-01', 'salary' => 1], '2024-01-01']]],
            'unknown operation' => [fn (array $ops, int $empNo) => [['delete', 'salaries', ['emp_no' => $empNo, 'from_date' => '2024-01-01']]]],
            'column outside the list' => [fn (array $ops, int $empNo) => [['set', 'salaries', ['emp_no' => $empNo, 'from_date' => '2024-01-01'], ['void_log_id' => 5], ['void_log_id' => 0]]]],
            'employee row inserted' => [fn (array $ops, int $empNo) => [['insert', 'employees', ['emp_no' => $empNo]]]],
            'not a list' => [fn (array $ops, int $empNo) => ['x']],
            'empty' => [fn (array $ops, int $empNo) => []],
        ];
    }

    #[DataProvider('forgedUndoProvider')]
    public function test_forged_undo_data_is_refused(\Closure $forge): void
    {
        $employee = $this->currentEmployee();
        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '61000', 'effective_date' => '2024-01-01'])->assertSessionHasNoErrors();

        // The fingerprint stays right: only the row changes are forged.
        $entry = ActivityLog::where('action', 'employee.salary_changed')->sole();
        $changes = $entry->changes;
        $changes['_undo']['ops'] = $forge($changes['_undo']['ops'], $employee->emp_no);
        DB::table('activity_log')->where('id', $entry->id)->update(['changes' => json_encode($changes)]);
        $records = $this->records($employee->emp_no);

        $this->get($this->profile($employee))->assertDontSee('Undo last change');
        $this->undo($employee)->assertSessionHasErrorsIn('undo', ['undo' => 'The last change cannot be undone.']);

        $this->assertSame($records, $this->records($employee->emp_no));
        $this->assertSame(0, ActivityLog::where('action', 'employee.change_undone')->count());
    }

    public function test_managers_and_viewers_cannot_undo(): void
    {
        $department = Department::factory()->create();
        $employee = $this->currentEmployee($department);
        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '61000', 'effective_date' => '2024-01-01']);

        foreach ([Role::Manager, Role::Viewer] as $role) {
            $this->signInAs($role, $department);
            $this->undo($employee)->assertForbidden();
        }
        $this->assertSame(61000, $this->current('salaries', $employee->emp_no)->salary);
    }

    public function test_the_undo_data_is_not_shown_in_the_audit_log(): void
    {
        $employee = $this->currentEmployee();
        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '61000', 'effective_date' => '2024-01-01']);

        $this->get(route('audit-log.index'))->assertOk()->assertSee('61,000')->assertDontSee('_undo')->assertDontSee('"ops"', false);
    }
}
