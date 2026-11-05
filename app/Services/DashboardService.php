<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Support\EmployeeVisibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard figures. Company-wide for most roles; for a manager everything is
 * limited to their department (current employees of that department). The
 * average salary is only computed for users who may see salaries.
 */
class DashboardService
{
    public const CURRENT = '9999-01-01';

    /** Cache lifetimes in seconds - the dashboard does not need to be real-time. */
    private const STATS_TTL = 900;

    private const BREAKDOWN_TTL = 1800;

    /**
     * @return array{
     *     isDepartmentScoped: bool,
     *     scopeDepartment: ?Department,
     *     totalEmployees: int,
     *     totalDepartments: int,
     *     averageSalary: float|int|string|null,
     *     recentEmployees: Collection,
     *     employeesByDepartment: Collection,
     *     employeesByTitle: Collection
     * }
     */
    public function build(EmployeeVisibility $visibility): array
    {
        if ($visibility->isDepartmentScoped()) {
            return $this->forDepartment($visibility);
        }

        $stats = Cache::remember('dashboard_stats', self::STATS_TTL, fn () => [
            'totalEmployees' => Employee::count(),
            'totalDepartments' => Department::count(),
            'averageSalary' => DB::table('salaries')->where('to_date', self::CURRENT)->avg('salary'),
        ]);

        $employeesByDepartment = Cache::remember('employees_by_department', self::BREAKDOWN_TTL, fn () => DB::table('dept_emp')
            ->join('departments', 'dept_emp.dept_no', '=', 'departments.dept_no')
            ->where('dept_emp.to_date', self::CURRENT)
            ->select('departments.dept_name', DB::raw('count(*) as total'))
            ->groupBy('departments.dept_name', 'departments.dept_no')
            ->orderBy('total', 'desc')
            ->limit(8)
            ->get());

        return [
            'isDepartmentScoped' => false,
            'scopeDepartment' => null,
            'totalEmployees' => $stats['totalEmployees'],
            'totalDepartments' => $stats['totalDepartments'],
            'averageSalary' => $visibility->canSeeSalaries() ? $stats['averageSalary'] : null,
            'recentEmployees' => Employee::select('emp_no', 'first_name', 'last_name', 'hire_date')
                ->orderBy('hire_date', 'desc')
                ->limit(7)
                ->get(),
            'employeesByDepartment' => $employeesByDepartment,
            'employeesByTitle' => collect(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forDepartment(EmployeeVisibility $visibility): array
    {
        $deptNo = $visibility->department();
        $department = $deptNo !== null ? Department::find($deptNo) : null;

        if ($department === null) {
            return [
                'isDepartmentScoped' => true,
                'scopeDepartment' => null,
                'totalEmployees' => 0,
                'totalDepartments' => 0,
                'averageSalary' => null,
                'recentEmployees' => collect(),
                'employeesByDepartment' => collect(),
                'employeesByTitle' => collect(),
            ];
        }

        $current = fn () => DB::table('dept_emp as de')
            ->where('de.dept_no', $department->dept_no)
            ->where('de.to_date', self::CURRENT);

        $stats = Cache::remember('dashboard_stats:dept:'.$department->dept_no, self::STATS_TTL, fn () => [
            'totalEmployees' => $current()->count(),
            'averageSalary' => $current()
                ->join('salaries as s', fn ($join) => $join->on('s.emp_no', '=', 'de.emp_no')->where('s.to_date', '=', self::CURRENT))
                ->avg('s.salary'),
        ]);

        $employeesByTitle = Cache::remember('employees_by_title:dept:'.$department->dept_no, self::BREAKDOWN_TTL, fn () => $current()
            ->join('titles as t', fn ($join) => $join->on('t.emp_no', '=', 'de.emp_no')->where('t.to_date', '=', self::CURRENT))
            ->select('t.title', DB::raw('count(*) as total'))
            ->groupBy('t.title')
            ->orderBy('total', 'desc')
            ->limit(8)
            ->get());

        return [
            'isDepartmentScoped' => true,
            'scopeDepartment' => $department,
            'totalEmployees' => $stats['totalEmployees'],
            'totalDepartments' => 1,
            'averageSalary' => $visibility->canSeeSalaries() ? $stats['averageSalary'] : null,
            'recentEmployees' => Employee::select('emp_no', 'first_name', 'last_name', 'hire_date')
                ->whereIn('emp_no', $current()->select('de.emp_no'))
                ->orderBy('hire_date', 'desc')
                ->limit(7)
                ->get(),
            'employeesByDepartment' => collect(),
            'employeesByTitle' => $employeesByTitle,
        ];
    }
}
