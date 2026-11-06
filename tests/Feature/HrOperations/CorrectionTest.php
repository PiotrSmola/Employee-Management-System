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

        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '61000'])
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
        ], $this->onlyEntry('employee.salary_corrected')->changes);
        $this->assertCacheFlushed([$department->dept_no]);
    }

    public function test_the_latest_title_can_be_corrected(): void
    {
        $employee = $this->currentEmployee(title: 'Enginer');

        $this->put(route('employees.title.correct', $employee->emp_no), ['title' => 'Engineer'])
            ->assertSessionHas('status', 'The latest job title of Anna Nowak has been corrected.');

        $this->assertSame([['title' => 'Engineer', 'from_date' => self::HIRED, 'to_date' => self::CURRENT]],
            $this->rows('titles', $employee->emp_no, ['title', 'from_date', 'to_date']));
        $this->assertEquals([
            'before' => ['title' => 'Enginer'],
            'after' => ['title' => 'Engineer'],
            'from_date' => self::HIRED,
            'to_date' => self::CURRENT,
        ], $this->onlyEntry('employee.title_corrected')->changes);
    }

    public function test_the_case_of_a_title_can_be_corrected(): void
    {
        $employee = $this->currentEmployee(title: 'senior engineer');

        $this->put(route('employees.title.correct', $employee->emp_no), ['title' => 'Senior Engineer'])->assertSessionHasNoErrors();

        $this->assertSame('Senior Engineer', $this->current('titles', $employee->emp_no)->title);
    }

    public function test_former_employees_latest_rows_can_be_corrected_too(): void
    {
        $employee = $this->formerEmployee(Department::factory()->create());

        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '51000'])->assertSessionHasNoErrors();

        $rows = $this->rows('salaries', $employee->emp_no);
        $this->assertSame(['salary' => 51000, 'to_date' => '2000-06-30'], ['salary' => end($rows)['salary'], 'to_date' => end($rows)['to_date']]);
    }

    public function test_unchanged_or_invalid_corrections_are_rejected(): void
    {
        $employee = $this->currentEmployee(title: 'Engineer', salary: 60000);

        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '60000'])
            ->assertSessionHasErrorsIn('correctSalary', ['salary' => 'The amount is unchanged.']);
        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '0'])
            ->assertSessionHasErrorsIn('correctSalary', ['salary']);
        $this->put(route('employees.title.correct', $employee->emp_no), ['title' => 'Engineer'])
            ->assertSessionHasErrorsIn('correctTitle', ['title' => 'The job title is unchanged.']);

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_an_employee_without_history_has_nothing_to_correct(): void
    {
        $employee = \App\Models\Employee::factory()->create(['first_name' => 'Ola', 'last_name' => 'Nowa']);

        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '1000'])
            ->assertSessionHasErrorsIn('correctSalary', ['salary' => 'Ola Nowa has no salary history.']);
        $this->put(route('employees.title.correct', $employee->emp_no), ['title' => 'Staff'])
            ->assertSessionHasErrorsIn('correctTitle', ['title' => 'Ola Nowa has no job title history.']);
    }
}
