<?php

namespace Tests\Feature\HrOperations;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Carbon;

class AuditLogPageTest extends HrOperationsTestCase
{
    private function entry(string $action, string $type, ?string $id, array $changes = [], ?User $user = null, string $at = '2026-01-10 12:00:00'): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'changes' => $changes === [] ? null : $changes,
            'ip' => '10.0.0.1',
            'created_at' => Carbon::parse($at),
        ]);
    }

    public function test_only_admins_and_hr_open_the_audit_log(): void
    {
        $this->get(route('audit-log.index'))->assertOk()->assertSee('Audit log');

        $this->signInAs(Role::Hr);
        $this->get(route('audit-log.index'))->assertOk();

        $this->signInAs(Role::Manager, 'd001');
        $this->get(route('audit-log.index'))->assertForbidden();

        $this->signInAs(Role::Viewer);
        $this->get(route('audit-log.index'))->assertForbidden();
    }

    public function test_the_navbar_links_the_audit_log_for_hr_staff_only(): void
    {
        $this->get(route('index'))->assertSee(route('audit-log.index'));

        $this->signInAs(Role::Viewer);
        $this->get(route('index'))->assertDontSee(route('audit-log.index'));
    }

    public function test_entries_are_rendered_readably_with_links_to_the_subjects(): void
    {
        $department = Department::factory()->create(['dept_name' => 'Research']);
        $employee = $this->currentEmployee();
        $actor = User::factory()->hr()->create(['name' => 'Helga HR']);
        $this->entry('employee.transferred', 'employee', (string) $employee->emp_no, [
            'before' => ['dept_no' => 'd999'],
            'after' => ['dept_no' => $department->dept_no],
            'effective_date' => '2024-05-01',
        ], $actor);
        $this->entry('employee.salary_changed', 'employee', (string) $employee->emp_no, [
            'before' => ['salary' => 60000], 'after' => ['salary' => 64500], 'change_percent' => 7.5,
        ], $actor);
        $this->entry('auth.login_failed', 'user', null, ['email' => 'x@example.com', 'reason' => 'invalid_credentials']);

        $this->get(route('audit-log.index'))
            ->assertOk()
            ->assertSee('Transferred')
            ->assertSee('employee.transferred')
            ->assertSee('Helga HR')
            ->assertSee('Anna Nowak')
            ->assertSee(route('employees.show', $employee->emp_no))
            ->assertSeeInOrder(['Department', 'd999', 'Research ('.$department->dept_no.')'])
            ->assertSee('Effective date')
            ->assertSee('01.05.2024')
            ->assertSeeInOrder(['Salary', '60,000', '64,500'])
            ->assertSee('+7.5 %')
            ->assertSee('Login failed')
            ->assertSee('System')
            ->assertSee('10.0.0.1');
    }

    public function test_filters(): void
    {
        $alice = User::factory()->admin()->create(['name' => 'Alice Admin']);
        $bob = User::factory()->hr()->create(['name' => 'Bob HR']);
        $this->entry('employee.hired', 'employee', '10001', ['after' => ['first_name' => 'Hired-One']], $alice, '2026-01-05 08:00:00');
        $this->entry('employee.terminated', 'employee', '10001', ['reason' => 'Terminated-One'], $bob, '2026-02-05 08:00:00');
        $this->entry('employee.hired', 'employee', '10002', ['after' => ['first_name' => 'Hired-Two']], $bob, '2026-03-05 08:00:00');
        $this->entry('user.created', 'user', '10001', ['after' => ['name' => 'User-Created']], $alice, '2026-03-06 08:00:00');
        $this->entry('auth.login_failed', 'user', null, ['email' => 'Failed-Login'], null, '2026-03-07 08:00:00');

        $cases = [
            [['action' => 'employee.hired'], ['Hired-One', 'Hired-Two']],
            [['action' => 'employee.*'], ['Hired-One', 'Terminated-One', 'Hired-Two']],
            [['subject_type' => 'employee', 'subject_id' => '10001'], ['Hired-One', 'Terminated-One']],
            [['subject_id' => '10001'], ['Hired-One', 'Terminated-One', 'User-Created']],
            [['subject_type' => 'user'], ['User-Created', 'Failed-Login']],
            [['user' => (string) $bob->id], ['Terminated-One', 'Hired-Two']],
            [['user' => 'none'], ['Failed-Login']],
            [['from' => '2026-02-01', 'to' => '2026-03-05'], ['Terminated-One', 'Hired-Two']],
            [['to' => '2026-01-31'], ['Hired-One']],
            [['action' => 'employee.hired', 'from' => '2026-03-01'], ['Hired-Two']],
            // Invalid values are ignored instead of failing the page.
            [['from' => 'yesterday', 'user' => 'bob', 'action' => "x' or 1=1"], ['Hired-One', 'Terminated-One', 'Hired-Two', 'User-Created', 'Failed-Login']],
        ];
        $all = ['Hired-One', 'Terminated-One', 'Hired-Two', 'User-Created', 'Failed-Login'];

        foreach ($cases as [$filters, $expected]) {
            $response = $this->get(route('audit-log.index', $filters))->assertOk();
            foreach ($all as $marker) {
                in_array($marker, $expected, true)
                    ? $response->assertSee($marker)
                    : $response->assertDontSee($marker);
            }
        }
    }

    public function test_entries_are_newest_first_and_paginated_without_counting(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->entry('employee.updated', 'employee', (string) (20000 + $i), ['after' => ['last_name' => sprintf('Marker%02d', $i)]],
                at: Carbon::parse('2026-01-01 00:00:00')->addMinutes($i)->toDateTimeString());
        }

        $this->get(route('audit-log.index'))
            ->assertOk()
            ->assertSeeInOrder(['Marker30', 'Marker29'])
            ->assertSee('Marker06')
            ->assertDontSee('Marker05')
            ->assertSee('page=2');

        $this->get(route('audit-log.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Marker05')
            ->assertSee('Marker01')
            ->assertDontSee('Marker06');
    }

    public function test_filters_survive_paging(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->entry('employee.updated', 'employee', '10001', ['after' => ['last_name' => 'X'.$i]]);
        }

        $this->get(route('audit-log.index', ['action' => 'employee.updated']))
            ->assertOk()
            ->assertSee('action=employee.updated&amp;page=2', false);
    }
}
