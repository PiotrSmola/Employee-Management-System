<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * History rows are never deleted: undoing an HR operation voids the rows it added
 * (void_log_id = the id of the undo's audit entry, 0 = active row; who, when and why
 * come from that entry, see App\Support\HistoryRows). The primary keys include the
 * column, so a voided row never collides with a corrected row of the same date
 * while active rows stay unique. dept_emp and dept_manager also get from_date in
 * the key: an employee can return to a department as a new period.
 *
 *   dept_emp, dept_manager  (emp_no, dept_no, from_date, void_log_id)
 *   titles                  (emp_no, title, from_date, void_log_id)
 *   salaries                (emp_no, from_date, void_log_id)
 *
 * - Idempotent: the current key is read from information_schema and only what is
 *   missing is changed.
 * - Online: one ALTER TABLE ... ALGORITHM=INPLACE, LOCK=NONE per table (the table is
 *   rebuilt, reads and writes continue meanwhile).
 * - The foreign keys on emp_no keep an emp_no-leading index (the primary key), the
 *   one on dept_no its `dept_no` index.
 * - InnoDB secondary indexes contain the primary key columns, so the directory
 *   indexes stay covering for queries with void_log_id = 0.
 * - down() leaves a table with voided rows as it is. Without voided rows it drops
 *   the column and restores the old key; dept_emp / dept_manager keep from_date
 *   in the key if an employee has several periods in one department.
 */
return new class extends Migration
{
    private const COLUMN = 'void_log_id';

    /** Table => primary key without / with the voiding column. */
    private const KEYS = [
        'dept_emp' => ['emp_no', 'dept_no', 'from_date'],
        'dept_manager' => ['emp_no', 'dept_no', 'from_date'],
        'titles' => ['emp_no', 'title', 'from_date'],
        'salaries' => ['emp_no', 'from_date'],
    ];

    /** The dataset's original keys of the department tables. */
    private const ORIGINAL_DEPARTMENT_KEY = ['emp_no', 'dept_no'];

    public function up(): void
    {
        foreach (self::KEYS as $table => $key) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $target = [...$key, self::COLUMN];
            $changes = [];

            if (! Schema::hasColumn($table, self::COLUMN)) {
                $changes[] = 'ADD COLUMN `'.self::COLUMN.'` BIGINT UNSIGNED NOT NULL DEFAULT 0';
            }
            if ($this->primaryKey($table) !== $target) {
                $changes[] = 'DROP PRIMARY KEY';
                $changes[] = 'ADD PRIMARY KEY ('.DB::getQueryGrammar()->columnize($target).')';
            }

            $this->alter($table, $changes);
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $table => $key) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, self::COLUMN)) {
                continue;
            }

            if (DB::table($table)->where(self::COLUMN, '<>', 0)->exists()) {
                $this->warn("{$table}: voided rows exist, the column and the primary key are kept.");

                continue;
            }

            $restore = $key;
            if (in_array('dept_no', $key, true)) {
                $repeated = DB::table($table)->select('emp_no', 'dept_no')->groupBy('emp_no', 'dept_no')
                    ->havingRaw('COUNT(*) > 1')->limit(1)->exists();

                if ($repeated) {
                    $this->warn("{$table}: some employees have several periods in one department; from_date stays in the primary key.");
                } else {
                    $restore = self::ORIGINAL_DEPARTMENT_KEY;
                }
            }

            $this->alter($table, [
                'DROP PRIMARY KEY',
                'ADD PRIMARY KEY ('.DB::getQueryGrammar()->columnize($restore).')',
                'DROP COLUMN `'.self::COLUMN.'`',
            ]);
        }
    }

    /**
     * @param  string[]  $changes
     */
    private function alter(string $table, array $changes): void
    {
        if ($changes !== []) {
            DB::statement(sprintf(
                'ALTER TABLE %s %s, ALGORITHM=INPLACE, LOCK=NONE',
                DB::getQueryGrammar()->wrapTable($table),
                implode(', ', $changes),
            ));
        }
    }

    /**
     * Columns of the table's primary key, in key order.
     *
     * @return string[]
     */
    private function primaryKey(string $table): array
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', 'PRIMARY')
            ->orderBy('ORDINAL_POSITION')
            ->pluck('COLUMN_NAME')
            ->map(fn ($column) => strtolower($column))
            ->all();
    }

    private function warn(string $message): void
    {
        app()->runningInConsole() ? fwrite(STDERR, $message.PHP_EOL) : logger()->warning($message);
    }
};
