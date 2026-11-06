<?php

namespace App\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Compensation reports (`view-salaries` only): salary statistics per department
 * and per title, salary distribution, gender pay gap and the average salary
 * over time.
 *
 * Salaries on the as-of date D are the salaries rows open on D (from_date <= D <
 * to_date), with the department and title open on D. Cached aggregates:
 *
 *  - salary_snapshot: groups of (department, title, gender, 10k salary band) with
 *    count, sum, min and max - every average, minimum, maximum, histogram and pay
 *    gap is summed up from them - and exact medians per department, per title and
 *    overall;
 *  - salary_growth: salaries rows grouped by start and end year (intersected
 *    with the department rows for a department scope); the average on 31 Dec
 *    of each year is (sum of started - sum of ended) / (count started - count ended).
 */
class CompensationReports
{
    public const KEYS = ['salary-departments', 'salary-titles', 'salary-distribution', 'pay-gap', 'salary-growth'];

    /** Width of a salary band of the distribution. */
    public const BAND = 10000;

    public function __construct(
        private readonly ReportCache $cache,
        private readonly ReportLabels $labels,
    ) {
    }

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
        $overall = $this->totals($this->summaryRows($context));

        return [
            'employees' => $overall['n'],
            'average' => $overall['average'],
            'median' => $this->medians($context)['overall'],
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
        $medians = $this->medians($context);
        $rows = [];
        foreach ($this->grouped($context, 'dept_no') as $deptNo => $group) {
            $rows[] = $this->statsRow($departments[$deptNo] ?? $deptNo, $group, $medians['departments'][$deptNo] ?? null);
        }

        return $this->statsTable('salary-departments', __('Salaries by department'), __('Department'), $rows, $context,
            __('Current salaries on the as-of date per department of the employee on that day.'));
    }

    public function byTitle(ReportContext $context): ReportTable
    {
        $medians = $this->medians($context);
        $rows = [];
        foreach ($this->grouped($context, 'title') as $title => $group) {
            $rows[] = $this->statsRow($title === '' ? __('(no title)') : $title, $group, $medians['titles'][$title] ?? null);
        }

        return $this->statsTable('salary-titles', __('Salaries by title'), __('Title'), $rows, $context,
            __('Current salaries on the as-of date per title held on that day.'));
    }

