<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * dept_emp and dept_manager get the primary key (emp_no, dept_no, from_date)
 * instead of (emp_no, dept_no), so an employee can return to a department (or
 * manage it again) as a new period without rewriting the old one
 * (docs/HR_OPERATIONS.md).
 *
 * - Idempotent: the current primary key is read from information_schema and a
 *   table that already has the new key is skipped.
 * - Online on MySQL: DROP + ADD PRIMARY KEY in one ALTER TABLE ... ALGORITHM=INPLACE,
 *   LOCK=NONE (the table is rebuilt, reads and writes continue meanwhile).
 * - The foreign key on emp_no keeps an emp_no-leading index (the new primary key);
 *   the one on dept_no keeps its own `dept_no` index.
 * - down() restores the old key only when no employee has two periods in the same
 *   department (it would fail, or lose data, otherwise); it leaves the table as is
 *   and says so in that case.
 */
return new class extends Migration
{
    private const TABLES = ['dept_emp', 'dept_manager'];

    private const OLD_KEY = ['emp_no', 'dept_no'];

    private const NEW_KEY = ['emp_no', 'dept_no', 'from_date'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && $this->primaryKey($table) !== self::NEW_KEY) {
                $this->replacePrimaryKey($table, self::NEW_KEY);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || $this->primaryKey($table) !== self::NEW_KEY) {
                continue;
            }

            $repeated = DB::table($table)->select('emp_no', 'dept_no')->groupBy('emp_no', 'dept_no')
                ->havingRaw('COUNT(*) > 1')->limit(1)->exists();

            if ($repeated) {
                $message = "{$table}: some employees have several periods in one department; the primary key (emp_no, dept_no, from_date) is kept.";
                app()->runningInConsole() ? fwrite(STDERR, $message.PHP_EOL) : logger()->warning($message);

                continue;
            }

            $this->replacePrimaryKey($table, self::OLD_KEY);
        }
    }

    /**
     * @param  string[]  $columns
     */
    private function replacePrimaryKey(string $table, array $columns): void
    {
        if (DB::getDriverName() === 'mysql') {
            $grammar = DB::getQueryGrammar();
            DB::statement(sprintf(
                'ALTER TABLE %s DROP PRIMARY KEY, ADD PRIMARY KEY (%s), ALGORITHM=INPLACE, LOCK=NONE',
                $grammar->wrapTable($table),
                $grammar->columnize($columns),
            ));

            return;
        }

        Schema::table($table, function (Blueprint $t) use ($columns) {
            $t->dropPrimary();
            $t->primary($columns);
        });
    }

    /**
     * Columns of the table's primary key, in key order.
     *
     * @return string[]
     */
    private function primaryKey(string $table): array
    {
        if (DB::getDriverName() === 'mysql') {
            return DB::table('information_schema.KEY_COLUMN_USAGE')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', $table)
                ->where('CONSTRAINT_NAME', 'PRIMARY')
                ->orderBy('ORDINAL_POSITION')
                ->pluck('COLUMN_NAME')
                ->map(fn ($column) => strtolower($column))
                ->all();
        }

        foreach (Schema::getIndexes($table) as $index) {
            if ($index['primary']) {
                return $index['columns'];
            }
        }

        return [];
    }
};
