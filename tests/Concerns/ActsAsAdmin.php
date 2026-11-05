<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;

/**
 * Every page requires a signed-in user. Test classes using this trait run as an
 * active administrator (full access, salaries visible) unless they switch with
 * signInAs().
 *
 * setUpActsAsAdmin() is called automatically by Laravel's setUpTraits(), after
 * RefreshDatabase has prepared the database.
 */
trait ActsAsAdmin
{
    protected User $user;

    protected function setUpActsAsAdmin(): void
    {
        $this->signInAs(Role::Admin);
    }

    /**
     * Sign in as a new active user with $role (managers get $department).
     */
    protected function signInAs(Role $role, Department|string|null $department = null): User
    {
        $factory = $role === Role::Manager
            ? User::factory()->manager($department)
            : User::factory()->role($role);

        $this->user = $factory->create();
        $this->actingAs($this->user);

        return $this->user;
    }
}
