<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use App\Support\Ability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The documented contract (docs/AUTHORIZATION.md), spelled out independently
     * of Ability::MATRIX so an accidental change of the matrix fails here.
     */
    private const EXPECTED = [
        'view-salaries' => ['admin', 'hr', 'manager'],
        'manage-employees' => ['admin', 'hr'],
        'manage-departments' => ['admin', 'hr'],
        'manage-users' => ['admin'],
        'view-audit-log' => ['admin', 'hr'],
        'export-data' => ['admin', 'hr', 'manager'],
        'import-data' => ['admin', 'hr'],
        'view-reports' => ['admin', 'hr', 'manager'],
    ];

    public function test_matrix_defines_exactly_the_documented_abilities(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(self::EXPECTED), Ability::all());
    }

    /**
     * @return array<string, array{Role, string, bool}>
     */
    public static function roleAbilityProvider(): array
    {
        $cases = [];
        foreach (self::EXPECTED as $ability => $roles) {
            foreach (Role::cases() as $role) {
                $cases["{$role->value} {$ability}"] = [$role, $ability, in_array($role->value, $roles, true)];
            }
        }

        return $cases;
    }

    #[DataProvider('roleAbilityProvider')]
    public function test_gate_per_role(Role $role, string $ability, bool $expected): void
    {
        $user = User::factory()->role($role)->create(['dept_no' => $role === Role::Manager ? 'd001' : null]);

        $this->assertSame($expected, Gate::forUser($user)->allows($ability));
        $this->assertSame($expected, Ability::check($user, $ability));
    }

    public function test_inactive_users_have_no_ability(): void
    {
        $user = User::factory()->admin()->inactive()->create();

        foreach (Ability::all() as $ability) {
            $this->assertFalse(Gate::forUser($user)->allows($ability), $ability);
        }

        $this->assertFalse(Gate::forUser($user)->allows('view', Employee::factory()->create()));
    }

    public function test_employee_policy_management_follows_manage_employees(): void
    {
        $employee = Employee::factory()->create();

        $this->assertTrue(Gate::forUser(User::factory()->hr()->create())->allows('update', $employee));
        $this->assertFalse(Gate::forUser(User::factory()->viewer()->create())->allows('update', $employee));
        $this->assertFalse(Gate::forUser(User::factory()->manager('d001')->create())->allows('delete', $employee));
        $this->assertTrue(Gate::forUser(User::factory()->viewer()->create())->allows('viewAny', Employee::class));
    }

    public function test_bulk_export_requires_export_data(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->post(route('employees.export'), ['format' => 'csv', 'selectAll' => '1'])
            ->assertForbidden();
    }

    public function test_user_management_requires_manage_users(): void
    {
        foreach ([Role::Hr, Role::Manager, Role::Viewer] as $role) {
            $actor = User::factory()->role($role)->create(['dept_no' => $role === Role::Manager ? 'd001' : null]);
            $other = User::factory()->create();

            $this->actingAs($actor);
            $this->get(route('users.index'))->assertForbidden();
            $this->get(route('users.create'))->assertForbidden();
            $this->post(route('users.store'), [])->assertForbidden();
            $this->get(route('users.edit', $other))->assertForbidden();
            $this->put(route('users.update', $other), [])->assertForbidden();
            $this->put(route('users.password', $other), [])->assertForbidden();
            $this->delete(route('users.destroy', $other))->assertForbidden();
        }

        $this->assertDatabaseCount('users', 6);
    }

    public function test_navbar_only_shows_links_the_user_can_use(): void
    {
        $this->actingAs(User::factory()->viewer()->create(['name' => 'Vera Viewer']))
            ->get(route('index'))
            ->assertOk()
            ->assertSee('Vera Viewer')
            ->assertSee('Viewer')
            ->assertSee(route('account.edit'))
            ->assertSee(route('logout'))
            ->assertDontSee(route('users.index'))
            ->assertDontSee('Export Data');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('index'))
            ->assertSee(route('users.index'))
            ->assertSee('Export Data')
            ->assertSee('Administrator');
    }

    public function test_viewer_does_not_get_the_export_ui(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('employees.index'))
            ->assertOk()
            ->assertDontSee('id="exportModal"', false)
            ->assertDontSee('Export Selected');
    }
}
