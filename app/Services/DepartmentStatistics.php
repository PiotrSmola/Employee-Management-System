<?php

namespace App\Services;

use App\Console\Commands\WarmDepartmentFigures;
use App\Models\Department;
use App\Models\Employee;
use App\Support\AfterResponse;
use App\Support\EmployeeDataCache;
use App\Support\EmployeeVisibility;
use App\Support\FigureCache;
use App\Support\HistoryRows;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures of the departments index and of a department page.
 *
 * The aggregates over the current employees (headcount, titles, salaries,
 * gender, tenure, headcount per year, recent movements) are expensive on the
 * full dataset (~240k current employees), so they go through FigureCache: a
 * reader gets the cached figures, the last good copy while they are refreshed
 * after the response, or the status PREPARING while another process computes
 * them for the first time.
 *
 * The cached payload is shared by everyone. What a user may see is decided per
 * request, here, before anything leaves the service: salary figures only with
 * `view-salaries`, and a department-scoped user does not get the names and
 * numbers of employees outside their scope ({@see self::mask()}). Managers
 * (dept_manager) are a few rows and are always read live, as are department
 * names, so neither change affects the cached figures.
 *
 * "Current" means to_date = 9999-01-01 (dept_emp, titles, salaries, dept_manager).
 * Headcounts count current dept_emp rows (assignments), the same way the
 * dashboard does: the dataset has one per employee.
 */
class DepartmentStatistics
{
    public const CURRENT = '9999-01-01';

    /**
     * Seconds until figures are refreshed. The overview is not invalidated by a
     * change of one employee (EmployeeDataCache::flush()), so it expires sooner.
     */
    private const OVERVIEW_TTL = 900;

    private const DETAILS_TTL = 1800;

    /**
     * Optimizer hint for the salary figures: read the current salaries from the
     * covering (to_date, salary) index and look up each employee's department.
     * The optimizer's own choice, from dept_emp through the salaries primary
     * key, reads every employee's whole salary history (most of the 2.8M rows).
     * MySQL silently ignores a hint on a missing index, so a test checks it exists.
     */
    public const SALARY_INDEX = 'salaries_to_date_salary_index';

    private const SALARY_PLAN = '/*+ JOIN_ORDER(s, de) INDEX(s '.self::SALARY_INDEX.') */';

    /** Rows in the "recently joined" / "recently left" lists. */
    public const RECENT_LIMIT = 8;

    /**
     * Index rows for $departments (in the given order). Figures are null while
     * figuresStatus is FigureCache::PREPARING.
     *
     * @param  Collection<int, Department>  $departments
     * @return array{departments: Collection<int, object>, figuresStatus: string}
     */
    public function overview(Collection $departments, EmployeeVisibility $visibility): array
    {
        $read = FigureCache::read(
            EmployeeDataCache::DEPARTMENTS_OVERVIEW,
            self::OVERVIEW_TTL,
            fn () => $this->computeOverview(),
            fn () => AfterResponse::artisan('departments:warm', ['--only' => [WarmDepartmentFigures::OVERVIEW], '--expired' => true]),
        );

        $figures = $read['value'];
        $withSalaries = $visibility->canSeeSalaries();
        $managers = $this->currentManagers($departments->pluck('dept_no')->all());
        $visible = $this->visibleEmployees($visibility, $managers->pluck('emp_no'));

        return [
            'figuresStatus' => $read['status'],
            'departments' => $departments->map(function (Department $department) use ($figures, $withSalaries, $managers, $visible) {
                $row = $figures[$department->dept_no] ?? null;
                $manager = $managers[$department->dept_no] ?? null;

                return (object) [
                    'dept_no' => $department->dept_no,
                    'dept_name' => $department->dept_name,
                    'headcount' => $figures === null ? null : (int) ($row['headcount'] ?? 0),
                    'titles' => $figures === null ? null : (int) ($row['titles'] ?? 0),
                    'avg_salary' => $withSalaries ? ($row['avg_salary'] ?? null) : null,
                    'manager' => $manager === null ? null : $this->mask($manager, $visible),
                ];
            })->values(),
        ];
    }

