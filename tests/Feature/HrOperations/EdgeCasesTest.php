<?php

namespace Tests\Feature\HrOperations;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\DeptEmp;
use App\Models\Employee;
use App\Models\Salary;
use App\Models\Title;
use App\Services\AuditLogger;
use App\Services\EmployeeOperationsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Old data with quirks, double submits, unusual amounts and escaping.
 */
class EdgeCasesTest extends HrOperationsTestCase
{
    /**
     * @return array<string, string>
     */
    private function personal(Employee $employee, array $overrides = []): array
    {
        return [
            ...$employee->only(EmployeeOperationsService::PERSONAL_FIELDS),
            'snapshot' => EmployeeOperationsService::personalSnapshot($employee),
            ...$overrides,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function hirePayload(Department $department, array $overrides = []): array
    {
        return [
            'first_name' => 'Maria', 'last_name' => 'Curie', 'gender' => 'F', 'birth_date' => '1990-04-12',
            'hire_date' => '2024-02-01', 'dept_no' => $department->dept_no, 'title' => 'Engineer', 'salary' => '65000',
            'form_token' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    public function test_a_name_fix_is_not_blocked_by_a_quirky_history(): void
    {
        // Hire date after the first history row and an implausible age - as in some imported data.
        $employee = $this->currentEmployee();
        $employee->forceFill(['hire_date' => '1996-01-01', 'birth_date' => '1990-01-01'])->save();

        $this->put(route('employees.update', $employee->emp_no), $this->personal($employee, ['last_name' => 'Nowakowska']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Nowakowska', $employee->fresh()->last_name);

        // Changing one of the dates still runs the checks.
        $this->put(route('employees.update', $employee->fresh()->emp_no), $this->personal($employee->fresh(), ['birth_date' => '1989-12-31']))
            ->assertSessionHasErrorsIn('personal', ['birth_date']);
        $this->put(route('employees.update', $employee->emp_no), $this->personal($employee->fresh(), ['hire_date' => '1996-02-01']))
            ->assertSessionHasErrorsIn('personal', ['hire_date']);
    }

    public function test_a_stale_personal_data_form_is_rejected(): void
    {
        $employee = $this->currentEmployee();
        $form = $this->personal($employee, ['first_name' => 'Annie']);
        $employee->forceFill(['last_name' => 'Elsewhere'])->save();

        $this->put(route('employees.update', $employee->emp_no), $form)
            ->assertSessionHasErrorsIn('personal', ['snapshot']);
        $this->assertSame('Anna', $employee->fresh()->first_name);
        $this->assertSame('Elsewhere', $employee->fresh()->last_name);

        $this->put(route('employees.update', $employee->emp_no), [...$form, 'snapshot' => 'x'])->assertSessionHasErrorsIn('personal', ['snapshot']);
    }

    public function test_a_double_submitted_hire_form_hires_once(): void
    {
        $department = Department::factory()->create();
        $payload = $this->hirePayload($department);

        $first = $this->post(route('employees.store'), $payload)->assertSessionHasNoErrors();
        $employee = Employee::sole();
        $first->assertRedirect($this->profile($employee));

        $this->post(route('employees.store'), $payload)
            ->assertRedirect($this->profile($employee))
            ->assertSessionHas('status', 'This form was already submitted - employee no. '.$employee->emp_no.' was hired with it.');

        $this->assertSame(1, Employee::count());
        $this->assertSame(1, ActivityLog::where('action', 'employee.hired')->count());
    }

    public function test_a_failed_submit_does_not_use_up_the_form_token(): void
    {
        $department = Department::factory()->create();
        $payload = $this->hirePayload($department, ['birth_date' => '2015-01-01']);

        $this->post(route('employees.store'), $payload)->assertSessionHasErrors('birth_date');
        $this->post(route('employees.store'), [...$payload, 'birth_date' => '1990-04-12'])->assertSessionHasNoErrors();

        $this->assertSame(1, Employee::count());
    }

    public function test_the_hire_form_needs_its_token(): void
    {
        $department = Department::factory()->create();

        $this->post(route('employees.store'), $this->hirePayload($department, ['form_token' => '']))->assertSessionHasErrors('form_token');
        $this->post(route('employees.store'), $this->hirePayload($department, ['form_token' => 'not-a-uuid']))->assertSessionHasErrors('form_token');
        $this->assertSame(0, Employee::count());

        $this->get(route('employees.create'))->assertSee('name="form_token"', false);
    }

    public function test_a_possible_duplicate_needs_a_confirmation(): void
    {
        $department = Department::factory()->create();
        $existing = Employee::factory()->create(['first_name' => 'Maria', 'last_name' => 'Curie', 'birth_date' => '1990-04-12']);

        $this->from(route('employees.create'))
            ->post(route('employees.store'), $this->hirePayload($department))
            ->assertRedirect(route('employees.create'))
            ->assertSessionHasErrors(['confirm_duplicate']);
        $this->assertSame(1, Employee::count());
        $this->assertStringContainsString((string) $existing->emp_no, session('errors')->first('confirm_duplicate'));

        $this->get(route('employees.create'))->assertSee('This is a different person - hire anyway');

        $this->post(route('employees.store'), $this->hirePayload($department, ['confirm_duplicate' => '1']))->assertSessionHasNoErrors();
        $this->assertSame(2, Employee::count());
    }

    public function test_an_unusual_salary_amount_needs_a_confirmation(): void
    {
        $employee = $this->currentEmployee(salary: 60000);
        $url = route('employees.salary', $employee->emp_no);

        $this->post($url, ['mode' => 'amount', 'salary' => '600000', 'effective_date' => '2024-01-01'])
            ->assertSessionHasErrorsIn('salary', ['confirm_unusual']);
        $this->post($url, ['mode' => 'amount', 'salary' => '29999', 'effective_date' => '2024-01-01'])
            ->assertSessionHasErrorsIn('salary', ['confirm_unusual']);
        // Exactly -50 % / +100 % is still usual.
        $this->post($url, ['mode' => 'amount', 'salary' => '120000', 'effective_date' => '2024-01-01'])->assertSessionHasNoErrors();
        $this->post($url, ['mode' => 'amount', 'salary' => '600000', 'effective_date' => '2024-02-01', 'confirm_unusual' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(600000, $this->current('salaries', $employee->emp_no)->salary);
        $entries = ActivityLog::where('action', 'employee.salary_changed')->orderBy('id')->get();
        $this->assertArrayNotHasKey('confirmed_unusual', $entries[0]->changes);
        $this->assertTrue($entries[1]->changes['confirmed_unusual']);
    }

    public function test_a_transfer_closes_every_current_department_but_only_the_management_left_behind(): void
    {
        [$a, $b, $c, $managedElsewhere] = Department::factory()->count(4)->create();
        $employee = $this->currentEmployee($a);
        DeptEmp::create(['emp_no' => $employee->emp_no, 'dept_no' => $b->dept_no, 'from_date' => '2000-01-01', 'to_date' => self::CURRENT]);
        $this->makeManager($employee, $b->dept_no, '2001-01-01');
        $this->makeManager($employee, $managedElsewhere->dept_no, '2001-01-01');

        $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $c->dept_no, 'effective_date' => '2024-05-01'])
            ->assertSessionHasNoErrors();

        $this->assertSame([$c->dept_no], DB::table('dept_emp')->where('emp_no', $employee->emp_no)->where('to_date', self::CURRENT)->pluck('dept_no')->all());
        $this->assertSame(2, DB::table('dept_emp')->where('emp_no', $employee->emp_no)->where('to_date', '2024-05-01')->count());
        $this->assertSame([$managedElsewhere->dept_no], DB::table('dept_manager')->where('emp_no', $employee->emp_no)->where('to_date', self::CURRENT)->pluck('dept_no')->all());

        $changes = $this->onlyEntry('employee.transferred')->changes;
        $this->assertSame($a->dept_no.', '.$b->dept_no, $changes['before']['dept_no']);
        $this->assertSame([$b->dept_no], $changes['ended_management']);

        $this->post(route('employees.terminate', $employee->emp_no), ['end_date' => '2024-09-30'])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('dept_manager')->where('emp_no', $employee->emp_no)->where('to_date', self::CURRENT)->count());
        $this->assertSame([$managedElsewhere->dept_no], $this->onlyEntry('employee.terminated')->changes['ended_management']);
    }

    public function test_a_rehire_closes_stray_current_records_of_the_former_employee(): void
    {
        $old = Department::factory()->create();
        $employee = $this->formerEmployee($old, '2000-06-30');
        $this->makeManager($employee, $old->dept_no, '1999-01-01');
        Title::create(['emp_no' => $employee->emp_no, 'title' => 'Ghost', 'from_date' => '2000-01-01', 'to_date' => self::CURRENT]);

        $this->post(route('employees.rehire', $employee->emp_no), ['dept_no' => $old->dept_no, 'title' => 'Staff', 'salary' => '50000', 'effective_date' => '2001-01-01'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2000-06-30', DB::table('dept_manager')->where('emp_no', $employee->emp_no)->value('to_date'));
        $this->assertSame('2000-06-30', DB::table('titles')->where('emp_no', $employee->emp_no)->where('title', 'Ghost')->value('to_date'));
        $this->assertSame('Staff', $this->current('titles', $employee->emp_no)->title);
        $this->assertSame(1, DB::table('titles')->where('emp_no', $employee->emp_no)->where('to_date', self::CURRENT)->count());

        $changes = $this->onlyEntry('employee.rehired')->changes;
        $this->assertSame(['titles', 'dept_manager'], $changes['closed_stray_records']);
        $this->assertSame([$old->dept_no], $changes['ended_management']);
        $this->assertTrue($changes['returning']);
    }

    public function test_a_current_employee_without_a_title_or_salary_row_gets_one(): void
    {
        $employee = $this->currentEmployee();
        Title::where('emp_no', $employee->emp_no)->delete();
        Salary::where('emp_no', $employee->emp_no)->delete();

        $this->get($this->profile($employee))->assertOk();
        $this->post(route('employees.title', $employee->emp_no), ['title' => 'Engineer', 'kind' => 'change', 'effective_date' => '2024-01-01'])
            ->assertSessionHasNoErrors();
        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'percent', 'percent' => '5', 'effective_date' => '2024-01-01'])
            ->assertSessionHasErrorsIn('salary', ['percent']);
        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '1000000', 'effective_date' => '2024-01-01'])
            ->assertSessionHasNoErrors();

        $this->assertSame([['title' => 'Engineer', 'from_date' => '2024-01-01', 'to_date' => self::CURRENT]], $this->rows('titles', $employee->emp_no, ['title', 'from_date', 'to_date']));
        $this->assertSame(1000000, $this->current('salaries', $employee->emp_no)->salary);
        $this->assertEquals(['before' => ['title' => null], 'after' => ['title' => 'Engineer'], 'effective_date' => '2024-01-01'],
            $this->onlyEntry('employee.title_changed')->changes);
        // Before the hire date is still not possible.
        $other = $this->currentEmployee();
        Title::where('emp_no', $other->emp_no)->delete();
        $this->post(route('employees.title', $other->emp_no), ['title' => 'Engineer', 'kind' => 'change', 'effective_date' => '1990-01-01'])
            ->assertSessionHasErrorsIn('title', ['effective_date']);
    }

    public function test_names_and_audit_payloads_are_escaped(): void
    {
        $employee = $this->currentEmployee();
        $employee->forceFill(['first_name' => '<b>x</b>', 'last_name' => '<i>y</i>'])->save();
        AuditLogger::log('employee.terminated', 'employee', $employee->emp_no, [
            'reason' => '<script>alert(1)</script>',
            'before' => ['title' => '<img src=x onerror=alert(2)>'],
        ]);

        foreach ([$this->profile($employee), route('audit-log.index')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee('<script>alert(1)</script>', false)
                ->assertDontSee('<img src=x', false)
                ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
                ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false);
        }

        $this->get($this->profile($employee))->assertDontSee('<b>x</b>', false)->assertSee('&lt;b&gt;x&lt;/b&gt;', false);
        $this->get(route('audit-log.index'))->assertDontSee('<b>x</b> <i>y</i>', false);
    }
}
