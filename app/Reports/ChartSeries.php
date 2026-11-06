<?php

namespace App\Reports;

/**
 * Helpers for chart descriptions (see ReportTable). Series colours are palette
 * slots 0-7 assigned in a fixed order (public/js/reports.js);
 * a chart never gets more than 8 coloured series - the rest is folded into one
 * grey "Other" series (the table always has every value).
 */
final class ChartSeries
{
    public const MAX_SERIES = 8;

    public const OTHER = 'other';

    /**
     * @param  array<string, array{label: string, data: list<int|float|null>}>  $series  keyed by a stable id (e.g. dept_no)
     * @return list<array{label: string, data: list<int|float|null>, slot: int|string}>
     */
    public static function fold(array $series, string $otherLabel): array
    {
        if (count($series) <= self::MAX_SERIES) {
            return array_values(array_map(
                fn (array $s, int $i) => [...$s, 'slot' => $i],
                $series,
                range(0, max(0, count($series) - 1))
            ));
        }

        // Keep the largest series (by total), coloured in their original (stable) order.
        $totals = array_map(fn (array $s) => array_sum(array_map('floatval', $s['data'])), $series);
        arsort($totals);
        $kept = array_slice(array_keys($totals), 0, self::MAX_SERIES - 1, true);

        $result = [];
        $other = null;
        $slot = 0;
        foreach ($series as $id => $s) {
            if (in_array($id, $kept, true)) {
                $result[] = [...$s, 'slot' => $slot++];

                continue;
            }

            $other = $other === null
                ? $s['data']
                : array_map(fn ($a, $b) => ($a ?? 0) + ($b ?? 0), $other, $s['data']);
        }

        $result[] = ['label' => $otherLabel, 'data' => $other ?? [], 'slot' => self::OTHER];

        return $result;
    }

    /**
     * @param  list<int|float|null>  $data
     * @return array{label: string, data: list<int|float|null>, slot: int|string}
     */
    public static function one(string $label, array $data, int|string $slot = 0): array
    {
        return ['label' => $label, 'data' => $data, 'slot' => $slot];
    }
}
