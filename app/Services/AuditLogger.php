<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Arr;

/**
 * Writes the audit log (activity_log table, model App\Models\ActivityLog).
 *
 *   AuditLogger::log('employee.hired', ActivityLog::SUBJECT_EMPLOYEE, $empNo, ['after' => $attributes]);
 *   AuditLogger::log('user.updated', 'user', $user->id, AuditLogger::diff($before, $after));
 *
 * The acting user defaults to the signed-in user and the IP address to the
 * current request's (both null on the console). Secrets (password, tokens) are
 * removed from $changes at any depth, so callers cannot log them by accident.
 */
final class AuditLogger
{
    /** Keys that are never stored in `changes`. */
    public const REDACTED_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'token',
        'api_token',
    ];

    /**
     * @param  string  $action  "<subject>.<verb>", e.g. employee.hired, department.manager_assigned
     * @param  string  $subjectType  ActivityLog::SUBJECT_* alias
     * @param  string|int|null  $subjectId  emp_no / dept_no / user id (null when there is no record, e.g. a failed login for an unknown e-mail)
     * @param  array<string, mixed>  $changes  diff() result or any payload; empty = null
     * @param  User|null  $user  acting user; defaults to the signed-in user
     */
    public static function log(
        string $action,
        string $subjectType,
        string|int|null $subjectId,
        array $changes = [],
        ?User $user = null,
    ): ActivityLog {
        $user ??= auth()->user();
        $changes = self::redact($changes);

        return ActivityLog::create([
            'user_id' => $user instanceof User ? $user->getKey() : null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'changes' => $changes === [] ? null : $changes,
            'ip' => app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * The changed values of two attribute arrays as {"before": {...}, "after": {...}}
     * (only keys whose value differs; keys missing on one side count as null;
     * scalars are compared as strings, so 5 and "5" are equal). Returns [] when
     * nothing changed. Enums and dates are stored as plain values.
     *
     *   $before = $user->only($fields);  ...save...  AuditLogger::diff($before, $user->only($fields));
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{before?: array<string, mixed>, after?: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $before = array_map(self::normalize(...), $before);
        $after = array_map(self::normalize(...), $after);
        $changed = ['before' => [], 'after' => []];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if (! self::same($old, $new)) {
                $changed['before'][$key] = $old;
                $changed['after'][$key] = $new;
            }
        }

        return $changed['after'] === [] ? [] : $changed;
    }

    /**
     * Plain values of attributes, for a "created" (['after' => ...]) or
     * "deleted" (['before' => ...]) entry.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function values(array $attributes): array
    {
        return array_map(self::normalize(...), $attributes);
    }

    /**
     * @param  array<array-key, mixed>  $changes
     * @return array<array-key, mixed>
     */
    private static function redact(array $changes): array
    {
        $changes = Arr::except($changes, self::REDACTED_KEYS);

        foreach ($changes as $key => $value) {
            if (is_array($value)) {
                $changes[$key] = self::redact($value);
            }
        }

        return $changes;
    }

    private static function same(mixed $old, mixed $new): bool
    {
        if ($old === null || $new === null) {
            return $old === $new;
        }

        if (is_scalar($old) && is_scalar($new)) {
            return (string) $old === (string) $new;
        }

        return $old == $new;
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format($value->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i:s'),
            default => $value,
        };
    }
}
