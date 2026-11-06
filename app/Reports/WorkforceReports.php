<?php

namespace App\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Workforce reports: headcount over time, hires vs leavers (turnover), tenure,
 * gender and age distribution. No salary data.
 *
 * Everything is derived from three cached aggregates, each a single GROUP BY
 * over the history tables (no per-employee work in PHP):
 *
 *  - movements: dept_emp rows grouped by (department, start year, end year) -
 *    headcount on 31 Dec of a year = rows started - rows ended up to that day, so
 *    the whole history comes from one scan - plus the rows of employees with several
 *    departments (transfers; a row that ends without one is a leaver) and hires per
 *    year.
 *  - snapshot: employees with an open dept_emp row on the as-of date, grouped by
 *    department, gender and 5-year age band (count + summed tenure days).
 *  - title_gender: open titles rows on the as-of date by title and gender.
 */
class WorkforceReports
{
    public const KEYS = ['headcount', 'hires-leavers', 'tenure', 'gender', 'age'];

    /** Counts of one department in one year: department rows started and ended, transfers among them. */
    private const NO_EVENTS = ['starts' => 0, 'ends' => 0, 'in' => 0, 'out' => 0];

    /** Sort key of a dept_emp row within an employee's rows (from_date can repeat). */
    private const ROW_KEY = 'CONCAT(de.from_date, de.to_date, de.dept_no)';

    public function __construct(
        private readonly ReportCache $cache,
        private readonly ReportLabels $labels,
    ) {}

    public function table(string $key, ReportContext $context): ReportTable
    {
        return match ($key) {
            'headcount' => $this->headcount($context),
            'hires-leavers' => $this->hiresAndLeavers($context),
            'tenure' => $this->tenure($context),
            'gender' => $this->gender($context),
            'age' => $this->age($context),
        };
    }

    /**
     * Figures for the summary tiles.
     *
     * @return array{headcount: int, tenure: ?float, femaleShare: ?float, turnover: ?float, turnoverYear: ?int}
     */
    public function summary(ReportContext $context): array
    {
        $people = $this->people($context);
        $headcount = array_sum(array_column($people, 'n'));
        $female = array_sum(array_column(array_filter($people, fn ($r) => $r['gender'] === 'F'), 'n'));

        $turnover = null;
        $turnoverYear = null;
        foreach (array_reverse($this->yearly($context)) as $year) {
            // The last complete year (31 Dec on or before the as-of date).
            if ($year['date'] === $year['year'].'-12-31') {
                $turnover = $year['turnover'];
                $turnoverYear = $year['year'];
                break;
            }
        }

        return [
            'headcount' => $headcount,
            'tenure' => $headcount > 0 ? array_sum(array_column($people, 'tenure_days')) / $headcount / 365.25 : null,
            'femaleShare' => $headcount > 0 ? $female / $headcount * 100 : null,
            'turnover' => $turnover,
            'turnoverYear' => $turnoverYear,
        ];
    }

    /** Recompute (or load) every cached aggregate of $context - used by reports:warm. */
    public function warm(ReportContext $context): void
    {
        $this->movements($context);
        $this->snapshot($context);
        $this->titleGender($context);
    }

