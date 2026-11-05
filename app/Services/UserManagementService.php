<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating, changing and deleting application accounts, with the rules that
 * keep the application administrable:
 *  - administrators cannot delete, deactivate or demote themselves;
 *  - there is always at least one active administrator.
 *
 * Every change is written to the audit log (user.created, user.updated,
 * user.role_changed, user.password_reset, user.deleted; never a password).
 */
class UserManagementService
{
    /** Account fields recorded in the audit log. */
    public const AUDITED_FIELDS = ['name', 'email', 'role', 'dept_no', 'is_active'];

    /**
     * @param  array{name: string, email: string, password: string, role: Role|string, dept_no?: ?string, is_active?: bool}  $data
     */
    public function create(array $data): User
    {
        $role = Role::from($data['role'] instanceof Role ? $data['role']->value : $data['role']);

        return DB::transaction(function () use ($data, $role) {
            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
                'role' => $role,
                'dept_no' => $role->isDepartmentScoped() ? ($data['dept_no'] ?? null) : null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            AuditLogger::log('user.created', ActivityLog::SUBJECT_USER, $user->id, [
                'after' => AuditLogger::values($user->only(self::AUDITED_FIELDS)),
            ]);

            return $user;
        });
    }

    /**
     * @param  array{name: string, email: string, role: Role|string, dept_no?: ?string, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function update(User $actor, User $user, array $data): User
    {
        $role = Role::from($data['role'] instanceof Role ? $data['role']->value : $data['role']);
        $active = (bool) ($data['is_active'] ?? true);

        return DB::transaction(function () use ($actor, $user, $data, $role, $active) {
            if ($actor->is($user)) {
                if ($role !== $user->role) {
                    throw ValidationException::withMessages(['role' => __('You cannot change your own role.')]);
                }
                if (! $active) {
                    throw ValidationException::withMessages(['is_active' => __('You cannot deactivate your own account.')]);
                }
            }

            $losesAdmin = $user->isActiveAdmin() && ($role !== Role::Admin || ! $active);
            if ($losesAdmin && $this->isLastActiveAdmin($user)) {
                throw ValidationException::withMessages([
                    $role !== Role::Admin ? 'role' : 'is_active' => __('At least one active administrator is required.'),
                ]);
            }

            $before = $user->only(self::AUDITED_FIELDS);

            $user->fill([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'role' => $role,
                'dept_no' => $role->isDepartmentScoped() ? ($data['dept_no'] ?? null) : null,
                'is_active' => $active,
            ]);

            if ($user->isDirty('is_active') && ! $active) {
                // Invalidate "remember me" cookies; open sessions are ended by the `active` middleware.
                $user->setRememberToken(Str::random(60));
            }

            $user->save();

            $changes = AuditLogger::diff($before, $user->only(self::AUDITED_FIELDS));
            if ($changes !== []) {
                AuditLogger::log('user.updated', ActivityLog::SUBJECT_USER, $user->id, $changes);
            }
            if (array_key_exists('role', $changes['after'] ?? [])) {
                AuditLogger::log('user.role_changed', ActivityLog::SUBJECT_USER, $user->id, [
                    'before' => ['role' => $changes['before']['role']],
                    'after' => ['role' => $changes['after']['role']],
                ]);
            }

            return $user;
        });
    }

    /**
     * Set a new password chosen by an administrator. The user's other sessions
     * and "remember me" cookies stop working (auth.session middleware).
     */
    public function resetPassword(User $user, string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        AuditLogger::log('user.password_reset', ActivityLog::SUBJECT_USER, $user->id);
    }

    /**
     * @throws ValidationException
     */
    public function delete(User $actor, User $user): void
    {
        $reason = $this->deletionBlockedReason($actor, $user);

        if ($reason !== null) {
            throw ValidationException::withMessages(['user' => $reason]);
        }

        DB::transaction(function () use ($user) {
            if ($user->isActiveAdmin() && $this->isLastActiveAdmin($user)) {
                throw ValidationException::withMessages(['user' => __('At least one active administrator is required.')]);
            }

            $before = AuditLogger::values($user->only(self::AUDITED_FIELDS));
            $user->delete();

            AuditLogger::log('user.deleted', ActivityLog::SUBJECT_USER, $user->id, ['before' => $before]);
        });
    }

    /**
     * Why $actor may not delete $user (null = allowed). Used to disable the button.
     */
    public function deletionBlockedReason(User $actor, User $user): ?string
    {
        if ($actor->is($user)) {
            return __('You cannot delete your own account.');
        }

        if ($user->isActiveAdmin() && $this->isLastActiveAdmin($user)) {
            return __('At least one active administrator is required.');
        }

        return null;
    }

    private function isLastActiveAdmin(User $user): bool
    {
        return ! User::activeAdmins()->whereKeyNot($user->getKey())->lockForUpdate()->exists();
    }
}
