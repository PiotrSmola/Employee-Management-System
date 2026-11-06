<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index for the duplicate check of the hire form (same name and birth date,
 * EmployeeOperationsService::duplicates()), which would otherwise scan the whole
 * employees table.
 *
 * Idempotent, and on MySQL built online (ALGORITHM=INPLACE, LOCK=NONE).
 */
return new class extends Migration
{
    private const INDEX = 'employees_birth_date_last_name_first_name_index';

    private const COLUMNS = ['birth_date', 'last_name', 'first_name'];

    public function up(): void
    {
        if ($this->exists()) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $grammar = DB::getQueryGrammar();
            DB::statement(sprintf(
                'ALTER TABLE %s ADD INDEX %s (%s), ALGORITHM=INPLACE, LOCK=NONE',
                $grammar->wrapTable('employees'),
                $grammar->wrap(self::INDEX),
                $grammar->columnize(self::COLUMNS),
            ));

            return;
        }

        Schema::table('employees', fn (Blueprint $table) => $table->index(self::COLUMNS, self::INDEX));
    }

    public function down(): void
    {
        if ($this->exists()) {
            Schema::table('employees', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }

    private function exists(): bool
    {
        return in_array(self::INDEX, array_map(
            fn (array $index) => strtolower($index['name']),
            Schema::getIndexes('employees'),
        ), true);
    }
};
