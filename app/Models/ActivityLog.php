<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One audit log entry - written by App\Services\AuditLogger, never updated.
 *
 * @property int $id
 * @property int|null $user_id who did it (null: system, or a failed login)
 * @property string $action e.g. employee.hired, user.role_changed, auth.login
 * @property string $subject_type one of the SUBJECT_* aliases
 * @property string|null $subject_id emp_no / dept_no / user id
 * @property array<string, mixed>|null $changes {"before": {...}, "after": {...}} or a payload
 * @property string|null $ip
 * @property \Illuminate\Support\Carbon $created_at
 */
class ActivityLog extends Model
{
    public const SUBJECT_EMPLOYEE = 'employee';

    public const SUBJECT_DEPARTMENT = 'department';

    public const SUBJECT_USER = 'user';

    /** Entry of an undone HR operation; `changes.undone_entry_id` points at the original entry. */
    public const ACTION_CHANGE_UNDONE = 'employee.change_undone';

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
            ->with('user:id,name')
            ->where('subject_type', self::SUBJECT_EMPLOYEE)
            ->whereIn('subject_id', $subjects)
            ->where('action', self::ACTION_CHANGE_UNDONE)
            ->get()
            // getAttribute(): inside this class ->changes would be Eloquent's protected property.
            ->keyBy(fn (self $undo) => $undo->getAttribute('changes')['undone_entry_id'] ?? 0)
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
