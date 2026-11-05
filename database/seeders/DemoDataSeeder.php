<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\DeptManager;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Small, self-consistent demo dataset in the shape of the "employees" sample
 * database: the 9 original departments, ~200 employees (about 80% current,
 * 20% former) with department, title and salary histories (including some
 * transfers and promotions) and one current manager per department.
 */
class DemoDataSeeder extends Seeder
{
    private const DEPARTMENTS = [
        'd001' => 'Marketing',
        'd002' => 'Finance',
        'd003' => 'Human Resources',
        'd004' => 'Production',
        'd005' => 'Development',
        'd006' => 'Quality Management',
        'd007' => 'Sales',
        'd008' => 'Research',
        'd009' => 'Customer Service',
    ];

    /** Typical career steps (junior title => senior title) per department. */
    private const CAREERS = [
        'd001' => ['Staff', 'Senior Staff'],
        'd002' => ['Staff', 'Senior Staff'],
        'd003' => ['Staff', 'Senior Staff'],
        'd004' => ['Engineer', 'Senior Engineer'],
        'd005' => ['Engineer', 'Senior Engineer'],
        'd006' => ['Assistant Engineer', 'Engineer'],
        'd007' => ['Staff', 'Senior Staff'],
        'd008' => ['Senior Engineer', 'Technique Leader'],
        'd009' => ['Staff', 'Senior Staff'],
    ];

    public function run(int $employees = 200): void
    {
        // Faker draws from PHP's global mt_rand state, and Faker\Generator::__destruct()
        // reseeds it randomly. Collect pending garbage first (e.g. generators of earlier
        // application instances in a test run) so that no destructor reseeds mid-run.
        gc_collect_cycles();
        fake()->seed(20021);

        DB::transaction(function () use ($employees) {
            $departments = collect(self::DEPARTMENTS)->map(fn ($name, $no) => Department::firstOrCreate(
                ['dept_no' => $no],
                ['dept_name' => $name]
            ));

            // One current manager per department.
            foreach ($departments as $deptNo => $department) {
                $manager = Employee::factory()->current($department, 'Manager', fake()->numberBetween(90000, 130000), previousTitle: self::CAREERS[$deptNo][1])->create();
                DeptManager::create([
                    'emp_no' => $manager->emp_no,
                    'dept_no' => $deptNo,
                    'from_date' => CarbonImmutable::parse($manager->hire_date)->addYears(2)->toDateString(),
                    'to_date' => EmployeeFactory::CURRENT,
                ]);
            }

            for ($i = $departments->count(); $i < $employees; $i++) {
                $deptNo = fake()->randomElement(array_keys(self::DEPARTMENTS));
                [$junior, $senior] = self::CAREERS[$deptNo];
                $promoted = fake()->boolean(40);
                $transferred = fake()->boolean(15);

                $args = [
                    'department' => $departments[$deptNo],
                    'title' => $promoted ? $senior : $junior,
                    'salary' => fake()->numberBetween(40000, $promoted ? 110000 : 80000),
                    // Picked with the seeded faker (Collection::random() is not reproducible).
                    'previousDepartment' => $transferred
                        ? $departments[fake()->randomElement(array_values(array_diff(array_keys(self::DEPARTMENTS), [$deptNo])))]
                        : null,
                    'previousTitle' => $promoted ? $junior : null,
                ];

                $factory = fake()->boolean(80)
                    ? Employee::factory()->current(...$args)
                    : Employee::factory()->former(...$args);

                $factory->create();
            }
        });
    }
}
