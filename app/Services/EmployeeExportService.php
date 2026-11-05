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
     * Without $withSalaries the salary and earnings columns are left out.
     */
    public function csv(EmployeeSearchQuery $query, bool $withSalaries = true): Response
    {
        set_time_limit(600);

        $ids = $query->ids();

        if ($ids === []) {
            return back()->with('error', __('No employees found for export.'));
        }

        $filename = 'employees_export_selected_'.count($ids).'_'.date('Y-m-d_H-i-s').'.csv';

        return new StreamedResponse(function () use ($query, $ids, $withSalaries) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $this->withoutSalaryColumns([
                __('Employee ID'),
                __('First Name'),
                __('Last Name'),
                __('Full Name'),
                __('Department'),
                __('Job Title'),
                'salary' => __('Current Salary'),
                'earnings' => __('Total Earnings'),
                __('Hire Date'),
                __('Gender'),
                __('Employment Status'),
            ], $withSalaries));

            foreach (array_chunk($ids, self::CSV_CHUNK) as $chunk) {
                $rows = $query->rowsFor($chunk);
                $earnings = $withSalaries ? $this->totalEarnings($chunk) : [];

                foreach ($rows as $employee) {
                    fputcsv($handle, $this->withoutSalaryColumns(
                        $this->csvRow($employee, $earnings[$employee->emp_no] ?? 0),
                        $withSalaries
                    ));
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
     * Without $withSalaries the salary and earnings columns are left out.
     */
    public function pdf(EmployeeSearchQuery $query, bool $withSalaries = true): Response
    {
        set_time_limit(600);
        ini_set('memory_limit', '2G');

        $ids = $query->ids(static::PDF_LIMIT);

        if ($ids === []) {
            return back()->with('error', __('No employees found for export.'));
        }

        // The PDF holds at most PDF_LIMIT rows; the selection itself may be larger.
        $selectedCount = count($ids) < static::PDF_LIMIT ? count($ids) : $query->count();

        $earnings = $withSalaries ? $this->totalEarnings($ids) : [];
        $employees = $query->rowsFor($ids)->map(fn ($employee) => (object) [
            'emp_no' => $employee->emp_no,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'hire_date' => $employee->hire_date,
            'gender' => $employee->gender,
            'current_dept_name' => $employee->current_dept_name,
            'current_title' => $employee->current_title,
            'current_salary' => $withSalaries ? $employee->current_salary : null,
            'last_dept_name' => $employee->last_dept_name,
            'last_job_title' => $employee->last_job_title,
            'total_earnings' => $withSalaries ? ($earnings[$employee->emp_no] ?? 0) : null,
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
                'withSalaries' => $withSalaries,
            ])
                ->setPaper('A4', 'landscape')
                ->setOptions([
                    'isHtml5ParserEnabled' => true,
                    'isPhpEnabled' => true,
                    'defaultFont' => 'DejaVu Sans',
                ]);

            return $pdf->download('employees_selected_'.$selectedCount.'_'.date('Y-m-d_H-i-s').'.pdf');
        } catch (\Exception $e) {
            // Details go to the log only - the message may contain paths, SQL or data.
            report($e);

            return back()->with('error', __('The PDF export failed. Please try again or export fewer employees.'));
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
     * Drop the entries keyed 'salary' / 'earnings' unless salaries may be shown.
     *
     * @param  array<int|string, mixed>  $columns
     * @return array<int, mixed>
     */
    private function withoutSalaryColumns(array $columns, bool $withSalaries): array
    {
        if (! $withSalaries) {
            unset($columns['salary'], $columns['earnings']);
        }

        return array_values($columns);
    }

    /**
     * @return array<int|string, mixed>
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

        return array_map(self::csvCell(...), [
            $employee->emp_no,
            $employee->first_name,
            $employee->last_name,
            $employee->first_name.' '.$employee->last_name,
            $department,
            $title,
            'salary' => $employee->current_salary ? number_format($employee->current_salary, 2, '.', '') : 'N/A',
            'earnings' => number_format((float) $totalEarnings, 2, '.', ''),
            date('Y-m-d', strtotime($employee->hire_date)),
            $employee->gender === 'M' ? __('Male') : __('Female'),
            $employee->current_dept_name ? __('Active') : __('Former'),
        ]);
    }

    /**
     * Neutralize spreadsheet formulas (CSV injection): a text cell starting with =, +, -, @,
     * a tab or a carriage return is prefixed with a single quote so that Excel / LibreOffice
     * show it as text instead of evaluating it.
     */
    private static function csvCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
