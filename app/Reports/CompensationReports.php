<?php

namespace App\Reports;

use Illuminate\Database\Query\Builder;

/**
 * Compensation reports (`view-salaries` only): salary statistics per department
 * and per title, salary distribution, gender pay gap and the average salary
 * over time.
 *
 * Salaries on the as-of date D are the salaries rows open on D (from_date <= D <
 * to_date), with the department and title open on D. Cached aggregates:
 *
 *  - salary_snapshot: count, average, exact median, minimum, maximum, gender split,
 *    pay gap and 10k salary bands of the whole scope, per department and per title;
 *  - salary_growth: salaries rows grouped by start and end year (intersected
 *    with the department rows for a department scope); the average on 31 Dec
 *    of each year is (sum of started - sum of ended) / (count started - count ended).
 *    Company-wide this counts salary rows, while the snapshot counts salaries of
 *    employees with an open department row, so somebody paid before their first
 *    department row (or with two open salaries) is only in this series.
 */
class CompensationReports
{
    public const KEYS = ['salary-departments', 'salary-titles', 'salary-distribution', 'pay-gap', 'salary-growth'];

    /** Width of a salary band of the distribution. */
    public const BAND = 10000;

    /** Packs a start date (YYYYMMDD) and a salary into one integer: date * RANK + salary. */
    private const RANK = 10_000_000_000;

    public function __construct(
        private readonly ReportCache $cache,
        private readonly ReportLabels $labels,
    ) {}

    public function table(string $key, ReportContext $context): ReportTable
    {
        return match ($key) {
            'salary-departments' => $this->byDepartment($context),
            'salary-titles' => $this->byTitle($context),
            'salary-distribution' => $this->distribution($context),
            'pay-gap' => $this->payGap($context),
            'salary-growth' => $this->growth($context),
        };
    }

    /**
     * @return array{employees: int, average: ?float, median: ?float, payGap: ?float}
     */
    public function summary(ReportContext $context): array
    {
        $overall = $this->snapshot($context)['overall'];

        return [
            'employees' => $overall['n'],
            'average' => $overall['average'],
            'median' => $overall['median'],
            'payGap' => $overall['gap'],
        ];
    }

    public function warm(ReportContext $context): void
    {
        $this->snapshot($context);
        $this->growthEvents($context);
    }

    public function byDepartment(ReportContext $context): ReportTable
    {
        $departments = $this->labels->departments();
        $rows = [];
        foreach ($this->snapshot($context)['departments'] as $deptNo => $group) {
            $rows[] = $this->statsRow($departments[$deptNo] ?? $deptNo, $group);
        }

        return $this->statsTable('salary-departments', __('Salaries by department'), __('Department'), $rows, $context,
            __('Current salaries on the as-of date per department of the employee on that day.'));
    }

    public function byTitle(ReportContext $context): ReportTable
    {
        $rows = [];
        foreach ($this->snapshot($context)['titles'] as $title => $group) {
            $rows[] = $this->statsRow($title === '' ? __('(no title)') : $title, $group);
        }

        return $this->statsTable('salary-titles', __('Salaries by title'), __('Title'), $rows, $context,
            __('Current salaries on the as-of date per title held on that day.'));
    }

    public function distribution(ReportContext $context): ReportTable
    {
        $bands = $this->snapshot($context)['overall']['bands'];

        $rows = [];
        if ($bands !== []) {
            for ($band = min(array_keys($bands)); $band <= max(array_keys($bands)); $band++) {
                $from = $band * self::BAND;
                $f = $bands[$band]['F'] ?? 0;
                $m = $bands[$band]['M'] ?? 0;
                $rows[] = [
                    'band' => number_format($from).' – '.number_format($from + self::BAND - 1),
                    'from' => $from,
                    'female' => $f,
                    'male' => $m,
                    'total' => $f + $m,
                ];
            }
        }

        return new ReportTable(
            'salary-distribution',
            __('Salary distribution'),
            [
                'band' => [__('Salary band'), 'text'],
                'from' => [__('From'), 'money'],
                'female' => [__('Female'), 'int'],
                'male' => [__('Male'), 'int'],
                'total' => [__('Employees'), 'int'],
            ],
            $rows,
            charts: $rows === [] ? [] : [[
                'id' => 'salary-distribution-chart', 'type' => 'bar', 'stacked' => true, 'format' => 'int',
                'labels' => array_map(fn ($r) => '$'.number_format($r['from'] / 1000).'k', $rows),
                'datasets' => [
                    ChartSeries::one(__('Female'), array_column($rows, 'female'), 1),
                    ChartSeries::one(__('Male'), array_column($rows, 'male'), 0),
                ],
            ]],
            description: __('Employees per salary band of :width on the as-of date.', ['width' => '$'.number_format(self::BAND)]),
        );
    }

