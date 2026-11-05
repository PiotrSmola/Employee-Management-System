<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_the_admin_and_the_demo_accounts(): void
    {
        $this->seed(UserSeeder::class);

        $this->assertSame([
            'admin@example.com' => 'admin',
            'hr@example.com' => 'hr',
            'manager@example.com' => 'manager',
            'viewer@example.com' => 'viewer',
        ], User::orderBy('email')->get()->mapWithKeys(fn ($u) => [$u->email => $u->role->value])->all());

        $this->assertSame('d005', User::where('email', 'manager@example.com')->value('dept_no'));
        $this->assertTrue(User::all()->every(fn ($u) => $u->is_active && Hash::check('password', $u->password)));
    }

    public function test_is_idempotent_and_never_overwrites_existing_accounts(): void
    {
        User::factory()->viewer()->create(['email' => 'admin@example.com', 'password' => 'kept-password', 'name' => 'Mine']);

        $this->seed(UserSeeder::class);
        $this->seed(UserSeeder::class);

        $this->assertDatabaseCount('users', 4);
        $admin = User::where('email', 'admin@example.com')->first();
        $this->assertSame('Mine', $admin->name);
        $this->assertSame(Role::Viewer, $admin->role);
        $this->assertTrue(Hash::check('kept-password', $admin->password));
    }

    public function test_admin_comes_from_the_configuration_and_demo_accounts_can_be_disabled(): void
    {
        config([
            'auth.initial_admin' => ['email' => 'Boss@Company.test', 'password' => 'top-secret', 'name' => 'Boss'],
            'auth.seed_demo_users' => false,
        ]);

        $this->seed(UserSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $admin = User::sole();
        $this->assertSame('boss@company.test', $admin->email);
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue(Hash::check('top-secret', $admin->password));
    }
}
