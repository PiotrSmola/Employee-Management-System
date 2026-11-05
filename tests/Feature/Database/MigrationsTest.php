<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema changes are DDL (implicit commits in MySQL), so these tests cannot run
 * inside the usual rollback transaction. They start from - and leave behind -
 * a freshly migrated test database.
 */
class MigrationsTest extends TestCase
{
    private const DATASET = '2026_11_04_000001_create_employees_dataset_tables';

    private const INDEXES = '2026_11_04_000002_add_directory_indexes_to_history_tables';

    private const EXPECTED_INDEXES = [
        'employees' => ['employees_hire_date_index'],
        'dept_emp' => ['dept_emp_to_date_index', 'dept_emp_dept_no_to_date_index'],
        'titles' => ['titles_to_date_index', 'titles_title_to_date_index'],
        'salaries' => ['salaries_to_date_salary_index'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    private function migration(string $name): object
    {
        return require base_path("database/migrations/{$name}.php");
    }

    private function indexNames(string $table): array
    {
        return array_map(fn ($i) => strtolower($i['name']), Schema::getIndexes($table));
    }

    private function seedOneEmployee(): void
    {
        DB::table('employees')->insert([
            'emp_no' => 1, 'birth_date' => '1960-01-01', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'gender' => 'F', 'hire_date' => '1990-01-01',
        ]);
        DB::table('departments')->insert(['dept_no' => 'd001', 'dept_name' => 'Sales']);
        DB::table('dept_emp')->insert(['emp_no' => 1, 'dept_no' => 'd001', 'from_date' => '1990-01-01', 'to_date' => '9999-01-01']);
        DB::table('titles')->insert(['emp_no' => 1, 'title' => 'Staff', 'from_date' => '1990-01-01', 'to_date' => '9999-01-01']);
        DB::table('salaries')->insert(['emp_no' => 1, 'salary' => 50000, 'from_date' => '1990-01-01', 'to_date' => '9999-01-01']);
    }

    public function test_a_fresh_database_gets_the_whole_dataset_schema(): void
    {
        foreach (['employees', 'departments', 'dept_emp', 'dept_manager', 'titles', 'salaries'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumns('employees', ['emp_no', 'birth_date', 'first_name', 'last_name', 'gender', 'hire_date']));
        $this->assertTrue(Schema::hasColumns('departments', ['dept_no', 'dept_name']));
        $this->assertTrue(Schema::hasColumns('dept_emp', ['emp_no', 'dept_no', 'from_date', 'to_date']));
        $this->assertTrue(Schema::hasColumns('dept_manager', ['emp_no', 'dept_no', 'from_date', 'to_date']));
        $this->assertTrue(Schema::hasColumns('titles', ['emp_no', 'title', 'from_date', 'to_date']));
        $this->assertTrue(Schema::hasColumns('salaries', ['emp_no', 'salary', 'from_date', 'to_date']));
    }

    public function test_foreign_keys_cascade_deletes_like_the_original_dataset(): void
    {
        $this->seedOneEmployee();

        DB::table('employees')->where('emp_no', 1)->delete();

        foreach (['dept_emp', 'titles', 'salaries'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(1, DB::table('departments')->count());
    }

    public function test_history_rows_cannot_reference_unknown_employees_or_departments(): void
    {
        $this->seedOneEmployee();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('dept_emp')->insert(['emp_no' => 999, 'dept_no' => 'd001', 'from_date' => '1990-01-01', 'to_date' => '9999-01-01']);
    }

    public function test_department_names_are_unique(): void
    {
        DB::table('departments')->insert(['dept_no' => 'd001', 'dept_name' => 'Sales']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('departments')->insert(['dept_no' => 'd002', 'dept_name' => 'Sales']);
    }

    public function test_dataset_migration_is_a_no_op_when_the_tables_exist(): void
    {
        $this->seedOneEmployee();
        Schema::table('employees', fn ($t) => $t->string('marker')->nullable()); // would vanish if the table were re-created

        $this->migration(self::DATASET)->up();
        $this->migration(self::DATASET)->up();

        $this->assertTrue(Schema::hasColumn('employees', 'marker'));
        $this->assertSame(1, DB::table('employees')->count());
        $this->assertSame(1, DB::table('salaries')->count());
        $this->assertSame(1, DB::table('dept_emp')->count());
    }

    public function test_dataset_migration_only_creates_the_missing_tables(): void
    {
        $this->seedOneEmployee();
        Schema::drop('salaries');

        $this->migration(self::DATASET)->up();

        $this->assertTrue(Schema::hasTable('salaries'));
        $this->assertSame(0, DB::table('salaries')->count());
        $this->assertSame(1, DB::table('employees')->count());
        $this->assertSame(1, DB::table('titles')->count());
    }

    public function test_dataset_migration_never_drops_data_on_rollback(): void
    {
        $this->seedOneEmployee();

        $this->migration(self::DATASET)->down();

        $this->assertSame(1, DB::table('employees')->count());
        $this->assertTrue(Schema::hasTable('salaries'));
    }

    public function test_index_migration_creates_every_directory_index(): void
    {
        foreach (self::EXPECTED_INDEXES as $table => $names) {
            foreach ($names as $name) {
                $this->assertContains($name, $this->indexNames($table), "$table.$name");
            }
        }
    }

    public function test_index_migration_is_idempotent(): void
    {
        $before = array_map(fn ($t) => $this->indexNames($t), array_keys(self::EXPECTED_INDEXES));

        $this->migration(self::INDEXES)->up();
        $this->migration(self::INDEXES)->up();

        $after = array_map(fn ($t) => $this->indexNames($t), array_keys(self::EXPECTED_INDEXES));
        $this->assertSame($before, $after);
        foreach ($after as $names) {
            $this->assertSame($names, array_values(array_unique($names)));
        }
    }

    public function test_index_migration_adds_only_the_missing_indexes_and_keeps_the_data(): void
    {
        $this->seedOneEmployee();
        Schema::table('titles', fn ($t) => $t->dropIndex('titles_to_date_index'));
        $this->assertNotContains('titles_to_date_index', $this->indexNames('titles'));

        $this->migration(self::INDEXES)->up();

        $this->assertContains('titles_to_date_index', $this->indexNames('titles'));
        $this->assertContains('titles_title_to_date_index', $this->indexNames('titles'));
        $this->assertSame(1, DB::table('titles')->count());
    }

    public function test_index_migration_can_be_rolled_back_twice_and_re_applied(): void
    {
        $this->seedOneEmployee();
        $migration = $this->migration(self::INDEXES);

        $migration->down();
        $migration->down();

        foreach (self::EXPECTED_INDEXES as $table => $names) {
            foreach ($names as $name) {
                $this->assertNotContains($name, $this->indexNames($table), "$table.$name");
            }
        }
        $this->assertSame(1, DB::table('salaries')->count());
        $this->assertContains('PRIMARY', array_map(fn ($i) => strtoupper($i['name']) === 'PRIMARY' ? 'PRIMARY' : $i['name'], Schema::getIndexes('salaries')));

        $migration->up();

        $this->assertContains('salaries_to_date_salary_index', $this->indexNames('salaries'));
    }

    public function test_running_migrate_twice_is_harmless(): void
    {
        $this->seedOneEmployee();

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertStringContainsString('Nothing to migrate', Artisan::output());

        $this->assertSame(1, DB::table('employees')->count());
    }

    public function test_migrating_an_already_imported_dataset_keeps_it_and_adds_the_indexes(): void
    {
        // Simulates the docker setup: dataset tables exist (and are filled), but the
        // migrations table does not know our two migrations yet and the indexes are missing.
        $this->seedOneEmployee();
        $this->migration(self::INDEXES)->down();
        DB::table('migrations')->whereIn('migration', [self::DATASET, self::INDEXES])->delete();

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $this->assertSame(1, DB::table('employees')->count());
        $this->assertSame(1, DB::table('salaries')->count());
        $this->assertContains('dept_emp_dept_no_to_date_index', $this->indexNames('dept_emp'));
        $this->assertSame(2, DB::table('migrations')->whereIn('migration', [self::DATASET, self::INDEXES])->count());
    }
}
