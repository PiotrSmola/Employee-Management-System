<?php

namespace Tests\Unit;

use App\Queries\EmployeeFilters;
use PHPUnit\Framework\TestCase;

class EmployeeFiltersTest extends TestCase
{
    public function test_defaults_mean_no_filtering(): void
    {
        $filters = EmployeeFilters::fromArray([]);

        $this->assertSame('', $filters->search);
        $this->assertSame('all', $filters->employmentStatus);
        $this->assertSame('all', $filters->department);
        $this->assertNull($filters->salaryMin);
        $this->assertSame('emp_no', $filters->sortBy);
        $this->assertSame('asc', $filters->sortDirection);
    }

    public function test_untrusted_input_is_normalized(): void
    {
        $filters = EmployeeFilters::fromArray([
            'employmentStatus' => 'bogus',
            'gender' => 'X',
            'department' => '',
            'salaryMin' => 'abc',
            'salaryMax' => '80000',
            'sortBy' => 'salary; DROP TABLE employees',
            'sortDirection' => 'DESC',
        ]);

        $this->assertSame('all', $filters->employmentStatus);
        $this->assertSame('all', $filters->gender);
        $this->assertSame('all', $filters->department);
        $this->assertNull($filters->salaryMin);
        $this->assertSame(80000, $filters->salaryMax);
        $this->assertSame('emp_no', $filters->sortBy);
        $this->assertSame('desc', $filters->sortDirection);
    }
}
