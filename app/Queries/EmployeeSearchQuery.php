<?php

namespace App\Queries;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Filtering, sorting and paging of the employee directory, shared by the
 * Livewire list and the bulk export.
 *
 * The work is split into cheap steps instead of one big join over all
 * history tables:
 *  - every filter is a semi-join (IN / EXISTS sub-query) on `employees`, so
 *    counting never joins dept_emp/titles/salaries for every employee;
 *  - only the ordered emp_no values of the requested page are selected;
 *  - display columns (current/last department, title, salary) are loaded for
 *    those few employees afterwards ({@see rowsFor()}).
 *
 * Sorting by department, title or salary would need a per-employee lookup
 * for 300k rows. Instead the result is read as consecutive "segments" that
 * can each be read in index order: e.g. for the department sort, employees
 * without any department first, then one segment per department (by name),
 * each ordered by emp_no.
 *
 * Semantics (identical to the original single-query implementation):
 *  - "current" rows have to_date = 9999-01-01;
 *  - employees without a current department row are former employees;
 *  - former employees are shown, filtered and sorted by their *last*
 *    department / title: the row with the latest to_date (ties: lowest
 *    dept_no / title, then earliest from_date);
 *  - ties of the sort column are broken by emp_no (same direction).
 */
class EmployeeSearchQuery
{
    public const CURRENT = '9999-01-01';

    /** @var int[]|null */
    private ?array $onlyIds = null;

    /** @var int[] */
    private array $exceptIds = [];

    /**
     * When reading a small slice of a large result, filter sub-queries are run
     * per employee (stopping as soon as the slice is full) instead of being
     * materialized for all 300k employees first. See {@see ids()}.
     */
    private bool $perRowSubqueries = false;

    /** Rows MySQL may scan per slice before materializing the sub-queries pays off. */
    private const PER_ROW_SCAN_BUDGET = 20000;

    /** Up to this many matches, one query ordered by a per-employee sort key is cheapest. */
    private const SMALL_RESULT = 20000;

    public function __construct(private readonly EmployeeFilters $filters)
    {
    }

    /**
     * @param  EmployeeFilters|array<string, mixed>  $filters
     */
    public static function make(EmployeeFilters|array $filters): self
    {
        return new self($filters instanceof EmployeeFilters ? $filters : EmployeeFilters::fromArray($filters));
    }

    public function filters(): EmployeeFilters
    {
        return $this->filters;
    }

    /**
     * Restrict the result to the given employee numbers.
     *
     * @param  iterable<int|string>  $ids
     */
    public function onlyIds(iterable $ids): self
    {
        $clone = clone $this;
        $clone->onlyIds = self::intIds($ids);

        return $clone;
    }

    /**
     * Exclude the given employee numbers from the result.
     *
     * @param  iterable<int|string>  $ids
     */
    public function exceptIds(iterable $ids): self
    {
        $clone = clone $this;
        $clone->exceptIds = self::intIds($ids);

        return $clone;
    }

    public function count(): int
    {
        $f = $this->filters;
        $onlyStatus = $f->employmentStatus !== 'all' && ! $f->hasSearch() && $f->gender === 'all'
            && ! $f->filtersByDepartment() && ! $f->filtersByTitle() && ! $f->filtersBySalary()
            && $this->onlyIds === null && $this->exceptIds === [];

        if ($onlyStatus) {
            // Index-only count instead of a (anti-)join over all employees;
            // dept_emp.emp_no always references an existing employee (foreign key).
            $current = DB::table('dept_emp')->where('to_date', self::CURRENT)->distinct()->count('emp_no');

            return $f->employmentStatus === 'current' ? $current : DB::table('employees')->count() - $current;
        }

        $onlyDepartment = $f->filtersByDepartment() && ! $f->hasSearch() && $f->gender === 'all'
            && ! $f->filtersByTitle() && ! $f->filtersBySalary()
            && $this->onlyIds === null && $this->exceptIds === [];

        if ($onlyDepartment) {
            return $this->departmentCount($f->department, $f->employmentStatus);
        }

        return $this->filtered()->count();
    }

