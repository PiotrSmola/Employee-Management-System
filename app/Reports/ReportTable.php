<?php

namespace App\Reports;

use Illuminate\Support\Carbon;

/**
 * One report as a table (HTML table and CSV) plus the charts drawn from it.
 *
 * Columns: key => [label, type]; types: text, int, money, percent, years, date.
 * Rows: lists of key => raw value (numbers unformatted, null = no value).
 * Charts: descriptions drawn by public/js/reports.js:
 *   ['id', 'type' => bar|line|doughnut, 'title', 'labels', 'datasets' => [[label, data, slot]],
 *    'stacked' => bool, 'horizontal' => bool, 'format' => int|money|percent|years]
 */
final class ReportTable
{
    /** Reports drawn at half width on large screens; the others use the full width. */
    private const HALF_WIDTH = ['tenure', 'age', 'salary-departments', 'salary-titles', 'salary-distribution', 'salary-growth'];

    /**
     * @param  array<string, array{0: string, 1: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $totals  rows shown below the body (e.g. "All departments")
     * @param  list<array<string, mixed>>  $charts
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $totals = [],
        public readonly array $charts = [],
        public readonly ?string $description = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /** Without a chart the table is the report, so it starts expanded. */
    public function showsTableFirst(): bool
    {
        return $this->charts === [];
    }

    public function tableId(): string
    {
        return 'report-table-'.$this->key;
    }

    public function widthClass(): string
    {
        return in_array($this->key, self::HALF_WIDTH, true) ? 'col-xl-6' : 'col-12';
    }

    /** A value for the HTML table. */
    public static function cell(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '–';
        }

        return match ($type) {
            'int' => number_format((float) $value),
            'money' => '$'.number_format((float) $value),
            'percent' => number_format((float) $value, 1).'%',
            'years' => number_format((float) $value, 1),
            'date' => Carbon::parse($value)->format('d.m.Y'),
            default => (string) $value,
        };
    }

    public static function cellClass(string $type): string
    {
        return in_array($type, ['text', 'date'], true) ? 'text-nowrap' : 'text-end text-nowrap';
    }

    /**
     * The charts with their layout on the page.
     *
     * @return list<array{id: string, title: ?string, label: string, json: string, height: int, column: string}>
     */
    public function chartCards(): array
    {
        return array_map(fn (array $chart) => [
            'id' => $chart['id'],
            'title' => $chart['title'] ?? null,
            'label' => $this->title.(empty($chart['title']) ? '' : ' - '.$chart['title']),
            'json' => json_encode($chart),
            'height' => match (true) {
                $chart['type'] === 'doughnut' => 260,
                ! empty($chart['horizontal']) => max(200, count($chart['labels']) * 28 + 70),
                default => 300,
            },
            'column' => match (true) {
                count($this->charts) === 1 => 'col-12',
                $chart['type'] === 'doughnut', count($this->charts) === 3 => 'col-lg-4',
                default => 'col-lg-6',
            },
        ], $this->charts);
    }

    /**
     * Header + rows + totals with raw values, ready for fputcsv.
     *
     * @return list<list<mixed>>
     */
    public function csvRows(): array
    {
        $lines = [array_map(fn (array $column) => $column[0], array_values($this->columns))];

        foreach ([...$this->rows, ...$this->totals] as $row) {
            $line = [];
            foreach ($this->columns as $key => [, $type]) {
                $line[] = self::csvValue($row[$key] ?? null, $type);
            }
            $lines[] = $line;
        }

        return $lines;
    }

    private static function csvValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return '';
        }

        return match ($type) {
            'money', 'int' => is_numeric($value) ? (int) round((float) $value) : $value,
            'percent', 'years' => is_numeric($value) ? round((float) $value, 2) : $value,
            // Text cells could start with a spreadsheet formula character (=, +, -, @).
            default => is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
                ? "'".$value
                : $value,
        };
    }
}