    /**
     * Everything the department page shows. Salary figures are null without
     * `view-salaries`; employees outside a scoped user's view have `hidden` set
     * and no name, number or destination.
     *
     * @return array{
     *     figuresStatus: string,
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
    public function details(Department $department, EmployeeVisibility $visibility): array
    {
        $deptNo = $department->dept_no;

        $read = FigureCache::read(
            EmployeeDataCache::departmentDetails($deptNo),
            self::DETAILS_TTL,
            fn () => $this->computeDetails($deptNo),
            fn () => AfterResponse::artisan('departments:warm', ['--only' => [$deptNo], '--expired' => true]),
        );

        $figures = $read['value'] ?? [
            'headcount' => 0,
            'gender' => ['M' => 0, 'F' => 0],
            'avgTenureYears' => null,
            'salary' => null,
            'titles' => [],
            'headcountByYear' => [],
            'recentJoiners' => [],
            'recentLeavers' => [],
        ];

        $history = $this->managerHistory($deptNo);

        $visible = $this->visibleEmployees($visibility, collect([
            ...$history->pluck('emp_no'),
            ...array_column($figures['recentJoiners'], 'emp_no'),
            ...array_column($figures['recentLeavers'], 'emp_no'),
        ]));

        $departmentNames = array_filter(array_column($figures['recentLeavers'], 'moved_to')) === []
            ? collect()
            : Department::query()->pluck('dept_name', 'dept_no');

        $history = $history->map(fn (object $row) => $this->mask($row, $visible));

        return [
            'figuresStatus' => $read['status'],
            'headcount' => $figures['headcount'],
            'gender' => $figures['gender'],
            'avgTenureYears' => $figures['avgTenureYears'],
            'salary' => $visibility->canSeeSalaries() ? $figures['salary'] : null,
            'titles' => collect($figures['titles'])->map(fn ($row) => (object) $row),
            'headcountByYear' => collect($figures['headcountByYear']),
            'recentJoiners' => collect($figures['recentJoiners'])
                ->map(fn ($row) => $this->mask((object) $row, $visible)),
            'recentLeavers' => collect($figures['recentLeavers'])
                ->map(function ($row) use ($departmentNames, $visible) {
                    // A deleted department no longer has a name; the move is still a move.
                    $row['moved_to'] = $row['moved_to'] === null ? null : ($departmentNames[$row['moved_to']] ?? __('another department'));

                    return $this->mask((object) $row, $visible);
                }),
            'currentManager' => $history->firstWhere('to_date', self::CURRENT),
            'managerHistory' => $history,
        ];
    }

    /**
     * Recompute the overview now, unless another process is on it.
     *
     * @return bool were the figures computed?
     */
    public function refreshOverview(bool $onlyWhenExpired = false): bool
    {
        return FigureCache::refresh(
            EmployeeDataCache::DEPARTMENTS_OVERVIEW,
            self::OVERVIEW_TTL,
            fn () => $this->computeOverview(),
            $onlyWhenExpired,
        );
    }

    /**
     * Recompute the figures of one department now, unless another process is on it.
     *
     * @return bool were the figures computed?
     */
    public function refreshDetails(string $deptNo, bool $onlyWhenExpired = false): bool
    {
        return FigureCache::refresh(
            EmployeeDataCache::departmentDetails($deptNo),
            self::DETAILS_TTL,
            fn () => $this->computeDetails($deptNo),
            $onlyWhenExpired,
        );
    }

