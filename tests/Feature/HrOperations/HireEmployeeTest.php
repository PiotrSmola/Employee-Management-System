<?php

namespace Tests\Feature\HrOperations;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Services\EmployeeNumberAllocator;
use App\Services\EmployeeOperationsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class HireEmployeeTest extends HrOperationsTestCase
{
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create(['dept_name' => 'Research']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'Maria',
            'last_name' => 'Curie',
            'gender' => 'F',
            'birth_date' => '1990-04-12',
            'hire_date' => '2024-02-01',
            'dept_no' => $this->department->dept_no,
            'title' => 'Engineer',
            'salary' => '65000',
            ...$overrides,
        ];
    }

    public function test_the_form_is_shown_with_departments_and_title_suggestions(): void
    {
        $this->currentEmployee($this->department, 'Senior Engineer');

        $this->get(route('employees.create'))
            ->assertOk()
            ->assertSee('Add employee')
            ->assertSee('Research')
            ->assertSee('<option value="Senior Engineer">', false);
    }

    public function test_hiring_creates_the_employee_with_a_current_history_and_the_next_number(): void
    {
        $existing = $this->currentEmployee();

        $response = $this->post(route('employees.store'), $this->payload());

        $employee = Employee::where('last_name', 'Curie')->firstOrFail();
        $response->assertRedirect(route('employees.show', $employee->emp_no))->assertSessionHas('status');

        $this->assertSame($existing->emp_no + 1, $employee->emp_no);
        $this->assertSame(['first_name' => 'Maria', 'gender' => 'F', 'birth_date' => '1990-04-12', 'hire_date' => '2024-02-01'],
            $employee->only(['first_name', 'gender', 'birth_date', 'hire_date']));

        $period = ['from_date' => '2024-02-01', 'to_date' => self::CURRENT];
        $this->assertSame([['dept_no' => $this->department->dept_no, ...$period]], $this->rows('dept_emp', $employee->emp_no, ['dept_no', 'from_date', 'to_date']));
        $this->assertSame([['title' => 'Engineer', ...$period]], $this->rows('titles', $employee->emp_no, ['title', 'from_date', 'to_date']));
        $this->assertSame([['salary' => 65000, ...$period]], $this->rows('salaries', $employee->emp_no, ['salary', 'from_date', 'to_date']));

        $entry = $this->onlyEntry('employee.hired');
        $this->assertSame('employee', $entry->subject_type);
        $this->assertSame((string) $employee->emp_no, $entry->subject_id);
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertEquals([
            'first_name' => 'Maria', 'last_name' => 'Curie', 'gender' => 'F', 'birth_date' => '1990-04-12',
            'hire_date' => '2024-02-01', 'dept_no' => $this->department->dept_no, 'title' => 'Engineer', 'salary' => 65000,
        ], $entry->changes['after']);

        $this->get(route('employees.show', $employee->emp_no))
            ->assertOk()
            ->assertSee('Maria Curie')
            ->assertSee('has been hired')
            ->assertSee('65,000');
    }

    public function test_the_first_number_of_an_empty_table_is_10001(): void
    {
        $this->post(route('employees.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(10001, Employee::sole()->emp_no);
    }

    public function test_names_are_trimmed(): void
    {
        $this->post(route('employees.store'), $this->payload(['first_name' => '  Maria ', 'title' => ' Engineer ']))->assertSessionHasNoErrors();

        $employee = Employee::sole();
        $this->assertSame('Maria', $employee->first_name);
        $this->assertSame('Engineer', $this->current('titles', $employee->emp_no)->title);
    }

    public function test_hiring_flushes_the_cached_figures_of_the_department(): void
    {
        $other = Department::factory()->create();
        $this->primeCache([$this->department->dept_no, $other->dept_no]);

        $this->post(route('employees.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertCacheFlushed([$this->department->dept_no]);
        $this->assertCacheKept([$other->dept_no]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidProvider(): array
    {
        $tomorrow = CarbonImmutable::tomorrow()->toDateString();

        return [
            'missing first name' => [['first_name' => ''], 'first_name'],
            'first name too long' => [['first_name' => str_repeat('a', 15)], 'first_name'],
            'last name too long' => [['last_name' => str_repeat('b', 17)], 'last_name'],
            'unknown gender' => [['gender' => 'X'], 'gender'],
            'hire date in the future' => [['hire_date' => $tomorrow], 'hire_date'],
            'hire date not a date' => [['hire_date' => '01.02.2024'], 'hire_date'],
            'born after the hire date' => [['birth_date' => '2024-03-01'], 'birth_date'],
            'too young on the hire date' => [['birth_date' => '2010-01-01'], 'birth_date'],
            'too old on the hire date' => [['birth_date' => '1930-01-01'], 'birth_date'],
            'born before 1900' => [['birth_date' => '1899-12-31'], 'birth_date'],
            'unknown department' => [['dept_no' => 'x999'], 'dept_no'],
            'missing title' => [['title' => ' '], 'title'],
            'title too long' => [['title' => str_repeat('t', 51)], 'title'],
            'zero salary' => [['salary' => '0'], 'salary'],
            'salary not a number' => [['salary' => 'lots'], 'salary'],
            'salary too high' => [['salary' => '10000001'], 'salary'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_invalid_data_is_rejected(array $overrides, string $field): void
    {
        $this->from(route('employees.create'))
            ->post(route('employees.store'), $this->payload($overrides))
            ->assertRedirect(route('employees.create'))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_a_number_taken_meanwhile_is_retried_without_leaving_partial_rows(): void
    {
        $taken = $this->currentEmployee();
        $this->app->instance(EmployeeNumberAllocator::class, new class($taken->emp_no) extends EmployeeNumberAllocator
        {
            public int $calls = 0;

            public function __construct(private readonly int $taken)
            {
            }

            public function next(): int
            {
                // First answer: a number another writer has just used; then the real next one.
                return $this->calls++ === 0 ? $this->taken : parent::next();
            }
        });

        $employee = app(EmployeeOperationsService::class)->hire($this->payload());

        $this->assertSame($taken->emp_no + 1, $employee->emp_no);
        $this->assertSame(2, app(EmployeeNumberAllocator::class)->calls);
        $this->assertSame('Anna', $taken->fresh()->first_name);
        $this->assertSame(1, DB::table('dept_emp')->where('emp_no', $employee->emp_no)->count());
        $this->assertSame(1, DB::table('activity_log')->where('action', 'employee.hired')->count());
    }

    public function test_after_three_collisions_the_error_is_reported(): void
    {
        $taken = $this->currentEmployee();
        $this->app->instance(EmployeeNumberAllocator::class, new class($taken->emp_no) extends EmployeeNumberAllocator
        {
            public function __construct(private readonly int $taken)
            {
            }

            public function next(): int
            {
                return $this->taken;
            }
        });

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        try {
            app(EmployeeOperationsService::class)->hire($this->payload());
        } finally {
            $this->assertSame(1, Employee::count());
            $this->assertSame(0, DB::table('activity_log')->count());
        }
    }

    public function test_the_allocator_reads_the_highest_number_with_a_locking_read(): void
    {
        Employee::factory()->create(['emp_no' => 499999]);
        Employee::factory()->create(['emp_no' => 10001]);

        DB::enableQueryLog();
        $next = DB::transaction(fn () => app(EmployeeNumberAllocator::class)->next());
        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");

        $this->assertSame(500000, $next);
        $this->assertStringContainsString('order by `emp_no` desc limit 1 for update', $sql);
    }

    public function test_the_directory_offers_add_employee_only_to_hr_staff(): void
    {
        $this->get(route('employees.index'))->assertOk()->assertSee(route('employees.create'));

        $this->signInAs(Role::Hr);
        $this->get(route('employees.index'))->assertOk()->assertSee('Add employee');

        $this->signInAs(Role::Viewer);
        $this->get(route('employees.index'))->assertOk()->assertDontSee(route('employees.create'));
    }
}
