<?php

namespace Tests\Feature\HrOperations;

use App\Models\Department;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

class TitleAndSalaryTest extends HrOperationsTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function kindProvider(): array
    {
        return [
            'promotion' => ['promotion', 'employee.promoted'],
            'demotion' => ['demotion', 'employee.demoted'],
            'other change' => ['change', 'employee.title_changed'],
        ];
    }

    #[DataProvider('kindProvider')]
    public function test_a_title_change_closes_the_current_title(string $kind, string $action): void
    {
        $department = Department::factory()->create();
        $employee = $this->currentEmployee($department, 'Engineer');
        $this->primeCache([$department->dept_no]);

        $this->post(route('employees.title', $employee->emp_no), ['title' => 'Senior Engineer', 'kind' => $kind, 'effective_date' => '2023-01-01'])
            ->assertRedirect($this->profile($employee))
            ->assertSessionHas('status', 'The job title of Anna Nowak is Senior Engineer from 01.01.2023.');

        $this->assertSame([
            ['title' => 'Engineer', 'from_date' => self::HIRED, 'to_date' => '2023-01-01'],
            ['title' => 'Senior Engineer', 'from_date' => '2023-01-01', 'to_date' => self::CURRENT],
        ], $this->rows('titles', $employee->emp_no, ['title', 'from_date', 'to_date']));

        $this->assertEquals([
            'before' => ['title' => 'Engineer'],
            'after' => ['title' => 'Senior Engineer'],
            'effective_date' => '2023-01-01',
        ], $this->onlyEntry($action)->changes);
        $this->assertCacheFlushed([$department->dept_no]);
    }

    public function test_title_change_rules(): void
    {
        $employee = $this->currentEmployee(title: 'Engineer');
        $url = route('employees.title', $employee->emp_no);

        $this->post($url, ['title' => 'engineer', 'kind' => 'change', 'effective_date' => '2023-01-01'])
            ->assertSessionHasErrorsIn('title', ['title' => 'This is already the current job title.']);
        // Same day as the current row: would collide / give an empty period.
        $this->post($url, ['title' => 'Senior Engineer', 'kind' => 'promotion', 'effective_date' => self::HIRED])
            ->assertSessionHasErrorsIn('title', ['effective_date' => 'The date must be 02.03.1995 or later (after the start of the current job title).']);
        $this->post($url, ['title' => 'Senior Engineer', 'kind' => 'promotion', 'effective_date' => CarbonImmutable::tomorrow()->toDateString()])
            ->assertSessionHasErrorsIn('title', ['effective_date']);
        $this->post($url, ['title' => 'Senior Engineer', 'kind' => 'sideways', 'effective_date' => '2023-01-01'])
            ->assertSessionHasErrorsIn('title', ['kind']);
        $this->post($url, ['title' => str_repeat('t', 51), 'kind' => 'change', 'effective_date' => '2023-01-01'])
            ->assertSessionHasErrorsIn('title', ['title']);

        $this->assertCount(1, $this->rows('titles', $employee->emp_no));
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_a_second_change_must_start_after_the_first(): void
    {
        $employee = $this->currentEmployee(title: 'Engineer');
        $url = route('employees.title', $employee->emp_no);

        $this->post($url, ['title' => 'Senior Engineer', 'kind' => 'promotion', 'effective_date' => '2023-01-01'])->assertSessionHasNoErrors();
        $this->post($url, ['title' => 'Technique Leader', 'kind' => 'promotion', 'effective_date' => '2023-01-01'])
            ->assertSessionHasErrorsIn('title', ['effective_date']);
        $this->post($url, ['title' => 'Technique Leader', 'kind' => 'promotion', 'effective_date' => '2023-01-02'])->assertSessionHasNoErrors();

        $this->assertSame(['Engineer', 'Senior Engineer', 'Technique Leader'], array_column($this->rows('titles', $employee->emp_no), 'title'));
    }

    public function test_a_salary_change_by_amount(): void
    {
        $department = Department::factory()->create();
        $employee = $this->currentEmployee($department, salary: 60000);
        $this->primeCache([$department->dept_no]);

        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '64500', 'effective_date' => '2024-01-01'])
            ->assertRedirect($this->profile($employee))
            ->assertSessionHas('status', 'The salary of Anna Nowak changes from 60,000 to 64,500 on 01.01.2024.');

        $rows = $this->rows('salaries', $employee->emp_no, ['salary', 'from_date', 'to_date']);
        $this->assertSame(['salary' => 60000, 'from_date' => self::CURRENT_SALARY_FROM, 'to_date' => '2024-01-01'], $rows[count($rows) - 2]);
        $this->assertSame(['salary' => 64500, 'from_date' => '2024-01-01', 'to_date' => self::CURRENT], $rows[count($rows) - 1]);

        $this->assertEquals([
            'before' => ['salary' => 60000],
            'after' => ['salary' => 64500],
            'effective_date' => '2024-01-01',
            'change_percent' => 7.5,
        ], $this->onlyEntry('employee.salary_changed')->changes);
        $this->assertCacheFlushed([$department->dept_no]);

        $this->get($this->profile($employee))->assertSee('64,500 USD');
    }

    public function test_a_salary_change_in_percent_including_a_cut(): void
    {
        $employee = $this->currentEmployee(salary: 60000);
        $url = route('employees.salary', $employee->emp_no);

        $this->post($url, ['mode' => 'percent', 'percent' => '3.5', 'salary' => 'ignored', 'effective_date' => '2024-01-01'])
            ->assertSessionHasNoErrors();
        $this->assertSame(62100, $this->current('salaries', $employee->emp_no)->salary);
        $this->assertEquals(3.5, $this->onlyEntry('employee.salary_changed')->changes['requested_percent']);

        $this->post($url, ['mode' => 'percent', 'percent' => '-10', 'effective_date' => '2024-06-01'])->assertSessionHasNoErrors();
        $this->assertSame(55890, $this->current('salaries', $employee->emp_no)->salary);
    }

    public function test_salary_change_rules(): void
    {
        $employee = $this->currentEmployee(salary: 60000);
        $url = route('employees.salary', $employee->emp_no);

        $this->post($url, ['mode' => 'amount', 'salary' => '60000', 'effective_date' => '2024-01-01'])
            ->assertSessionHasErrorsIn('salary', ['salary' => 'The new salary is the same as the current one.']);
        $this->post($url, ['mode' => 'amount', 'salary' => '61000', 'effective_date' => self::CURRENT_SALARY_FROM])
            ->assertSessionHasErrorsIn('salary', ['effective_date']);
        $this->post($url, ['mode' => 'amount', 'salary' => '61000', 'effective_date' => '2001-12-31'])
            ->assertSessionHasErrorsIn('salary', ['effective_date']);
        $this->post($url, ['mode' => 'amount', 'salary' => '61000', 'effective_date' => CarbonImmutable::tomorrow()->toDateString()])
            ->assertSessionHasErrorsIn('salary', ['effective_date']);
        $this->post($url, ['mode' => 'amount', 'salary' => '0', 'effective_date' => '2024-01-01'])
            ->assertSessionHasErrorsIn('salary', ['salary']);
        $this->post($url, ['mode' => 'amount', 'effective_date' => '2024-01-01'])
            ->assertSessionHasErrorsIn('salary', ['salary']);
        $this->post($url, ['mode' => 'percent', 'percent' => '0', 'effective_date' => '2024-01-01'])
            ->assertSessionHasErrorsIn('salary', ['percent']);
        $this->post($url, ['mode' => 'percent', 'percent' => '150', 'effective_date' => '2024-01-01'])
            ->assertSessionHasErrorsIn('salary', ['percent']);
        $this->post($url, ['mode' => 'percent', 'percent' => '-60', 'effective_date' => '2024-01-01'])
            ->assertSessionHasErrorsIn('salary', ['percent']);
        $this->post($url, ['mode' => 'both', 'salary' => '61000', 'effective_date' => '2024-01-01'])
            ->assertSessionHasErrorsIn('salary', ['mode']);

        $this->assertSame(60000, $this->current('salaries', $employee->emp_no)->salary);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_former_employees_get_no_title_or_salary_change(): void
    {
        $employee = $this->formerEmployee(Department::factory()->create());

        $this->post(route('employees.title', $employee->emp_no), ['title' => 'Senior Staff', 'kind' => 'promotion', 'effective_date' => '2024-01-01'])
            ->assertSessionHasErrorsIn('title', ['title' => 'Jan Kowalski is not currently employed.']);
        $this->post(route('employees.salary', $employee->emp_no), ['mode' => 'amount', 'salary' => '70000', 'effective_date' => '2024-01-01'])
            ->assertSessionHasErrorsIn('salary', ['salary' => 'Jan Kowalski is not currently employed.']);
    }
}