    public function headcount(ReportContext $context): ReportTable
    {
        $years = $this->yearly($context);
        $departments = $this->labels->departments();
        $byDepartment = $this->movements($context)['events'];
        $shown = $context->department !== null ? [$context->department] : array_keys($byDepartment);
        sort($shown);

        $columns = ['year' => [__('Year'), 'text'], 'date' => [__('Date'), 'date']];
        foreach ($shown as $deptNo) {
            $columns['d_'.$deptNo] = [$departments[$deptNo] ?? $deptNo, 'int'];
        }
        if (count($shown) > 1 || $shown === []) {
            $columns['total'] = [__('Total'), 'int'];
        }

        $rows = [];
        $running = array_fill_keys($shown, 0);
        foreach ($years as $year) {
            $row = ['year' => (string) $year['year'], 'date' => $year['date']];
            foreach ($shown as $deptNo) {
                $e = $byDepartment[$deptNo][$year['year']] ?? null;
                $running[$deptNo] += $e === null ? 0 : $e['starts'] - $e['ends'];
                $row['d_'.$deptNo] = $running[$deptNo];
            }
            $row['total'] = $year['end'];
            $rows[] = $row;
        }

        $labels = array_column($rows, 'year');
        $charts = [];
        if ($rows !== []) {
            $charts[] = count($shown) > 1
                ? [
                    'id' => 'headcount-chart', 'type' => 'bar', 'stacked' => true, 'format' => 'int',
                    'labels' => $labels,
                    'datasets' => ChartSeries::fold(
                        array_combine($shown, array_map(fn ($d) => [
                            'label' => $departments[$d] ?? $d,
                            'data' => array_column($rows, 'd_'.$d),
                        ], $shown)),
                        __('Other departments')
                    ),
                ]
                : [
                    'id' => 'headcount-chart', 'type' => 'line', 'format' => 'int', 'labels' => $labels,
                    'datasets' => [ChartSeries::one(__('Headcount'), array_column($rows, 'total'))],
                ];
        }

        return new ReportTable(
            'headcount',
            __('Headcount over time'),
            $columns,
            $rows,
            charts: $charts,
            description: __('Employees on 31 December of each year (the last year: on the as-of date), per department.'),
        );
    }

    public function hiresAndLeavers(ReportContext $context): ReportTable
    {
        $rows = array_map(fn (array $y) => [
            'year' => (string) $y['year'],
            'date' => $y['date'],
            'hires' => $y['hires'],
            'leavers' => $y['leavers'],
            'in' => $y['in'],
            'out' => $y['out'],
            'net' => $y['end'] - $y['start'],
            'start' => $y['start'],
            'end' => $y['end'],
            'turnover' => $y['turnover'],
        ], $this->yearly($context));

        $labels = array_column($rows, 'year');

        return new ReportTable(
            'hires-leavers',
            __('Hires, leavers and turnover'),
            [
                'year' => [__('Year'), 'text'],
                'date' => [__('Until'), 'date'],
                'hires' => [__('Hires'), 'int'],
                'leavers' => [__('Leavers'), 'int'],
                'in' => [__('Transfers in'), 'int'],
                'out' => [__('Transfers out'), 'int'],
                'net' => [__('Net change'), 'int'],
                'start' => [__('Headcount at start'), 'int'],
                'end' => [__('Headcount at end'), 'int'],
                'turnover' => [__('Turnover rate'), 'percent'],
            ],
            $rows,
            charts: $rows === [] ? [] : [
                [
                    'id' => 'hires-leavers-chart', 'type' => 'bar', 'format' => 'int', 'labels' => $labels,
                    'title' => __('Hires vs leavers'),
                    'datasets' => [
                        ChartSeries::one(__('Hires'), array_column($rows, 'hires'), 0),
                        ChartSeries::one(__('Leavers'), array_column($rows, 'leavers'), 1),
                    ],
                ],
                [
                    'id' => 'turnover-chart', 'type' => 'line', 'format' => 'percent', 'labels' => $labels,
                    'title' => __('Turnover rate'),
                    'datasets' => [ChartSeries::one(__('Turnover rate'), array_column($rows, 'turnover'), 7)],
                ],
            ],
            description: __('Hires are counted on the hire date, leavers when their last department row ends; transfers move between departments. Headcount counts employees assigned to a department, so it can follow a hire with a delay. Turnover rate = leavers / average of the headcount at the start and at the end of the year.'),
        );
    }