    public function payGap(ReportContext $context): ReportTable
    {
        $departments = $this->labels->departments();
        $row = fn (string $breakdown, string $group, array $g) => [
            'breakdown' => $breakdown,
            'group' => $group,
            'female_count' => $g['F']['n'] ?? 0,
            'male_count' => $g['M']['n'] ?? 0,
            'female_avg' => $g['female'],
            'male_avg' => $g['male'],
            'gap' => $g['gap'],
        ];

        $snapshot = $this->snapshot($context);
        $deptRows = [];
        foreach ($snapshot['departments'] as $deptNo => $group) {
            $deptRows[] = $row(__('Department'), $departments[$deptNo] ?? $deptNo, $group);
        }
        $titleRows = [];
        foreach ($snapshot['titles'] as $title => $group) {
            $titleRows[] = $row(__('Title'), $title === '' ? __('(no title)') : $title, $group);
        }

        $rows = $snapshot['overall']['n'] === 0 ? [] : [$row(__('Overall'), __('All employees'), $snapshot['overall']), ...$deptRows, ...$titleRows];

        $bars = fn (string $id, string $title, array $groupRows) => [
            'id' => $id, 'type' => 'bar', 'horizontal' => true, 'format' => 'percent', 'title' => $title,
            'labels' => array_column($groupRows, 'group'),
            'datasets' => [ChartSeries::one(__('Pay gap'), array_map(fn ($v) => $v === null ? null : round($v, 2), array_column($groupRows, 'gap')), 6)],
        ];

        $charts = [];
        if (count($deptRows) > 1) {
            $charts[] = $bars('pay-gap-departments-chart', __('By department'), $deptRows);
        }
        if ($titleRows !== []) {
            $charts[] = $bars('pay-gap-titles-chart', __('By title'), $titleRows);
        }

        return new ReportTable(
            'pay-gap',
            __('Gender pay gap'),
            [
                'breakdown' => [__('Breakdown'), 'text'],
                'group' => [__('Group'), 'text'],
                'female_count' => [__('Women'), 'int'],
                'male_count' => [__('Men'), 'int'],
                'female_avg' => [__('Average (women)'), 'money'],
                'male_avg' => [__('Average (men)'), 'money'],
                'gap' => [__('Pay gap'), 'percent'],
            ],
            $rows,
            charts: $charts,
            description: __('Pay gap = (average salary of men - average salary of women) / average salary of men. Positive: women earn less on average.'),
        );
    }

