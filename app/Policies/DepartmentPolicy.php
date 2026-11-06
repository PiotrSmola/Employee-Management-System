<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use App\Support\Ability;
use App\Support\EmployeeVisibility;

/**
 * Departments (registered by Laravel's policy discovery: App\Models\Department
 * -> App\Policies\DepartmentPolicy).
 *
 * Everyone who can see employees can see the departments; a manager only their
 * own department (EmployeeVisibility scope). Changes need `manage-departments`.
 */
class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Department $department): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $visibility = EmployeeVisibility::for($user);

        return ! $visibility->isDepartmentScoped() || $visibility->department() === $department->dept_no;
    }

    public function create(User $user): bool
    {
        return Ability::check($user, Ability::MANAGE_DEPARTMENTS);
    }

    public function update(User $user, Department $department): bool
    {
        return Ability::check($user, Ability::MANAGE_DEPARTMENTS);
    }

    public function delete(User $user, Department $department): bool
    {
        return Ability::check($user, Ability::MANAGE_DEPARTMENTS);
    }
}
