<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\EmployeeVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/departments: code, name and current head count. A manager only
 * gets their own department (as in the directory's filter options).
 */
class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $visibility = EmployeeVisibility::for($request->user());
        $scope = fn ($query, string $column) => $query->when(
            $visibility->isDepartmentScoped(),
            fn ($q) => $q->where($column, $visibility->department() ?? EmployeeVisibility::NO_DEPARTMENT)
        );

        $counts = $scope(DB::table('dept_emp'), 'dept_no')
            ->where('to_date', '9999-01-01')
            ->groupBy('dept_no')
            ->pluck(DB::raw('COUNT(*)'), 'dept_no');

        $departments = $scope(DB::table('departments'), 'dept_no')
            ->orderBy('dept_no')
            ->get(['dept_no', 'dept_name'])
            ->map(fn ($department) => [
                'dept_no' => $department->dept_no,
                'name' => $department->dept_name,
                'current_employees' => (int) ($counts[$department->dept_no] ?? 0),
            ]);

        return response()->json(['data' => $departments->values()]);
    }
}
