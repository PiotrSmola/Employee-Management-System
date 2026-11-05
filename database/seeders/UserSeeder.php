<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Initial accounts. Idempotent: accounts are matched by e-mail, and an
 * existing account is never changed (in particular its password is kept),
 * so this runs safely on every container start.
 *
 *  - administrator from ADMIN_EMAIL / ADMIN_PASSWORD / ADMIN_NAME
 *    (defaults admin@example.com / "password");
 *  - demo accounts hr@, manager@ (department d005) and viewer@example.com
 *    with password "password", unless SEED_DEMO_USERS=false.
 *
 * Change these passwords after the first sign-in.
 */
class UserSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'password';

    public const DEMO_MANAGER_DEPARTMENT = 'd005';

    public function run(): void
    {
        $admin = config('auth.initial_admin');

        $this->ensure($admin['email'], $admin['name'], $admin['password'], Role::Admin);

        if (! config('auth.seed_demo_users')) {
            return;
        }

        $this->ensure('hr@example.com', 'HR Demo', self::DEMO_PASSWORD, Role::Hr);
        $this->ensure('manager@example.com', 'Manager Demo', self::DEMO_PASSWORD, Role::Manager, self::DEMO_MANAGER_DEPARTMENT);
        $this->ensure('viewer@example.com', 'Viewer Demo', self::DEMO_PASSWORD, Role::Viewer);
    }

    private function ensure(string $email, string $name, string $password, Role $role, ?string $deptNo = null): void
    {
        $user = User::firstOrCreate(
            ['email' => strtolower($email)],
            ['name' => $name, 'password' => $password, 'role' => $role, 'dept_no' => $deptNo, 'is_active' => true],
        );

        $this->command?->line($user->wasRecentlyCreated
            ? "  created {$role->value} account {$user->email}"
            : "  {$user->email} already exists - left unchanged");
    }
}
