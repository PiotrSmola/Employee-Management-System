<?php

namespace App\Services;

use App\Queries\EmployeeSearchQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk CSV / PDF export of the employee directory.
 */
class EmployeeExportService
{
    public const PDF_LIMIT = 1000;

    private const CSV_CHUNK = 1000;

    /**
     * Stream every matching employee as CSV, in the list's sort order.
     */
    public function csv(EmployeeSearchQuery $query): Response
    {
        set_time_limit(600);

        $ids = $query->ids();

        if ($ids === []) {
            return back()->with('error', __('No employees found for export.'));
        }

        $filename = 'employees_export_selected_'.count($ids).'_'.date('Y-m-d_H-i-s').'.csv';

        return new StreamedResponse(function () use ($query, $ids) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('Employee ID'),
                __('First Name'),
                __('Last Name'),
                __('Full Name'),
                __('Department'),
                __('Job Title'),
                __('Current Salary'),
                __('Total Earnings'),
                __('Hire Date'),
                __('Gender'),
                __('Employment Status'),
            ]);

            foreach (array_chunk($ids, self::CSV_CHUNK) as $chunk) {
                $rows = $query->rowsFor($chunk);
                $earnings = $this->totalEarnings($chunk);

                foreach ($rows as $employee) {
                    fputcsv($handle, $this->csvRow($employee, $earnings[$employee->emp_no] ?? 0));
                }
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Render up to PDF_LIMIT matching employees into a landscape PDF.
     */
    public function pdf(EmployeeSearchQuery $query): Response
    {
        set_time_limit(600);
        ini_set('memory_limit', '2G');

        $ids = $query->ids(static::PDF_LIMIT);

        if ($ids === []) {
            return back()->with('error', __('No employees found for export.'));
        }

        // The PDF holds at most PDF_LIMIT rows; the selection itself may be larger.
        $selectedCount = count($ids) < static::PDF_LIMIT ? count($ids) : $query->count();

        $earnings = $this->totalEarnings($ids);
        $employees = $query->rowsFor($ids)->map(fn ($employee) => (object) [
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
            'total_earnings' => $earnings[$employee->emp_no] ?? 0,
        ]);

        try {
            $pdf = Pdf::loadView('exports.employees-pdf', [
                'employees' => $employees,
                'exportDate' => now()->format('Y-m-d H:i:s'),
                'totalCount' => $employees->count(),
                'selectedCount' => $selectedCount,
                'isLimited' => $selectedCount > static::PDF_LIMIT,
                'limit' => static::PDF_LIMIT,
                'filters' => $query->filters(),
            ])
                ->setPaper('A4', 'landscape')
                ->setOptions([
                    'isHtml5ParserEnabled' => true,
                    'isPhpEnabled' => true,
                    'defaultFont' => 'DejaVu Sans',
                ]);

            return $pdf->download('employees_selected_'.$selectedCount.'_'.date('Y-m-d_H-i-s').'.pdf');
        } catch (\Exception $e) {
            report($e);

            return back()->with('error', __('PDF export failed: :message', ['message' => $e->getMessage()]));
        }
    }

    /**
     * Sum of all salary rows per employee.
     *
     * @param  int[]  $ids
     * @return array<int, int|string>
     */
    public function totalEarnings(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('salaries')
            ->select('emp_no', DB::raw('SUM(salary) as total_salary'))
            ->whereIntegerInRaw('emp_no', $ids)
            ->groupBy('emp_no')
            ->pluck('total_salary', 'emp_no')
            ->toArray();
    }

    /**
     * @return array<int, mixed>
     */
    private function csvRow(object $employee, int|float|string $totalEarnings): array
    {
        $department = 'N/A';
        if ($employee->current_dept_name) {
            $department = $employee->current_dept_name;
        } elseif ($employee->last_dept_name) {
            $department = __('Former').' - '.$employee->last_dept_name;
        }

        $title = 'N/A';
        if ($employee->current_title) {
            $title = $employee->current_title;
        } elseif ($employee->last_job_title) {
            $title = $employee->last_job_title.' ('.__('Last').')';
        }

        return [
            $employee->emp_no,
            $employee->first_name,
            $employee->last_name,
            $employee->first_name.' '.$employee->last_name,
            $department,
            $title,
            $employee->current_salary ? number_format($employee->current_salary, 2, '.', '') : 'N/A',
            number_format((float) $totalEarnings, 2, '.', ''),
            date('Y-m-d', strtotime($employee->hire_date)),
            $employee->gender === 'M' ? __('Male') : __('Female'),
            $employee->current_dept_name ? __('Active') : __('Former'),
        ];
    }
}