    public function tenure(ReportContext $context): ReportTable
    {
        $departments = $this->labels->departments();
        $byDepartment = [];
        foreach ($this->snapshot($context) as $r) {
            $byDepartment[$r['dept_no']]['n'] = ($byDepartment[$r['dept_no']]['n'] ?? 0) + $r['n'];
            $byDepartment[$r['dept_no']]['days'] = ($byDepartment[$r['dept_no']]['days'] ?? 0) + $r['tenure_days'];
        }
        ksort($byDepartment);

        $rows = [];
        foreach ($byDepartment as $deptNo => $d) {
            $rows[] = [
                'department' => $departments[$deptNo] ?? $deptNo,
                'employees' => $d['n'],
                'tenure' => $d['days'] / $d['n'] / 365.25,
            ];
        }

        $people = $this->people($context);
        $n = array_sum(array_column($people, 'n'));
        $totals = count($rows) > 1 ? [[
            'department' => __('All departments'),
            'employees' => $n,
            'tenure' => array_sum(array_column($people, 'tenure_days')) / $n / 365.25,
        ]] : [];

        return new ReportTable(
            'tenure',
            __('Average tenure'),
            [
                'department' => [__('Department'), 'text'],
                'employees' => [__('Employees'), 'int'],
                'tenure' => [__('Average tenure (years)'), 'years'],
            ],
            $rows,
            $totals,
            charts: count($rows) > 1 ? [[
                'id' => 'tenure-chart', 'type' => 'bar', 'horizontal' => true, 'format' => 'years',
                'labels' => array_column($rows, 'department'),
                'datasets' => [ChartSeries::one(__('Average tenure (years)'), array_map(fn ($v) => round($v, 2), array_column($rows, 'tenure')))],
            ]] : [],
            description: __('Years since the hire date of the employees employed on the as-of date.'),
        );
    }

    public function gender(ReportContext $context): ReportTable
    {
        $departments = $this->labels->departments();
        $byDepartment = [];
        foreach ($this->snapshot($context) as $r) {
            $byDepartment[$r['dept_no']][$r['gender']] = ($byDepartment[$r['dept_no']][$r['gender']] ?? 0) + $r['n'];
        }
        ksort($byDepartment);

        $byTitle = [];
        foreach ($this->titleGender($context) as $r) {
            $byTitle[$r['title']][$r['gender']] = $r['n'];
        }
        ksort($byTitle);

        $row = fn (string $breakdown, string $group, array $counts) => [
            'breakdown' => $breakdown,
            'group' => $group,
            'female' => $counts['F'] ?? 0,
            'male' => $counts['M'] ?? 0,
            'total' => ($counts['F'] ?? 0) + ($counts['M'] ?? 0),
            'female_share' => ($counts['F'] ?? 0) + ($counts['M'] ?? 0) > 0
                ? ($counts['F'] ?? 0) / (($counts['F'] ?? 0) + ($counts['M'] ?? 0)) * 100
                : null,
        ];

        $overall = ['F' => 0, 'M' => 0];
        foreach ($this->people($context) as $r) {
            $overall[$r['gender']] += $r['n'];
        }

        $deptRows = [];
        foreach ($byDepartment as $deptNo => $counts) {
            $deptRows[] = $row(__('Department'), $departments[$deptNo] ?? $deptNo, $counts);
        }
        $titleRows = [];
        foreach ($byTitle as $title => $counts) {
            $titleRows[] = $row(__('Title'), $title, $counts);
        }

        $rows = $deptRows === [] && $titleRows === [] ? [] : [$row(__('Overall'), __('All employees'), $overall), ...$deptRows, ...$titleRows];

        $stack = fn (string $id, string $title, array $groupRows) => [
            'id' => $id, 'type' => 'bar', 'stacked' => true, 'horizontal' => true, 'format' => 'int', 'title' => $title,
            'labels' => array_column($groupRows, 'group'),
            'datasets' => [
                ChartSeries::one(__('Female'), array_column($groupRows, 'female'), 1),
                ChartSeries::one(__('Male'), array_column($groupRows, 'male'), 0),
            ],
        ];

        $charts = [];
        if ($rows !== []) {
            $charts[] = [
                'id' => 'gender-chart', 'type' => 'doughnut', 'format' => 'int', 'title' => __('All employees'),
                'labels' => [__('Female'), __('Male')],
                'datasets' => [['label' => __('Employees'), 'data' => [$overall['F'], $overall['M']], 'slots' => [1, 0]]],
            ];
            if (count($deptRows) > 1) {
                $charts[] = $stack('gender-departments-chart', __('By department'), $deptRows);
            }
            if ($titleRows !== []) {
                $charts[] = $stack('gender-titles-chart', __('By title'), $titleRows);
            }
        }

        return new ReportTable(
            'gender',
            __('Gender distribution'),
            [
                'breakdown' => [__('Breakdown'), 'text'],
                'group' => [__('Group'), 'text'],
                'female' => [__('Female'), 'int'],
                'male' => [__('Male'), 'int'],
                'total' => [__('Total'), 'int'],
                'female_share' => [__('Female share'), 'percent'],
            ],
            $rows,
            charts: $charts,
            description: __('Employees on the as-of date by gender - overall, per department and per current title.'),
        );
    }

