<?php

namespace App\Enums;

/**
 * Application roles. What each role may do is defined in one place:
 * {@see \App\Providers\AuthorizationServiceProvider::ABILITIES}.
 */
enum Role: string
{
    case Admin = 'admin';
    case Hr = 'hr';
    case Manager = 'manager';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Administrator'),
            self::Hr => __('HR'),
            self::Manager => __('Manager'),
            self::Viewer => __('Viewer'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => __('Full access, including user management.'),
            self::Hr => __('Manages employees and departments, sees salaries.'),
            self::Manager => __('Sees employees (and salaries) of their own department only.'),
            self::Viewer => __('Read-only access to the directory, without salary data.'),
        };
    }

    /**
     * Bootstrap contextual colour of the role badge.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Admin => 'text-bg-danger',
            self::Hr => 'text-bg-primary',
            self::Manager => 'text-bg-warning',
            self::Viewer => 'text-bg-secondary',
        };
    }

    /**
     * Managers are limited to the employees of one department (users.dept_no).
     */
    public function isDepartmentScoped(): bool
    {
        return $this === self::Manager;
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
