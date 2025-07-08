<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf; //
use Carbon\Carbon;

class ExportController extends Controller
{
    public function export(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '2G');

        $format = $request->input('format', 'csv');
        $selectedEmployees = $request->input('selectedEmployees', []);

        if (empty($selectedEmployees)) {
            return back()->with('error', 'Nie wybrano żadnych pracowników do eksportu.');
        }

        // Get filters from request
        $filters = [
            'search' => $request->search,
            'employmentStatus' => $request->employmentStatus,
            'gender' => $request->gender,
            'department' => $request->department,
            'salaryMin' => $request->salaryMin,
            'salaryMax' => $request->salaryMax,
            'jobTitle' => $request->jobTitle,
            'sortBy' => $request->sortBy ?? 'emp_no',
            'sortDirection' => $request->sortDirection ?? 'asc',
            'selectedEmployees' => $selectedEmployees,
        ];

        if ($format === 'pdf') {
            return $this->exportToPdf($filters);
        }

        return $this->exportToCsv($filters);
    }

    private function exportToCsv(array $filters)
    {
        $selectedCount = count($filters['selectedEmployees']);
        $filename = 'employees_export_selected_' . $selectedCount . '_' . date('Y-m-d_H-i-s') . '.csv';

        $response = response()->stream(function () use ($filters) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            // CSV Headers
            fputcsv($handle, [
                'Employee ID',
                'First Name',
                'Last Name',
                'Full Name',
                'Department',
                'Job Title',
                'Current Salary',
                'Total Earnings',
                'Hire Date',
                'Gender',
                'Employment Status'
            ]);

            $this->getEmployeesQuery($filters)
                ->chunk(100, function ($employees) use ($handle) {

                    $empNumbers = $employees->pluck('emp_no')->toArray();
                    $totalEarnings = [];

                    if (!empty($empNumbers)) {
                        $totalEarnings = DB::table('salaries')
                            ->select('emp_no', DB::raw('SUM(salary) as total_salary'))
                            ->whereIn('emp_no', $empNumbers)
                            ->groupBy('emp_no')
                            ->pluck('total_salary', 'emp_no')
                            ->toArray();
                    }

                    foreach ($employees as $employee) {
                        $departmentDisplay = 'N/A';
                        if ($employee->current_dept_name) {
                            $departmentDisplay = $employee->current_dept_name;
                        } elseif ($employee->last_dept_name) {
                            $departmentDisplay = 'Former - ' . $employee->last_dept_name;
                        }

                        $jobTitleDisplay = 'N/A';
                        if ($employee->current_title) {
                            $jobTitleDisplay = $employee->current_title;
                        } elseif ($employee->last_job_title) {
                            $jobTitleDisplay = $employee->last_job_title . ' (Last)';
                        }

                        fputcsv($handle, [
                            $employee->emp_no,
                            $employee->first_name,
                            $employee->last_name,
                            $employee->first_name . ' ' . $employee->last_name,
                            $departmentDisplay,
                            $jobTitleDisplay,
                            $employee->current_salary ? number_format($employee->current_salary, 2, '.', '') : 'N/A',
                            number_format($totalEarnings[$employee->emp_no] ?? 0, 2, '.', ''),
                            date('Y-m-d', strtotime($employee->hire_date)),
                            $employee->gender === 'M' ? 'Male' : 'Female',
                            $employee->current_dept_name ? 'Active' : 'Former'
                        ]);
                    }
                });

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    private function exportToPdf(array $filters)
    {
        $employeesData = $this->getEmployeesQuery($filters)->limit(1000)->get();

        if ($employeesData->isEmpty()) {
            return back()->with('error', 'No employees found for export.');
        }

        $employeeNumbers = $employeesData->pluck('emp_no')->toArray();
        $totalEarnings = DB::table('salaries')
            ->select('emp_no', DB::raw('SUM(salary) as total_salary'))
            ->whereIn('emp_no', $employeeNumbers)
            ->groupBy('emp_no')
            ->pluck('total_salary', 'emp_no')
            ->toArray();

        $employeesWithTotals = $employeesData->map(function ($employee) use ($totalEarnings) {
            return (object) [
                'emp_no' => $employee->emp_no,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'hire_date' => $employee->hire_date,
                'gender' => $employee->gender,
                'current_dept_name' => $employee->current_dept_name,
                'current_title' => $employee->current_title,
                'current_salary' => $employee->current_salary,
                'last_dept_name' => $employee->last_dept_name,
                'last_job_title' => $employee->last_job_title,
                'total_earnings' => $totalEarnings[$employee->emp_no] ?? 0,
            ];
        });

        try {
            $selectedCount = count($filters['selectedEmployees']);
            $pdf = Pdf::loadView('exports.employees-pdf', [
                'employees' => $employeesWithTotals,
                'exportDate' => now()->format('Y-m-d H:i:s'),
                'totalCount' => $employeesData->count(),
                'selectedCount' => $selectedCount,
                'isLimited' => $employeesData->count() >= 1000,
                'filters' => $filters
            ])
                ->setPaper('A4', 'landscape')
                ->setOptions([
                    'isHtml5ParserEnabled' => true,
                    'isPhpEnabled' => true,
                    'defaultFont' => 'DejaVu Sans'
                ]);

            return $pdf->download('employees_selected_' . $selectedCount . '_' . date('Y-m-d_H-i-s') . '.pdf');
        } catch (\Exception $e) {
            return back()->with('error', 'PDF export failed: ' . $e->getMessage());
        }
    }

    private function getEmployeesQuery(array $filters)
    {
        $query = DB::table('employees as e')
            ->leftJoin('dept_emp as de_current', function ($join) {
                $join->on('e.emp_no', '=', 'de_current.emp_no')
                    ->where('de_current.to_date', '=', '9999-01-01');
            })
            ->leftJoin('departments as d_current', 'de_current.dept_no', '=', 'd_current.dept_no')
            ->leftJoin('titles as t_current', function ($join) {
                $join->on('e.emp_no', '=', 't_current.emp_no')
                    ->where('t_current.to_date', '=', '9999-01-01');
            })
            ->leftJoin('salaries as s_current', function ($join) {
                $join->on('e.emp_no', '=', 's_current.emp_no')
                    ->where('s_current.to_date', '=', '9999-01-01');
            })
            ->select([
                'e.emp_no',
                'e.first_name',
                'e.last_name',
                'e.hire_date',
                'e.gender',
                'd_current.dept_name as current_dept_name',
                't_current.title as current_title',
                's_current.salary as current_salary',
                DB::raw("(SELECT d.dept_name
                           FROM departments d
                           JOIN dept_emp de_last ON d.dept_no = de_last.dept_no 
                           WHERE de_last.emp_no = e.emp_no 
                             AND de_last.to_date != '9999-01-01' 
                           ORDER BY de_last.to_date DESC 
                           LIMIT 1) as last_dept_name"),
                DB::raw("(SELECT t_last.title 
                           FROM titles t_last 
                           WHERE t_last.emp_no = e.emp_no 
                             AND t_last.to_date != '9999-01-01' 
                           ORDER BY t_last.to_date DESC 
                           LIMIT 1) as last_job_title")
            ]);

        if (!empty($filters['selectedEmployees'])) {
            $query->whereIn('e.emp_no', $filters['selectedEmployees']);
        }

        $this->applyFilters($query, $filters);

        return $query;
    }

    private function applyFilters($query, array $filters)
    {
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('e.first_name', 'like', $search)
                    ->orWhere('e.last_name', 'like', $search)
                    ->orWhere(DB::raw("CONCAT(e.first_name, ' ', e.last_name)"), 'like', $search);
            });
        }

        if (isset($filters['employmentStatus'])) {
            if ($filters['employmentStatus'] === 'current') {
                $query->whereNotNull('de_current.dept_no');
            } elseif ($filters['employmentStatus'] === 'former') {
                $query->whereNull('de_current.dept_no');
            }
        }

        if (isset($filters['gender']) && $filters['gender'] !== 'all') {
            $query->where('e.gender', $filters['gender']);
        }

        if (isset($filters['department']) && $filters['department'] !== 'all') {
            $departmentFilter = $filters['department'];
            $query->where(function ($q) use ($departmentFilter) {
                $q->where('d_current.dept_no', $departmentFilter) // Filter at current department
                    ->orWhere(function ($q2) use ($departmentFilter) { // Or by last if current is NULL
                        $q2->whereNull('d_current.dept_no')
                            ->whereRaw('? = (SELECT de_last.dept_no 
                                           FROM dept_emp de_last 
                                           WHERE de_last.emp_no = e.emp_no 
                                             AND de_last.to_date != \'9999-01-01\' 
                                           ORDER BY de_last.to_date DESC 
                                           LIMIT 1)', [$departmentFilter]);
                    });
            });
        }

        if (isset($filters['jobTitle']) && $filters['jobTitle'] !== 'all') {
            $jobTitleFilter = $filters['jobTitle'];
            $query->where(function ($q) use ($jobTitleFilter) {
                $q->where('t_current.title', $jobTitleFilter) // Filter by current
                    ->orWhere(function ($q2) use ($jobTitleFilter) { // Or by last if current is NULL
                        $q2->whereNull('t_current.title')
                            ->whereRaw('? = (SELECT t_last.title 
                                             FROM titles t_last 
                                             WHERE t_last.emp_no = e.emp_no 
                                               AND t_last.to_date != \'9999-01-01\' 
                                             ORDER BY t_last.to_date DESC 
                                             LIMIT 1)', [$jobTitleFilter]);
                    });
            });
        }

        if (!empty($filters['salaryMin'])) {
            $query->where('s_current.salary', '>=', $filters['salaryMin']);
        }

        if (!empty($filters['salaryMax'])) {
            $query->where('s_current.salary', '<=', $filters['salaryMax']);
        }

        $sortBy = $filters['sortBy'] ?? 'emp_no';
        $sortDirection = $filters['sortDirection'] ?? 'asc';

        switch ($sortBy) {
            case 'emp_no':
                $query->orderBy('e.emp_no', $sortDirection);
                break;
            case 'name':
                $query->orderBy('e.first_name', $sortDirection)
                    ->orderBy('e.last_name', $sortDirection);
                break;
            case 'department':
                $query->orderByRaw(DB::raw("COALESCE(d_current.dept_name, (SELECT d.dept_name FROM departments d JOIN dept_emp de_last ON d.dept_no = de_last.dept_no WHERE de_last.emp_no = e.emp_no AND de_last.to_date != '9999-01-01' ORDER BY de_last.to_date DESC LIMIT 1)) {$sortDirection}"));
                break;
            case 'title':
                $query->orderByRaw(DB::raw("COALESCE(t_current.title, (SELECT t_last.title FROM titles t_last WHERE t_last.emp_no = e.emp_no AND t_last.to_date != '9999-01-01' ORDER BY t_last.to_date DESC LIMIT 1)) {$sortDirection}"));
                break;
            case 'salary':
                $query->orderBy('s_current.salary', $sortDirection);
                break;
            default:
                $query->orderBy('e.emp_no', $sortDirection);
        }
    }
}