    /**
     * The current manager of each department (dept_no => emp_no, first_name, last_name, from_date).
     *
     * @param  string[]|null  $deptNos
     * @return Collection<string, object>
     */
    public function currentManagers(?array $deptNos = null): Collection
    {
        return HistoryRows::active(DB::table('dept_manager as dm'), 'dm')
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
        return HistoryRows::active(DB::table('dept_manager as dm'), 'dm')
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
            'managedPeriods' => HistoryRows::active(DB::table('dept_manager as dm'), 'dm')
                ->join('departments as d', 'd.dept_no', '=', 'dm.dept_no')
                ->where('dm.emp_no', $employee->emp_no)
                ->orderByDesc('dm.from_date')
                ->get(['dm.dept_no', 'd.dept_name', 'dm.from_date', 'dm.to_date']),
        ];
    }

    /**
     * Which of $empNos a scoped user may see; null = no restriction.
     *
     * @param  Collection<int, int|string>  $empNos
     * @return array<int, true>|null
     */
    private function visibleEmployees(EmployeeVisibility $visibility, Collection $empNos): ?array
    {
        if (! $visibility->isDepartmentScoped()) {
            return null;
        }

        if ($empNos->isEmpty()) {
            return [];
        }

        return $visibility->constrain(DB::table('employees')->whereIn('emp_no', $empNos->unique()->values()))
            ->pluck('emp_no')
            ->mapWithKeys(fn ($empNo) => [(int) $empNo => true])
            ->all();
    }

    /**
     * Set `hidden` on a row about an employee, and for employees outside the
     * user's view drop what identifies them: the row stays (the department's
     * history is the manager's to see), the person does not.
     *
     * @param  array<int, true>|null  $visible
     */
    private function mask(object $row, ?array $visible): object
    {
        if ($visible === null || isset($visible[(int) $row->emp_no])) {
            $row->hidden = false;

            return $row;
        }

        return (object) [
            ...(array) $row,
            'emp_no' => null,
            'first_name' => null,
            'last_name' => null,
            'moved_to' => null,
            'hidden' => true,
        ];
    }

    /**
     * @return array<string, array{headcount: int, titles: int, avg_salary: ?float}>
     */
    private function computeOverview(): array
    {
        $headcount = HistoryRows::active(DB::table('dept_emp'))
            ->where('to_date', self::CURRENT)
            ->groupBy('dept_no')
            ->selectRaw('dept_no, count(*) as total')
            ->pluck('total', 'dept_no');

        $titles = $this->currentMembers()
            ->join('titles as t', fn (JoinClause $j) => HistoryRows::active($j->on('t.emp_no', '=', 'de.emp_no'), 't')->where('t.to_date', '=', self::CURRENT))
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
     * Cached part of the department page (plain arrays, so the cache does not
     * hold objects; no department names, so a rename does not affect it).
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
            ->join('titles as t', fn (JoinClause $j) => HistoryRows::active($j->on('t.emp_no', '=', 'de.emp_no'), 't')->where('t.to_date', '=', self::CURRENT))
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
     * Assignments in the department at the end of each year, from its first
     * assignment to the last year with any data, the same for every department
     * (the imported dataset ends in 2002): the running total of the assignments
     * that started minus those that ended (a period ends on its to_date, the
     * day the next one starts). Counts dept_emp rows, not distinct employees.
     *
     * @return array<int, array{year: int, headcount: int}>
     */
    private function headcountByYear(string $deptNo): array
    {
        $joined = HistoryRows::active(DB::table('dept_emp'))
            ->where('dept_no', $deptNo)
            ->groupByRaw('year(from_date)')
            ->selectRaw('year(from_date) as year, count(*) as total')
            ->pluck('total', 'year');

        if ($joined->isEmpty()) {
            return [];
        }

        $left = HistoryRows::active(DB::table('dept_emp'))
            ->where('dept_no', $deptNo)
            ->where('to_date', '<', self::CURRENT)
            ->groupByRaw('year(to_date)')
            ->selectRaw('year(to_date) as year, count(*) as total')
            ->pluck('total', 'year');

        // Latest row by index order: MAX() no longer stops at the first index entry
        // with the void_log_id condition.
        $lastYear = (int) substr(max(
            (string) HistoryRows::active(DB::table('dept_emp'))->orderByDesc('from_date')->value('from_date'),
            (string) HistoryRows::active(DB::table('dept_emp'))->where('to_date', '<', self::CURRENT)->orderByDesc('to_date')->value('to_date'),
        ), 0, 4);

        $rows = [];
        $running = 0;

        for ($year = (int) $joined->keys()->min(); $year <= $lastYear; $year++) {
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
        $rows = HistoryRows::active(DB::table('dept_emp'))
            ->where('dept_no', $deptNo)
            ->orderByDesc('from_date')
            ->orderByDesc('emp_no')
            ->limit(self::RECENT_LIMIT)
            ->get(['emp_no', 'from_date']);

        $people = $this->people($rows);

        return $rows->map(fn ($row) => [
            'emp_no' => (int) $row->emp_no,
            'first_name' => $people[$row->emp_no]->first_name,
            'last_name' => $people[$row->emp_no]->last_name,
            'date' => $row->from_date,
            'hired' => $row->from_date === $people[$row->emp_no]->hire_date,
        ])->all();
    }

    /**
     * Latest assignments that ended; `moved_to` = the dept_no the employee went
     * to next (null: left the company). details() replaces it with the name.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentLeavers(string $deptNo): array
    {
        // Read from the (dept_no, to_date) index in order, names afterwards.
        $rows = HistoryRows::active(DB::table('dept_emp'))
            ->where('dept_no', $deptNo)
            ->where('to_date', '<', self::CURRENT)
            ->orderByDesc('to_date')
            ->orderByDesc('emp_no')
            ->limit(self::RECENT_LIMIT)
            ->get(['emp_no', 'to_date']);

        $people = $this->people($rows);

        $later = HistoryRows::active(DB::table('dept_emp'))
            ->whereIn('emp_no', $rows->pluck('emp_no'))
            ->where('dept_no', '<>', $deptNo)
            ->orderBy('from_date')
            ->get(['emp_no', 'dept_no', 'from_date']);

        return $rows->map(fn ($row) => [
            'emp_no' => (int) $row->emp_no,
            'first_name' => $people[$row->emp_no]->first_name,
            'last_name' => $people[$row->emp_no]->last_name,
            'date' => $row->to_date,
            'moved_to' => $later
                ->where('emp_no', $row->emp_no)
                ->where('from_date', '>=', $row->to_date)
                ->first()?->dept_no,
        ])->all();
    }

    /**
     * Names and hire dates of the employees of a few rows, by emp_no.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function people(Collection $rows): Collection
    {
        return DB::table('employees')
            ->whereIn('emp_no', $rows->pluck('emp_no'))
            ->get(['emp_no', 'first_name', 'last_name', 'hire_date'])
            ->keyBy('emp_no');
    }

    /**
     * Current salaries (salaries as s) of the current employees (dept_emp as de),
     * optionally of one department. Select with {@see self::SALARY_PLAN}.
     */
    private function currentSalaries(?string $deptNo = null): Builder
    {
        return HistoryRows::active(DB::table('salaries as s'), 's')
            ->join('dept_emp as de', fn (JoinClause $j) => HistoryRows::active($j->on('de.emp_no', '=', 's.emp_no'), 'de')->where('de.to_date', '=', self::CURRENT))
            ->where('s.to_date', self::CURRENT)
            ->when($deptNo !== null, fn (Builder $q) => $q->where('de.dept_no', $deptNo));
    }

    /**
     * Current assignments (dept_emp as de), optionally of one department.
     */
    private function currentMembers(?string $deptNo = null): Builder
    {
        return HistoryRows::active(DB::table('dept_emp as de'), 'de')
            ->where('de.to_date', self::CURRENT)
            ->when($deptNo !== null, fn (Builder $q) => $q->where('de.dept_no', $deptNo));
    }
}
