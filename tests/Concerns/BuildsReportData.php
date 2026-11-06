<?php

namespace Tests\Concerns;

use App\Models\Department;
use App\Models\DeptEmp;
use App\Models\Employee;
use App\Models\Salary;
use App\Models\Title;

/**
 * A small company with an exactly known history for the report tests:
 *
 *   emp  gender  born        hired       departments                                   titles                    salaries
 *   E1   F       1960-06-15  1990-01-01  Sales 1990-01-01 ->                           Staff                     40000 (1990), 50000 (1991 ->)
 *   E2   M       1970-01-01  1990-06-01  Sales -> 1992-03-01, Development ->           Engineer                  60000 (1990-06), 70000 (1992-06 ->)
 *   E3   M       1965-03-01  1991-02-01  Development 1991-02-01 -> 1993-07-01 (left)   Engineer (until leaving)  55000
 *   E4   F       1980-12-31  1993-01-01  Development ->                                Senior Engineer           80000
 *   E5   M       1975-05-05  1994-01-01  Development ->                                Engineer                  65000
 *
 * ("->" = still open, to_date 9999-01-01)
 */
trait BuildsReportData
{
    protected Department $sales;

    protected Department $development;

    /** @var array<string, Employee> */
    protected array $people = [];

    protected function buildReportData(): void
    {
        $this->sales = Department::factory()->create(['dept_no' => 'd001', 'dept_name' => 'Sales']);
        $this->development = Department::factory()->create(['dept_no' => 'd002', 'dept_name' => 'Development']);

        $open = '9999-01-01';

        $this->person('E1', 'F', '1960-06-15', '1990-01-01',
            [['d001', '1990-01-01', $open]],
            [['Staff', '1990-01-01', $open]],
            [[40000, '1990-01-01', '1991-01-01'], [50000, '1991-01-01', $open]]);

        $this->person('E2', 'M', '1970-01-01', '1990-06-01',
            [['d001', '1990-06-01', '1992-03-01'], ['d002', '1992-03-01', $open]],
            [['Engineer', '1990-06-01', $open]],
            [[60000, '1990-06-01', '1992-06-01'], [70000, '1992-06-01', $open]]);

        $this->person('E3', 'M', '1965-03-01', '1991-02-01',
            [['d002', '1991-02-01', '1993-07-01']],
            [['Engineer', '1991-02-01', '1993-07-01']],
            [[55000, '1991-02-01', '1993-07-01']]);

        $this->person('E4', 'F', '1980-12-31', '1993-01-01',
            [['d002', '1993-01-01', $open]],
            [['Senior Engineer', '1993-01-01', $open]],
            [[80000, '1993-01-01', $open]]);

        $this->person('E5', 'M', '1975-05-05', '1994-01-01',
            [['d002', '1994-01-01', $open]],
            [['Engineer', '1994-01-01', $open]],
            [[65000, '1994-01-01', $open]]);
    }

    /**
     * @param  list<array{string, string, string}>  $departments  [dept_no, from, to]
     * @param  list<array{string, string, string}>  $titles  [title, from, to]
     * @param  list<array{int, string, string}>  $salaries  [salary, from, to]
     */
    private function person(string $name, string $gender, string $born, string $hired, array $departments, array $titles, array $salaries): Employee
    {
        $employee = Employee::factory()->create([
            'first_name' => $name,
            'gender' => $gender,
            'birth_date' => $born,
            'hire_date' => $hired,
        ]);

        foreach ($departments as [$deptNo, $from, $to]) {
            DeptEmp::create(['emp_no' => $employee->emp_no, 'dept_no' => $deptNo, 'from_date' => $from, 'to_date' => $to]);
        }
        foreach ($titles as [$title, $from, $to]) {
            Title::create(['emp_no' => $employee->emp_no, 'title' => $title, 'from_date' => $from, 'to_date' => $to]);
        }
        foreach ($salaries as [$salary, $from, $to]) {
            Salary::create(['emp_no' => $employee->emp_no, 'salary' => $salary, 'from_date' => $from, 'to_date' => $to]);
        }

        return $this->people[$name] = $employee;
    }
}
