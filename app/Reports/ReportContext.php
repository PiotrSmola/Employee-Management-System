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
 *    Today or later means "current" (cached as such, computed for today).
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
    ) {
    }

    public static function make(EmployeeVisibility $visibility, ?string $department = null, ?CarbonImmutable $asOf = null): self
    {
        if ($visibility->isDepartmentScoped()) {
            $department = $visibility->department();
        }

        $today = CarbonImmutable::today();
        $current = $asOf === null || $asOf->startOfDay()->gte($today);

        return new self($visibility, $department, $current ? $today : $asOf->startOfDay(), $current);
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
        $department = is_string($input['department'] ?? null) && $input['department'] !== '' ? $input['department'] : null;

        if ($department !== null && ! $visibility->isDepartmentScoped() && ! Department::whereKey($department)->exists()) {
            $errors['department'] = __('Unknown department - showing all departments.');
            $department = null;
        }

        $asOf = null;
        $raw = $input['as_of'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? CarbonImmutable::createFromFormat('!Y-m-d', $raw) : false;

            if ($parsed === false || $parsed->format('Y-m-d') !== $raw || $parsed->year < self::EARLIEST_YEAR) {
                $errors['as_of'] = __('Invalid date - showing the current state.');
            } else {
                $asOf = $parsed;
            }
        }

        return self::make($visibility, $department, $asOf);
    }

    /** A manager without a department sees nobody - every report is empty. */
    public function seesNobody(): bool
    {
        return $this->visibility->isDepartmentScoped() && $this->visibility->department() === null;
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

    /** Cache key part of the date: current | Y-m-d. */
    public function asOfKey(): string
    {
        return $this->current ? 'current' : $this->date();
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
