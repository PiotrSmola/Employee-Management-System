<?php

namespace Tests\Concerns;

use App\Models\Department;
use App\Models\Employee;

/**
 * A small, fully deterministic employee directory used by the query, list and
 * export tests.
 *
 *  emp_no  name         G  state                                    current salary
 *  1       Anna Smith   F  Alpha / Engineer (hired 1990-03-01)       50000
 *  2       Bob Jones    M  Omega / Manager                           90000
 *  3       Carl Smith   M  FORMER, last dept Mid (was Alpha),         -
 *                          last title Staff (was Engineer)
 *  4       Dana Brown   F  FORMER, last dept Alpha / Manager          -
 *  5       Eve Nobody   F  no history at all                          -
 *  6       Anna Smith   M  Alpha / Engineer (hired 1990-03-01)       50000
 *  7       Hank Lee     M  Mid (was Omega) / Senior Staff (was Staff) 70000
 */
trait BuildsDirectory
{
    protected Department $alpha;

    protected Department $mid;

    protected Department $omega;

    protected function buildDirectory(): void
    {
        $this->alpha = Department::factory()->create(['dept_name' => 'Alpha']);
        $this->mid = Department::factory()->create(['dept_name' => 'Mid']);
        $this->omega = Department::factory()->create(['dept_name' => 'Omega']);

        $make = fn (int $no, string $first, string $last, string $gender, string $hired) => [
            'emp_no' => $no, 'first_name' => $first, 'last_name' => $last, 'gender' => $gender, 'hire_date' => $hired,
        ];

        Employee::factory()->current($this->alpha, 'Engineer', 50000)
            ->create($make(1, 'Anna', 'Smith', 'F', '1990-03-01'));
        Employee::factory()->current($this->omega, 'Manager', 90000)
            ->create($make(2, 'Bob', 'Jones', 'M', '1985-06-01'));
        Employee::factory()->former($this->mid, 'Staff', '1995-05-05', 60000, previousDepartment: $this->alpha, previousTitle: 'Engineer')
            ->create($make(3, 'Carl', 'Smith', 'M', '1988-01-01'));
        Employee::factory()->former($this->alpha, 'Manager', '1998-08-08', 80000)
            ->create($make(4, 'Dana', 'Brown', 'F', '1992-02-02'));
        Employee::factory()
            ->create($make(5, 'Eve', 'Nobody', 'F', '1999-09-09'));
        Employee::factory()->current($this->alpha, 'Engineer', 50000)
            ->create($make(6, 'Anna', 'Smith', 'M', '1990-03-01'));
        Employee::factory()->current($this->mid, 'Senior Staff', 70000, previousDepartment: $this->omega, previousTitle: 'Staff')
            ->create($make(7, 'Hank', 'Lee', 'M', '1991-01-01'));
    }
}
