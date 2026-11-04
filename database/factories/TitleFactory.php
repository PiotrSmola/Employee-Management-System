<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A single (current by default) title row. For complete histories prefer
 * Employee::factory()->current() / ->former().
 *
 * @extends Factory<Title>
 */
class TitleFactory extends Factory
{
    public const TITLES = [
        'Assistant Engineer', 'Engineer', 'Manager', 'Senior Engineer', 'Senior Staff', 'Staff', 'Technique Leader',
    ];

    protected $model = Title::class;

    public function definition(): array
    {
        return [
            'emp_no' => Employee::factory(),
            'title' => fake()->randomElement(self::TITLES),
            'from_date' => fake()->dateTimeBetween('1985-01-01', '2000-12-31')->format('Y-m-d'),
            'to_date' => EmployeeFactory::CURRENT,
        ];
    }

    public function ended(string $toDate): static
    {
        return $this->state(fn () => ['to_date' => $toDate]);
    }
}
