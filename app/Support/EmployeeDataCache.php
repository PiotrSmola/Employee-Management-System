<?php

namespace App\Support;

use App\Models\Department;
use Illuminate\Support\Facades\Cache;

/**
 * Cache keys of figures derived from the employee data (dashboard, filter
 * options). Code that changes employees, departments, titles, salaries or
 * department assignments calls EmployeeDataCache::flush() afterwards, so the
 * dashboard and the filters do not show stale data until the TTL runs out.
 *
 * Readers build their keys with the methods below, so the list stays complete.
 */
final class EmployeeDataCache
{
    /** Company-wide dashboard totals (DashboardService). */
    public const DASHBOARD_STATS = 'dashboard_stats';

    /** Company-wide employees per department (DashboardService). */
    public const EMPLOYEES_BY_DEPARTMENT = 'employees_by_department';

    /** Distinct job titles of the dataset (suggestions of the HR forms, EmployeeOperationsService). */
    public const JOB_TITLES = 'job_titles';

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
     * Every key, for all departments (or only $departments) - company-wide keys always.
     *
     * @param  string[]|null  $departments
     * @return string[]
     */
    public static function keys(?array $departments = null): array
    {
        $departments ??= [...Department::query()->pluck('dept_no')->all(), EmployeeVisibility::NO_DEPARTMENT];

        $keys = [self::DASHBOARD_STATS, self::EMPLOYEES_BY_DEPARTMENT, self::JOB_TITLES];

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
