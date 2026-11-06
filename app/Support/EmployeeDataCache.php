<?php

namespace App\Support;

use App\Models\Department;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Cache keys of figures derived from the employee data (dashboard, filter
 * options). Code that changes employees, departments, titles, salaries or
 * department assignments calls EmployeeDataCache::flush() afterwards, so the
 * dashboard and the filters do not show stale data until the TTL runs out.
 *
 * Readers build their keys with the methods below, so the list stays complete.
 * Reports use a versioned namespace instead of single keys (report()).
 */
final class EmployeeDataCache
{
    /** Company-wide dashboard totals (DashboardService). */
    public const DASHBOARD_STATS = 'dashboard_stats';

    /** Company-wide employees per department (DashboardService). */
    public const EMPLOYEES_BY_DEPARTMENT = 'employees_by_department';

    /**
     * Current namespace of the cached reports (App\Reports). Report keys embed it,
     * so forgetting it (flush()) retires every cached report at once - for any
     * department, scope and as-of date - without listing them; the old entries
     * simply expire.
     */
    public const REPORTS_VERSION = 'reports:version';

    /** Dashboard totals of a manager's department. */
    public static function departmentStats(string $deptNo): string
    {
        return 'dashboard_stats:dept:'.$deptNo;
    }

    /** Employees per title in a manager's department (dashboard). */
    public static function departmentTitleBreakdown(string $deptNo): string
    {
        return 'employees_by_title:dept:'.$deptNo;
    }

    /** Job titles offered in a manager's directory filter (EmployeeFilter). */
    public static function departmentJobTitles(string $deptNo): string
    {
        return 'job_titles:dept:'.$deptNo;
    }

    /**
     * Key of a cached report figure: reports:<version>:<name>:<part>:<part>...
     * e.g. report('snapshot', 'all', 'current') or report('salary_snapshot', 'mgr-d005', '1995-12-31').
     */
    public static function report(string $name, string ...$parts): string
    {
        return implode(':', ['reports', self::reportsVersion(), $name, ...$parts]);
    }

    /** The current reports namespace (created on first use and after every flush). */
    public static function reportsVersion(): string
    {
        $version = Cache::get(self::REPORTS_VERSION);

        if ($version === null) {
            // Concurrent first uses: add() lets one win, and everybody reads the winner.
            $candidate = Str::lower(Str::random(8));
            Cache::add(self::REPORTS_VERSION, $candidate, now()->addYears(10));
            $version = Cache::get(self::REPORTS_VERSION, $candidate);
        }

        return (string) $version;
    }

    /** Retire every cached report, keep the other figures. */
    public static function flushReports(): void
    {
        Cache::forget(self::REPORTS_VERSION);
    }

    /**
     * Every key, for all departments (or only $departments) - company-wide keys always.
     *
     * @param  string[]|null  $departments
     * @return string[]
     */
    public static function keys(?array $departments = null): array
    {
        $departments ??= [...Department::query()->pluck('dept_no')->all(), EmployeeVisibility::NO_DEPARTMENT];

        // Reports cover every department (company-wide figures, histories), so any flush retires them.
        $keys = [self::DASHBOARD_STATS, self::EMPLOYEES_BY_DEPARTMENT, self::REPORTS_VERSION];

        foreach ($departments as $deptNo) {
            $keys[] = self::departmentStats($deptNo);
            $keys[] = self::departmentTitleBreakdown($deptNo);
            $keys[] = self::departmentJobTitles($deptNo);
        }

        return $keys;
    }

    /**
     * Forget the cached figures. Pass the affected departments (e.g. old and
     * new department of a transfer) to keep the other departments' caches;
     * without arguments everything is cleared.
     *
     * @param  string[]|null  $departments
     */
    public static function flush(?array $departments = null): void
    {
        foreach (self::keys($departments) as $key) {
            Cache::forget($key);
        }
    }
}
