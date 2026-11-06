<?php

namespace Tests\Feature\HrOperations;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * A form acts on the row it showed: when that row is no longer the latest (or was
 * changed) at submit time, it is refused instead of hitting whatever is latest now.
 */
class StaleFormsTest extends HrOperationsTestCase
{
    private const MESSAGE = 'The data changed in the meantime. Reload the page and try again.';

    public function test_the_forms_carry_the_row_they_show(): void
    {
        $employee = $this->currentEmployee(title: 'Engineer', salary: 60000);

        $this->get($this->profile($employee))
            ->assertOk()
            ->assertSee('name="shown_salary" value="60000"', false)
            ->assertSee('name="row_salary" value="60000"', false)
            ->assertSee('name="row_title" value="Engineer"', false)
            ->assertSee('name="row_from_date" value="'.self::CURRENT_SALARY_FROM.'"', false)
            ->assertSee('name="row_from_date" value="'.self::HIRED.'"', false);
    }

    public function test_a_salary_correction_of_a_row_that_is_no_longer_the_latest_is_refused(): void
    {
        $employee = $this->currentEmployee(salary: 60000);
        $form = ['salary' => '61000', 'reason' => 'Typo', ...$this->salaryRow($employee)];

        // Someone else raises the salary while the form is open.
        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '65000', 'effective_date' => '2024-01-01'])->assertSessionHasNoErrors();

        $this->put(route('employees.salary.correct', $employee->emp_no), $form)
            ->assertSessionHasErrorsIn('correctSalary', ['stale' => self::MESSAGE]);

        $this->assertSame(65000, $this->current('salaries', $employee->emp_no)->salary);
        $this->assertSame(0, ActivityLog::where('action', 'employee.salary_corrected')->count());
    }

    public function test_a_salary_correction_of_an_amount_changed_meanwhile_is_refused(): void
    {
        $employee = $this->currentEmployee(salary: 60000);
        $form = ['salary' => '61000', 'reason' => 'Typo', ...$this->salaryRow($employee)];

        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '62000', 'reason' => 'First', ...$this->salaryRow($employee)])->assertSessionHasNoErrors();

        $this->put(route('employees.salary.correct', $employee->emp_no), $form)
            ->assertSessionHasErrorsIn('correctSalary', ['stale' => self::MESSAGE]);
        $this->assertSame(62000, $this->current('salaries', $employee->emp_no)->salary);
    }

    public function test_a_title_correction_of_a_row_that_changed_is_refused(): void
    {
        $employee = $this->currentEmployee(title: 'Enginer');
        $url = route('employees.title.correct', $employee->emp_no);
        $form = ['title' => 'Engineer', 'reason' => 'Spelling', ...$this->titleRow($employee)];

        // Same start date, other wording: a correction by someone else.
        $this->put($url, ['title' => 'Enginneer', 'reason' => 'Other', ...$this->titleRow($employee)])->assertSessionHasNoErrors();
        $this->put($url, $form)->assertSessionHasErrorsIn('correctTitle', ['stale' => self::MESSAGE]);
        $this->assertSame('Enginneer', $this->current('titles', $employee->emp_no)->title);

        // A newer row altogether.
        $form = ['title' => 'Engineer', 'reason' => 'Spelling', ...$this->titleRow($employee)];
        $this->post(route('employees.title', $employee->emp_no), ['title' => 'Lead', 'kind' => 'promotion', 'effective_date' => '2024-01-01'])->assertSessionHasNoErrors();
        $this->put($url, $form)->assertSessionHasErrorsIn('correctTitle', ['stale' => self::MESSAGE]);
        $this->assertSame('Lead', $this->current('titles', $employee->emp_no)->title);
    }

    public function test_a_percent_change_of_a_salary_that_changed_is_refused(): void
    {
        $employee = $this->currentEmployee(salary: 60000);
        $url = route('employees.salary', $employee->emp_no);

        $this->post($url, ['mode' => 'amount', 'salary' => '70000', 'effective_date' => '2024-01-01'])->assertSessionHasNoErrors();

        $this->post($url, ['mode' => 'percent', 'percent' => '10', 'shown_salary' => '60000', 'effective_date' => '2024-06-01'])
            ->assertSessionHasErrorsIn('salary', ['stale' => self::MESSAGE]);
        $this->post($url, ['mode' => 'percent', 'percent' => '10', 'effective_date' => '2024-06-01'])
            ->assertSessionHasErrorsIn('salary', ['stale' => self::MESSAGE]);
        $this->assertSame(70000, $this->current('salaries', $employee->emp_no)->salary);

        $this->post($url, ['mode' => 'percent', 'percent' => '10', 'shown_salary' => '70000', 'effective_date' => '2024-06-01'])->assertSessionHasNoErrors();
        $this->assertSame(77000, $this->current('salaries', $employee->emp_no)->salary);
    }

    public function test_an_amount_needs_no_shown_salary(): void
    {
        $employee = $this->currentEmployee(salary: 60000);

        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '61000', 'shown_salary' => '1', 'effective_date' => '2024-01-01'])
            ->assertSessionHasNoErrors();
    }

    public function test_corrections_need_the_row_identity(): void
    {
        $employee = $this->currentEmployee(title: 'Engineer', salary: 60000);

        $this->put(route('employees.salary.correct', $employee->emp_no), ['salary' => '61000', 'reason' => 'x'])
            ->assertSessionHasErrorsIn('correctSalary', ['row_from_date', 'row_salary']);
        $this->put(route('employees.title.correct', $employee->emp_no), ['title' => 'Lead', 'reason' => 'x'])
            ->assertSessionHasErrorsIn('correctTitle', ['row_from_date', 'row_title']);

        $this->assertSame(0, DB::table('activity_log')->count());
    }

    public function test_the_refusal_is_shown_in_the_reopened_form(): void
    {
        $employee = $this->currentEmployee(salary: 60000);
        $stale = ['salary' => '61000', 'reason' => 'Typo', 'row_from_date' => '1999-01-01', 'row_salary' => '1'];

        $this->from($this->profile($employee))
            ->followingRedirects()
            ->put(route('employees.salary.correct', $employee->emp_no), $stale)
            ->assertOk()
            ->assertSee(self::MESSAGE)
            ->assertSee('data-open-modal="correctSalaryModal"', false);
    }
}
