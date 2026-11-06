<?php

namespace App\Http\Controllers;

use App\Http\Requests\Departments\AssignManagerRequest;
use App\Http\Requests\Departments\EndManagementRequest;
use App\Models\Department;
use App\Queries\DepartmentMemberSearch;
use App\Services\DepartmentManagementService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Department managers (routes behind `can:manage-departments`).
 */
class DepartmentManagerController extends Controller
{
    public function __construct(private readonly DepartmentManagementService $departments) {}

    /**
     * Current employees of the department for the searchable "new manager" select.
     */
    public function candidates(Request $request, Department $department, DepartmentMemberSearch $search): JsonResponse
    {
        $term = $request->query('q');
        $rows = $search->search($department->dept_no, is_string($term) ? mb_substr($term, 0, 50) : '');

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'emp_no' => $row->emp_no,
                'name' => $row->first_name.' '.$row->last_name,
                'title' => $row->title,
                'since' => Carbon::parse($row->since)->format('d.m.Y'),
                'manager' => $row->manager,
            ])->values(),
        ]);
    }

    public function store(AssignManagerRequest $request, Department $department): RedirectResponse
    {
        $this->departments->assignManager($department, (int) $request->validated('emp_no'), $request->validated('from_date'));

        return redirect()->route('departments.show', $department)
            ->with('status', __('The manager of :name has been changed.', ['name' => $department->dept_name]));
    }

    public function end(EndManagementRequest $request, Department $department): RedirectResponse
    {
        $this->departments->endManagement($department, $request->validated('to_date'));

        return redirect()->route('departments.show', $department)
            ->with('status', __('The management of :name has been ended.', ['name' => $department->dept_name]));
    }
}
