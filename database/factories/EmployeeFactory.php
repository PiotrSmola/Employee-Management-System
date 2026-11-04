<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\DeptEmp;
use App\Models\Employee;
use App\Models\Salary;
use App\Models\Title;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Employees, optionally with a consistent employment history:
 *
 *   Employee::factory()->current($department, 'Engineer')->create();
 *   Employee::factory()->former($department, 'Staff', endDate: '1999-06-30')->create();
 *   Employee::factory()->current($newDept, 'Senior Staff', previousDepartment: $oldDept, previousTitle: 'Staff')->create();
 *
 * A history consists of dept_emp, titles and yearly salaries rows that start at
 * the hire date and end with to_date = 9999-01-01 (current employee) or at the
 * end date (former employee).
 *
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public const CURRENT = '9999-01-01';

    protected $model = Employee::class;

    private static int $nextEmpNo = 10001;

    public function definition(): array
    {
        return [
            'emp_no' => self::$nextEmpNo++,
            'birth_date' => fake()->dateTimeBetween('1952-01-01', '1965-12-31')->format('Y-m-d'),
            'first_name' => mb_substr(fake()->firstName(), 0, 14),
            'last_name' => mb_substr(fake()->lastName(), 0, 16),
            'gender' => fake()->randomElement(['M', 'F']),
            'hire_date' => fake()->dateTimeBetween('1985-01-01', '1999-12-31')->format('Y-m-d'),
        ];
    }

    /**
     * Currently employed in $department (created when omitted) with $title and
     * current $salary; optionally after an earlier department and/or title.
     */
    public function current(
        ?Department $department = null,
        string $title = 'Staff',
        ?int $salary = null,
        ?Department $previousDepartment = null,
        ?string $previousTitle = null,
    ): static {
        return $this->withHistory($department, $title, null, $salary, $previousDepartment, $previousTitle);
    }

    /**
     * Left the company on $endDate (default: a few years after the hire date);
     * $department / $title / $salary become the *last* ones.
     */
    public function former(
        ?Department $department = null,
        string $title = 'Staff',
        ?string $endDate = null,
        ?int $salary = null,
        ?Department $previousDepartment = null,
        ?string $previousTitle = null,
    ): static {
        return $this->withHistory($department, $title, $endDate ?? false, $salary, $previousDepartment, $previousTitle);
    }

    /**
     * @param  string|false|null  $endDate  null = current employee, false = former with a generated end date
     */
    private function withHistory(
        ?Department $department,
        string $title,
        string|false|null $endDate,
        ?int $salary,
        ?Department $previousDepartment,
        ?string $previousTitle,
    ): static {
        return $this->afterCreating(function (Employee $employee) use ($department, $title, $endDate, $salary, $previousDepartment, $previousTitle) {
            $hired = CarbonImmutable::parse($employee->hire_date);
            $end = match (true) {
                $endDate === null => null,
                $endDate === false => $hired->addDays(fake()->numberBetween(400, 3000)),
                default => CarbonImmutable::parse($endDate),
            };

            self::createHistory(
                $employee,
                $department ?? Department::factory()->create(),
                $title,
                $end,
                $salary ?? fake()->numberBetween(40000, 90000),
                $previousDepartment?->dept_no,
                $previousTitle,
            );
        });
    }

    /**
     * Write dept_emp, titles and salaries rows for $employee.
     * $end = null means the employee is still employed (to_date 9999-01-01).
     */
    public static function createHistory(
        Employee $employee,
        Department $department,
        string $title,
        ?CarbonImmutable $end,
        int $finalSalary,
        ?string $previousDeptNo = null,
        ?string $previousTitle = null,
    ): void {
        $hired = CarbonImmutable::parse($employee->hire_date);
        $last = $end ?? CarbonImmutable::parse('2002-08-01');
        $finalTo = $end?->toDateString() ?? self::CURRENT;
        $middle = $hired->addDays(max(1, intdiv((int) $hired->diffInDays($last), 2)));

        // Departments
        if ($previousDeptNo !== null && $previousDeptNo !== $department->dept_no) {
            DeptEmp::create(['emp_no' => $employee->emp_no, 'dept_no' => $previousDeptNo,
                'from_date' => $hired->toDateString(), 'to_date' => $middle->toDateString()]);
            DeptEmp::create(['emp_no' => $employee->emp_no, 'dept_no' => $department->dept_no,
                'from_date' => $middle->toDateString(), 'to_date' => $finalTo]);
        } else {
            DeptEmp::create(['emp_no' => $employee->emp_no, 'dept_no' => $department->dept_no,
                'from_date' => $hired->toDateString(), 'to_date' => $finalTo]);
        }

        // Titles
        if ($previousTitle !== null && $previousTitle !== $title) {
            Title::create(['emp_no' => $employee->emp_no, 'title' => $previousTitle,
                'from_date' => $hired->toDateString(), 'to_date' => $middle->toDateString()]);
            Title::create(['emp_no' => $employee->emp_no, 'title' => $title,
                'from_date' => $middle->toDateString(), 'to_date' => $finalTo]);
        } else {
            Title::create(['emp_no' => $employee->emp_no, 'title' => $title,
                'from_date' => $hired->toDateString(), 'to_date' => $finalTo]);
        }

        // Yearly salaries, growing ~3% a year up to $finalSalary.
        $years = max(1, (int) floor($hired->diffInYears($last)) + 1);
        for ($i = 0; $i < $years; $i++) {
            $from = $hired->addYears($i);
            $isLast = $i === $years - 1;
            Salary::create([
                'emp_no' => $employee->emp_no,
                'salary' => (int) round($finalSalary / (1.03 ** ($years - 1 - $i))),
                'from_date' => $from->toDateString(),
                'to_date' => $isLast ? $finalTo : $from->addYear()->toDateString(),
            ]);
        }
    }
}
