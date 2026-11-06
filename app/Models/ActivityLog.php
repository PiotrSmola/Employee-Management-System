<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One audit log entry - written by App\Services\AuditLogger, never updated.
 *
 * Inside this class `$entry->changes` is NOT the `changes` column: Eloquent's Model
 * has a protected property of that name, which wins where it is accessible (here and
 * in closures bound to the class). Use `$entry->getAttribute('changes')` in this class.
 *
 * @property int $id
 * @property int|null $user_id who did it (null: system, or a failed login)
 * @property string $action e.g. employee.hired, user.role_changed, auth.login
 * @property string $subject_type one of the SUBJECT_* aliases
 * @property string|null $subject_id emp_no / dept_no / user id
 * @property array<string, mixed>|null $changes {"before": {...}, "after": {...}} or a payload
 * @property string|null $ip
 * @property Carbon $created_at
 */
class ActivityLog extends Model
{
    public const SUBJECT_EMPLOYEE = 'employee';

    public const SUBJECT_DEPARTMENT = 'department';

    public const SUBJECT_USER = 'user';

    /** Entry of an undone HR operation; `changes.undone_entry_id` points at the original entry. */
    public const ACTION_CHANGE_UNDONE = 'employee.change_undone';

    /** Bulk export (subject id: exports.id of a queued export, null for a direct download). */
    public const SUBJECT_EXPORT = 'export';

    /** Employee import (subject id: null; the created emp_no range is in `changes`). */
    public const SUBJECT_IMPORT = 'import';

    public const UPDATED_AT = null;

    protected $table = 'activity_log';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Entries about one record, e.g. ActivityLog::forSubject('employee', 10001).
     */
    public function scopeForSubject(Builder $query, string $type, string|int $id): void
    {
        $query->where('subject_type', $type)->where('subject_id', (string) $id);
    }

    /**
     * The undo entries of $entries, by the id of the entry they undid. Entries are
     * never updated, so "this was undone" is only recorded on the undo entry.
     *
     * @param  iterable<self>  $entries
     * @return array<int, self>
     */
    public static function undoEntriesFor(iterable $entries): array
    {
        $subjects = collect($entries)
            ->where('subject_type', self::SUBJECT_EMPLOYEE)
            ->pluck('subject_id')
            ->unique()
            ->values()
            ->all();

        if ($subjects === []) {
            return [];
        }

        return self::query()
            ->select(['id', 'user_id', 'created_at'])
            ->selectRaw("CAST(JSON_EXTRACT(changes, '$.undone_entry_id') AS UNSIGNED) AS undone_entry_id")
            ->with('user:id,name')
            ->where('subject_type', self::SUBJECT_EMPLOYEE)
            ->whereIn('subject_id', $subjects)
            ->where('action', self::ACTION_CHANGE_UNDONE)
            ->get()
            ->keyBy('undone_entry_id')
            ->all();
    }

    /**
     * Newest first (uses the created_at index; id breaks ties within a second).
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
