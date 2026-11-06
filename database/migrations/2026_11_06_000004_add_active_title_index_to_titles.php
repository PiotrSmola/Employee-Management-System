<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The directory's job title list is SELECT DISTINCT title. With the void_log_id = 0
 * condition the (title, to_date) index can no longer answer it with a loose index
 * scan (8 ms became 510 ms on the full dataset, on every directory render); this
 * index can, because the condition is an equality on its second column.
 *
 * Idempotent, and on MySQL built online (ALGORITHM=INPLACE, LOCK=NONE).
 */
return new class extends Migration
{
    private const INDEX = 'titles_title_void_log_id_index';

    public function up(): void
    {
        if ($this->exists()) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE %s ADD INDEX %s (`title`, `void_log_id`), ALGORITHM=INPLACE, LOCK=NONE',
                DB::getQueryGrammar()->wrapTable('titles'),
                DB::getQueryGrammar()->wrap(self::INDEX),
            ));

            return;
        }

        Schema::table('titles', fn (Blueprint $table) => $table->index(['title', 'void_log_id'], self::INDEX));
    }

    public function down(): void
    {
        if ($this->exists()) {
            Schema::table('titles', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }

    private function exists(): bool
    {
        return in_array(self::INDEX, array_map(
            fn (array $index) => strtolower($index['name']),
            Schema::getIndexes('titles'),
        ), true);
    }
};
