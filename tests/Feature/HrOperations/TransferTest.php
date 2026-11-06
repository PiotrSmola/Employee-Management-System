<?php

namespace Tests\Feature\HrOperations;

use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TransferTest extends HrOperationsTestCase
{
    private Department $sales;

    private Department $research;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sales = Department::factory()->create(['dept_name' => 'Sales']);
        $this->research = Department::factory()->create(['dept_name' => 'Research']);
    }

    public function test_a_transfer_closes_the_current_assignment_and_opens_a_new_one(): void
    {
        $employee = $this->currentEmployee($this->sales);
        $untouched = Department::factory()->create();
        $this->primeCache([$this->sales->dept_no, $this->research->dept_no, $untouched->dept_no]);

        $this->from($this->profile($employee))
            ->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $this->research->dept_no, 'effective_date' => '2024-05-01'])
            ->assertRedirect($this->profile($employee))
            ->assertSessionHas('status', 'Anna Nowak moves from Sales to Research on 01.05.2024.');

        $this->assertSame([
            ['dept_no' => $this->sales->dept_no, 'from_date' => self::HIRED, 'to_date' => '2024-05-01'],
            ['dept_no' => $this->research->dept_no, 'from_date' => '2024-05-01', 'to_date' => self::CURRENT],
        ], $this->rows('dept_emp', $employee->emp_no, ['dept_no', 'from_date', 'to_date']));

        $this->assertEquals([
            'before' => ['dept_no' => $this->sales->dept_no],
            'after' => ['dept_no' => $this->research->dept_no],
            'effective_date' => '2024-05-01',
        ], $this->onlyEntry('employee.transferred')->changes);

        $this->assertCacheFlushed([$this->sales->dept_no, $this->research->dept_no]);
        $this->assertCacheKept([$untouched->dept_no]);

        $this->get($this->profile($employee))->assertOk()->assertSeeInOrder(['Research', 'Engineer']);
    }

    public function test_the_management_of_the_old_department_ends_with_the_transfer(): void
    {
        $employee = $this->currentEmployee($this->sales);
        $this->makeManager($employee, $this->sales->dept_no);

        $this->get($this->profile($employee))
            ->assertOk()
            ->assertSee('Anna Nowak is the current manager of Sales. The management ends on the transfer date');

        $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $this->research->dept_no, 'effective_date' => '2024-05-01'])
            ->assertSessionHas('status', 'Anna Nowak moves from Sales to Research on 01.05.2024. The management of Sales ended on the same date.');

        $this->assertSame([['dept_no' => $this->sales->dept_no, 'from_date' => '1998-01-01', 'to_date' => '2024-05-01']],
            $this->rows('dept_manager', $employee->emp_no, ['dept_no', 'from_date', 'to_date']));
        $this->assertSame([$this->sales->dept_no], $this->onlyEntry('employee.transferred')->changes['ended_management']);
    }

    public function test_a_return_to_a_previous_department_is_refused(): void
    {
        $employee = $this->currentEmployee($this->research, previousDepartment: $this->sales);
        $before = $this->rows('dept_emp', $employee->emp_no);

        $this->get($this->profile($employee))->assertSee('previously held - not possible');

        $this->from($this->profile($employee))
            ->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $this->sales->dept_no, 'effective_date' => '2024-05-01'])
            ->assertRedirect($this->profile($employee))
            ->assertSessionHasErrorsIn('transfer', ['dept_no']);

        $message = session('errors')->getBag('transfer')->first('dept_no');
        $this->assertStringContainsString('Anna Nowak already belonged to Sales', $message);
        $this->assertStringContainsString('a return to a previous department cannot be recorded', $message);
        $this->assertSame($before, $this->rows('dept_emp', $employee->emp_no));
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_the_current_department_cannot_be_chosen(): void
    {
        $employee = $this->currentEmployee($this->sales);

        $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $this->sales->dept_no, 'effective_date' => '2024-05-01'])
            ->assertSessionHasErrorsIn('transfer', ['dept_no' => 'Anna Nowak already works in this department.']);
    }

    public function test_the_date_must_be_after_the_current_assignment_started_and_not_in_the_future(): void
    {
        $employee = $this->currentEmployee($this->sales);
        $manager = $this->currentEmployee($this->sales);
        $this->makeManager($manager, $this->sales->dept_no, '2010-06-01');

        foreach ([self::HIRED, '1990-01-01', CarbonImmutable::tomorrow()->toDateString()] as $date) {
            $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $this->research->dept_no, 'effective_date' => $date])
                ->assertSessionHasErrorsIn('transfer', ['effective_date']);
        }

        // The start of the management counts too: no closed row may end before it starts.
        $this->post(route('employees.transfer', $manager->emp_no), ['dept_no' => $this->research->dept_no, 'effective_date' => '2010-06-01'])
            ->assertSessionHasErrorsIn('transfer', ['effective_date' => 'The date must be 02.06.2010 or later (after the start of the current records).']);

        $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $this->research->dept_no, 'effective_date' => '1995-03-02'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('activity_log')->count());
    }

    public function test_a_former_employee_cannot_be_transferred(): void
    {
        $employee = $this->formerEmployee($this->sales);

        $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => $this->research->dept_no, 'effective_date' => '2024-05-01'])
            ->assertSessionHasErrorsIn('transfer', ['dept_no' => 'Jan Kowalski is not currently employed.']);
    }

    public function test_an_unknown_department_is_rejected(): void
    {
        $employee = $this->currentEmployee($this->sales);

        $this->post(route('employees.transfer', $employee->emp_no), ['dept_no' => 'zzzz', 'effective_date' => '2024-05-01'])
            ->assertSessionHasErrorsIn('transfer', ['dept_no']);
    }
}
