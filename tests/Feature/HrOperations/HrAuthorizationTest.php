<?php

namespace Tests\Feature\HrOperations;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Services\AuditLogger;
use App\Support\Ability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Only `manage-employees` (admin, hr) may run HR operations; only `view-audit-log`
 * (admin, hr) sees the change history. Managers and viewers get 403 on every route,
 * whatever they send, and nothing is written.
 */
class HrAuthorizationTest extends HrOperationsTestCase
{
    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function operationProvider(): array
    {
        return [
            'hire form' => ['get', 'employees.create', []],
            'hire' => ['post', 'employees.store', ['first_name' => 'X', 'last_name' => 'Y', 'gender' => 'M', 'birth_date' => '1980-01-01', 'hire_date' => '2020-01-01', 'title' => 'Staff', 'salary' => '1000']],
            'personal data' => ['put', 'employees.update', ['first_name' => 'X', 'last_name' => 'Y', 'gender' => 'M', 'birth_date' => '1965-05-20', 'hire_date' => '1995-03-01']],
            'transfer' => ['post', 'employees.transfer', ['effective_date' => '2024-01-01']],
            'title' => ['post', 'employees.title', ['title' => 'Boss', 'kind' => 'promotion', 'effective_date' => '2024-01-01']],
            'salary' => ['post', 'employees.salary', ['mode' => 'amount', 'salary' => '99999', 'effective_date' => '2024-01-01']],
            'terminate' => ['post', 'employees.terminate', ['end_date' => '2024-01-01']],
            'rehire' => ['post', 'employees.rehire', ['title' => 'Boss', 'salary' => '99999', 'effective_date' => '2024-01-01']],
            'correct salary' => ['put', 'employees.salary.correct', ['salary' => '1']],
            'correct title' => ['put', 'employees.title.correct', ['title' => 'Boss']],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('operationProvider')]
    public function test_managers_and_viewers_cannot_run_hr_operations(string $method, string $route, array $payload): void
    {
        $department = Department::factory()->create();
        $other = Department::factory()->create();
        $employee = $this->currentEmployee($department);
        $payload['dept_no'] = $other->dept_no;
        $snapshot = $this->snapshot($employee);
        $url = $route === 'employees.create' || $route === 'employees.store' ? route($route) : route($route, $employee->emp_no);

        foreach ([Role::Manager, Role::Viewer] as $role) {
            // The manager is even the employee's own department manager.
            $this->signInAs($role, $department);
            ($method === 'get' ? $this->get($url) : $this->{$method}($url, $payload))->assertForbidden();
        }

        $this->assertSame($snapshot, $this->snapshot($employee));
        $this->assertSame(0, ActivityLog::where('action', 'like', 'employee.%')->count());
    }

    public function test_inactive_admins_are_signed_out_before_anything_happens(): void
    {
        $employee = $this->currentEmployee();
        $this->user->update(['is_active' => false]);

        $this->post(route('employees.terminate', $employee->emp_no), ['end_date' => '2024-01-01'])->assertRedirect(route('login'));
        $this->assertNotNull($this->current('dept_emp', $employee->emp_no));
    }

    public function test_hr_can_run_operations(): void
    {
        $employee = $this->currentEmployee();
        $this->signInAs(Role::Hr);

        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'percent', 'percent' => '5', 'shown_salary' => '60000', 'effective_date' => '2024-01-01'])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->user->id, $this->onlyEntry('employee.salary_changed')->user_id);
    }

    public function test_managing_employees_does_not_imply_seeing_salaries(): void
    {
        $employee = $this->currentEmployee(salary: 61357);
        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '62468', 'effective_date' => '2024-01-01'])->assertSessionHasNoErrors();
        $this->signInAs(Role::Hr);

        $render = fn (bool $canSee) => $this->blade(
            '<x-employee-actions :employee="$employee" :can-see-salaries="$canSee" />',
            ['employee' => $employee->fresh(), 'canSee' => $canSee],
        );

        $render(true)->assertSee('id="salaryModal"', false)->assertSee('62,468')->assertSee('id="correctSalaryModal"', false);

        $render(false)
            ->assertDontSee('id="salaryModal"', false)
            ->assertDontSee('id="correctSalaryModal"', false)
            ->assertDontSee('62,468')
            ->assertDontSee('62468')
            ->assertDontSee('61,357')
            ->assertDontSee('data-current-salary', false)
            ->assertSee('id="undoModal"', false);

        Gate::before(fn ($user, string $ability) => $ability === Ability::VIEW_SALARIES ? false : null);

        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '70000', 'effective_date' => '2024-02-01'])->assertForbidden();
        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '70000', 'reason' => 'x', ...$this->salaryRow($employee)])->assertForbidden();
        $this->assertSame(62468, $this->current('salaries', $employee->emp_no)->salary);
    }

    public function test_the_profile_shows_actions_and_change_history_only_to_hr_staff(): void
    {
        $department = Department::factory()->create();
        $employee = $this->currentEmployee($department);
        AuditLogger::log('employee.updated', ActivityLog::SUBJECT_EMPLOYEE, $employee->emp_no, [
            'before' => ['last_name' => 'Old'], 'after' => ['last_name' => 'Nowak'],
        ]);

        foreach ([Role::Admin, Role::Hr] as $role) {
            $this->signInAs($role);
            $this->get($this->profile($employee))
                ->assertOk()
                ->assertSee('HR actions')
                ->assertSee('id="transferModal"', false)
                ->assertSee('Change history')
                ->assertSeeInOrder(['Last name', 'Old', 'Nowak']);
        }

        foreach ([Role::Manager, Role::Viewer] as $role) {
            $this->signInAs($role, $department);
            $this->get($this->profile($employee))
                ->assertOk()
                ->assertDontSee('HR actions')
                ->assertDontSee('id="transferModal"', false)
                ->assertDontSee('Change history')
                ->assertDontSee(route('audit-log.index'));
        }
    }

    public function test_the_change_history_lists_the_latest_entries_of_this_employee_only(): void
    {
        $employee = $this->currentEmployee();
        $other = $this->currentEmployee();
        for ($i = 1; $i <= 12; $i++) {
            AuditLogger::log('employee.salary_changed', ActivityLog::SUBJECT_EMPLOYEE, $employee->emp_no, [
                'before' => ['salary' => 1000 * $i], 'after' => ['salary' => 1000 * $i + 1],
            ]);
        }
        AuditLogger::log('employee.updated', ActivityLog::SUBJECT_EMPLOYEE, $other->emp_no, ['after' => ['first_name' => 'Elsewhere']]);

        $response = $this->get($this->profile($employee))
            ->assertOk()
            ->assertSee('12,001')
            ->assertDontSee('Elsewhere')
            ->assertSee('Show all')
            ->assertSee(route('audit-log.index', ['subject_type' => 'employee', 'subject_id' => $employee->emp_no]));

        $this->assertSame(10, substr_count($response->getContent(), '>Salary changed<'));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Employee $employee): array
    {
        $tables = ['dept_emp', 'titles', 'salaries', 'dept_manager'];

        return [
            'employees' => DB::table('employees')->orderBy('emp_no')->get()->map(fn ($r) => (array) $r)->all(),
            ...array_combine($tables, array_map(fn ($t) => $this->rows($t, $employee->emp_no), $tables)),
        ];
    }
}
