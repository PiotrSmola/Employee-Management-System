<?php

namespace App\Reports;

use App\Models\Department;
use App\Support\EmployeeVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * What a report is computed for: who asks (their visibility), which department
 * and on which date.
 *
 *  - department: the department filter. Managers are always limited to their own
 *    department (the filter is forced to it) and, on top of that, every query is
 *    restricted to the employees they may see (EmployeeVisibility::constrain()).
 *  - as-of date: the state of the company on that day. A history row counts on
 *    day D when from_date <= D < to_date (to_date 9999-01-01 = still open).
 *    Today or later means "current". A date before the first hire is empty and
 *    runs no query. Dates from a request are moved to the end of their month, which
 *    bounds how many cached versions of a report can exist.
 */
final class ReportContext
{
    /** Earliest selectable as-of year. */
    public const EARLIEST_YEAR = 1900;

    private function __construct(
        public readonly EmployeeVisibility $visibility,
        public readonly ?string $department,
        public readonly CarbonImmutable $asOf,
        public readonly bool $current,
        private readonly bool $beforeHistory,
    ) {}

    public static function make(EmployeeVisibility $visibility, ?string $department = null, ?CarbonImmutable $asOf = null): self
    {
        if ($visibility->isDepartmentScoped()) {
            $department = $visibility->department();
        }

        $today = CarbonImmutable::today();
        $current = $asOf === null || $asOf->startOfDay()->gte($today);
        $date = $current ? $today : $asOf->startOfDay();
        $earliest = $current ? null : CompanyHistory::earliestHireDate();

        return new self($visibility, $department, $date, $current, $earliest !== null && $date->toDateString() < $earliest);
    }

    /**
     * Built from request input (`department`, `as_of`). Invalid values are
     * ignored and reported in $errors, so a bad link still shows the reports.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $errors
     */
    public static function fromInput(EmployeeVisibility $visibility, array $input, array &$errors = []): self
    {
        $department = null;
        $rawDepartment = $input['department'] ?? null;

        // A manager's department is forced in make(), whatever the request says.
        if (! $visibility->isDepartmentScoped() && $rawDepartment !== null && $rawDepartment !== '') {
            // The database value: the column's collation also matches "D005" and "d005 ".
            $department = is_string($rawDepartment) ? Department::find($rawDepartment)?->dept_no : null;

            if ($department === null) {
                $errors['department'] = __('Unknown department - showing all departments.');
            }
        }

        $asOf = null;
        $rawDate = $input['as_of'] ?? null;
        if ($rawDate !== null && $rawDate !== '') {
            $asOf = is_string($rawDate) ? self::parseDate($rawDate)?->endOfMonth()->startOfDay() : null;

            if ($asOf === null) {
                $errors['as_of'] = __('Invalid date - showing the current state.');
            }
        }

        return self::make($visibility, $department, $asOf);
    }

    /** A real calendar date in the format Y-m-d, or null. */
    public static function parseDate(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->format('Y-m-d') === $value && $date->year >= self::EARLIEST_YEAR ? $date : null;
    }

    /** A manager without a department sees nobody - every report is empty. */
    public function seesNobody(): bool
    {
        return $this->visibility->isDepartmentScoped() && $this->visibility->department() === null;
    }

    /** The as-of date is before the first hire. */
    public function isBeforeHistory(): bool
    {
        return $this->beforeHistory;
    }

    /** Nothing to compute: every report of this context is empty, no query runs. */
    public function isEmpty(): bool
    {
        return $this->beforeHistory || $this->seesNobody();
    }

    public function isDepartmentScoped(): bool
    {
        return $this->visibility->isDepartmentScoped();
    }

    /** The as-of date for SQL (Y-m-d). */
    public function date(): string
    {
        return $this->asOf->toDateString();
    }

    /**
     * Cache key part of the department scope: all | dept-d005 | mgr-d005 | mgr--
     * (manager results differ from the plain filter: constrained to visible employees).
     */
    public function scopeKey(): string
    {
        if ($this->visibility->isDepartmentScoped()) {
            return 'mgr-'.($this->department ?? EmployeeVisibility::NO_DEPARTMENT);
        }

        return $this->department === null ? 'all' : 'dept-'.$this->department;
    }

    /**
     * Cache key part of the date: current-<today> | Y-m-d. Today is part of the key
     * because ages and tenures of the current state change with the date.
     */
    public function asOfKey(): string
    {
        return ($this->current ? 'current-' : '').$this->date();
    }

    /** Query string to reproduce this context (links, CSV downloads). */
    public function query(): array
    {
        return array_filter([
            'department' => $this->visibility->isDepartmentScoped() ? null : $this->department,
            'as_of' => $this->current ? null : $this->date(),
        ]);
    }

    /**
     * Restrict a query over employees to the ones the user may see.
     *
     * @template T of Builder|EloquentBuilder
     *
     * @param  T  $query
     * @return T
     */
    public function constrain(Builder|EloquentBuilder $query, string $empNoColumn): Builder|EloquentBuilder
    {
        return $this->visibility->constrain($query, $empNoColumn);
    }
}
