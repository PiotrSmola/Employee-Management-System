<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Salary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A single (current by default) salary row. For complete histories prefer
 * Employee::factory()->current() / ->former().
 *
 * @extends Factory<Salary>
 */
class SalaryFactory extends Factory
{
    protected $model = Salary::class;

    public function definition(): array
    {
        return [
            'emp_no' => Employee::factory(),
            'salary' => fake()->numberBetween(40000, 120000),
            'from_date' => fake()->dateTimeBetween('1985-01-01', '2001-12-31')->format('Y-m-d'),
            'to_date' => EmployeeFactory::CURRENT,
        ];
    }

    public function ended(string $toDate): static
    {
        return $this->state(fn () => ['to_date' => $toDate]);
    }
}
