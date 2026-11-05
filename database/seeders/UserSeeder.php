<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Initial accounts. Runs on every container start, so it only ever acts on a
 * fresh installation and never brings back accounts that were changed or
 * deleted on purpose:
 *
 *  - the initial administrator (ADMIN_EMAIL / ADMIN_PASSWORD / ADMIN_NAME) is
 *    created only while there is no administrator account at all (active or
 *    not). Without ADMIN_PASSWORD it gets "password" in the local environment
 *    and a random password everywhere else, printed once to the console (the
 *    container log);
 *  - the demo accounts hr@, manager@ (department d005) and viewer@example.com
 *    (password "password") are created only in the local environment, with
 *    SEED_DEMO_USERS enabled, and only when the users table was empty before
 *    this run - deleted demo accounts do not come back.
 *
 * Existing accounts are never modified.
 */
class UserSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'password';

    public const LOCAL_ADMIN_PASSWORD = 'password';

    public const DEMO_MANAGER_DEPARTMENT = 'd005';

    public function run(): void
    {
        $firstSeed = ! User::query()->exists();

        $this->seedAdmin();

        if (! $firstSeed) {
            return;
        }

        if (! app()->environment('local') || ! config('auth.seed_demo_users')) {
            return;
        }

        $this->create('hr@example.com', 'HR Demo', self::DEMO_PASSWORD, Role::Hr);
        $this->create('manager@example.com', 'Manager Demo', self::DEMO_PASSWORD, Role::Manager, self::DEMO_MANAGER_DEPARTMENT);
        $this->create('viewer@example.com', 'Viewer Demo', self::DEMO_PASSWORD, Role::Viewer);
    }

    private function seedAdmin(): void
    {
        if (User::query()->where('role', Role::Admin->value)->exists()) {
            $this->command?->line('  an administrator account exists - no initial administrator created');

            return;
        }

        $config = config('auth.initial_admin');
        $email = strtolower((string) $config['email']);

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->warn("  no administrator exists, but {$email} is taken by another account - set ADMIN_EMAIL to create one");

            return;
        }

        $password = (string) ($config['password'] ?? '');
        $generated = false;

        if ($password === '') {
            if (app()->environment('local')) {
                $password = self::LOCAL_ADMIN_PASSWORD;
            } else {
                $password = Str::password(20, symbols: false);
                $generated = true;
            }
        }

        $this->create($email, (string) $config['name'], $password, Role::Admin);

        if ($generated) {
            $this->command?->warn("  generated password for {$email}: {$password}");
            $this->command?->warn('  (shown only once - sign in and change it under "My account")');
        }
    }

    private function create(string $email, string $name, string $password, Role $role, ?string $deptNo = null): void
    {
        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        User::create([
            'email' => $email,
            'name' => $name,
            'password' => $password,
            'role' => $role,
            'dept_no' => $deptNo,
            'is_active' => true,
        ]);

        $this->command?->line("  created {$role->value} account {$email}");
    }
}
