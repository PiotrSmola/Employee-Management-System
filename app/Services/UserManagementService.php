<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating, changing and deleting application accounts, with the rules that
 * keep the application administrable:
 *  - administrators cannot delete, deactivate or demote themselves;
 *  - there is always at least one active administrator.
 */
class UserManagementService
{
    /**
     * @param  array{name: string, email: string, password: string, role: Role|string, dept_no?: ?string, is_active?: bool}  $data
     */
    public function create(array $data): User
    {
        $role = Role::from($data['role'] instanceof Role ? $data['role']->value : $data['role']);

        return User::create([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'password' => $data['password'],
            'role' => $role,
            'dept_no' => $role->isDepartmentScoped() ? ($data['dept_no'] ?? null) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
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

            $user->delete();
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