    public function age(ReportContext $context): ReportTable
    {
        $bands = [];
        foreach ($this->people($context) as $r) {
            $bands[$r['age_band']][$r['gender']] = ($bands[$r['age_band']][$r['gender']] ?? 0) + $r['n'];
        }

        $rows = [];
        if ($bands !== []) {
            for ($band = min(array_keys($bands)); $band <= max(array_keys($bands)); $band += 5) {
                $f = $bands[$band]['F'] ?? 0;
                $m = $bands[$band]['M'] ?? 0;
                $rows[] = ['band' => $band.'–'.($band + 4), 'female' => $f, 'male' => $m, 'total' => $f + $m];
            }
        }

        return new ReportTable(
            'age',
            __('Age distribution'),
            [
                'band' => [__('Age'), 'text'],
                'female' => [__('Female'), 'int'],
                'male' => [__('Male'), 'int'],
                'total' => [__('Employees'), 'int'],
            ],
            $rows,
            charts: $rows === [] ? [] : [[
                'id' => 'age-chart', 'type' => 'bar', 'stacked' => true, 'format' => 'int',
                'labels' => array_column($rows, 'band'),
                'datasets' => [
                    ChartSeries::one(__('Female'), array_column($rows, 'female'), 1),
                    ChartSeries::one(__('Male'), array_column($rows, 'male'), 0),
                ],
            ]],
            description: __('Age (full years) on the as-of date of the employees employed on that day, in 5-year bands.'),
        );
    }

    /**
     * The company (or department) per year: hires, leavers, transfers, headcount
     * at the start and the end (31 Dec, the last year: the as-of date) and turnover.
     *
     * @return list<array{year: int, date: string, hires: int, leavers: int, in: int, out: int, start: int, end: int, turnover: ?float}>
     */
    private function yearly(ReportContext $context): array
    {
        ['events' => $byDepartment, 'hires' => $hires, 'last_year' => $lastYear] = $this->movements($context);

        $events = [];
        foreach ($byDepartment as $years) {
            foreach ($years as $year => $e) {
                foreach ($e as $kind => $n) {
                    $events[$year][$kind] = ($events[$year][$kind] ?? 0) + $n;
                }
            }
        }

        if ($events === [] && $hires === []) {
            return [];
        }

        $years = [...array_keys($events), ...array_keys($hires)];
        $result = [];
        $headcount = 0;
        for ($year = min($years), $last = max([...$years, $lastYear ?? 0]); $year <= $last; $year++) {
            $e = $events[$year] ?? self::NO_EVENTS;
            $leavers = $e['ends'] - $e['out'];
            $start = $headcount;
            $headcount += $e['starts'] - $e['ends'];
            $average = ($start + $headcount) / 2;

            $result[] = [
                'year' => $year,
                'date' => $year === $context->asOf->year ? $context->date() : $year.'-12-31',
                'hires' => $hires[$year] ?? 0,
                'leavers' => $leavers,
                'in' => $e['in'],
                'out' => $e['out'],
                'start' => $start,
                'end' => $headcount,
                'turnover' => $average > 0 ? $leavers / $average * 100 : null,
            ];
        }

        return $result;
    }

