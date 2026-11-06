<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Dates in views: @date($employee->hire_date), @date($row->from_date, 'M d, Y').
 */
final class DisplayDate
{
    public const DEFAULT_FORMAT = 'd.m.Y';

    public static function format(CarbonInterface|string|null $date, string $format = self::DEFAULT_FORMAT): string
    {
        return $date === null ? '' : Carbon::parse($date)->format($format);
    }
}
