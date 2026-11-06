<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the filters of the audit log screen (App\Http\Controllers\AuditLogController):
 *
 *  - action_group: the part of `action` before the dot ("employee" for
 *    employee.hired), a VIRTUAL generated column (no table rebuild) indexed with
 *    created_at, so "all employee.* actions, newest first" reads the index in
 *    order instead of sorting every matching entry;
 *  - (user_id, created_at): "everything one user did, newest first".
 *
 * Idempotent (existing column / indexes are skipped).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('activity_log', 'action_group')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->string('action_group', 64)->virtualAs("substring_index(`action`, '.', 1)")->after('action');
            });
        }

        $existing = $this->indexes();

        Schema::table('activity_log', function (Blueprint $table) use ($existing) {
            if (! in_array('activity_log_action_group_created_at_index', $existing, true)) {
                $table->index(['action_group', 'created_at']);
            }
            if (! in_array('activity_log_user_id_created_at_index', $existing, true)) {
                $table->index(['user_id', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        // MySQL drops the index it created for the user_id foreign key once (user_id, created_at)
        // exists; the foreign key needs an index again before the composite one goes.
        if (! $this->hasIndexStartingWith('user_id', except: 'activity_log_user_id_created_at_index')) {
            Schema::table('activity_log', fn (Blueprint $table) => $table->index('user_id'));
        }

        $existing = $this->indexes();

        Schema::table('activity_log', function (Blueprint $table) use ($existing) {
            if (in_array('activity_log_action_group_created_at_index', $existing, true)) {
                $table->dropIndex(['action_group', 'created_at']);
            }
            if (in_array('activity_log_user_id_created_at_index', $existing, true)) {
                $table->dropIndex(['user_id', 'created_at']);
            }
        });

        if (Schema::hasColumn('activity_log', 'action_group')) {
            Schema::table('activity_log', fn (Blueprint $table) => $table->dropColumn('action_group'));
        }
    }

    private function hasIndexStartingWith(string $column, string $except): bool
    {
        foreach (Schema::getIndexes('activity_log') as $index) {
            if (strtolower($index['name']) !== $except && ($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function indexes(): array
    {
        return array_map(fn (array $index) => strtolower($index['name']), Schema::getIndexes('activity_log'));
    }
};
