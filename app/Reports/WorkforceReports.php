<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Workforce reports: headcount over time, hires vs leavers (turnover), tenure,
 * gender and age distribution. No salary data.
 *
 * Everything is derived from three cached aggregates, each a single GROUP BY
 * over the history tables (no per-employee work in PHP):
 *
 *  - movements: dept_emp rows grouped by (department, start year, end year,
 *    hire year, first/last row of the employee). A row starts on from_date (a
 *    transfer in unless it is the employee's first row) and ends on to_date (a
 *    leaver when it is the last row, a transfer out otherwise). Headcount on
 *    31 Dec of a year = rows started - rows ended up to that day, so the whole
 *    history comes from one scan. Hires use employees.hire_date, which in the
 *    dataset is often earlier than the first department row.
 *  - snapshot: employees with an open dept_emp row on the as-of date, grouped by
 *    department, gender and 5-year age band (count + summed tenure days).
 *  - title_gender: open titles rows on the as-of date by title and gender.
 */
class WorkforceReports
{
    public const KEYS = ['headcount', 'hires-leavers', 'tenure', 'gender', 'age'];

    /** Counts of one department in one year; joined = first department row of an employee. */
    private const NO_EVENTS = ['hires' => 0, 'joined' => 0, 'in' => 0, 'leavers' => 0, 'out' => 0];

    public function __construct(
        private readonly ReportCache $cache,
        private readonly ReportLabels $labels,
    ) {
    }

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
        $snapshot = $this->snapshot($context);
        $headcount = array_sum(array_column($snapshot, 'n'));
        $female = array_sum(array_column(array_filter($snapshot, fn ($r) => $r['gender'] === 'F'), 'n'));

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
            'tenure' => $headcount > 0 ? array_sum(array_column($snapshot, 'tenure_days')) / $headcount / 365.25 : null,
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
                $running[$deptNo] += $e === null ? 0 : $e['joined'] + $e['in'] - $e['leavers'] - $e['out'];
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

