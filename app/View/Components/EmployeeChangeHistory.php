<?php

namespace App\View\Components;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Support\AuditLogPresenter;
use App\Support\EmployeeVisibility;
use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * "Change history" of the employee profile: the latest audit entries about the
 * employee, read with the (subject_type, subject_id, created_at) index.
 * <x-employee-change-history :employee="$employee" /> inside @can('view-audit-log').
 */
class EmployeeChangeHistory extends Component
{
    public const LIMIT = 10;

    public Collection $entries;

    public bool $hasMore;

    public AuditLogPresenter $presenter;

    /** @var array<int, ActivityLog> undo entries by the id of the entry they undid */
    public array $undoneBy;

    public function __construct(public Employee $employee)
    {
        $entries = ActivityLog::forSubject(ActivityLog::SUBJECT_EMPLOYEE, $employee->emp_no)
            ->with('user:id,name')
            ->latestFirst()
            ->limit(self::LIMIT + 1)
            ->get();

        $this->hasMore = $entries->count() > self::LIMIT;
        $this->entries = $entries->take(self::LIMIT);
        $this->undoneBy = ActivityLog::undoEntriesFor($this->entries);

        $user = auth()->user();
        $this->presenter = new AuditLogPresenter(
            Department::query()->pluck('dept_name', 'dept_no')->all(),
            $user !== null && EmployeeVisibility::for($user)->canSeeSalaries(),
        );
    }

    public function render(): View
    {
        return view('components.employee-change-history');
    }
}
