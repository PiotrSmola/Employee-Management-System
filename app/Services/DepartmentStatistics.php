<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Support\EmployeeDataCache;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Figures of the departments index and of a department page.
 *
 * The aggregates over the current employees (headcount, titles, salaries,
 * gender, tenure, headcount per year, recent movements) are expensive on the
 * full dataset (~240k current employees), so they are cached
 * ({@see EmployeeDataCache::DEPARTMENTS_OVERVIEW},
 * {@see EmployeeDataCache::departmentDetails()}) - always including the salary
 * figures, which are removed here before anything leaves the service when the
 * caller may not see salaries. Managers (dept_manager) are a few rows and are
 * always read live.
 *
 * "Current" means to_date = 9999-01-01 (dept_emp, titles, salaries, dept_manager).
 */
class DepartmentStatistics
{
    public const CURRENT = '9999-01-01';

    /** Cache lifetime in seconds; writes through the app flush the keys right away. */
    private const TTL = 1800;

    /**
     * Optimizer hint for the salary figures: read the current salaries from the
     * covering (to_date, salary) index and look up each employee's department.
     * The optimizer's own choice, from dept_emp through the salaries primary
     * key, reads every employee's whole salary history (most of the 2.8M rows).
     */
    private const SALARY_PLAN = '/*+ JOIN_ORDER(s, de) INDEX(s salaries_to_date_salary_index) */';

    /** Rows in the "recently joined" / "recently left" lists. */
    public const RECENT_LIMIT = 8;

    /**
     * Index rows for $departments (in the given order).
     *
     * @param  Collection<int, Department>  $departments
     * @return Collection<int, object{dept_no: string, dept_name: string, headcount: int, titles: int, avg_salary: ?float, manager: ?object}>
     */
    public function overview(Collection $departments, bool $withSalaries): Collection
    {
        $figures = Cache::remember(EmployeeDataCache::DEPARTMENTS_OVERVIEW, self::TTL, fn () => $this->computeOverview());
        $managers = $this->currentManagers($departments->pluck('dept_no')->all());

        return $departments->map(fn (Department $department) => (object) [
            'dept_no' => $department->dept_no,
            'dept_name' => $department->dept_name,
            'headcount' => (int) ($figures[$department->dept_no]['headcount'] ?? 0),
            'titles' => (int) ($figures[$department->dept_no]['titles'] ?? 0),
            'avg_salary' => $withSalaries ? ($figures[$department->dept_no]['avg_salary'] ?? null) : null,
            'manager' => $managers[$department->dept_no] ?? null,
        ])->values();
    }

    /**
     * Everything the department page shows. Salary figures are null without $withSalaries.
     *
     * @return array{
     *     headcount: int,
     *     gender: array{M: int, F: int},
     *     avgTenureYears: ?float,
     *     salary: ?array{avg: ?float, min: ?int, max: ?int},
     *     titles: Collection,
     *     headcountByYear: Collection,
     *     recentJoiners: Collection,
     *     recentLeavers: Collection,
     *     currentManager: ?object,
     *     managerHistory: Collection,
     * }
     */
    public function details(Department $department, bool $withSalaries): array
    {
        $figures = Cache::remember(
            EmployeeDataCache::departmentDetails($department->dept_no),
            self::TTL,
            fn () => $this->computeDetails($department->dept_no)
        );

        $history = $this->managerHistory($department->dept_no);

        return [
            'headcount' => $figures['headcount'],
            'gender' => $figures['gender'],
            'avgTenureYears' => $figures['avgTenureYears'],
            'salary' => $withSalaries ? $figures['salary'] : null,
            'titles' => collect($figures['titles'])->map(fn ($row) => (object) $row),
            'headcountByYear' => collect($figures['headcountByYear']),
            'recentJoiners' => collect($figures['recentJoiners'])->map(fn ($row) => (object) $row),
            'recentLeavers' => collect($figures['recentLeavers'])->map(fn ($row) => (object) $row),
            'currentManager' => $history->firstWhere('to_date', self::CURRENT),
            'managerHistory' => $history,
        ];
    }

    /**
     * The current manager of each department (dept_no => emp_no, first_name, last_name, from_date).
     *
     * @param  string[]|null  $deptNos
     * @return Collection<string, object>
     */
    public function currentManagers(?array $deptNos = null): Collection
    {
        return DB::table('dept_manager as dm')
            ->join('employees as e', 'e.emp_no', '=', 'dm.emp_no')
            ->where('dm.to_date', self::CURRENT)
            ->when($deptNos !== null, fn (Builder $q) => $q->whereIn('dm.dept_no', $deptNos))
            ->orderBy('dm.from_date')
            ->get(['dm.dept_no', 'dm.emp_no', 'e.first_name', 'e.last_name', 'dm.from_date', 'dm.to_date'])
            ->keyBy('dept_no');
    }

