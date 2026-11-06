<?php

namespace Tests\Feature\HrOperations;

use App\Models\Department;

class CorrectionTest extends HrOperationsTestCase
{
    public function test_the_latest_salary_amount_can_be_corrected_without_new_rows(): void
    {
        $department = Department::factory()->create();
        $employee = $this->currentEmployee($department, salary: 60000);
        $before = $this->rows('salaries', $employee->emp_no);
        $this->primeCache([$department->dept_no]);

        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '61000', 'reason' => 'Typo in the contract', ...$this->salaryRow($employee)])
            ->assertRedirect($this->profile($employee))
            ->assertSessionHas('status', 'The latest salary of Anna Nowak has been corrected.');

        $after = $this->rows('salaries', $employee->emp_no);
        $this->assertCount(count($before), $after);
        $this->assertSame(61000, end($after)['salary']);
        $this->assertSame(array_slice($before, 0, -1), array_slice($after, 0, -1), 'Older rows are untouched.');

        $this->assertEquals([
            'before' => ['salary' => 60000],
            'after' => ['salary' => 61000],
            'from_date' => self::CURRENT_SALARY_FROM,
            'to_date' => self::CURRENT,
            'reason' => 'Typo in the contract',
        ], $this->onlyEntry('employee.salary_corrected')->changes);
        $this->assertCacheFlushed([$department->dept_no]);
    }

    public function test_the_latest_title_can_be_corrected(): void
    {
        $employee = $this->currentEmployee(title: 'Enginer');

        $this->put(route('employees.title.correct', $employee->emp_no), ['title' => 'Engineer', 'reason' => 'Spelling', ...$this->titleRow($employee)])
            ->assertSessionHas('status', 'The latest job title of Anna Nowak has been corrected.');

        $this->assertSame([['title' => 'Engineer', 'from_date' => self::HIRED, 'to_date' => self::CURRENT]],
            $this->rows('titles', $employee->emp_no, ['title', 'from_date', 'to_date']));
        $this->assertEquals([
            'before' => ['title' => 'Enginer'],
            'after' => ['title' => 'Engineer'],
            'from_date' => self::HIRED,
            'to_date' => self::CURRENT,
            'reason' => 'Spelling',
        ], $this->onlyEntry('employee.title_corrected')->changes);
    }

    public function test_the_case_of_a_title_can_be_corrected(): void
    {
        $employee = $this->currentEmployee(title: 'senior engineer');

        $this->put(route('employees.title.correct', $employee->emp_no), ['title' => 'Senior Engineer', 'reason' => 'Case', ...$this->titleRow($employee)])->assertSessionHasNoErrors();

        $this->assertSame('Senior Engineer', $this->current('titles', $employee->emp_no)->title);
    }

    public function test_former_employees_cannot_be_corrected(): void
    {
        $employee = $this->formerEmployee(Department::factory()->create());

        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '51000', 'reason' => 'x', ...$this->salaryRow($employee)])
            ->assertSessionHasErrorsIn('correctSalary', ['salary' => 'Corrections are only possible for current employees.']);
        $this->put(route('employees.title.correct', $employee->emp_no), ['title' => 'Senior Staff', 'reason' => 'x', ...$this->titleRow($employee)])
            ->assertSessionHasErrorsIn('correctTitle', ['title' => 'Corrections are only possible for current employees.']);

        $this->get($this->profile($employee))->assertDontSee('id="correctSalaryModal"', false);
    }

    public function test_a_reason_is_required(): void
    {
        $employee = $this->currentEmployee(title: 'Enginer', salary: 60000);

        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '61000', ...$this->salaryRow($employee)])
            ->assertSessionHasErrorsIn('correctSalary', ['reason']);
        $this->put(route('employees.title.correct', $employee->emp_no), ['title' => 'Engineer', 'reason' => str_repeat('r', 501), ...$this->titleRow($employee)])
            ->assertSessionHasErrorsIn('correctTitle', ['reason']);

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_an_unusual_correction_needs_a_confirmation(): void
    {
        $employee = $this->currentEmployee(salary: 60000);
        $url = route('employees.salary.correct', $employee->emp_no);

        $this->put($url, ['salary' => '600000', 'reason' => 'One zero missing', ...$this->salaryRow($employee)])
            ->assertSessionHasErrorsIn('correctSalary', ['confirm_unusual']);
        $this->put($url, ['salary' => '25000', 'reason' => 'Typo', ...$this->salaryRow($employee)])
            ->assertSessionHasErrorsIn('correctSalary', ['confirm_unusual']);
        $this->assertSame(60000, $this->current('salaries', $employee->emp_no)->salary);

        $this->put($url, ['salary' => '600000', 'reason' => 'Really', 'confirm_unusual' => '1', ...$this->salaryRow($employee)])->assertSessionHasNoErrors();
        $this->assertSame(600000, $this->current('salaries', $employee->emp_no)->salary);
    }

    public function test_unchanged_or_invalid_corrections_are_rejected(): void
    {
        $employee = $this->currentEmployee(title: 'Engineer', salary: 60000);

        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '60000', 'reason' => 'x', ...$this->salaryRow($employee)])
            ->assertSessionHasErrorsIn('correctSalary', ['salary' => 'The amount is unchanged.']);
        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '0', 'reason' => 'x', ...$this->salaryRow($employee)])
            ->assertSessionHasErrorsIn('correctSalary', ['salary']);
        $this->put(route('employees.title.correct', $employee->emp_no), ['title' => 'Engineer', 'reason' => 'x', ...$this->titleRow($employee)])
            ->assertSessionHasErrorsIn('correctTitle', ['title' => 'The job title is unchanged.']);

        $this->assertDatabaseCount('activity_log', 0);
    }
}
