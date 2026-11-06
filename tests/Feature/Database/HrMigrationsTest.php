<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migrations of the HR operations: the wider primary keys of dept_emp /
 * dept_manager and the audit log filter indexes. DDL commits implicitly, so -
 * like MigrationsTest - these start from and leave a freshly migrated database.
 */
class HrMigrationsTest extends TestCase
{
    private const PRIMARY_KEYS = '2026_11_06_000001_add_from_date_to_department_history_primary_keys';

    private const AUDIT_INDEXES = '2026_11_06_000002_add_filter_indexes_to_activity_log';

    private const DUPLICATE_INDEX = '2026_11_06_000003_add_duplicate_check_index_to_employees';

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

    /**
     * @return string[]
     */
    private function primaryKey(string $table): array
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', $table)->where('CONSTRAINT_NAME', 'PRIMARY')
            ->orderBy('ORDINAL_POSITION')->pluck('COLUMN_NAME')->map(fn ($c) => strtolower($c))->all();
    }

    private function seedHistory(): void
    {
        DB::table('employees')->insert(['emp_no' => 1, 'birth_date' => '1960-01-01', 'first_name' => 'Ann', 'last_name' => 'Lee', 'gender' => 'F', 'hire_date' => '1990-01-01']);
        DB::table('departments')->insert([['dept_no' => 'd001', 'dept_name' => 'Sales'], ['dept_no' => 'd002', 'dept_name' => 'Finance']]);
        DB::table('dept_emp')->insert(['emp_no' => 1, 'dept_no' => 'd001', 'from_date' => '1990-01-01', 'to_date' => '1995-01-01']);
        DB::table('dept_manager')->insert(['emp_no' => 1, 'dept_no' => 'd001', 'from_date' => '1991-01-01', 'to_date' => '1995-01-01']);
    }

    public function test_the_primary_keys_include_the_start_date_and_returns_are_possible(): void
    {
        $this->assertSame(['emp_no', 'dept_no', 'from_date'], $this->primaryKey('dept_emp'));
        $this->assertSame(['emp_no', 'dept_no', 'from_date'], $this->primaryKey('dept_manager'));

        $this->seedHistory();
        DB::table('dept_emp')->insert(['emp_no' => 1, 'dept_no' => 'd002', 'from_date' => '1995-01-01', 'to_date' => '2000-01-01']);
        DB::table('dept_emp')->insert(['emp_no' => 1, 'dept_no' => 'd001', 'from_date' => '2000-01-01', 'to_date' => '9999-01-01']);
        DB::table('dept_manager')->insert(['emp_no' => 1, 'dept_no' => 'd001', 'from_date' => '2001-01-01', 'to_date' => '9999-01-01']);
        $this->assertSame(3, DB::table('dept_emp')->count());

        // Same period twice is still refused, and the foreign keys still hold.
        $this->expectException(QueryException::class);
        DB::table('dept_emp')->insert(['emp_no' => 1, 'dept_no' => 'd001', 'from_date' => '2000-01-01', 'to_date' => '9999-01-01']);
    }

    public function test_foreign_keys_still_cascade_and_reject_unknown_employees(): void
    {
        $this->seedHistory();

        try {
            DB::table('dept_emp')->insert(['emp_no' => 999, 'dept_no' => 'd001', 'from_date' => '1990-01-01', 'to_date' => '9999-01-01']);
            $this->fail('Unknown employees must be rejected.');
        } catch (QueryException) {
        }

        DB::table('employees')->where('emp_no', 1)->delete();
        $this->assertSame(0, DB::table('dept_emp')->count());
        $this->assertSame(0, DB::table('dept_manager')->count());
    }

    public function test_the_migration_is_idempotent_and_keeps_the_data(): void
    {
        $this->seedHistory();

        $this->migration(self::PRIMARY_KEYS)->up();
        $this->migration(self::PRIMARY_KEYS)->up();

        $this->assertSame(['emp_no', 'dept_no', 'from_date'], $this->primaryKey('dept_emp'));
        $this->assertSame(1, DB::table('dept_emp')->count());
        $this->assertSame(1, DB::table('dept_manager')->count());
    }

    public function test_down_restores_the_old_key_only_without_repeated_periods(): void
    {
        $this->seedHistory();

        $this->migration(self::PRIMARY_KEYS)->down();
        $this->assertSame(['emp_no', 'dept_no'], $this->primaryKey('dept_emp'));
        $this->assertSame(['emp_no', 'dept_no'], $this->primaryKey('dept_manager'));

        $this->migration(self::PRIMARY_KEYS)->up();
        DB::table('dept_emp')->insert(['emp_no' => 1, 'dept_no' => 'd001', 'from_date' => '2000-01-01', 'to_date' => '9999-01-01']);

        $this->migration(self::PRIMARY_KEYS)->down();
        $this->assertSame(['emp_no', 'dept_no', 'from_date'], $this->primaryKey('dept_emp'), 'Kept: an employee has two periods in d001.');
        $this->assertSame(['emp_no', 'dept_no'], $this->primaryKey('dept_manager'));
        $this->assertSame(2, DB::table('dept_emp')->count());
    }

    public function test_the_audit_log_gets_its_filter_indexes_idempotently(): void
    {
        $indexes = fn () => array_map(fn ($i) => strtolower($i['name']), Schema::getIndexes('activity_log'));

        $this->assertContains('activity_log_action_group_created_at_index', $indexes());
        $this->assertContains('activity_log_user_id_created_at_index', $indexes());

        $this->migration(self::AUDIT_INDEXES)->up();
        $this->migration(self::AUDIT_INDEXES)->down();
        $this->assertFalse(Schema::hasColumn('activity_log', 'action_group'));
        $this->assertNotContains('activity_log_user_id_created_at_index', $indexes());
        $this->migration(self::AUDIT_INDEXES)->down();

        $this->migration(self::AUDIT_INDEXES)->up();
        DB::table('activity_log')->insert(['action' => 'employee.hired', 'subject_type' => 'employee', 'subject_id' => '1', 'created_at' => now()]);
        $this->assertSame('employee', DB::table('activity_log')->value('action_group'));
    }

    public function test_the_duplicate_check_index_is_added_idempotently(): void
    {
        $name = 'employees_birth_date_last_name_first_name_index';
        $indexes = fn () => array_map(fn ($i) => strtolower($i['name']), Schema::getIndexes('employees'));

        $this->assertContains($name, $indexes());

        $this->migration(self::DUPLICATE_INDEX)->up();
        $this->assertContains($name, $indexes());

        $this->migration(self::DUPLICATE_INDEX)->down();
        $this->migration(self::DUPLICATE_INDEX)->down();
        $this->assertNotContains($name, $indexes());

        $this->migration(self::DUPLICATE_INDEX)->up();
        $this->assertContains($name, $indexes());
    }
}