    /**
     * All management periods of a department, newest first.
     *
     * @return Collection<int, object{emp_no: int, first_name: string, last_name: string, from_date: string, to_date: string}>
     */
    public function managerHistory(string $deptNo): Collection
    {
        return DB::table('dept_manager as dm')
            ->join('employees as e', 'e.emp_no', '=', 'dm.emp_no')
            ->where('dm.dept_no', $deptNo)
            ->orderByDesc('dm.from_date')
            ->orderByDesc('dm.to_date')
            ->get(['dm.emp_no', 'e.first_name', 'e.last_name', 'dm.from_date', 'dm.to_date']);
    }

    /**
     * Department management facts for an employee profile: the current manager
     * of the employee's current department(s) and the employee's own
     * management periods (newest first).
     *
     * @return array{departmentManagers: Collection<int, object>, managedPeriods: Collection<int, object>}
     */
    public function forEmployee(Employee $employee): array
    {
        $departments = $employee->relationLoaded('currentDepartment')
            ? $employee->currentDepartment
            : $employee->currentDepartment()->get();

        $managers = $departments->isEmpty() ? collect() : $this->currentManagers($departments->pluck('dept_no')->all());

        return [
            'departmentManagers' => $departments->map(fn (Department $department) => (object) [
                'dept_no' => $department->dept_no,
                'dept_name' => $department->dept_name,
                'manager' => $managers[$department->dept_no] ?? null,
            ])->values(),
            'managedPeriods' => DB::table('dept_manager as dm')
                ->join('departments as d', 'd.dept_no', '=', 'dm.dept_no')
                ->where('dm.emp_no', $employee->emp_no)
                ->orderByDesc('dm.from_date')
                ->get(['dm.dept_no', 'd.dept_name', 'dm.from_date', 'dm.to_date']),
        ];
    }

    /**
     * @return array<string, array{headcount: int, titles: int, avg_salary: ?float}>
     */
    private function computeOverview(): array
    {
        $headcount = DB::table('dept_emp')
            ->where('to_date', self::CURRENT)
            ->groupBy('dept_no')
            ->selectRaw('dept_no, count(*) as total')
            ->pluck('total', 'dept_no');

        $titles = $this->currentMembers()
            ->join('titles as t', fn (JoinClause $j) => $j->on('t.emp_no', '=', 'de.emp_no')->where('t.to_date', '=', self::CURRENT))
            ->groupBy('de.dept_no')
            ->selectRaw('de.dept_no, count(distinct t.title) as total')
            ->pluck('total', 'dept_no');

        $salaries = $this->currentSalaries()
            ->groupBy('de.dept_no')
            ->selectRaw(self::SALARY_PLAN.' de.dept_no, avg(s.salary) as average')
            ->pluck('average', 'dept_no');

        $figures = [];
        foreach ($headcount as $deptNo => $count) {
            $figures[$deptNo] = [
                'headcount' => (int) $count,
                'titles' => (int) ($titles[$deptNo] ?? 0),
                'avg_salary' => isset($salaries[$deptNo]) ? round((float) $salaries[$deptNo], 2) : null,
            ];
        }

        return $figures;
    }

    /**
     * Cached part of the department page (plain arrays, so the cache does not hold objects).
     *
     * @return array<string, mixed>
     */
    private function computeDetails(string $deptNo): array
    {
        $members = fn () => $this->currentMembers($deptNo);

        $headcount = $members()->count();

        $people = $members()
            ->join('employees as e', 'e.emp_no', '=', 'de.emp_no')
            ->selectRaw("sum(e.gender = 'M') as male, sum(e.gender = 'F') as female, avg(datediff(curdate(), e.hire_date)) as tenure_days")
            ->first();

        $salary = $this->currentSalaries($deptNo)
            ->selectRaw(self::SALARY_PLAN.' avg(s.salary) as avg_salary, min(s.salary) as min_salary, max(s.salary) as max_salary')
            ->first();

        $titles = $members()
            ->join('titles as t', fn (JoinClause $j) => $j->on('t.emp_no', '=', 'de.emp_no')->where('t.to_date', '=', self::CURRENT))
            ->groupBy('t.title')
            ->orderByDesc('total')
            ->orderBy('t.title')
            ->get(['t.title', DB::raw('count(*) as total')])
            ->map(fn ($row) => ['title' => $row->title, 'total' => (int) $row->total])
            ->all();

        return [
            'headcount' => $headcount,
            'gender' => ['M' => (int) ($people->male ?? 0), 'F' => (int) ($people->female ?? 0)],
            'avgTenureYears' => $people?->tenure_days !== null ? round($people->tenure_days / 365.25, 1) : null,
            'salary' => [
                'avg' => $salary?->avg_salary !== null ? round((float) $salary->avg_salary, 2) : null,
                'min' => $salary?->min_salary !== null ? (int) $salary->min_salary : null,
                'max' => $salary?->max_salary !== null ? (int) $salary->max_salary : null,
            ],
            'titles' => $titles,
            'headcountByYear' => $this->headcountByYear($deptNo),
            'recentJoiners' => $this->recentJoiners($deptNo),
            'recentLeavers' => $this->recentLeavers($deptNo),
        ];
    }