    public function distribution(ReportContext $context): ReportTable
    {
        $bands = [];
        foreach ($this->summaryRows($context) as $r) {
            $bands[$r['band']][$r['gender']] = ($bands[$r['band']][$r['gender']] ?? 0) + $r['n'];
        }

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

        $deptRows = [];
        foreach ($this->grouped($context, 'dept_no') as $deptNo => $group) {
            $deptRows[] = $row(__('Department'), $departments[$deptNo] ?? $deptNo, $group);
        }
        $titleRows = [];
        foreach ($this->grouped($context, 'title') as $title => $group) {
            $titleRows[] = $row(__('Title'), $title === '' ? __('(no title)') : $title, $group);
        }

        $all = $this->summaryRows($context);
        $rows = $all === [] ? [] : [$row(__('Overall'), __('All employees'), $this->totals($all)), ...$deptRows, ...$titleRows];

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
            description: __('Average salary of the employees on 31 December of each year (the last year: on the as-of date).'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function statsTable(string $key, string $title, string $groupLabel, array $rows, ReportContext $context, string $description): ReportTable
    {
        $all = $this->summaryRows($context);
        $totals = count($rows) > 1
            ? [$this->statsRow(__('All employees'), $this->totals($all), $this->medians($context)['overall'])]
            : [];

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
     * @param  array{n: int, average: ?float, min: ?int, max: ?int}  $group
     * @return array<string, mixed>
     */
    private function statsRow(string $label, array $group, ?float $median): array
    {
        return [
            'group' => $label,
            'employees' => $group['n'],
            'average' => $group['average'],
            'median' => $median,
            'min' => $group['min'],
            'max' => $group['max'],
        ];
    }

    /**
     * Summary rows totalled per department or per title.
     *
     * @return array<string, array<string, mixed>>
     */
    private function grouped(ReportContext $context, string $by): array
    {
        $groups = [];
        foreach ($this->summaryRows($context) as $r) {
            $groups[(string) $r[$by]][] = $r;
        }
        ksort($groups);

        return array_map(fn (array $rows) => $this->totals($rows), $groups);
    }

    /**
     * Count, average, min, max - overall and per gender - and the pay gap of summary rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows): array
    {
        $t = ['n' => 0, 'sum' => 0, 'min' => null, 'max' => null, 'F' => ['n' => 0, 'sum' => 0], 'M' => ['n' => 0, 'sum' => 0]];
        foreach ($rows as $r) {
            $t['n'] += $r['n'];
            $t['sum'] += $r['sum'];
            $t['min'] = $t['min'] === null ? $r['min'] : min($t['min'], $r['min']);
            $t['max'] = $t['max'] === null ? $r['max'] : max($t['max'], $r['max']);
            $t[$r['gender']]['n'] += $r['n'];
            $t[$r['gender']]['sum'] += $r['sum'];
        }

        $t['average'] = $t['n'] > 0 ? $t['sum'] / $t['n'] : null;
        $t['female'] = $t['F']['n'] > 0 ? $t['F']['sum'] / $t['F']['n'] : null;
        $t['male'] = $t['M']['n'] > 0 ? $t['M']['sum'] / $t['M']['n'] : null;
        $t['gap'] = $t['female'] !== null && $t['male'] !== null && $t['male'] > 0
            ? ($t['male'] - $t['female']) / $t['male'] * 100
            : null;

        return $t;
    }

    /**
     * Salaries open on the as-of date with the department and title of that day.
     */
    private function base(ReportContext $context): Builder
    {
        $date = $context->date();

        $query = DB::table('salaries as s')
            ->join('dept_emp as de', fn ($j) => $j->on('de.emp_no', '=', 's.emp_no')
                ->where('de.from_date', '<=', $date)
                ->where('de.to_date', '>', $date))
            ->leftJoin('titles as t', fn ($j) => $j->on('t.emp_no', '=', 's.emp_no')
                ->where('t.from_date', '<=', $date)
                ->where('t.to_date', '>', $date))
            ->join('employees as e', 'e.emp_no', '=', 's.emp_no')
            ->where('s.from_date', '<=', $date)
            ->where('s.to_date', '>', $date)
            ->when($context->department !== null, fn ($q) => $q->where('de.dept_no', $context->department));

        return $context->constrain($query, 's.emp_no');
    }

    /**
     * @return list<array{dept_no: string, title: string, gender: string, band: int, n: int, sum: int, min: int, max: int}>
     */
    private function summaryRows(ReportContext $context): array
    {
        return $this->snapshot($context)['summary'];
    }

    /**
     * Exact medians per department, per title and overall.
     *
     * @return array{departments: array<string, float>, titles: array<string, float>, overall: ?float}
     */
    private function medians(ReportContext $context): array
    {
        return $this->snapshot($context)['medians'];
    }

    /**
     * Summary groups and medians of the salaries on the as-of date.
     *
     * One pass over the open salaries (about 240k rows in the full dataset, read
     * with a cursor) instead of SQL aggregation: medians in MySQL need a sort per
     * partition, and with the default sort buffer and temporary table sizes those
     * sorts go to disk and take far longer than the join itself.
     *
     * @return array{summary: list<array<string, mixed>>, medians: array{departments: array<string, float>, titles: array<string, float>, overall: ?float}}
     */
    private function snapshot(ReportContext $context): array
    {
        if ($context->seesNobody()) {
            return ['summary' => [], 'medians' => ['departments' => [], 'titles' => [], 'overall' => null]];
        }

        return $this->cache->remember('salary_snapshot', $context, function () use ($context) {
            $rows = $this->base($context)
                ->selectRaw("de.dept_no, COALESCE(t.title, '') AS title, e.gender, s.salary")
                ->cursor();

            $groups = [];
            $departments = [];
            $titles = [];
            $all = [];

            foreach ($rows as $r) {
                $salary = (int) $r->salary;
                $band = (int) floor($salary / self::BAND);
                $key = "{$r->dept_no}|{$r->title}|{$r->gender}|{$band}";

                $group = $groups[$key] ?? [
                    'dept_no' => $r->dept_no, 'title' => $r->title, 'gender' => $r->gender, 'band' => $band,
                    'n' => 0, 'sum' => 0, 'min' => $salary, 'max' => $salary,
                ];
                $group['n']++;
                $group['sum'] += $salary;
                $group['min'] = min($group['min'], $salary);
                $group['max'] = max($group['max'], $salary);
                $groups[$key] = $group;

                $departments[$r->dept_no][] = $salary;
                $titles[$r->title][] = $salary;
                $all[] = $salary;
            }

            return ['summary' => array_values($groups), 'medians' => [
                'departments' => array_map(self::median(...), $departments),
                'titles' => array_map(self::median(...), $titles),
                'overall' => $all === [] ? null : self::median($all),
            ]];
        });
    }

    /**
     * @param  non-empty-list<int>  $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);

        return ($values[intdiv($n - 1, 2)] + $values[intdiv($n, 2)]) / 2;
    }

    /**
     * Salary rows (of the department scope) started and ended per year, up to the as-of date.
     *
     * @return array{events: array<int, array{n: int, total: int}>, last_year: ?int} events: year => net change of count and sum
     */
    private function growthEvents(ReportContext $context): array
    {
        if ($context->seesNobody()) {
            return ['events' => [], 'last_year' => null];
        }

        return $this->cache->remember('salary_growth', $context, function () use ($context) {
            $date = $context->date();

            if ($context->department === null) {
                $start = 's.from_date';
                $end = 's.to_date';
                $query = DB::table('salaries as s');
            } else {
                // The part of each salary period spent in the department.
                $start = 'GREATEST(s.from_date, de.from_date)';
                $end = 'LEAST(s.to_date, de.to_date)';
                $query = DB::table('dept_emp as de')
                    ->join('salaries as s', fn ($j) => $j->on('s.emp_no', '=', 'de.emp_no')
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
