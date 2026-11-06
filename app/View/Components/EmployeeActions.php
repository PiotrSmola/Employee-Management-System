<?php

namespace App\View\Components;

use App\Models\Employee;
use App\Services\EmployeeOperationsService;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * "HR actions" card + modals of the employee profile:
 * <x-employee-actions :employee="$employee" /> inside @can('manage-employees').
 */
class EmployeeActions extends Component
{
    /** @var array<string, mixed> */
    public array $context;

    public function __construct(public Employee $employee, EmployeeOperationsService $operations)
    {
        $this->context = $operations->formContext($employee);
    }

    public function render(): View
    {
        return view('components.employee-actions', $this->context);
    }
}
