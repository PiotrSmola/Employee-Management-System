<?php

namespace Tests\Unit;

use App\Support\AuditLogPresenter;
use Tests\TestCase;

/**
 * Needs the application only for the translator (__()); no database.
 */
class AuditLogPresenterTest extends TestCase
{
    private function presenter(bool $withSalaries = true): AuditLogPresenter
    {
        return new AuditLogPresenter(['d005' => 'Development', 'd007' => 'Sales'], $withSalaries);
    }

    public function test_action_labels_and_classes(): void
    {
        $this->assertSame('Salary changed', AuditLogPresenter::actionLabel('employee.salary_changed'));
        $this->assertSame('Login failed', AuditLogPresenter::actionLabel('auth.login_failed'));
        $this->assertSame('Something', AuditLogPresenter::actionLabel('something'));
        $this->assertSame('text-bg-success', AuditLogPresenter::actionClass('employee.hired'));
        $this->assertSame('text-bg-danger', AuditLogPresenter::actionClass('employee.terminated'));
        $this->assertSame('text-bg-warning', AuditLogPresenter::actionClass('employee.salary_corrected'));
        $this->assertSame('text-bg-primary', AuditLogPresenter::actionClass('employee.transferred'));
        $this->assertSame('text-bg-secondary', AuditLogPresenter::actionClass('data.imported'));
    }

    public function test_before_after_pairs_come_first_then_the_payload(): void
    {
        $rows = $this->presenter()->rows([
            'effective_date' => '2024-05-01',
            'before' => ['dept_no' => 'd005'],
            'after' => ['dept_no' => 'd007'],
            'ended_management' => ['d005'],
        ]);

        $this->assertSame([
            ['field' => 'Department', 'before' => 'Development (d005)', 'after' => 'Sales (d007)', 'change' => true],
            ['field' => 'Effective date', 'before' => null, 'after' => '01.05.2024', 'change' => false],
            ['field' => 'Ended management of', 'before' => null, 'after' => 'Development (d005)', 'change' => false],
        ], $rows);
    }

    public function test_created_and_deleted_entries(): void
    {
        $this->assertSame(
            [['field' => 'First name', 'before' => null, 'after' => 'Maria', 'change' => true]],
            $this->presenter()->rows(['after' => ['first_name' => 'Maria']]),
        );
        $this->assertSame(
            [['field' => 'Active', 'before' => 'Yes', 'after' => null, 'change' => true]],
            $this->presenter()->rows(['before' => ['is_active' => true]]),
        );
        $this->assertSame([], $this->presenter()->rows(null));
        $this->assertSame([], $this->presenter()->rows([]));
    }

    public function test_value_formatting(): void
    {
        $presenter = $this->presenter();

        $this->assertSame('64,500', $presenter->value('salary', 64500));
        $this->assertSame('+7.5 %', $presenter->value('change_percent', 7.5));
        $this->assertSame('-10 %', $presenter->value('requested_percent', -10));
        $this->assertSame('present', $presenter->value('to_date', '9999-01-01'));
        $this->assertSame('Female', $presenter->value('gender', 'F'));
        $this->assertSame('No', $presenter->value('is_active', false));
        $this->assertSame('-', $presenter->value('reason', null));
        $this->assertSame('Development (d005), d404', $presenter->value('dept_no', 'd005, d404'));
        $this->assertSame('a, b', $presenter->value('tags', ['a', 'b']));
        $this->assertSame('{"rows":3}', $presenter->value('stats', ['rows' => 3]));
        $this->assertSame('<b>x</b>', $presenter->value('reason', '<b>x</b>'), 'Escaping is left to Blade.');
    }

    public function test_salary_figures_are_left_out_without_view_salaries(): void
    {
        $rows = $this->presenter(withSalaries: false)->rows([
            'before' => ['salary' => 60000, 'title' => 'Staff'],
            'after' => ['salary' => 63000, 'title' => 'Senior Staff'],
            'change_percent' => 5,
            'effective_date' => '2024-01-01',
        ]);

        $this->assertSame(['Title', 'Effective date'], array_column($rows, 'field'));
    }
}