    /**
     * Members of $deptNo counted on dept_emp alone (the department filter alone,
     * e.g. a manager's unfiltered list). Exact: a current member has exactly one
     * row in the department (primary key emp_no + dept_no), a former member exactly
     * one "last" row, and the two groups are disjoint. The "is there a later row"
     * check is a primary-key lookup per row, which beats the semi-join plan here.
     */
    private function departmentCount(string $deptNo, string $status): int
    {
        $current = $status === 'former' ? 0 : DB::table('dept_emp')
            ->where('dept_no', $deptNo)
            ->where('to_date', self::CURRENT)
            ->count();

        $former = $status === 'current' ? 0 : self::departmentMembership(
            DB::table('dept_emp as de_f'), $deptNo, 'former', perRowLastCheck: true
        )->count();

        return $current + $former;
    }

    /**
     * Ordered emp_no values of the matching employees (all of them, or one slice).
     * $total (the known count of matches) lets small slices use cheaper per-row sub-queries.
     *
     * @return int[]
     */
    public function ids(?int $limit = null, int $offset = 0, ?int $total = null): array
    {
        if ($limit !== null && $limit <= 0) {
            return [];
        }

        $upperBound = $total ?? ($this->onlyIds !== null ? count($this->onlyIds) : null);

        if ($upperBound !== null && $upperBound <= self::SMALL_RESULT) {
            $query = $this->sortedByKey();

            return self::pluckIds($limit === null ? $query : $query->limit($limit)->offset($offset));
        }

        // Expected number of employees MySQL scans (in emp_no order) to fill the slice.
        $this->perRowSubqueries = $limit !== null && $total !== null && $total > 0
            && ($offset + $limit) * DB::table('employees')->count() / $total <= self::PER_ROW_SCAN_BUDGET;

        try {
            return $this->readSegments($this->segments(), $limit, $offset);
        } finally {
            $this->perRowSubqueries = false;
        }
    }

    /**
     * All matches in one query, ordered by a sort key computed per employee
     * (cheap for small results, see SMALL_RESULT).
     */
    private function sortedByKey(): Builder
    {
        $f = $this->filters;
        $dir = $f->sortDirection;
        $query = $this->filtered()->select('e.emp_no');

        match ($f->sortBy) {
            'name' => $query->orderBy('e.first_name', $dir)->orderBy('e.last_name', $dir),
            'hire_date', 'gender' => $query->orderBy('e.'.$f->sortBy, $dir),
            'salary' => $query->orderByRaw("(SELECT s_k.salary FROM salaries s_k
                WHERE s_k.emp_no = e.emp_no AND s_k.to_date = '".self::CURRENT."' LIMIT 1) ".$dir),
            'department' => $query->orderByRaw('(SELECT d.dept_name FROM dept_emp de_k
                JOIN departments d ON d.dept_no = de_k.dept_no
                WHERE de_k.emp_no = e.emp_no
                ORDER BY de_k.to_date DESC, de_k.dept_no ASC LIMIT 1) '.$dir),
            'title' => $query->orderByRaw('(SELECT t_k.title FROM titles t_k
                WHERE t_k.emp_no = e.emp_no AND t_k.to_date IS NOT NULL
                ORDER BY t_k.to_date DESC, t_k.title ASC, t_k.from_date ASC LIMIT 1) '.$dir),
            default => null,
        };

        return $query->orderBy('e.emp_no', $dir);
    }

