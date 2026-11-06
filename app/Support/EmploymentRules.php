<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Plain rules shared by the HR operations (App\Services\EmployeeOperationsService)
 * and their form requests - no database access, unit tested on their own.
 *
 * Dates are 'Y-m-d' strings; in the dataset a period ends on the day the next
 * one starts (old.to_date = new.from_date) and to_date = 9999-01-01 marks the
 * current row.
 */
final class EmploymentRules
{
    public const CURRENT = '9999-01-01';

    /** Plausible age on the hire date. */
    public const MIN_AGE_AT_HIRE = 16;

    public const MAX_AGE_AT_HIRE = 80;

    /** Earliest accepted birth date. */
    public const MIN_BIRTH_DATE = '1900-01-01';

    /** Accepted salary range (salaries.salary is an INT). */
    public const MIN_SALARY = 1;

    public const MAX_SALARY = 10_000_000;

    /** Accepted raise / cut in percent. */
    public const MIN_PERCENT = -50;

    public const MAX_PERCENT = 100;

    /** Column sizes of the dataset. */
    public const FIRST_NAME_MAX = 14;

    public const LAST_NAME_MAX = 16;

    public const TITLE_MAX = 50;

    /**
     * Full years between $birthDate and $date.
     */
    public static function ageOn(string $birthDate, string $date): int
    {
        return (int) CarbonImmutable::parse($birthDate)->diffInYears(CarbonImmutable::parse($date));
    }

    public static function isPlausibleAgeAtHire(string $birthDate, string $hireDate): bool
    {
        if ($hireDate <= $birthDate) {
            return false;
        }

        $age = self::ageOn($birthDate, $hireDate);

        return $age >= self::MIN_AGE_AT_HIRE && $age <= self::MAX_AGE_AT_HIRE;
    }

    /**
     * $salary changed by $percent (e.g. 5 = +5 %, -10 = -10 %), rounded to whole units.
     */
    public static function applyPercent(int $salary, float $percent): int
    {
        return (int) round($salary * (100 + $percent) / 100);
    }

    /**
     * The change from $before to $after in percent, two decimals (null for a zero base).
     */
    public static function percentChange(int $before, int $after): ?float
    {
        return $before === 0 ? null : round(($after - $before) / $before * 100, 2);
    }

    /**
     * Is the change from $before to $after outside the usual range (MIN_PERCENT to MAX_PERCENT)?
     */
    public static function isUnusualChange(int $before, int $after): bool
    {
        if ($before === 0) {
            return false;
        }

        // Compared unrounded: 29,999 from 60,000 is -50.0017 %, i.e. beyond -50 %.
        $percent = ($after - $before) / $before * 100;

        return $percent < self::MIN_PERCENT || $percent > self::MAX_PERCENT;
    }

    public static function dayAfter(string $date): string
    {
        return CarbonImmutable::parse($date)->addDay()->toDateString();
    }

    /**
     * The latest of the given dates (nulls ignored), or null.
     */
    public static function latest(?string ...$dates): ?string
    {
        $dates = array_filter($dates, fn (?string $date) => $date !== null && $date !== '');

        return $dates === [] ? null : max($dates);
    }

    public static function today(): string
    {
        return CarbonImmutable::today()->toDateString();
    }

    public static function display(?string $date): string
    {
        return $date === null ? '-' : CarbonImmutable::parse($date)->format('d.m.Y');
    }
}
