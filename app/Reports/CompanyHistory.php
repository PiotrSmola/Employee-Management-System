<?php

namespace App\Reports;

use App\Support\EmployeeDataCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Extent of the company's history, so the yearly series of every scope (a
 * department, a manager's employees) run up to the same year as the company's.
 */
final class CompanyHistory
{
    /** The first hire date (index lookup, cached with the reports), null without employees. */
    public static function earliestHireDate(): ?string
    {
        return Cache::remember(
            EmployeeDataCache::report('earliest_hire'),
            ReportCache::TTL,
            fn () => DB::table('employees')->min('hire_date')
        );
    }

    /**
     * Year of the last hire or closed history row on or before $date (index lookups only).
     */
    public static function lastChangeYear(string $date): ?int
    {
        $dates = [
            DB::table('employees')->where('hire_date', '<=', $date)->max('hire_date'),
            ReportSources::deptEmp('de')->where('de.to_date', '<=', $date)->max('de.to_date'),
            ReportSources::titles('t')->where('t.to_date', '<=', $date)->max('t.to_date'),
            ReportSources::salaries('s')->where('s.to_date', '<=', $date)->max('s.to_date'),
        ];

        $years = array_map(fn ($d) => (int) substr((string) $d, 0, 4), array_filter($dates));

        return $years === [] ? null : max($years);
    }
}
