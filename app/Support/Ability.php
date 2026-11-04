<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * Names of the application's Gates and the roles that have them - the single
 * permission matrix of the app (registered by AuthorizationServiceProvider,
 * documented in docs/AUTHORIZATION.md).
 *
 *   Gate::allows(Ability::VIEW_SALARIES)       @can('export-data')       ->middleware('can:manage-users')
 *
 * Inactive accounts have no ability at all.
 */
final class Ability
{
    /** Salary data anywhere: list column, salary filter/sort, profile, PDFs, exports, dashboard average. */
    public const VIEW_SALARIES = 'view-salaries';

    /** Create / edit / delete employees and their history. */
    public const MANAGE_EMPLOYEES = 'manage-employees';

    /** Create / edit / delete departments and department managers. */
    public const MANAGE_DEPARTMENTS = 'manage-departments';

    /** Application accounts (Users screen). */
    public const MANAGE_USERS = 'manage-users';

    public const VIEW_AUDIT_LOG = 'view-audit-log';

    /** Bulk CSV / PDF export of the directory. */
    public const EXPORT_DATA = 'export-data';

    public const IMPORT_DATA = 'import-data';

    public const VIEW_REPORTS = 'view-reports';

    /**
     * Ability => roles that have it.
     *
     * @var array<string, Role[]>
     */
    public const MATRIX = [
        self::VIEW_SALARIES => [Role::Admin, Role::Hr, Role::Manager],
        self::MANAGE_EMPLOYEES => [Role::Admin, Role::Hr],
        self::MANAGE_DEPARTMENTS => [Role::Admin, Role::Hr],
        self::MANAGE_USERS => [Role::Admin],
        self::VIEW_AUDIT_LOG => [Role::Admin, Role::Hr],
        self::EXPORT_DATA => [Role::Admin, Role::Hr, Role::Manager],
        self::IMPORT_DATA => [Role::Admin, Role::Hr],
        self::VIEW_REPORTS => [Role::Admin, Role::Hr, Role::Manager],
    ];

    /**
     * Does $user have $ability? Same answer as the Gate, usable for any user
     * (not only the authenticated one) and without the Gate's before/after hooks.
     */
    public static function check(User $user, string $ability): bool
    {
        return $user->is_active && in_array($user->role, self::MATRIX[$ability] ?? [], true);
    }

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return array_keys(self::MATRIX);
    }
}