        $n = array_sum(array_column($byDepartment, 'n'));
        $totals = count($rows) > 1 ? [[
            'department' => __('All departments'),
            'employees' => $n,
            'tenure' => array_sum(array_column($byDepartment, 'days')) / $n / 365.25,
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
        foreach ($byDepartment as $counts) {
            $overall['F'] += $counts['F'] ?? 0;
            $overall['M'] += $counts['M'] ?? 0;
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
        foreach ($this->snapshot($context) as $r) {
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
        ['events' => $byDepartment, 'last_year' => $lastYear] = $this->movements($context);

        $events = [];
        foreach ($byDepartment as $years) {
            foreach ($years as $year => $e) {
                foreach ($e as $kind => $n) {
                    $events[$year][$kind] = ($events[$year][$kind] ?? 0) + $n;
                }
            }
        }

        if ($events === []) {
            return [];
        }

        $result = [];
        $headcount = 0;
        $last = max([...array_keys($events), $lastYear ?? 0]);
        for ($year = min(array_keys($events)); $year <= $last; $year++) {
            $e = $events[$year] ?? self::NO_EVENTS;
            $start = $headcount;
            $headcount += $e['joined'] + $e['in'] - $e['leavers'] - $e['out'];
            $average = ($start + $headcount) / 2;

            $result[] = [
                'year' => $year,
                'date' => $year === $context->asOf->year ? $context->date() : $year.'-12-31',
                'hires' => $e['hires'],
                'leavers' => $e['leavers'],
                'in' => $e['in'],
                'out' => $e['out'],
                'start' => $start,
                'end' => $headcount,
                'turnover' => $average > 0 ? $e['leavers'] / $average * 100 : null,
            ];
        }

        return $result;
    }

    /**
     * Department history events up to the as-of date (events: dept_no => year => counts)
     * and the year of the company's last change on or before that date.
     *
     * @return array{events: array<string, array<int, array{hires: int, joined: int, in: int, leavers: int, out: int}>>, last_year: ?int}
     */
    private function movements(ReportContext $context): array
    {
        if ($context->seesNobody()) {
            return ['events' => [], 'last_year' => null];
        }

        return $this->cache->remember('movements', $context, function () use ($context) {
            $date = $context->date();
            $rowKey = 'CONCAT(%1$s.from_date, %1$s.to_date, %1$s.dept_no)';

            // First and last dept_emp row of each employee (primary key order, no sorting).
            $spans = DB::table('dept_emp as f')
                ->select('f.emp_no')
                ->selectRaw('MIN('.sprintf($rowKey, 'f').') AS first_row, MAX(f.to_date) AS last_to')
                ->when($context->department !== null, fn ($q) => $q->whereIn('f.emp_no', fn ($in) => $in
                    ->select('de_d.emp_no')->from('dept_emp as de_d')->where('de_d.dept_no', $context->department)))
                ->groupBy('f.emp_no');
            $context->constrain($spans, 'f.emp_no');

            $isFirst = sprintf($rowKey, 'de').' = f.first_row';

            $groups = DB::table('dept_emp as de')
                ->joinSub($spans, 'f', 'f.emp_no', '=', 'de.emp_no')
                ->join('employees as e', 'e.emp_no', '=', 'de.emp_no')
                ->select('de.dept_no')
                ->selectRaw('CASE WHEN de.from_date <= ? THEN YEAR(de.from_date) END AS start_year', [$date])
                ->selectRaw('CASE WHEN de.to_date <= ? THEN YEAR(de.to_date) END AS end_year', [$date])
                ->selectRaw("CASE WHEN {$isFirst} AND e.hire_date <= ? THEN YEAR(e.hire_date) END AS hire_year", [$date])
                ->selectRaw("{$isFirst} AS is_first")
                ->selectRaw('de.to_date = f.last_to AS is_last')
                ->selectRaw('COUNT(*) AS n')
                ->where(fn ($q) => $q->where('de.from_date', '<=', $date)->orWhere('e.hire_date', '<=', $date))
                ->when($context->department !== null, fn ($q) => $q->where('de.dept_no', $context->department))
                ->groupBy('de.dept_no', 'start_year', 'end_year', 'hire_year', 'is_first', 'is_last')
                ->get();

            $result = [];
            $add = function (string $deptNo, int $year, string $kind, int $n) use (&$result) {
                $result[$deptNo][$year] ??= self::NO_EVENTS;
                $result[$deptNo][$year][$kind] += $n;
            };

            foreach ($groups as $g) {
                $n = (int) $g->n;
                if ($g->start_year !== null) {
                    $add($g->dept_no, (int) $g->start_year, $g->is_first ? 'joined' : 'in', $n);
                }
                if ($g->end_year !== null) {
                    $add($g->dept_no, (int) $g->end_year, $g->is_last ? 'leavers' : 'out', $n);
                }
                if ($g->hire_year !== null) {
                    $add($g->dept_no, (int) $g->hire_year, 'hires', $n);
                }
            }

            // Histories of all scopes run up to the last change in the company (not only in the department).
            return ['events' => $result, 'last_year' => CompanyHistory::lastChangeYear($date)];
        });
    }

    /**
     * Employed on the as-of date by department, gender and age band.
     *
     * @return list<array{dept_no: string, gender: string, age_band: int, n: int, tenure_days: int}>
     */
    private function snapshot(ReportContext $context): array
    {
        if ($context->seesNobody()) {
            return [];
        }

        return $this->cache->remember('snapshot', $context, function () use ($context) {
            $date = $context->date();

            $query = DB::table('dept_emp as de')
                ->join('employees as e', 'e.emp_no', '=', 'de.emp_no')
                ->selectRaw('de.dept_no, e.gender, FLOOR(TIMESTAMPDIFF(YEAR, e.birth_date, ?) / 5) * 5 AS age_band', [$date])
                ->selectRaw('COUNT(*) AS n, SUM(DATEDIFF(?, e.hire_date)) AS tenure_days', [$date])
                ->where('de.from_date', '<=', $date)
                ->where('de.to_date', '>', $date)
                ->when($context->department !== null, fn ($q) => $q->where('de.dept_no', $context->department))
                ->groupBy('de.dept_no', 'e.gender', 'age_band');
            $context->constrain($query, 'de.emp_no');

            return $query->get()->map(fn ($r) => [
                'dept_no' => $r->dept_no,
                'gender' => $r->gender,
                'age_band' => (int) $r->age_band,
                'n' => (int) $r->n,
                'tenure_days' => (int) $r->tenure_days,
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
        if ($context->seesNobody()) {
            return [];
        }

        return $this->cache->remember('title_gender', $context, function () use ($context) {
            $date = $context->date();

            $query = DB::table('titles as t')
                ->join('employees as e', 'e.emp_no', '=', 't.emp_no')
                ->selectRaw('t.title, e.gender, COUNT(*) AS n')
                ->where('t.from_date', '<=', $date)
                ->where('t.to_date', '>', $date)
                ->when($context->department !== null, fn ($q) => $q->whereExists(fn ($de) => $de
                    ->selectRaw('1')->from('dept_emp as de')
                    ->whereColumn('de.emp_no', 't.emp_no')
                    ->where('de.dept_no', $context->department)
                    ->where('de.from_date', '<=', $date)
                    ->where('de.to_date', '>', $date)))
                ->groupBy('t.title', 'e.gender');
            $context->constrain($query, 't.emp_no');

            return $query->get()->map(fn ($r) => ['title' => $r->title, 'gender' => $r->gender, 'n' => (int) $r->n])->all();
        });
    }
}
