<?php

namespace App\Queries;

/**
 * Immutable set of filters and sort options for the employee directory
 * (shared by the Livewire list and the bulk export).
 */
final class EmployeeFilters
{
    public const SORTABLE = ['emp_no', 'name', 'department', 'title', 'salary', 'hire_date', 'gender'];

    public const STATUSES = ['all', 'current', 'former'];

    public function __construct(
        public readonly string $search = '',
        public readonly string $employmentStatus = 'all',
        public readonly string $gender = 'all',
        public readonly string $department = 'all',
        public readonly string $jobTitle = 'all',
        public readonly int|float|null $salaryMin = null,
        public readonly int|float|null $salaryMax = null,
        public readonly string $sortBy = 'emp_no',
        public readonly string $sortDirection = 'asc',
    ) {
    }

    /**
     * Build from loosely typed input (request data or Livewire properties).
     * Unknown, empty or non-scalar values (e.g. `sortBy[]=x` in a request) fall back to
     * "no filter"; sort options are whitelisted.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $status = self::text($input['employmentStatus'] ?? null, 'all');
        $gender = self::text($input['gender'] ?? null, 'all');
        $sortBy = self::text($input['sortBy'] ?? null, 'emp_no');
        $direction = strtolower(self::text($input['sortDirection'] ?? null, 'asc'));

        return new self(
            search: self::text($input['search'] ?? null, ''),
            employmentStatus: in_array($status, self::STATUSES, true) ? $status : 'all',
            gender: in_array($gender, ['M', 'F'], true) ? $gender : 'all',
            department: self::optionOrAll($input['department'] ?? null),
            jobTitle: self::optionOrAll($input['jobTitle'] ?? null),
            salaryMin: self::amount($input['salaryMin'] ?? null),
            salaryMax: self::amount($input['salaryMax'] ?? null),
            sortBy: in_array($sortBy, self::SORTABLE, true) ? $sortBy : 'emp_no',
            sortDirection: $direction === 'desc' ? 'desc' : 'asc',
        );
    }

    public function hasSearch(): bool
    {
        return $this->search !== '';
    }

    public function filtersByDepartment(): bool
    {
        return $this->department !== 'all';
    }

    public function filtersByTitle(): bool
    {
        return $this->jobTitle !== 'all';
    }

    public function filtersBySalary(): bool
    {
        return $this->salaryMin !== null || $this->salaryMax !== null;
    }

    /**
     * Scalar input as a string; anything else (null, arrays, objects) is $default.
     */
    private static function text(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    private static function optionOrAll(mixed $value): string
    {
        $value = self::text($value, '');

        return $value === '' ? 'all' : $value;
    }

    /**
     * Salary bounds: empty, zero or non-numeric input means "no bound".
     */
    private static function amount(mixed $value): int|float|null
    {
        if (! is_numeric($value) || (float) $value == 0) {
            return null;
        }

        return $value + 0;
    }
}
