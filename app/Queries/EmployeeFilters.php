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
     * Unknown or empty values fall back to "no filter"; sort options are whitelisted.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $status = (string) ($input['employmentStatus'] ?? 'all');
        $gender = (string) ($input['gender'] ?? 'all');
        $sortBy = (string) ($input['sortBy'] ?? 'emp_no');
        $direction = strtolower((string) ($input['sortDirection'] ?? 'asc'));

        return new self(
            search: (string) ($input['search'] ?? ''),
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

    private static function optionOrAll(mixed $value): string
    {
        $value = (string) ($value ?? '');

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
