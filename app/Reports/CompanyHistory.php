<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Extent of the company's history, so the yearly series of every scope (a
 * department, a manager's employees) run up to the same year as the company's.
 */
final class CompanyHistory
{
    /**
     * Year of the last hire or closed history row on or before $date (index lookups only).
     */
    public static function lastChangeYear(string $date): ?int
    {
        $dates = [
            DB::table('employees')->where('hire_date', '<=', $date)->max('hire_date'),
            DB::table('dept_emp')->where('to_date', '<=', $date)->max('to_date'),
            DB::table('titles')->where('to_date', '<=', $date)->max('to_date'),
            DB::table('salaries')->where('to_date', '<=', $date)->max('to_date'),
        ];

        $years = array_map(fn ($d) => (int) substr((string) $d, 0, 4), array_filter($dates));

        return $years === [] ? null : max($years);
    }
}
