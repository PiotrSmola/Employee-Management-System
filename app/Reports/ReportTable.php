<?php

namespace App\Reports;

/**
 * One report as a table (HTML table and CSV) plus the charts drawn from it.
 *
 * Columns: key => [label, type]; types: text, int, money, percent, years, date.
 * Rows: lists of key => raw value (numbers unformatted, null = no value).
 * Charts: Chart.js-agnostic descriptions rendered by resources/views/reports/partials/chart.blade.php:
 *   ['id', 'type' => bar|line|doughnut, 'title', 'labels', 'datasets' => [[label, data, slot]],
 *    'stacked' => bool, 'horizontal' => bool, 'format' => int|money|percent|years]
 */
final class ReportTable
{
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