    public function growth(ReportContext $context): ReportTable
    {
        ['events' => $events, 'last_year' => $lastYear] = $this->growthEvents($context);
        $rows = [];

        if ($events !== []) {
            $count = 0;
            $sum = 0;
            $previous = null;
            $last = max([...array_keys($events), $lastYear ?? 0]);
            for ($year = min(array_keys($events)); $year <= $last; $year++) {
                $count += $events[$year]['n'] ?? 0;
                $sum += $events[$year]['total'] ?? 0;
                $average = $count > 0 ? $sum / $count : null;

                $rows[] = [
                    'year' => (string) $year,
                    'date' => $year === $context->asOf->year ? $context->date() : $year.'-12-31',
                    'employees' => $count,
                    'average' => $average,
                    'change' => $previous !== null && $average !== null ? ($average - $previous) / $previous * 100 : null,
                ];
                $previous = $average;
            }
        }

        return new ReportTable(
            'salary-growth',
            __('Average salary over time'),
            [
                'year' => [__('Year'), 'text'],
                'date' => [__('Date'), 'date'],
                'employees' => [__('Employees'), 'int'],
                'average' => [__('Average salary'), 'money'],
                'change' => [__('Change vs previous year'), 'percent'],
            ],
            $rows,
            charts: $rows === [] ? [] : [[
                'id' => 'salary-growth-chart', 'type' => 'line', 'format' => 'money',
                'labels' => array_column($rows, 'year'),
                'datasets' => [ChartSeries::one(__('Average salary'), array_map(fn ($v) => $v === null ? null : round($v), array_column($rows, 'average')))],
            ]],
            description: __('Average of the salaries paid on 31 December of each year (the last year: on the as-of date). Counts every open salary, also of employees without a department on that day.'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function statsTable(string $key, string $title, string $groupLabel, array $rows, ReportContext $context, string $description): ReportTable
    {
        $totals = count($rows) > 1 ? [$this->statsRow(__('All employees'), $this->snapshot($context)['overall'])] : [];

        $charts = $rows === [] ? [] : [[
            'id' => $key.'-chart', 'type' => 'bar', 'horizontal' => true, 'format' => 'money',
            'labels' => array_column($rows, 'group'),
            'datasets' => [
                ChartSeries::one(__('Average'), array_map(fn ($v) => round($v), array_column($rows, 'average')), 0),
                ChartSeries::one(__('Median'), array_map(fn ($v) => $v === null ? null : round($v), array_column($rows, 'median')), 2),
            ],
        ]];

        return new ReportTable(
            $key,
            $title,
            [
                'group' => [$groupLabel, 'text'],
                'employees' => [__('Employees'), 'int'],
                'average' => [__('Average'), 'money'],
                'median' => [__('Median'), 'money'],
                'min' => [__('Minimum'), 'money'],
                'max' => [__('Maximum'), 'money'],
            ],
            $rows,
            $totals,
            $charts,
            $description,
        );
    }

    /**
     * @param  array<string, mixed>  $group  see {@see stats()}
     * @return array<string, mixed>
     */
    private function statsRow(string $label, array $group): array
    {
        return [
            'group' => $label,
            'employees' => $group['n'],
            'average' => $group['average'],
            'median' => $group['median'],
            'min' => $group['min'],
            'max' => $group['max'],
        ];
    }

    /**
     * Salaries open on the as-of date with the department and title of that day.
     */
    private function base(ReportContext $context): Builder
    {
        $date = $context->date();

        $query = ReportSources::open(ReportSources::salaries('s'), 's', $date);
        ReportSources::joinDeptEmp($query, 'de', fn ($j) => ReportSources::open($j->on('de.emp_no', '=', 's.emp_no'), 'de', $date));
        ReportSources::joinTitles($query, 't', fn ($j) => ReportSources::open($j->on('t.emp_no', '=', 's.emp_no'), 't', $date), 'left');
        $query->join('employees as e', 'e.emp_no', '=', 's.emp_no')
            ->when($context->department !== null, fn ($q) => $q->where('de.dept_no', $context->department));

        return $context->constrain($query, 's.emp_no');
    }

    /**
     * Statistics of the salaries on the as-of date, over the whole scope and per
     * department and title. Each employee counts once per group, also with several
     * open rows; rows of a person in two departments or with two titles appear in
     * both groups. A person's salary is the open row that started last (the highest
     * one when two started on the same day), so the result does not depend on the
     * order of the rows.
     *
     * One pass in PHP over the open salaries (about 240k rows in the full dataset)
     * instead of SQL aggregation: medians in MySQL need a sort per partition, and
     * with the default sort buffer and temporary table sizes those sorts go to disk
     * and take far longer than the join itself. A cursor does not save memory here:
     * PDO's MySQL client buffers the whole result, and the loop keeps one number per
     * employee and group. Together that is about 60 MB on the full dataset, on top of
     * the 45 MB of the framework, so keep memory_limit above 128 MB in mind.
     *
     * @return array{overall: array<string, mixed>, departments: array<string, array<string, mixed>>, titles: array<string, array<string, mixed>>}
     */
    private function snapshot(ReportContext $context): array
    {
        if ($context->isEmpty()) {
            return ['overall' => self::stats([], []), 'departments' => [], 'titles' => []];
        }

        return $this->cache->remember('salary_snapshot', $context, function () use ($context) {
            $rows = $this->base($context)
                ->selectRaw("s.emp_no, s.from_date, s.salary, de.dept_no, COALESCE(t.title, '') AS title, e.gender")
                ->cursor();

            // emp_no => start date and salary packed into one integer: the largest wins.
            $overall = [];
            $departments = [];
            $titles = [];
            $women = [];

            foreach ($rows as $r) {
                $emp = (int) $r->emp_no;
                $rank = (int) str_replace('-', '', (string) $r->from_date) * self::RANK + (int) $r->salary;

                $overall[$emp] = max($overall[$emp] ?? 0, $rank);
                $departments[$r->dept_no][$emp] = max($departments[$r->dept_no][$emp] ?? 0, $rank);
                $titles[$r->title][$emp] = max($titles[$r->title][$emp] ?? 0, $rank);

                if ($r->gender === 'F') {
                    $women[$emp] = true;
                }
            }

            ksort($departments);
            ksort($titles);

            return [
                'overall' => self::stats($overall, $women),
                'departments' => array_map(fn (array $ranks) => self::stats($ranks, $women), $departments),
                'titles' => array_map(fn (array $ranks) => self::stats($ranks, $women), $titles),
            ];
        });
    }

    /**
     * Count, average, median, min, max, gender split and pay gap of one group.
     *
     * @param  array<int, int>  $ranks  emp_no => packed start date and salary
     * @param  array<int, true>  $women
     * @return array<string, mixed>
     */
    private static function stats(array $ranks, array $women): array
    {
        $gender = ['F' => ['n' => 0, 'sum' => 0], 'M' => ['n' => 0, 'sum' => 0]];
        $bands = [];
        $salaries = [];

        foreach ($ranks as $emp => $rank) {
            $salary = $rank % self::RANK;
            $g = isset($women[$emp]) ? 'F' : 'M';
            $salaries[] = $salary;
            $gender[$g]['n']++;
            $gender[$g]['sum'] += $salary;
            $band = (int) floor($salary / self::BAND);
            $bands[$band][$g] = ($bands[$band][$g] ?? 0) + 1;
        }

        sort($salaries);
        $n = count($salaries);
        $female = $gender['F']['n'] > 0 ? $gender['F']['sum'] / $gender['F']['n'] : null;
        $male = $gender['M']['n'] > 0 ? $gender['M']['sum'] / $gender['M']['n'] : null;

        return [
            'n' => $n,
            'average' => $n > 0 ? array_sum($salaries) / $n : null,
            'median' => $n > 0 ? ($salaries[intdiv($n - 1, 2)] + $salaries[intdiv($n, 2)]) / 2 : null,
            'min' => $salaries[0] ?? null,
            'max' => $salaries[$n - 1] ?? null,
            'F' => $gender['F'],
            'M' => $gender['M'],
            'female' => $female,
            'male' => $male,
            'gap' => $female !== null && $male !== null && $male > 0 ? ($male - $female) / $male * 100 : null,
            'bands' => $bands,
        ];
    }

    /**
     * Salary rows (of the department scope) started and ended per year, up to the as-of date.
     *
     * @return array{events: array<int, array{n: int, total: int}>, last_year: ?int} events: year => net change of count and sum
     */
    private function growthEvents(ReportContext $context): array
    {
        if ($context->isEmpty()) {
            return ['events' => [], 'last_year' => null];
        }

        return $this->cache->remember('salary_growth', $context, function () use ($context) {
            $date = $context->date();

            if ($context->department === null) {
                $start = 's.from_date';
                $end = 's.to_date';
                $query = ReportSources::salaries('s');
            } else {
                // The part of each salary period spent in the department.
                $start = 'GREATEST(s.from_date, de.from_date)';
                $end = 'LEAST(s.to_date, de.to_date)';
                $query = ReportSources::joinSalaries(ReportSources::deptEmp('de'), 's', fn ($j) => $j->on('s.emp_no', '=', 'de.emp_no')
                    ->whereColumn('s.from_date', '<', 'de.to_date')
                    ->whereColumn('s.to_date', '>', 'de.from_date'))
                    ->where('de.dept_no', $context->department);
            }

            $query->selectRaw("YEAR({$start}) AS start_year")
                ->selectRaw("CASE WHEN {$end} <= ? THEN YEAR({$end}) END AS end_year", [$date])
                ->selectRaw('COUNT(*) AS n, SUM(s.salary) AS total')
                ->whereRaw("{$start} <= ?", [$date])
                ->groupBy('start_year', 'end_year');
            $context->constrain($query, 's.emp_no');

            $events = [];
            foreach ($query->get() as $g) {
                $events[(int) $g->start_year]['n'] = ($events[(int) $g->start_year]['n'] ?? 0) + (int) $g->n;
                $events[(int) $g->start_year]['total'] = ($events[(int) $g->start_year]['total'] ?? 0) + (int) $g->total;
                if ($g->end_year !== null) {
                    $events[(int) $g->end_year]['n'] = ($events[(int) $g->end_year]['n'] ?? 0) - (int) $g->n;
                    $events[(int) $g->end_year]['total'] = ($events[(int) $g->end_year]['total'] ?? 0) - (int) $g->total;
                }
            }

            return ['events' => $events, 'last_year' => CompanyHistory::lastChangeYear($date)];
        });
    }
}
