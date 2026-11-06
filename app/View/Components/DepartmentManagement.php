<?php

namespace App\View\Components;

use App\Models\Employee;
use App\Presenters\DepartmentPresenter;
use App\Services\DepartmentStatistics;
use App\Support\EmployeeVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * Employee profile: the manager of the employee's current department and the
 * employee's own department management periods.
 *
 *   <x-department-management :employee="$employee" />
 *
 * A department-scoped user gets no link to other departments, and no name for
 * a manager outside their scope.
 */
class DepartmentManagement extends Component
{
    /** @var Collection<int, object> dept_no, dept_name, linked, hasManager, isThisEmployee, managerEmpNo, managerName */
    public Collection $departments;

    /** @var Collection<int, object> dept_no, dept_name, linked, from, to */
    public Collection $periods;

    public bool $managesNow;

    public function __construct(Employee $employee, DepartmentStatistics $statistics, DepartmentPresenter $presenter)
    {
        $facts = $statistics->forEmployee($employee);
        $visibility = EmployeeVisibility::current();

        $linked = fn (string $deptNo) => ! $visibility->isDepartmentScoped() || $visibility->department() === $deptNo;

        $this->departments = $facts['departmentManagers']->map(function (object $row) use ($employee, $visibility, $linked) {
            $manager = $row->manager;
            $isThisEmployee = $manager !== null && (int) $manager->emp_no === (int) $employee->emp_no;
            $named = $manager !== null && ! $isThisEmployee && $visibility->canView($manager->emp_no);

            return (object) [
                'dept_no' => $row->dept_no,
                'dept_name' => $row->dept_name,
                'linked' => $linked($row->dept_no),
                'hasManager' => $manager !== null,
                'isThisEmployee' => $isThisEmployee,
                'managerEmpNo' => $named ? $manager->emp_no : null,
                'managerName' => $named ? $manager->first_name.' '.$manager->last_name : null,
            ];
        });

        $this->periods = $facts['managedPeriods']->map(fn (object $period) => (object) [
            'dept_no' => $period->dept_no,
            'dept_name' => $period->dept_name,
            'linked' => $linked($period->dept_no),
            'from' => $presenter->date($period->from_date),
            'to' => $presenter->date($period->to_date),
        ]);

        $this->managesNow = $facts['managedPeriods']->contains('to_date', DepartmentStatistics::CURRENT);
    }

    public function shouldRender(): bool
    {
        return $this->departments->isNotEmpty() || $this->periods->isNotEmpty();
    }

    public function render(): View
    {
        return view('components.department-management');
    }
}
