<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class IndexController extends Controller
{
    public function index()
    {
        // Cache 15 minut - dashboard nie musi być realtime
        $stats = Cache::remember('dashboard_stats', 900, function () {
            return [
                'totalEmployees' => Employee::count(),
                'totalDepartments' => Department::count(),
                'averageSalary' => DB::table('salaries')
                    ->where('to_date', '9999-01-01')
                    ->avg('salary')
            ];
        });

        $recentEmployees = Employee::select('emp_no', 'first_name', 'last_name', 'hire_date')
            ->orderBy('hire_date', 'desc')
            ->limit(7)
            ->get();
            
        $employeesByDepartment = Cache::remember('employees_by_department', 1800, function () {
            return DB::table('dept_emp')
                ->join('departments', 'dept_emp.dept_no', '=', 'departments.dept_no')
                ->where('dept_emp.to_date', '9999-01-01')
                ->select('departments.dept_name', DB::raw('count(*) as total'))
                ->groupBy('departments.dept_name', 'departments.dept_no')
                ->orderBy('total', 'desc')
                ->limit(8) // max 8 departamentów
                ->get();
        });

        return view('index', compact(
            'recentEmployees',
            'employeesByDepartment'
        ) + $stats);
    }
}