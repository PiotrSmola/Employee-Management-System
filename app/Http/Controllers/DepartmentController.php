<?php

namespace App\Http\Controllers;

use App\Http\Requests\Departments\DepartmentRequest;
use App\Models\Department;
use App\Services\DepartmentManagementService;
use App\Services\DepartmentStatistics;
use App\Support\EmployeeVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Departments index and page (everyone who can see employees; managers only
 * their own department) and creating / renaming / deleting departments
 * (`manage-departments`, enforced by the routes and DepartmentRequest).
 */
class DepartmentController extends Controller
{
    public function __construct(
        private readonly DepartmentStatistics $statistics,
        private readonly DepartmentManagementService $departments,
    ) {
    }

    public function index(Request $request): View
    {
        $visibility = EmployeeVisibility::for($request->user());

        $departments = Department::query()
            ->when($visibility->isDepartmentScoped(), fn ($q) => $q->whereKey($visibility->department() ?? EmployeeVisibility::NO_DEPARTMENT))
            ->orderBy('dept_no')
            ->get(['dept_no', 'dept_name']);

        return view('departments.index', [
            'departments' => $this->statistics->overview($departments, $visibility->canSeeSalaries()),
            'canSeeSalaries' => $visibility->canSeeSalaries(),
            'isDepartmentScoped' => $visibility->isDepartmentScoped(),
        ]);
    }

    public function show(Request $request, Department $department): View
    {
        $this->authorize('view', $department);

        $visibility = EmployeeVisibility::for($request->user());
        $details = $this->statistics->details($department, $visibility->canSeeSalaries());

        return view('departments.show', [
            'department' => $department,
            'canSeeSalaries' => $visibility->canSeeSalaries(),
            'linkable' => $this->linkableEmployees($visibility, $details),
            'deletionBlocked' => $request->user()->can('delete', $department)
                ? $this->departments->deletionBlockedReason($department)
                : null,
            ...$details,
        ]);
    }

    public function create(): View
    {
        return view('departments.form', [
            'department' => new Department(['dept_no' => $this->nextCode()]),
        ]);
    }

    public function store(DepartmentRequest $request): RedirectResponse
    {
        $department = $this->departments->create($request->validated('dept_no'), $request->validated('dept_name'));

        return redirect()->route('departments.show', $department)
            ->with('status', __('Department :name has been created.', ['name' => $department->dept_name]));
    }

    public function edit(Department $department): View
    {
        return view('departments.form', ['department' => $department]);
    }

    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        $this->departments->rename($department, $request->validated('dept_name'));

        return redirect()->route('departments.show', $department)
            ->with('status', __('Department :name has been saved.', ['name' => $department->dept_name]));
    }

    public function destroy(Department $department): RedirectResponse
    {
        try {
            $this->departments->delete($department);
        } catch (ValidationException $e) {
            return redirect()->route('departments.show', $department)
                ->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('departments.index')
            ->with('status', __('Department :name has been deleted.', ['name' => $department->dept_name]));
    }

    /**
     * Employee numbers on the page whose profile the user may open (a manager
     * cannot open the profiles of employees who moved to other departments).
     *
     * @param  array<string, mixed>  $details
     * @return array<int, true>
     */
    private function linkableEmployees(EmployeeVisibility $visibility, array $details): array
    {
        $ids = collect([$details['recentJoiners'], $details['recentLeavers'], $details['managerHistory']])
            ->flatten(1)
            ->pluck('emp_no')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($visibility->isDepartmentScoped() && $ids->isNotEmpty()) {
            $ids = $visibility->constrain(DB::table('employees')->whereIn('emp_no', $ids))->pluck('emp_no');
        }

        return $ids->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * First free code after the highest "dNNN" one, as a suggestion for the create form.
     */
    private function nextCode(): string
    {
        $highest = Department::query()
            ->where('dept_no', 'like', 'd%')
            ->pluck('dept_no')
            ->filter(fn (string $code) => preg_match(DepartmentRequest::CODE_PATTERN, $code) === 1)
            ->map(fn (string $code) => (int) substr($code, 1))
            ->max() ?? 0;

        return $highest < 999 ? sprintf('d%03d', $highest + 1) : '';
    }
}