    /**
     * @param  array<int, Closure(): Builder>  $segments
     * @return int[]
     */
    private function readSegments(array $segments, ?int $limit, int $offset): array
    {
        $ids = [];
        $last = count($segments) - 1;

        foreach ($segments as $i => $segment) {
            $query = $segment();

            if ($limit === null) {
                array_push($ids, ...self::pluckIds($query));

                continue;
            }

            $needed = $limit - count($ids);

            if ($i === $last) {
                array_push($ids, ...self::pluckIds($query->limit($needed)->offset($offset)));
                break;
            }

            // Read up to the end of the requested slice; a shorter result means the
            // slice starts (or continues) in the next segment.
            $chunk = self::pluckIds($query->limit($offset + $needed));

            if (count($chunk) > $offset) {
                array_push($ids, ...array_slice($chunk, $offset));
                $offset = 0;
            } else {
                $offset -= count($chunk);
            }

            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    /**
     * One page of display rows. Pass $total when it is already known to skip the count query.
     */
    public function paginate(int $perPage, int $page, ?int $total = null, string $pageName = 'page'): LengthAwarePaginator
    {
        $total ??= $this->count();
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $rows = $total > $offset
            ? $this->rowsFor($this->ids($perPage, $offset, $total))
            : collect();

        return new LengthAwarePaginator($rows, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Walk through all matching display rows, in sort order, chunk by chunk.
     *
     * @param  callable(Collection): mixed  $callback
     */
    public function chunk(int $size, callable $callback, ?int $limit = null): void
    {
        foreach (array_chunk($this->ids($limit), $size) as $ids) {
            $callback($this->rowsFor($ids));
        }
    }

    /**
     * Display rows for the given employees, returned in the order of $ids.
     *
     * @param  int[]  $ids
     */
    public function rowsFor(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $rows = DB::table('employees as e')
            ->leftJoin('dept_emp as de_current', function ($join) {
                $join->on('e.emp_no', '=', 'de_current.emp_no')->where('de_current.to_date', '=', self::CURRENT);
            })
            ->leftJoin('departments as d_current', 'de_current.dept_no', '=', 'd_current.dept_no')
            ->leftJoin('titles as t_current', function ($join) {
                $join->on('e.emp_no', '=', 't_current.emp_no')->where('t_current.to_date', '=', self::CURRENT);
            })
            ->leftJoin('salaries as s_current', function ($join) {
                $join->on('e.emp_no', '=', 's_current.emp_no')->where('s_current.to_date', '=', self::CURRENT);
            })
            ->whereIntegerInRaw('e.emp_no', $ids)
            ->select([
                'e.emp_no',
                'e.first_name',
                'e.last_name',
                'e.hire_date',
                'e.gender',
                'd_current.dept_name as current_dept_name',
                't_current.title as current_title',
                's_current.salary as current_salary',
                DB::raw("(SELECT d.dept_name FROM dept_emp de_last
                    JOIN departments d ON d.dept_no = de_last.dept_no
                    WHERE de_last.emp_no = e.emp_no AND de_last.to_date <> '".self::CURRENT."'
                    ORDER BY de_last.to_date DESC, de_last.dept_no ASC LIMIT 1) as last_dept_name"),
                DB::raw("(SELECT t_last.title FROM titles t_last
                    WHERE t_last.emp_no = e.emp_no AND t_last.to_date <> '".self::CURRENT."'
                    ORDER BY t_last.to_date DESC, t_last.title ASC, t_last.from_date ASC LIMIT 1) as last_job_title"),
            ])
            ->get()
            ->keyBy('emp_no');

        return collect($ids)->map(fn ($id) => $rows->get($id))->filter()->values();
    }

    /**
     * `employees as e` with every filter applied as a semi-join.
     */
    public function filtered(bool $withSalaryFilter = true): Builder
    {
        $f = $this->filters;
        $query = DB::table('employees as e');

        if ($this->onlyIds !== null) {
            $query->whereIntegerInRaw('e.emp_no', $this->onlyIds);
        }

        if ($this->exceptIds !== []) {
            $query->whereIntegerNotInRaw('e.emp_no', $this->exceptIds);
        }

        if ($f->hasSearch()) {
            $term = '%'.$f->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('e.first_name', 'like', $term)
                    ->orWhere('e.last_name', 'like', $term)
                    ->orWhereRaw("CONCAT(e.first_name, ' ', e.last_name) like ?", [$term]);
            });
        }

        if ($f->gender !== 'all') {
            $query->where('e.gender', $f->gender);
        }

        if ($f->filtersByDepartment()) {
            // The department condition already implies the employment status.
            $this->whereEffectiveDepartment($query, $f->department, $f->employmentStatus, $this->perRowSubqueries);
        } elseif ($f->employmentStatus === 'current') {
            $query->whereIn('e.emp_no', fn ($q) => $this->subSelect($q, 'de_st.emp_no')
                ->from('dept_emp as de_st')
                ->where('de_st.to_date', self::CURRENT));
        } elseif ($f->employmentStatus === 'former') {
            $query->whereNotExists(fn ($q) => $this->subSelect($q)
                ->from('dept_emp as de_st')
                ->whereColumn('de_st.emp_no', 'e.emp_no')
                ->where('de_st.to_date', self::CURRENT));
        }

        if ($f->filtersByTitle()) {
            $this->whereEffectiveTitle($query, $f->jobTitle, $this->perRowSubqueries);
        }

        if ($withSalaryFilter && $f->filtersBySalary()) {
            $query->whereIn('e.emp_no', fn ($q) => $this->whereSalaryBetween($this->subSelect($q, 's_f.emp_no')
                ->from('salaries as s_f')
                ->where('s_f.to_date', self::CURRENT), 's_f.salary'));
        }

        return $query;
    }

    /**
     * Ordered "segments" whose concatenation is the sorted result.
     *
     * @return array<int, Closure(): Builder>
     */
    private function segments(): array
    {
        $f = $this->filters;
        $dir = $f->sortDirection;
        $byEmpNo = fn () => $this->filtered()->select('e.emp_no')->orderBy('e.emp_no', $dir);

        switch ($f->sortBy) {
            case 'name':
                return [fn () => $this->filtered()->select('e.emp_no')
                    ->orderBy('e.first_name', $dir)->orderBy('e.last_name', $dir)->orderBy('e.emp_no', $dir)];

            case 'hire_date':
            case 'gender':
                return [fn () => $this->filtered()->select('e.emp_no')
                    ->orderBy('e.'.$f->sortBy, $dir)->orderBy('e.emp_no', $dir)];

            case 'salary':
                // Read in the order of the salaries(to_date, salary) index; the salary
                // range filter (if any) is applied to the same join.
                $withSalary = fn () => $this->whereSalaryBetween($this->filtered(withSalaryFilter: false)
                    ->join('salaries as s_sort', function ($join) {
                        $join->on('s_sort.emp_no', '=', 'e.emp_no')->where('s_sort.to_date', '=', self::CURRENT);
                    }), 's_sort.salary')
                    ->select('e.emp_no')
                    ->orderBy('s_sort.salary', $dir)
                    ->orderBy('s_sort.emp_no', $dir);

                if ($f->filtersBySalary()) {
                    return [$withSalary];
                }

                $withoutSalary = fn () => $byEmpNo()->whereNotExists(fn ($q) => $this->subSelect($q)
                    ->from('salaries as s_none')
                    ->whereColumn('s_none.emp_no', 'e.emp_no')
                    ->where('s_none.to_date', self::CURRENT));

                // NULL (no current salary) sorts first ascending, last descending.
                return $dir === 'asc' ? [$withoutSalary, $withSalary] : [$withSalary, $withoutSalary];

            case 'department':
                if ($f->filtersByDepartment()) {
                    return [$byEmpNo];
                }

                $segments = array_map(
                    fn ($deptNo) => fn () => $this->whereEffectiveDepartment($byEmpNo(), $deptNo, $f->employmentStatus, false),
                    DB::table('departments')->orderBy('dept_name', $dir)->pluck('dept_no')->all()
                );

                return $this->withNullSegment($segments, $dir, 'dept_emp', fn () => $byEmpNo()
                    ->whereNotExists(fn ($q) => $this->subSelect($q)
                        ->from('dept_emp as de_none')
                        ->whereColumn('de_none.emp_no', 'e.emp_no')));

            case 'title':
                if ($f->filtersByTitle()) {
                    return [$byEmpNo];
                }

                $segments = array_map(
                    fn ($title) => fn () => $this->whereEffectiveTitle($byEmpNo(), $title, false),
                    DB::table('titles')->distinct()->orderBy('title', $dir)->pluck('title')->all()
                );

                return $this->withNullSegment($segments, $dir, 'titles', fn () => $byEmpNo()
                    ->whereNotExists(fn ($q) => $this->subSelect($q)
                        ->from('titles as t_none')
                        ->whereColumn('t_none.emp_no', 'e.emp_no')
                        ->whereNotNull('t_none.to_date')));

            default:
                return [$byEmpNo];
        }
    }

    /**
     * Add the segment of employees without any department/title row (sort value NULL):
     * first when ascending, last when descending. Skipped when every employee has one.
     *
     * @param  array<int, Closure(): Builder>  $segments
     * @return array<int, Closure(): Builder>
     */
    private function withNullSegment(array $segments, string $dir, string $historyTable, Closure $nullSegment): array
    {
        $everyoneHasHistory = DB::table('employees')->count()
            === DB::table($historyTable)->distinct()->count('emp_no')
            && ! DB::table($historyTable)->whereNull('to_date')->exists();

        if ($everyoneHasHistory) {
            return $segments;
        }

        return $dir === 'asc' ? [$nullSegment, ...$segments] : [...$segments, $nullSegment];
    }

    /**
     * Current department is $deptNo, or - for employees without a current
     * department - the last one is. Optionally restricted to current / former.
     */
    private function whereEffectiveDepartment(Builder $query, string $deptNo, string $status, bool $perRow): Builder
    {
        return $query->whereIn('e.emp_no', fn ($q) => self::departmentMembership(
            $this->subSelect($q, 'de_f.emp_no', $perRow)->from('dept_emp as de_f'),
            $deptNo,
            $status
        ));
    }

    /**
     * Restrict a query on `dept_emp as de_f` (the alias is required) to the rows
     * that make an employee belong to $deptNo: the current assignment, or - for
     * employees without a current department - the last one (latest to_date,
     * ties: lowest dept_no). Optionally only current / only former employees.
     * Selecting `de_f.emp_no` gives each member exactly once.
     *
     * This is the definition of "employee of a department" used by the
     * department filter and by the manager scope ({@see \App\Support\EmployeeVisibility}).
     */
    public static function departmentMembership(Builder $deptEmp, string $deptNo, string $status = 'all', bool $perRowLastCheck = false): Builder
    {
        $deptEmp->where('de_f.dept_no', $deptNo);

        $isLast = function ($w) use ($perRowLastCheck) {
            $w->where('de_f.to_date', '<>', self::CURRENT)
                ->whereNotExists(fn ($later) => $later->select(DB::raw($perRowLastCheck ? '/*+ NO_SEMIJOIN() */ 1' : '1'))
                    ->from('dept_emp as de_l')
                    ->whereColumn('de_l.emp_no', 'de_f.emp_no')
                    ->where(fn ($o) => $o->whereColumn('de_l.to_date', '>', 'de_f.to_date')
                        ->orWhere(fn ($t) => $t->whereColumn('de_l.to_date', '=', 'de_f.to_date')
                            ->whereColumn('de_l.dept_no', '<', 'de_f.dept_no'))));
        };

        return match ($status) {
            'current' => $deptEmp->where('de_f.to_date', self::CURRENT),
            'former' => $deptEmp->where($isLast),
            default => $deptEmp->where(fn ($w) => $w->where('de_f.to_date', self::CURRENT)->orWhere($isLast)),
        };
    }

    /**
     * Current title is $title, or - for employees without a current title - the last one is.
     */
    private function whereEffectiveTitle(Builder $query, string $title, bool $perRow): Builder
    {
        return $query->whereIn('e.emp_no', fn ($q) => $this->subSelect($q, 't_f.emp_no', $perRow)
            ->from('titles as t_f')
            ->where('t_f.title', $title)
            ->where(fn ($w) => $w->where('t_f.to_date', self::CURRENT)
                ->orWhere(fn ($last) => $last->where('t_f.to_date', '<>', self::CURRENT)
                    ->whereNotExists(fn ($later) => $later->select(DB::raw(1))
                        ->from('titles as t_l')
                        ->whereColumn('t_l.emp_no', 't_f.emp_no')
                        ->where(fn ($o) => $o->whereColumn('t_l.to_date', '>', 't_f.to_date')
                            ->orWhere(fn ($t) => $t->whereColumn('t_l.to_date', '=', 't_f.to_date')
                                ->where(fn ($u) => $u->whereColumn('t_l.title', '<', 't_f.title')
                                    ->orWhere(fn ($v) => $v->whereColumn('t_l.title', '=', 't_f.title')
                                        ->whereColumn('t_l.from_date', '<', 't_f.from_date')))))))));
    }

    private function whereSalaryBetween(Builder $query, string $column): Builder
    {
        $f = $this->filters;

        return $query
            ->when($f->salaryMin !== null, fn ($w) => $w->where($column, '>=', $f->salaryMin))
            ->when($f->salaryMax !== null, fn ($w) => $w->where($column, '<=', $f->salaryMax));
    }

    /**
     * Select list of a filter sub-query on `e`, optionally forcing MySQL to
     * evaluate it per employee row (NO_SEMIJOIN) - see $perRowSubqueries.
     */
    private function subSelect(Builder $query, ?string $column = null, ?bool $perRow = null): Builder
    {
        $hint = ($perRow ?? $this->perRowSubqueries) ? '/*+ NO_SEMIJOIN() */ ' : '';

        return $query->selectRaw($hint.($column === null ? '1' : $query->getGrammar()->wrap($column)));
    }

    /**
     * @return int[]
     */
    private static function pluckIds(Builder $query): array
    {
        return array_map('intval', $query->pluck('e.emp_no')->all());
    }

    /**
     * @param  iterable<int|string>  $ids
     * @return int[]
     */
    private static function intIds(iterable $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $result[] = (int) $id;
            }
        }

        return array_values(array_unique($result));
    }
}
