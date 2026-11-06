<?php

namespace App\Livewire\Concerns;

use App\Models\Department;

/**
 * The directory can be opened pre-filtered to a department:
 * /employees?department=d005 (e.g. the link on a department page). The filter
 * and the list both read the parameter when they are mounted; anything that is
 * not an existing department code means "all departments". The manager scope
 * still overrides it (EmployeeVisibility::filters()).
 */
trait ReadsInitialDepartment
{
    protected function initialDepartment(): string
    {
        $department = request()->query('department');

        if (! is_string($department) || $department === '' || strlen($department) > 4) {
            return 'all';
        }

        return Department::query()->whereKey($department)->value('dept_no') ?? 'all';
    }
}
