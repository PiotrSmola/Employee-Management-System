<?php

namespace Tests\Feature\HrOperations;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Services\EmployeeOperationsService;
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
                $t->post(route('employees.salary', $e->emp_no), ['mode' => 'percent', 'percent' => '5', 'effective_date' => '2024-01-01']);
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
                $t->put(route('employees.salary.correct', $e->emp_no), ['salary' => '61000', 'reason' => 'Typo']);
            }, 'employee.salary_corrected'],
            'title correction' => [function (self $t, Employee $e) {
                $t->put(route('employees.title.correct', $e->emp_no), ['title' => 'Engineer II', 'reason' => 'Typo']);
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
        $this->assertSame($action, $undone->changes['undone_action']);
        $this->assertCacheFlushed([$department->dept_no]);

        // Only one level: the undo itself cannot be undone.
        $this->get($this->profile($employee))->assertDontSee('Undo last change');
        $this->undo($employee, $undone->id)->assertSessionHasErrorsIn('undo', ['undo' => 'The last change cannot be undone.']);
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

    public function test_undoing_a_hire_removes_the_employee(): void
    {
        $department = Department::factory()->create();
        $this->post(route('employees.store'), [
            'first_name' => 'Double', 'last_name' => 'Click', 'gender' => 'M', 'birth_date' => '1990-01-01', 'hire_date' => '2024-01-01',
            'dept_no' => $department->dept_no, 'title' => 'Staff', 'salary' => '40000', 'form_token' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $employee = Employee::sole();

        $this->get($this->profile($employee))->assertSee('Undoing a hire removes the employee record.');
        $this->undo($employee)
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('status', 'The hire of Double Click has been undone; the employee record was removed.');

        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('dept_emp', 0);
        $this->assertDatabaseCount('salaries', 0);
        $this->assertSame((string) $employee->emp_no, $this->onlyEntry('employee.change_undone')->subject_id);
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
        \App\Services\AuditLogger::log('employee.salary_changed', 'employee', $employee->emp_no, ['before' => ['salary' => 1], 'after' => ['salary' => 2]]);

        $this->undo($employee)->assertSessionHasErrorsIn('undo', ['undo' => 'The last change cannot be undone.']);
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
