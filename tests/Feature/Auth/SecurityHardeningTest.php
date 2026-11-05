<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Livewire\EmployeeFilter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BuildsDirectory;
use Tests\TestCase;

/**
 * Fixes from the security review of the authentication / roles work.
 */
class SecurityHardeningTest extends TestCase
{
    use ActsAsAdmin;
    use BuildsDirectory;
    use RefreshDatabase;

    public function test_failed_pdf_export_shows_a_generic_message_without_exception_details(): void
    {
        Employee::factory()->current()->create();
        Pdf::shouldReceive('loadView')->andThrow(new RuntimeException('SQLSTATE secret /var/www/html/path'));

        $this->from(route('employees.index'))
            ->post(route('employees.export'), ['format' => 'pdf', 'selectAll' => '1'])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('error', fn (string $message) => ! str_contains($message, 'secret')
                && str_contains($message, 'PDF export failed'));
    }

    public function test_passwords_need_ten_characters_with_letters_and_numbers(): void
    {
        $data = ['name' => 'Nina', 'email' => 'nina@example.com', 'role' => 'viewer', 'is_active' => '1'];

        foreach (['short1', 'onlyletterslong', '1234567890123'] as $weak) {
            $this->post(route('users.store'), $data + ['password' => $weak, 'password_confirmation' => $weak])
                ->assertSessionHasErrors('password');
        }

        $this->post(route('users.store'), $data + ['password' => 'letters-and-42', 'password_confirmation' => 'letters-and-42'])
            ->assertSessionHasNoErrors();

        $this->put(route('account.password'), [
            'current_password' => 'password', 'password' => 'onlyletterslong', 'password_confirmation' => 'onlyletterslong',
        ])->assertSessionHasErrorsIn('password', 'password');
    }

    public function test_admin_cannot_reset_their_own_password_in_user_management(): void
    {
        $this->get(route('users.edit', $this->user))
            ->assertOk()
            ->assertDontSee(route('users.password', $this->user))
            ->assertSee(route('account.edit'));

        $this->put(route('users.password', $this->user), ['password' => 'new-password-42', 'password_confirmation' => 'new-password-42'])
            ->assertRedirect(route('account.edit'))
            ->assertSessionHas('error');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
        $this->get(route('index'))->assertOk(); // still signed in
    }

    public function test_own_password_change_keeps_the_session(): void
    {
        $this->put(route('account.password'), [
            'current_password' => 'password', 'password' => 'new-password-42', 'password_confirmation' => 'new-password-42',
        ])->assertSessionHasNoErrors();

        $this->get(route('users.index'))->assertOk();
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_password_change_is_throttled(): void
    {
        $wrong = ['current_password' => 'wrong', 'password' => 'new-password-42', 'password_confirmation' => 'new-password-42'];

        for ($i = 0; $i < 5; $i++) {
            $this->put(route('account.password'), $wrong)->assertRedirect();
        }

        $this->put(route('account.password'), $wrong)->assertTooManyRequests();
    }

    public function test_dashboard_cache_does_not_leak_between_roles_and_departments(): void
    {
        $this->buildDirectory();

        // The admin fills the company-wide cache, including the average salary.
        $this->get(route('index'))->assertViewHas('averageSalary', fn ($avg) => $avg !== null);

        $this->signInAs(Role::Viewer);
        $this->get(route('index'))
            ->assertViewHas('averageSalary', null)
            ->assertDontSee('Average Salary');

        // Managers of different departments get their own figures, in either order.
        $this->signInAs(Role::Manager, $this->alpha);
        $this->get(route('index'))
            ->assertViewHas('totalEmployees', 2)
            ->assertViewHas('averageSalary', fn ($avg) => (float) $avg === 50000.0);

        $this->signInAs(Role::Manager, $this->omega);
        $this->get(route('index'))
            ->assertViewHas('totalEmployees', 1)
            ->assertViewHas('averageSalary', fn ($avg) => (float) $avg === 90000.0)
            ->assertViewHas('recentEmployees', fn ($recent) => $recent->pluck('emp_no')->all() === [2]);

        $this->signInAs(Role::Manager, $this->alpha);
        $this->get(route('index'))->assertViewHas('totalEmployees', 2);

        // And the company-wide figures are still the admin's.
        $this->signInAs(Role::Admin);
        $this->get(route('index'))->assertViewHas('totalEmployees', 7);
    }

    public function test_manager_export_with_tampered_filters_only_contains_their_department(): void
    {
        $this->buildDirectory();
        $this->signInAs(Role::Manager, $this->alpha);

        $content = $this->post(route('employees.export'), [
            'format' => 'csv',
            'selectAll' => '1',
            'department' => $this->omega->dept_no,
            'employmentStatus' => 'all',
            'excludedEmployees' => [6],
        ])->assertOk()->streamedContent();

        $this->assertSame([1, 4], $this->csvIds($content));

        $content = $this->post(route('employees.export'), [
            'format' => 'csv',
            'selectAll' => '0',
            'department' => 'all',
            'selectedEmployees' => [2, 3, 5, 7, 4],
        ])->assertOk()->streamedContent();

        $this->assertSame([4], $this->csvIds($content));
    }

    public function test_manager_only_sees_job_titles_of_their_department(): void
    {
        $this->buildDirectory();
        $titles = fn () => Livewire::test(EmployeeFilter::class)->viewData('jobTitles')->pluck('title')->all();

        $this->assertSame(['Engineer', 'Manager', 'Senior Staff', 'Staff'], $titles());

        // Alpha members: 1 and 6 (Engineer), 4 (Manager).
        $this->signInAs(Role::Manager, $this->alpha);
        $this->assertSame(['Engineer', 'Manager'], $titles());

        $this->signInAs(Role::Manager, $this->omega);
        $this->assertSame(['Manager'], $titles());

        $this->actingAs(User::factory()->create(['role' => Role::Manager, 'dept_no' => null]));
        $this->assertSame([], $titles());
    }

    public function test_title_cache_is_kept_per_department(): void
    {
        $sales = Department::factory()->create();
        Employee::factory()->current($sales, 'Seller')->create();
        $this->signInAs(Role::Manager, $sales);

        Livewire::test(EmployeeFilter::class)->assertSee('Seller');

        $this->signInAs(Role::Manager, Department::factory()->create());
        Livewire::test(EmployeeFilter::class)->assertDontSee('Seller');
    }

    /**
     * @return int[]
     */
    private function csvIds(string $content): array
    {
        $lines = array_slice(array_filter(explode("\n", trim($content))), 1);

        return array_map(fn ($line) => (int) str_getcsv(ltrim($line, "\xEF\xBB\xBF"))[0], $lines);
    }
}
