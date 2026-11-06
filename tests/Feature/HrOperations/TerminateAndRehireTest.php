<?php

namespace Tests\Feature\HrOperations;

use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TerminateAndRehireTest extends HrOperationsTestCase
{
    public function test_termination_closes_every_current_row_and_keeps_the_reason(): void
    {
        $sales = Department::factory()->create(['dept_name' => 'Sales']);
        $managed = Department::factory()->create(['dept_name' => 'Marketing']);
        $employee = $this->currentEmployee($sales, 'Manager', 90000);
        $this->makeManager($employee, $sales->dept_no);
        $this->makeManager($employee, $managed->dept_no, '2001-01-01');
        $this->primeCache([$sales->dept_no, $managed->dept_no]);
        $salaryRows = count($this->rows('salaries', $employee->emp_no));

        $this->from($this->profile($employee))
            ->post(route('employees.terminate', $employee->emp_no), ['end_date' => '2024-09-30', 'reason' => '  Moved abroad  '])
            ->assertRedirect($this->profile($employee))
            ->assertSessionHas('status', 'The employment of Anna Nowak ended on 30.09.2024.');

        foreach (['dept_emp', 'titles', 'salaries', 'dept_manager'] as $table) {
            $this->assertNull($this->current($table, $employee->emp_no), "{$table} should have no current row.");
        }
        $this->assertSame('2024-09-30', DB::table('dept_emp')->where('emp_no', $employee->emp_no)->value('to_date'));
        $this->assertSame(2, DB::table('dept_manager')->where('emp_no', $employee->emp_no)->where('to_date', '2024-09-30')->count());
        $this->assertCount($salaryRows, $this->rows('salaries', $employee->emp_no), 'No history row is deleted.');

        $this->assertEquals([
            'before' => ['dept_no' => $sales->dept_no, 'title' => 'Manager', 'salary' => 90000],
            'end_date' => '2024-09-30',
            'reason' => 'Moved abroad',
            'ended_management' => [$sales->dept_no, $managed->dept_no],
        ], $this->onlyEntry('employee.terminated')->changes);
        $this->assertCacheFlushed([$sales->dept_no, $managed->dept_no]);

        $this->get($this->profile($employee))
            ->assertOk()
            ->assertSee('Former Employee')
            ->assertSee('30.09.2024')
            ->assertSee('Rehire')
            ->assertDontSee('Terminate employment');
    }

    public function test_the_reason_is_optional(): void
    {
        $employee = $this->currentEmployee();

        $this->post(route('employees.terminate', $employee->emp_no), ['end_date' => '2024-09-30', 'reason' => ''])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('reason', $this->onlyEntry('employee.terminated')->changes);
    }

    public function test_termination_rules(): void
    {
        $employee = $this->currentEmployee();
        $url = route('employees.terminate', $employee->emp_no);

        // The current salary row started on 2002-03-01: the end date must be later.
        $this->post($url, ['end_date' => self::CURRENT_SALARY_FROM])
            ->assertSessionHasErrorsIn('terminate', ['end_date' => 'The date must be 02.03.2002 or later (after the start of the current records).']);
        $this->post($url, ['end_date' => CarbonImmutable::tomorrow()->toDateString()])->assertSessionHasErrorsIn('terminate', ['end_date']);
        $this->post($url, ['end_date' => '2024-09-30', 'reason' => str_repeat('r', 501)])->assertSessionHasErrorsIn('terminate', ['reason']);
        $this->assertNotNull($this->current('dept_emp', $employee->emp_no));

        $this->post($url, ['end_date' => '2002-03-02'])->assertSessionHasNoErrors();
        $this->post($url, ['end_date' => '2024-09-30'])
            ->assertSessionHasErrorsIn('terminate', ['end_date' => 'Anna Nowak is not currently employed.']);
    }

    public function test_a_former_employee_is_rehired_after_the_end_of_the_last_employment(): void
    {
        $old = Department::factory()->create(['dept_name' => 'Sales']);
        $new = Department::factory()->create(['dept_name' => 'Research']);
        $employee = $this->formerEmployee($old, '2000-06-30');
        $this->primeCache([$new->dept_no]);

        $this->get($this->profile($employee))
            ->assertOk()
            ->assertSee('The last employment ended on 30.06.2000.')
            ->assertSee('return - worked here before');

        $this->from($this->profile($employee))
            ->post(route('employees.rehire', $employee->emp_no), [
                'dept_no' => $new->dept_no, 'title' => 'Engineer', 'salary' => '70000', 'effective_date' => '2000-07-01',
            ])
            ->assertRedirect($this->profile($employee))
            ->assertSessionHas('status', 'Jan Kowalski has been rehired from 01.07.2000.');

        $this->assertEquals(['dept_no' => $new->dept_no, 'from_date' => '2000-07-01'], (array) DB::table('dept_emp')
            ->where('emp_no', $employee->emp_no)->where('to_date', self::CURRENT)->first(['dept_no', 'from_date']));
        $this->assertSame('Engineer', $this->current('titles', $employee->emp_no)->title);
        $this->assertSame(70000, $this->current('salaries', $employee->emp_no)->salary);
        $this->assertSame(self::HIRED, $employee->fresh()->hire_date, 'The original hire date is kept.');
        $this->assertCount(2, $this->rows('dept_emp', $employee->emp_no));

        $this->assertEquals([
            'after' => ['dept_no' => $new->dept_no, 'title' => 'Engineer', 'salary' => 70000],
            'effective_date' => '2000-07-01',
            'previous_end_date' => '2000-06-30',
        ], $this->onlyEntry('employee.rehired')->changes);
        $this->assertCacheFlushed([$new->dept_no]);

        $this->get($this->profile($employee))->assertSee('Research')->assertSee('Terminate employment');
    }

    public function test_rehire_rules(): void
    {
        $old = Department::factory()->create();
        $new = Department::factory()->create();
        $employee = $this->formerEmployee($old, '2000-06-30');
        $url = route('employees.rehire', $employee->emp_no);
        $valid = ['dept_no' => $new->dept_no, 'title' => 'Engineer', 'salary' => '70000', 'effective_date' => '2010-01-01'];

        $this->post($url, [...$valid, 'effective_date' => '2000-06-30'])
            ->assertSessionHasErrorsIn('rehire', ['effective_date' => 'The date must be 01.07.2000 or later (after the end of the last employment).']);
        $this->post($url, [...$valid, 'salary' => '-5'])->assertSessionHasErrorsIn('rehire', ['salary']);
        $this->post($url, [...$valid, 'title' => ''])->assertSessionHasErrorsIn('rehire', ['title']);
        $this->post($url, [...$valid, 'effective_date' => CarbonImmutable::tomorrow()->toDateString()])->assertSessionHasErrorsIn('rehire', ['effective_date']);
        $this->assertDatabaseCount('activity_log', 0);

        $current = $this->currentEmployee();
        $this->post(route('employees.rehire', $current->emp_no), $valid)
            ->assertSessionHasErrorsIn('rehire', ['dept_no' => 'Anna Nowak is currently employed.']);
    }
}
