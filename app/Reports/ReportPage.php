<?php

namespace App\Reports;

use Illuminate\Support\Carbon;

/**
 * Everything resources/views/reports/show.blade.php displays besides the report
 * tables: page name, scope label, filter values, quick dates and summary tiles.
 */
final class ReportPage
{
    /** Years offered as quick as-of dates (31 December). */
    private const QUICK_YEARS = [2000, 1995, 1990];

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, string>  $departments  dept_no => name
     */
    public function __construct(
        public readonly string $section,
        public readonly ReportContext $context,
        public readonly array $summary,
        public readonly array $departments,
    ) {
    }

    public function isCompensation(): bool
    {
        return $this->section === ReportCatalog::COMPENSATION;
    }

    public function name(): string
    {
        return $this->isCompensation() ? __('Compensation') : __('Workforce');
    }

    /** The manager's department for the badge. */
    public function scopeLabel(): string
    {
        $department = $this->context->department;

        return __('Your department: :name', [
            'name' => $department === null ? __('none assigned') : ($this->departments[$department] ?? $department),
        ]);
    }

    public function earliestDate(): string
    {
        return ReportContext::EARLIEST_YEAR.'-01-01';
    }

    public function latestDate(): string
    {
        return Carbon::today()->toDateString();
    }

    /** Value of the date input: empty for the current state. */
    public function asOfValue(): string
    {
        return $this->context->current ? '' : $this->context->date();
    }

    /**
     * Quick as-of links for the page at $url.
     *
     * @return list<array{label: string, url: string, active: bool}>
     */
    public function quickDates(string $url): array
    {
        $dates = [['label' => __('Current'), 'date' => null]];
        foreach (self::QUICK_YEARS as $year) {
            $dates[] = ['label' => __('End of :year', ['year' => $year]), 'date' => $year.'-12-31'];
        }

        return array_map(function (array $quick) use ($url) {
            $query = http_build_query(array_filter([
                'department' => $this->context->isDepartmentScoped() ? null : $this->context->department,
                'as_of' => $quick['date'],
            ]));

            return [
                'label' => $quick['label'],
                'url' => $url.'?'.$query,
                'active' => $quick['date'] === null
                    ? $this->context->current
                    : ! $this->context->current && $this->context->date() === $quick['date'],
            ];
        }, $dates);
    }

    /**
     * Summary figures as formatted tiles.
     *
     * @return list<array{gradient: int, icon: string, value: string, label: string}>
     */
    public function tiles(): array
    {
        return $this->isCompensation() ? $this->compensationTiles() : $this->workforceTiles();
    }

    /**
     * @return list<array{gradient: int, icon: string, value: string, label: string}>
     */
    private function workforceTiles(): array
    {
        $s = $this->summary;

        return [
            ['gradient' => 1, 'icon' => 'people', 'value' => number_format($s['headcount']), 'label' => __('Employees')],
            ['gradient' => 2, 'icon' => 'hourglass-split', 'label' => __('Average tenure'),
                'value' => $s['tenure'] === null ? '–' : __(':years yrs', ['years' => number_format($s['tenure'], 1)])],
            ['gradient' => 3, 'icon' => 'gender-ambiguous', 'label' => __('Women'),
                'value' => $s['femaleShare'] === null ? '–' : number_format($s['femaleShare'], 1).'%'],
            ['gradient' => 4, 'icon' => 'arrow-left-right',
                'label' => $s['turnoverYear'] ? __('Turnover in :year', ['year' => $s['turnoverYear']]) : __('Turnover'),
                'value' => $s['turnover'] === null ? '–' : number_format($s['turnover'], 1).'%'],
        ];
    }

    /**
     * @return list<array{gradient: int, icon: string, value: string, label: string}>
     */
    private function compensationTiles(): array
    {
        $s = $this->summary;

        return [
            ['gradient' => 1, 'icon' => 'cash-stack', 'label' => __('Average salary'),
                'value' => $s['average'] === null ? '–' : '$'.number_format($s['average'])],
            ['gradient' => 2, 'icon' => 'distribute-vertical', 'label' => __('Median salary'),
                'value' => $s['median'] === null ? '–' : '$'.number_format($s['median'])],
            ['gradient' => 3, 'icon' => 'gender-ambiguous', 'label' => __('Gender pay gap'),
                'value' => $s['payGap'] === null ? '–' : number_format($s['payGap'], 2).'%'],
            ['gradient' => 4, 'icon' => 'people', 'value' => number_format($s['employees']), 'label' => __('Employees with a salary')],
        ];
    }
}
