<?php

namespace Tests\Feature\HrOperations;

use App\Models\Department;
use App\Models\Employee;
use Carbon\CarbonImmutable;

class PersonalDataTest extends HrOperationsTestCase
{
    /**
     * @return array<string, string>
     */
    private function payload(Employee $employee, array $overrides = []): array
    {
        return [
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'gender' => $employee->gender,
            'birth_date' => $employee->birth_date,
            'hire_date' => $employee->hire_date,
            ...$overrides,
        ];
    }

    public function test_personal_data_is_updated_and_the_diff_logged(): void
    {
        $department = Department::factory()->create();
        $employee = $this->currentEmployee($department);
        $this->primeCache([$department->dept_no]);

        $this->from($this->profile($employee))
            ->put(route('employees.update', $employee->emp_no), $this->payload($employee, [
                'last_name' => 'Nowak-Kowalska',
                'hire_date' => '1995-01-15',
            ]))
            ->assertRedirect($this->profile($employee))
            ->assertSessionHas('status', 'The personal data of Anna Nowak-Kowalska has been updated.');

        $employee->refresh();
        $this->assertSame('Nowak-Kowalska', $employee->last_name);
        $this->assertSame('1995-01-15', $employee->hire_date);

        $this->assertEquals([
            'before' => ['last_name' => 'Nowak', 'hire_date' => self::HIRED],
            'after' => ['last_name' => 'Nowak-Kowalska', 'hire_date' => '1995-01-15'],
        ], $this->onlyEntry('employee.updated')->changes);
        $this->assertCacheFlushed([$department->dept_no]);
    }

    public function test_saving_unchanged_data_writes_no_audit_entry(): void
    {
        $employee = $this->currentEmployee();

        $this->put(route('employees.update', $employee->emp_no), $this->payload($employee))
            ->assertSessionHas('status', 'Nothing to save - the personal data is unchanged.');

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_the_hire_date_cannot_be_after_the_first_history_row(): void
    {
        $employee = $this->currentEmployee();

        $this->from($this->profile($employee))
            ->put(route('employees.update', $employee->emp_no), $this->payload($employee, ['hire_date' => '1995-03-02']))
            ->assertRedirect($this->profile($employee))
            ->assertSessionHasErrorsIn('personal', ['hire_date']);

        $this->assertSame(self::HIRED, $employee->fresh()->hire_date);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_an_implausible_age_is_rejected(): void
    {
        $employee = $this->currentEmployee();

        $this->put(route('employees.update', $employee->emp_no), $this->payload($employee, ['birth_date' => '1985-01-01']))
            ->assertSessionHasErrorsIn('personal', ['birth_date']);

        $this->put(route('employees.update', $employee->emp_no), $this->payload($employee, ['birth_date' => '1900-01-01']))
            ->assertSessionHasErrorsIn('personal', ['birth_date']);

        $this->assertSame('1965-05-20', $employee->fresh()->birth_date);
    }

    public function test_field_rules(): void
    {
        $employee = $this->currentEmployee();
        $tomorrow = CarbonImmutable::tomorrow()->toDateString();

        $this->put(route('employees.update', $employee->emp_no), $this->payload($employee, [
            'first_name' => str_repeat('x', 15),
            'gender' => 'Q',
            'hire_date' => $tomorrow,
        ]))->assertSessionHasErrorsIn('personal', ['first_name', 'gender', 'hire_date']);
    }

    public function test_the_profile_reopens_the_form_with_the_errors(): void
    {
        $employee = $this->currentEmployee();

        $this->from($this->profile($employee))
            ->followingRedirects()
            ->put(route('employees.update', $employee->emp_no), $this->payload($employee, ['hire_date' => '1995-03-02']))
            ->assertOk()
            ->assertSee('The hire date cannot be after the start of the employment history (01.03.1995).')
            ->assertSee('getElementById("personalModal")', false);
    }

    public function test_unknown_employee_is_404(): void
    {
        $this->put(route('employees.update', 999999), [
            'first_name' => 'A', 'last_name' => 'B', 'gender' => 'M', 'birth_date' => '1970-01-01', 'hire_date' => '2000-01-01',
        ])->assertNotFound();
    }
}
