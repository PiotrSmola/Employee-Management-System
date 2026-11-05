<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    private function inEnvironment(string $env): void
    {
        $this->app['env'] = $env;
        config(['app.env' => $env]);
    }

    private function seedUsers(): string
    {
        Artisan::call('db:seed', ['--class' => UserSeeder::class, '--force' => true]);

        return Artisan::output();
    }

    /**
     * @return array<string, string>
     */
    private function accounts(): array
    {
        return User::orderBy('email')->get()->mapWithKeys(fn ($u) => [$u->email => $u->role->value])->all();
    }

    public function test_fresh_local_install_gets_the_admin_and_the_demo_accounts(): void
    {
        $this->inEnvironment('local');
        config(['auth.initial_admin.password' => null, 'auth.seed_demo_users' => true]);

        $this->seedUsers();

        $this->assertSame([
            'admin@example.com' => 'admin',
            'hr@example.com' => 'hr',
            'manager@example.com' => 'manager',
            'viewer@example.com' => 'viewer',
        ], $this->accounts());
        $this->assertSame('d005', User::where('email', 'manager@example.com')->value('dept_no'));
        $this->assertTrue(User::all()->every(fn ($u) => $u->is_active && Hash::check('password', $u->password)));
    }

    public function test_deleted_or_renamed_accounts_do_not_come_back(): void
    {
        $this->inEnvironment('local');
        config(['auth.seed_demo_users' => true]);
        $this->seedUsers();

        // The admin renames their account and deletes the demo accounts.
        User::where('email', 'admin@example.com')->update(['email' => 'boss@company.test']);
        User::where('email', 'like', '%@example.com')->delete();

        $this->seedUsers();

        $this->assertSame(['boss@company.test' => 'admin'], $this->accounts());
    }

    public function test_no_admin_is_created_while_any_admin_exists_even_an_inactive_one(): void
    {
        User::factory()->admin()->inactive()->create(['email' => 'old-admin@company.test']);

        $this->seedUsers();

        $this->assertSame(['old-admin@company.test' => 'admin'], $this->accounts());
    }

    public function test_existing_accounts_are_never_changed(): void
    {
        User::factory()->viewer()->create(['email' => 'admin@example.com', 'password' => 'kept-password1', 'name' => 'Mine']);

        $output = $this->seedUsers();

        // No admin exists, but the configured e-mail is taken: nothing is created or changed.
        $this->assertSame(['admin@example.com' => 'viewer'], $this->accounts());
        $admin = User::sole();
        $this->assertSame('Mine', $admin->name);
        $this->assertTrue(Hash::check('kept-password1', $admin->password));
        $this->assertStringContainsString('is taken', $output);
    }

    public function test_non_local_install_gets_a_random_printed_admin_password_and_no_demo_accounts(): void
    {
        $this->inEnvironment('production');
        config(['auth.initial_admin.password' => null, 'auth.seed_demo_users' => true]);

        $output = $this->seedUsers();

        $this->assertSame(['admin@example.com' => 'admin'], $this->accounts());
        $admin = User::sole();
        $this->assertFalse(Hash::check('password', $admin->password));

        $this->assertMatchesRegularExpression('/generated password for admin@example\.com: (\S{20})/', $output);
        preg_match('/generated password for admin@example\.com: (\S{20})/', $output, $match);
        $this->assertTrue(Hash::check($match[1], $admin->password));

        // Printed only once.
        $this->assertStringNotContainsString('generated password', $this->seedUsers());
    }

    public function test_configured_admin_password_is_used_in_any_environment(): void
    {
        $this->inEnvironment('production');
        config(['auth.initial_admin' => ['email' => 'Boss@Company.test', 'password' => 'top-secret-42', 'name' => 'Boss']]);

        $output = $this->seedUsers();

        $admin = User::sole();
        $this->assertSame('boss@company.test', $admin->email);
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue(Hash::check('top-secret-42', $admin->password));
        $this->assertStringNotContainsString('top-secret-42', $output);
    }

    public function test_demo_accounts_can_be_disabled_locally(): void
    {
        $this->inEnvironment('local');
        config(['auth.seed_demo_users' => false]);

        $this->seedUsers();

        $this->assertSame(['admin@example.com' => 'admin'], $this->accounts());
    }

    public function test_demo_accounts_are_not_added_to_an_existing_installation(): void
    {
        $this->inEnvironment('local');
        config(['auth.seed_demo_users' => true]);
        User::factory()->hr()->create(['email' => 'someone@company.test']);

        $this->seedUsers();

        $this->assertSame(['admin@example.com' => 'admin', 'someone@company.test' => 'hr'], $this->accounts());
    }
}
