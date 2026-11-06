<?php

namespace App\View\Components;

use App\Models\Employee;
use App\Services\EmployeeOperationsService;
use App\Support\AuditLogPresenter;
use App\Support\EmploymentRules;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * "HR actions" card + modals of the employee profile:
 * <x-employee-actions :employee="$employee" /> inside @can('manage-employees').
 *
 * Public methods are callable in the view ($old(...), $invalid(...), ...).
 */
class EmployeeActions extends Component
{
    /** Error bag of a form => its modal; a failed form opens again. */
    private const MODALS = [
        'personal' => 'personalModal',
        'transfer' => 'transferModal',
        'title' => 'titleModal',
        'salary' => 'salaryModal',
        'terminate' => 'terminateModal',
        'rehire' => 'rehireModal',
        'correctSalary' => 'correctSalaryModal',
        'correctTitle' => 'correctTitleModal',
        'undo' => 'undoModal',
    ];

    /** @var array<string, mixed> */
    public array $context;

    public function __construct(public Employee $employee, EmployeeOperationsService $operations)
    {
        $this->context = $operations->formContext($employee);
    }

    public function render(): View
    {
        $hireLimit = $this->context['minDates']['hire'];
        $today = $this->context['today'];

        return view('components.employee-actions', [
            ...$this->context,
            'rules' => EmploymentRules::class,
            'name' => trim($this->employee->first_name.' '.$this->employee->last_name),
            'openModal' => collect(self::MODALS)->first(fn (string $modal, string $bag) => $this->errorBag($bag)->any()),
            'hireMax' => $hireLimit !== null && $hireLimit < $today ? $hireLimit : $today,
            'undoHours' => EmployeeOperationsService::UNDO_HOURS,
            'undoPresenter' => new AuditLogPresenter($this->context['departmentNames']->all()),
            'currentDepartmentNames' => $this->departmentNames($this->context['currentDepartments']),
            'managedDepartmentNames' => $this->departmentNames($this->context['managedDepartments']),
            'allManagedDepartmentNames' => $this->departmentNames($this->context['allManagedDepartments']),
        ]);
    }

    /**
     * Old input, only for the form that failed (the forms share field names).
     */
    public function old(string $bag, string $key, mixed $default = null): mixed
    {
        return $this->errorBag($bag)->any() ? old($key, $default) : $default;
    }

    public function invalid(string $bag, string $key): string
    {
        return $this->errorBag($bag)->has($key) ? 'is-invalid' : '';
    }

    /**
     * Can a form with the earliest date $min be submitted today?
     */
    public function possible(?string $min): bool
    {
        return $min === null || $min <= $this->context['today'];
    }

    public function wasHeld(string $deptNo): bool
    {
        return in_array($deptNo, $this->context['heldDepartments'], true);
    }

    public function isCurrentDepartment(string $deptNo): bool
    {
        return in_array($deptNo, $this->context['currentDepartments'], true);
    }

    /**
     * @param  string[]  $departments
     */
    private function departmentNames(array $departments): string
    {
        return collect($departments)
            ->map(fn (string $deptNo) => $this->context['departmentNames'][$deptNo] ?? $deptNo)
            ->implode(', ');
    }

    private function errorBag(string $bag): MessageBag
    {
        return (session('errors') ?? new ViewErrorBag)->getBag($bag);
    }
}
