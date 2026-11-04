<?php

namespace App\Providers;

use App\Models\Employee;
use App\Models\User;
use App\Policies\EmployeePolicy;
use App\Support\Ability;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the permission matrix ({@see Ability::MATRIX}) as Gates and the
 * model policies. See docs/AUTHORIZATION.md.
 */
class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (Ability::all() as $ability) {
            Gate::define($ability, fn (User $user) => Ability::check($user, $ability));
        }

        Gate::policy(Employee::class, EmployeePolicy::class);
    }
}
