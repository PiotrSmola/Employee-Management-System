<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Secondary indexes used by the employee directory (filters, sorting, counts)
 * and the dashboard. Only the primary keys exist in the original dataset.
 *
 * Idempotent: an index that already exists is skipped. On MySQL the indexes are
 * built online (ALGORITHM=INPLACE, LOCK=NONE), so the tables stay readable and
 * writable meanwhile; on the full dataset (2.8M salary rows) this takes roughly
 * half a minute in total.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string[]>> table => index name => columns */
    private array $indexes = [
        'employees' => [
            'employees_hire_date_index' => ['hire_date'],
        ],
        'dept_emp' => [
            'dept_emp_to_date_index' => ['to_date'],
            'dept_emp_dept_no_to_date_index' => ['dept_no', 'to_date'],
        ],
        'titles' => [
            'titles_to_date_index' => ['to_date'],
            'titles_title_to_date_index' => ['title', 'to_date'],
        ],
        'salaries' => [
            'salaries_to_date_salary_index' => ['to_date', 'salary'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = $this->existingIndexes($table);

            foreach ($indexes as $name => $columns) {
                if (in_array($name, $existing, true)) {
                    continue;
                }

                if (DB::getDriverName() === 'mysql') {
                    $grammar = DB::getQueryGrammar();
                    DB::statement(sprintf(
                        'ALTER TABLE %s ADD INDEX %s (%s), ALGORITHM=INPLACE, LOCK=NONE',
                        $grammar->wrapTable($table),
                        $grammar->wrap($name),
                        $grammar->columnize($columns)
                    ));
                } else {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = $this->existingIndexes($table);

            foreach (array_keys($indexes) as $name) {
                if (in_array($name, $existing, true)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }

    /**
     * @return string[]
     */
    private function existingIndexes(string $table): array
    {
        return array_map(
            fn (array $index) => strtolower($index['name']),
            Schema::getIndexes($table)
        );
    }
};
