<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    private static int $next = 1;

    public function definition(): array
    {
        $n = self::$next++;

        return [
            'dept_no' => sprintf('d%03d', 100 + $n % 900),
            'dept_name' => mb_substr(ucfirst(fake()->word()).' '.$n, 0, 40),
        ];
    }
}
