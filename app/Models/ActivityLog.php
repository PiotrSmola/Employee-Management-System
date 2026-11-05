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
     * Newest first (uses the created_at index; id breaks ties within a second).
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