    /**
     * Employees in the department at the end of each year, from the first
     * assignment to the last recorded change: the running total of the
     * assignments that started minus those that ended (a period ends on its
     * to_date, the day the next one starts).
     *
     * @return array<int, array{year: int, headcount: int}>
     */
    private function headcountByYear(string $deptNo): array
    {
        $joined = DB::table('dept_emp')
            ->where('dept_no', $deptNo)
            ->groupByRaw('year(from_date)')
            ->selectRaw('year(from_date) as year, count(*) as total')
            ->pluck('total', 'year');

        $left = DB::table('dept_emp')
            ->where('dept_no', $deptNo)
            ->where('to_date', '<>', self::CURRENT)
            ->groupByRaw('year(to_date)')
            ->selectRaw('year(to_date) as year, count(*) as total')
            ->pluck('total', 'year');

        if ($joined->isEmpty()) {
            return [];
        }

        $years = [...$joined->keys()->all(), ...$left->keys()->all()];
        $rows = [];
        $running = 0;

        for ($year = (int) min($years); $year <= (int) max($years); $year++) {
            $running += (int) ($joined[$year] ?? 0) - (int) ($left[$year] ?? 0);
            $rows[] = ['year' => $year, 'headcount' => $running];
        }

        return $rows;
    }

    /**
     * Latest assignments to the department; `hired` = the assignment started on the hire date.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentJoiners(string $deptNo): array
    {
        // Sorted on dept_emp alone: joining employees first would sort every
        // assignment of the department with the names (85k rows for the largest).
        $rows = DB::table('dept_emp')
            ->where('dept_no', $deptNo)
            ->orderByDesc('from_date')
            ->orderByDesc('emp_no')
            ->limit(self::RECENT_LIMIT)
            ->get(['emp_no', 'from_date', 'to_date']);

        $people = DB::table('employees')
            ->whereIn('emp_no', $rows->pluck('emp_no'))
            ->get(['emp_no', 'first_name', 'last_name', 'hire_date'])
            ->keyBy('emp_no');

        return $rows->map(fn ($row) => [
            'emp_no' => (int) $row->emp_no,
            'first_name' => $people[$row->emp_no]->first_name,
            'last_name' => $people[$row->emp_no]->last_name,
            'date' => $row->from_date,
            'hired' => $row->from_date === $people[$row->emp_no]->hire_date,
            'still_here' => $row->to_date === self::CURRENT,
        ])->all();
    }

    /**
     * Latest assignments that ended; `moved_to` = the department the employee
     * went to next (null: left the company).
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentLeavers(string $deptNo): array
    {
        $rows = DB::table('dept_emp as de')
            ->join('employees as e', 'e.emp_no', '=', 'de.emp_no')
            ->where('de.dept_no', $deptNo)
            ->where('de.to_date', '<>', self::CURRENT)
            ->orderByDesc('de.to_date')
            ->orderByDesc('de.emp_no')
            ->limit(self::RECENT_LIMIT)
            ->get(['de.emp_no', 'e.first_name', 'e.last_name', 'de.to_date']);

        $next = $rows->isEmpty() ? collect() : DB::table('dept_emp as n')
            ->join('departments as d', 'd.dept_no', '=', 'n.dept_no')
            ->whereIn('n.emp_no', $rows->pluck('emp_no'))
            ->where('n.dept_no', '<>', $deptNo)
            ->get(['n.emp_no', 'n.from_date', 'd.dept_name']);

        return $rows->map(fn ($row) => [
            'emp_no' => (int) $row->emp_no,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'date' => $row->to_date,
            'moved_to' => $next->where('emp_no', $row->emp_no)->firstWhere('from_date', $row->to_date)?->dept_name,
        ])->all();
    }

    /**
     * Current salaries (salaries as s) of the current employees (dept_emp as de),
     * optionally of one department. Select with {@see self::SALARY_PLAN}.
     */
    private function currentSalaries(?string $deptNo = null): Builder
    {
        return DB::table('salaries as s')
            ->join('dept_emp as de', fn (JoinClause $j) => $j->on('de.emp_no', '=', 's.emp_no')->where('de.to_date', '=', self::CURRENT))
            ->where('s.to_date', self::CURRENT)
            ->when($deptNo !== null, fn (Builder $q) => $q->where('de.dept_no', $deptNo));
    }

    /**
     * Current assignments (dept_emp as de), optionally of one department.
     */
    private function currentMembers(?string $deptNo = null): Builder
    {
        return DB::table('dept_emp as de')
            ->where('de.to_date', self::CURRENT)
            ->when($deptNo !== null, fn (Builder $q) => $q->where('de.dept_no', $deptNo));
    }
}
