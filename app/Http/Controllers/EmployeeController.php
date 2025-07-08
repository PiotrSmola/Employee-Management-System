<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $employees = Employee::with([
            'currentDepartment:dept_no,dept_name',
            'currentTitle:emp_no,title',
            'currentSalary:emp_no,salary'
        ])
            ->select('emp_no', 'first_name', 'last_name', 'hire_date')
            ->paginate(20);

        return view('employees.index', compact('employees'));
    }

    public function show($emp_no)
    {
        $employee = Employee::with([
            'currentDepartment',
            'currentTitle',
            'currentSalary'
        ])->findOrFail($emp_no);

        $departmentHistory = DB::table('dept_emp')
            ->join('departments', 'dept_emp.dept_no', '=', 'departments.dept_no')
            ->where('dept_emp.emp_no', $emp_no)
            ->select(
                'departments.dept_name',
                'departments.dept_no',
                'dept_emp.from_date',
                'dept_emp.to_date'
            )
            ->orderBy('dept_emp.from_date', 'asc')
            ->get();

        $titleHistory = DB::table('titles')
            ->where('emp_no', $emp_no)
            ->select('title', 'from_date', 'to_date')
            ->orderBy('from_date', 'asc')
            ->get();

        $salaryHistory = DB::table('salaries')
            ->where('emp_no', $emp_no)
            ->select('salary', 'from_date', 'to_date')
            ->orderBy('from_date', 'asc')
            ->get();

        $combinedHistory = $this->createCombinedHistory($departmentHistory, $titleHistory, $salaryHistory);

        $isCurrentEmployee = $employee->currentDepartment->count() > 0;
        $employmentEndDate = null;

        if (!$isCurrentEmployee && $departmentHistory->count() > 0) {
            $lastDepartmentRecord = $departmentHistory->filter(function ($dept) {
                return $dept->to_date !== '9999-01-01';
            })->sortByDesc('to_date')->first();
            if ($lastDepartmentRecord) {
                $employmentEndDate = Carbon::parse($lastDepartmentRecord->to_date);
            }
        }

        $hireDate = Carbon::parse($employee->hire_date);
        $endDate = $isCurrentEmployee ? Carbon::now() : ($employmentEndDate ?? Carbon::now());

        if ($hireDate->gt($endDate)) {
            $yearsEmployed = 0;
            $remainingDays = 0;
            $totalDays = 0;
        } else {
            $yearsEmployed = (int) $hireDate->diffInYears($endDate);
            $tempDate = $hireDate->copy()->addYears($yearsEmployed);
            $remainingDays = (int) $tempDate->diffInDays($endDate);
            $totalDays = (int) $hireDate->diffInDays($endDate);
        }

        $totalEarnings = DB::table('salaries')
            ->where('emp_no', $emp_no)
            ->sum('salary');

        $avgSalary = DB::table('salaries')
            ->where('emp_no', $emp_no)
            ->avg('salary');

        $stats = [
            'totalEarnings' => $totalEarnings,
            'avgSalary' => $avgSalary,
            'yearsEmployed' => $yearsEmployed,
            'remainingDays' => $remainingDays,
            'totalDays' => $totalDays,
            'departmentChanges' => $departmentHistory->count(),
            'titleChanges' => $titleHistory->count(),
            'salaryChanges' => $salaryHistory->count(),
            'isCurrentEmployee' => $isCurrentEmployee,
            'employmentEndDate' => $employmentEndDate
        ];

        $salaryChartData = collect();
        if ($salaryHistory->count() > 0) {
            $salaryByYear = $salaryHistory->groupBy(function ($salary) {
                return Carbon::parse($salary->from_date)->year;
            })->map(function ($yearSalaries) {
                return [
                    'year' => Carbon::parse($yearSalaries->first()->from_date)->year,
                    'salary' => round($yearSalaries->avg('salary')),
                    'count' => $yearSalaries->count()
                ];
            })->sortBy('year');
            $salaryChartData = $salaryByYear->values();
        }

        return view('employees.show', compact(
            'employee',
            'departmentHistory',
            'titleHistory',
            'salaryHistory',
            'combinedHistory',
            'stats',
            'salaryChartData'
        ));
    }

    private function createCombinedHistory($departmentHistory, $titleHistory, $salaryHistory)
    {
        $combinedHistory = collect();

        foreach ($salaryHistory as $salary) {
            $salaryFromDate = Carbon::parse($salary->from_date);
            $salaryToDate = $salary->to_date === '9999-01-01' ? null : Carbon::parse($salary->to_date);

            $department = $departmentHistory->first(function ($dept) use ($salaryFromDate, $salaryToDate) {
                $deptFromDate = Carbon::parse($dept->from_date);
                $deptToDate = $dept->to_date === '9999-01-01' ? null : Carbon::parse($dept->to_date);

                // check if salary period overlaps with department period
                if ($salaryToDate === null && $deptToDate === null) {
                    return $salaryFromDate >= $deptFromDate;
                } elseif ($salaryToDate === null) {
                    return $salaryFromDate <= $deptToDate;
                } elseif ($deptToDate === null) {
                    return $salaryToDate >= $deptFromDate;
                } else {
                    return $salaryFromDate <= $deptToDate && $salaryToDate >= $deptFromDate;
                }
            });

            $title = $titleHistory->first(function ($titleItem) use ($salaryFromDate, $salaryToDate) {
                $titleFromDate = Carbon::parse($titleItem->from_date);
                $titleToDate = $titleItem->to_date === '9999-01-01' ? null : Carbon::parse($titleItem->to_date);

                // check if salary period overlaps with title period
                if ($salaryToDate === null && $titleToDate === null) {
                    return $salaryFromDate >= $titleFromDate;
                } elseif ($salaryToDate === null) {
                    return $salaryFromDate <= $titleToDate;
                } elseif ($titleToDate === null) {
                    return $salaryToDate >= $titleFromDate;
                } else {
                    return $salaryFromDate <= $titleToDate && $salaryToDate >= $titleFromDate;
                }
            });

            $combinedHistory->push((object)[
                'from_date' => $salary->from_date,
                'to_date' => $salary->to_date,
                'salary' => $salary->salary,
                'department_name' => $department ? $department->dept_name : 'Unknown',
                'title' => $title ? $title->title : 'Unknown',
                'is_current' => $salary->to_date === '9999-01-01'
            ]);
        }

        return $combinedHistory->sortByDesc('from_date');
    }

    public function exportShowToPdf($emp_no)
    {
        $employee = Employee::with([
            'currentDepartment',
            'currentTitle',
            'currentSalary'
        ])->findOrFail($emp_no);

        $departmentHistory = DB::table('dept_emp')
            ->join('departments', 'dept_emp.dept_no', '=', 'departments.dept_no')
            ->where('dept_emp.emp_no', $emp_no)
            ->select('departments.dept_name', 'departments.dept_no', 'dept_emp.from_date', 'dept_emp.to_date')
            ->orderBy('dept_emp.from_date', 'asc')
            ->get();

        $titleHistory = DB::table('titles')
            ->where('emp_no', $emp_no)
            ->select('title', 'from_date', 'to_date')
            ->orderBy('from_date', 'asc')
            ->get();

        $salaryHistory = DB::table('salaries')
            ->where('emp_no', $emp_no)
            ->select('salary', 'from_date', 'to_date')
            ->orderBy('from_date', 'asc')
            ->get();

        $combinedHistory = $this->createCombinedHistory($departmentHistory, $titleHistory, $salaryHistory);

        $isCurrentEmployee = $employee->currentDepartment->count() > 0;
        $employmentEndDate = null;

        if (!$isCurrentEmployee && $departmentHistory->count() > 0) {
            $lastDepartmentRecord = $departmentHistory->filter(function ($dept) {
                return $dept->to_date !== '9999-01-01';
            })->sortByDesc('to_date')->first();
            if ($lastDepartmentRecord) {
                $employmentEndDate = Carbon::parse($lastDepartmentRecord->to_date);
            }
        }

        $hireDate = Carbon::parse($employee->hire_date);
        $endDate = $isCurrentEmployee ? Carbon::now() : ($employmentEndDate ?? Carbon::now());

        if ($hireDate->gt($endDate)) {
            $yearsEmployed = 0;
            $remainingDays = 0;
            $totalDays = 0;
        } else {
            $yearsEmployed = (int) $hireDate->diffInYears($endDate);
            $tempDate = $hireDate->copy()->addYears($yearsEmployed);
            $remainingDays = (int) $tempDate->diffInDays($endDate);
            $totalDays = (int) $hireDate->diffInDays($endDate);
        }

        $totalEarnings = DB::table('salaries')->where('emp_no', $emp_no)->sum('salary');
        $avgSalary = DB::table('salaries')->where('emp_no', $emp_no)->avg('salary');

        $stats = [
            'totalEarnings' => $totalEarnings,
            'avgSalary' => $avgSalary,
            'yearsEmployed' => $yearsEmployed,
            'remainingDays' => $remainingDays,
            'totalDays' => $totalDays,
            'departmentChanges' => $departmentHistory->count(),
            'isCurrentEmployee' => $isCurrentEmployee,
            'employmentEndDate' => $employmentEndDate
        ];

        $dataToPass = compact(
            'employee',
            'departmentHistory',
            'titleHistory',
            'salaryHistory',
            'combinedHistory',
            'stats'
        );

        $pdf = Pdf::loadView('exports.show-pdf', $dataToPass)
            ->setPaper('A4', 'portrait')
            ->setOptions(['isHtml5ParserEnabled' => true, 'isPhpEnabled' => true, 'defaultFont' => 'DejaVu Sans']);

        return $pdf->download('employee_details_' . $employee->emp_no . '_' . $employee->first_name . '_' . $employee->last_name . '.pdf');
    }
}
