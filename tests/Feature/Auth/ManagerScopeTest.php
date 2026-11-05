<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Livewire\EmployeeFilter;
use App\Livewire\EmployeeList;
use App\Models\Employee;
use App\Models\User;
use App\Queries\EmployeeSearchQuery;
use App\Support\EmployeeVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BuildsDirectory;
use Tests\TestCase;

/**
 * A manager only sees the employees of their department: current members and
 * former employees whose last department it was. With the BuildsDirectory data
 * the Alpha members are 1 and 6 (current) and 4 (former, last dept Alpha);
 * 3 left from Mid (was Alpha before) and is not an Alpha member.
 */
class ManagerScopeTest extends TestCase
{
    use ActsAsAdmin;
    use BuildsDirectory;
    use RefreshDatabase;

    private const ALPHA_MEMBERS = [1, 4, 6];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildDirectory();
        $this->signInAs(Role::Manager, $this->alpha);
    }

    public function test_list_only_shows_the_managers_department(): void
    {
        $component = Livewire::test(EmployeeList::class)->assertSee('3 employee(s) found');

        $this->assertSame(self::ALPHA_MEMBERS, $this->listedIds($component));
    }

    public function test_department_filter_cannot_widen_the_scope(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('updateFilters', ['department' => $this->omega->dept_no])
            ->assertSee('3 employee(s) found');
        $this->assertSame(self::ALPHA_MEMBERS, $this->listedIds($component));

        $component->set('department', 'all');
        $this->assertSame(self::ALPHA_MEMBERS, $this->listedIds($component));
        $this->assertSame($this->alpha->dept_no, $component->instance()->getExportPayload()['department']);
    }

    public function test_other_filters_still_apply_within_the_scope(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('updateFilters', ['employmentStatus' => 'former'])
            ->assertSee('1 employee(s) found');

        $this->assertSame([4], $this->listedIds($component));
    }

    public function test_filter_component_shows_only_the_locked_department(): void
    {
        Livewire::test(EmployeeFilter::class)
            ->assertSet('department', $this->alpha->dept_no)
            ->assertSee('Alpha')
            ->assertDontSee('Omega')
            ->assertSee('Salary Range');
    }

    public function test_profile_and_profile_pdf_outside_the_department_are_forbidden(): void
    {
        foreach ([2, 3, 5, 7] as $outside) {
            $this->get(route('employees.show', $outside))->assertForbidden();
            $this->get(route('employees.export-show-pdf', $outside))->assertForbidden();
        }

        foreach (self::ALPHA_MEMBERS as $inside) {
            $this->get(route('employees.show', $inside))->assertOk();
        }

        // Managers see salaries of their own employees.
        $this->get(route('employees.show', 1))->assertSee('50,000');
        $this->get(route('employees.show', 999999))->assertNotFound();
    }

    public function test_export_only_contains_the_managers_department(): void
    {
        $content = $this->post(route('employees.export'), ['format' => 'csv', 'selectAll' => '1'])
            ->assertOk()
            ->streamedContent();
        $this->assertSame(self::ALPHA_MEMBERS, $this->csvIds($content));

        // Explicitly selected employees outside the scope are dropped.
        $content = $this->post(route('employees.export'), [
            'format' => 'csv', 'selectAll' => '0', 'selectedEmployees' => [2, 1, 3],
            'department' => $this->omega->dept_no,
        ])->assertOk()->streamedContent();
        $this->assertSame([1], $this->csvIds($content));
    }

    public function test_dashboard_is_limited_to_the_department(): void
    {
        $this->get(route('index'))
            ->assertOk()
            ->assertViewHas('totalEmployees', 2) // current members only
            ->assertViewHas('scopeDepartment', fn ($department) => $department->is($this->alpha))
            ->assertViewHas('averageSalary', fn ($avg) => (float) $avg === 50000.0)
            ->assertViewHas('recentEmployees', fn ($recent) => $recent->pluck('emp_no')->sort()->values()->all() === [1, 6])
            ->assertViewHas('employeesByTitle', fn ($titles) => $titles->pluck('total', 'title')->all() === ['Engineer' => 2])
            ->assertSee('Alpha')
            ->assertDontSee('Bob Jones');
    }

    public function test_manager_without_a_department_sees_nobody(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Manager, 'dept_no' => null]));

        Livewire::test(EmployeeList::class)->assertSee('0 employee(s) found');
        $this->get(route('employees.show', 1))->assertForbidden();
        $this->get(route('index'))->assertOk()->assertViewHas('totalEmployees', 0);
    }

    public function test_visibility_constrains_any_query_builder(): void
    {
        $visibility = EmployeeVisibility::for($this->user);

        $ids = $visibility->constrain(DB::table('employees as e'), 'e.emp_no')->orderBy('e.emp_no')->pluck('e.emp_no')->all();
        $this->assertSame(self::ALPHA_MEMBERS, array_map('intval', $ids));

        $eloquent = $visibility->constrain(Employee::query())->orderBy('emp_no')->pluck('emp_no')->all();
        $this->assertSame(self::ALPHA_MEMBERS, $eloquent);

        $this->assertTrue($visibility->canView(4));
        $this->assertFalse($visibility->canView(3));
        $this->assertSame($this->alpha->dept_no, $visibility->department());
    }

    public function test_other_roles_are_not_scoped(): void
    {
        foreach ([Role::Admin, Role::Hr, Role::Viewer] as $role) {
            $visibility = EmployeeVisibility::for(User::factory()->role($role)->create());

            $this->assertFalse($visibility->isDepartmentScoped());
            $this->assertSame(7, $visibility->constrain(DB::table('employees'))->count());
            $this->assertTrue($visibility->canView(2));
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function departmentCountProvider(): array
    {
        $cases = [];
        foreach (['alpha', 'mid', 'omega'] as $dept) {
            foreach (['all', 'current', 'former'] as $status) {
                $cases["{$dept} {$status}"] = [$dept, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('departmentCountProvider')]
    public function test_department_only_count_matches_the_filtered_query(string $dept, string $status): void
    {
        $query = EmployeeSearchQuery::make(['department' => $this->{$dept}->dept_no, 'employmentStatus' => $status]);

        $this->assertSame($query->filtered()->count(), $query->count());
    }

    private function listedIds($component): array
    {
        return collect($component->viewData('employees')->items())->pluck('emp_no')->map(fn ($id) => (int) $id)->all();
    }

    private function csvIds(string $content): array
    {
        $lines = array_slice(array_filter(explode("\n", trim($content))), 1);

        return array_map(fn ($line) => (int) str_getcsv(ltrim($line, "\xEF\xBB\xBF"))[0], $lines);
    }
}
