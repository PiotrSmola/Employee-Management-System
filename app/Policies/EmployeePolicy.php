<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Support\Ability;
use App\Support\EmployeeVisibility;

class EmployeePolicy
{
    /**
     * The directory itself is open to every active user (rows are restricted by EmployeeVisibility).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    /**
     * Profile, profile PDF: managers only for employees of their department.
     */
    public function view(User $user, Employee $employee): bool
    {
        return $user->is_active && EmployeeVisibility::for($user)->canView($employee);
    }

    public function create(User $user): bool
    {
        return Ability::check($user, Ability::MANAGE_EMPLOYEES);
    }

    public function update(User $user, Employee $employee): bool
    {
        return Ability::check($user, Ability::MANAGE_EMPLOYEES);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return Ability::check($user, Ability::MANAGE_EMPLOYEES);
    }
}