    /**
     * History up to the as-of date: department rows started, ended and moved per
     * department and year, hires per year and the year of the company's last change.
     *
     * @return array{events: array<string, array<int, array{starts: int, ends: int, in: int, out: int}>>, hires: array<int, int>, last_year: ?int}
     */
    private function movements(ReportContext $context): array
    {
        if ($context->isEmpty()) {
            return ['events' => [], 'hires' => [], 'last_year' => null];
        }

        return $this->cache->remember('movements', $context, function () use ($context) {
            $date = $context->date();
            $result = [];
            $add = function (string $deptNo, int $year, string $kind, int $n) use (&$result) {
                $result[$deptNo][$year] ??= self::NO_EVENTS;
                $result[$deptNo][$year][$kind] += $n;
            };

            $rows = ReportSources::deptEmp('de')
                ->select('de.dept_no')
                ->selectRaw('YEAR(de.from_date) AS start_year')
                ->selectRaw('CASE WHEN de.to_date <= ? THEN YEAR(de.to_date) END AS end_year', [$date])
                ->selectRaw('COUNT(*) AS n')
                ->where('de.from_date', '<=', $date)
                ->when($context->department !== null, fn ($q) => $q->where('de.dept_no', $context->department))
                ->groupBy('de.dept_no', 'start_year', 'end_year');
            $context->constrain($rows, 'de.emp_no');

            foreach ($rows->get() as $g) {
                $add($g->dept_no, (int) $g->start_year, 'starts', (int) $g->n);
                if ($g->end_year !== null) {
                    $add($g->dept_no, (int) $g->end_year, 'ends', (int) $g->n);
                }
            }

            foreach ($this->transfers($context) as $g) {
                if ($g->in_year !== null) {
                    $add($g->dept_no, (int) $g->in_year, 'in', (int) $g->n);
                }
                if ($g->out_year !== null) {
                    $add($g->dept_no, (int) $g->out_year, 'out', (int) $g->n);
                }
            }

            return [
                'events' => $result,
                'hires' => $this->hires($context),
                // Histories of all scopes run up to the last change in the company (not only in the department).
                'last_year' => CompanyHistory::lastChangeYear($date),
            ];
        });
    }

    /**
     * Department rows that are not an employee's first (a transfer in) or last
     * (a transfer out). Only employees with several rows matter: about a tenth.
     *
     * @return Collection<int, object>
     */
    private function transfers(ReportContext $context): Collection
    {
        $date = $context->date();
        $spans = $this->firstAndLastRows($context)->havingRaw('COUNT(*) > 1');

        return ReportSources::deptEmp('de')
            ->joinSub($spans, 'f', 'f.emp_no', '=', 'de.emp_no')
            ->select('de.dept_no')
            ->selectRaw('CASE WHEN de.from_date <= ? AND '.self::ROW_KEY.' <> f.first_row THEN YEAR(de.from_date) END AS in_year', [$date])
            ->selectRaw('CASE WHEN de.to_date <= ? AND de.to_date <> f.last_to THEN YEAR(de.to_date) END AS out_year', [$date])
            ->selectRaw('COUNT(*) AS n')
            ->when($context->department !== null, fn ($q) => $q->where('de.dept_no', $context->department))
            ->groupBy('de.dept_no', 'in_year', 'out_year')
            ->get();
    }

