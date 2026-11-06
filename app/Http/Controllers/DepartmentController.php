<?php

namespace App\Http\Controllers;

use App\Http\Requests\Departments\DepartmentRequest;
use App\Models\Department;
use App\Presenters\DepartmentPresenter;
use App\Services\DepartmentManagementService;
use App\Services\DepartmentStatistics;
use App\Support\Ability;
use App\Support\EmployeeVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        private readonly DepartmentPresenter $presenter,
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
            ...$this->presenter->overview($this->statistics->overview($departments, $visibility)),
            'canSeeSalaries' => $visibility->canSeeSalaries(),
            'isDepartmentScoped' => $visibility->isDepartmentScoped(),
        ]);
    }

    public function show(Request $request, Department $department): View
    {
        $this->authorize('view', $department);

        $visibility = EmployeeVisibility::for($request->user());

        return view('departments.show', [
            'department' => $department,
            'canSeeSalaries' => $visibility->canSeeSalaries(),
            'canManage' => $request->user()->can(Ability::MANAGE_DEPARTMENTS),
            'deletionBlocked' => $request->user()->can('delete', $department)
                ? $this->departments->deletionBlockedReason($department)
                : null,
            ...$this->presenter->page($this->statistics->details($department, $visibility)),
        ]);
    }

    public function create(): View
    {
        return view('departments.form', [
            'department' => new Department(['dept_no' => $this->nextCode()]),
            'editing' => false,
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
        return view('departments.form', ['department' => $department, 'editing' => true]);
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