    /**
     * Hires per year: on employees.hire_date, which in the dataset is often earlier
     * than the first department row. A department counts the employees who started in it.
     *
     * @return array<int, int>
     */
    private function hires(ReportContext $context): array
    {
        $date = $context->date();

        $query = $context->department === null
            ? DB::table('employees as e')
            : ReportSources::deptEmp('de')
                ->join('employees as e', 'e.emp_no', '=', 'de.emp_no')
                ->leftJoinSub($this->firstAndLastRows($context)->havingRaw('COUNT(*) > 1'), 'f', 'f.emp_no', '=', 'de.emp_no')
                ->where('de.dept_no', $context->department)
                ->where(fn ($q) => $q->whereNull('f.emp_no')->orWhereRaw(self::ROW_KEY.' = f.first_row'));

        return $context->constrain($query, 'e.emp_no')
            ->where('e.hire_date', '<=', $date)
            ->selectRaw('YEAR(e.hire_date) AS hire_year, COUNT(*) AS n')
            ->groupBy('hire_year')
            ->pluck('n', 'hire_year')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * First and last dept_emp row per employee of the scope (primary key order, no sorting).
     */
    private function firstAndLastRows(ReportContext $context): Builder
    {
        $spans = ReportSources::deptEmp('de')
            ->select('de.emp_no')
            ->selectRaw('MIN('.self::ROW_KEY.') AS first_row, MAX(de.to_date) AS last_to')
            ->when($context->department !== null, fn ($q) => $q->whereIn('de.emp_no', ReportSources::deptEmp('de_d')
                ->select('de_d.emp_no')->where('de_d.dept_no', $context->department)))
            ->groupBy('de.emp_no');

        return $context->constrain($spans, 'de.emp_no');
    }

    /**
     * The snapshot with every employee counted once, for figures over the whole scope.
     *
     * @return list<array{dept_no: string, gender: string, age_band: int, n: int, tenure_days: int, primary: bool}>
     */
    private function people(ReportContext $context): array
    {
        return array_values(array_filter($this->snapshot($context), fn (array $r) => $r['primary']));
    }

    /**
     * Employed on the as-of date by department, gender and age band. Someone with
     * several open department rows is in the groups of each department; `primary`
     * marks the rows that count each person once (lowest dept_no of the open rows,
     * as in EmployeeVisibility). A department filter sees a person only once anyway:
     * (emp_no, dept_no) is the primary key.
     *
     * @return list<array{dept_no: string, gender: string, age_band: int, n: int, tenure_days: int, primary: bool}>
     */
    private function snapshot(ReportContext $context): array
    {
        if ($context->isEmpty()) {
            return [];
        }

        return $this->cache->remember('snapshot', $context, function () use ($context) {
            $date = $context->date();

            $query = ReportSources::open(ReportSources::deptEmp('de'), 'de', $date)
                ->join('employees as e', 'e.emp_no', '=', 'de.emp_no')
                ->selectRaw('de.dept_no, e.gender, FLOOR(TIMESTAMPDIFF(YEAR, e.birth_date, ?) / 5) * 5 AS age_band', [$date])
                ->selectRaw('COUNT(*) AS n, SUM(DATEDIFF(?, e.hire_date)) AS tenure_days', [$date])
                ->when($context->department !== null, fn ($q) => $q->where('de.dept_no', $context->department))
                ->groupBy('de.dept_no', 'e.gender', 'age_band', 'is_primary');
            $context->constrain($query, 'de.emp_no');

            if ($context->department === null) {
                $query->leftJoinSub(
                    ReportSources::open(ReportSources::deptEmp('o'), 'o', $date)
                        ->select('o.emp_no')
                        ->selectRaw('MIN(o.dept_no) AS first_dept')
                        ->groupBy('o.emp_no')
                        ->havingRaw('COUNT(*) > 1'),
                    'dup', 'dup.emp_no', '=', 'de.emp_no'
                )->selectRaw('(dup.emp_no IS NULL OR de.dept_no = dup.first_dept) AS is_primary');
            } else {
                $query->selectRaw('1 AS is_primary');
            }

            return $query->get()->map(fn ($r) => [
                'dept_no' => $r->dept_no,
                'gender' => $r->gender,
                'age_band' => (int) $r->age_band,
                'n' => (int) $r->n,
                'tenure_days' => (int) $r->tenure_days,
                'primary' => (bool) $r->is_primary,
            ])->all();
        });
    }

    /**
     * Current titles on the as-of date by title and gender.
     *
     * @return list<array{title: string, gender: string, n: int}>
     */
    private function titleGender(ReportContext $context): array
    {
        if ($context->isEmpty()) {
            return [];
        }

        return $this->cache->remember('title_gender', $context, function () use ($context) {
            $date = $context->date();

            $query = ReportSources::open(ReportSources::titles('t'), 't', $date)
                ->join('employees as e', 'e.emp_no', '=', 't.emp_no')
                ->selectRaw('t.title, e.gender, COUNT(*) AS n')
                ->when($context->department !== null, fn ($q) => $q->whereExists(
                    ReportSources::open(ReportSources::deptEmp('de'), 'de', $date)
                        ->selectRaw('1')
                        ->whereColumn('de.emp_no', 't.emp_no')
                        ->where('de.dept_no', $context->department)
                ))
                ->groupBy('t.title', 'e.gender');
            $context->constrain($query, 't.emp_no');

            return $query->get()->map(fn ($r) => ['title' => $r->title, 'gender' => $r->gender, 'n' => (int) $r->n])->all();
        });
    }
}
