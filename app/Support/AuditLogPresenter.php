<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Human-readable rendering of audit log entries (audit log page, profile
 * "Change history"): action labels, the `changes` payload as
 * "field: before -> after" rows and links to the subjects.
 *
 *   $presenter = new AuditLogPresenter($departmentNames, withSalaries: $canSeeSalaries);
 *   $presenter->rows($entry->changes);   // [['field' => 'Salary', 'before' => '60,000', 'after' => '63,000'], ...]
 *
 * Salary figures (keys containing "salary" or "percent", at any depth) are left
 * out without `view-salaries`. Internal keys (starting with "_", e.g. the undo
 * data of HR operations) are never shown. Values are returned as plain text;
 * escaping is left to Blade.
 */
final class AuditLogPresenter
{
    /** Labels of known payload keys; other keys are turned into words ("effective_date" -> "Effective date"). */
    private const FIELD_LABELS = [
        'dept_no' => 'Department',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'birth_date' => 'Birth date',
        'hire_date' => 'Hire date',
        'is_active' => 'Active',
        'ended_management' => 'Ended management of',
        'change_percent' => 'Change (%)',
        'requested_percent' => 'Requested change (%)',
        'previous_end_date' => 'Previous employment ended',
        'from_date' => 'Period start',
        'to_date' => 'Period end',
        'returning' => 'Return to a previous department',
        'closed_stray_records' => 'Closed stray current records',
        'confirmed_unusual' => 'Unusual change confirmed',
        'undone_entry_id' => 'Undone entry',
        'undone_action' => 'Undone change',
        'undone_at' => 'Change made at',
    ];

    /** Keys whose values are department numbers. */
    private const DEPARTMENT_KEYS = ['dept_no', 'ended_management'];

    /**
     * @param  array<string, string>  $departmentNames  dept_no => dept_name
     */
    public function __construct(
        private readonly array $departmentNames = [],
        private readonly bool $withSalaries = true,
    ) {
    }

    /**
     * "employee.salary_changed" -> "Salary changed".
     */
    public static function actionLabel(string $action): string
    {
        $verb = Str::contains($action, '.') ? Str::after($action, '.') : $action;

        return Str::ucfirst(str_replace('_', ' ', $verb));
    }

    /**
     * Bootstrap colour of the action badge.
     */
    public static function actionClass(string $action): string
    {
        $verb = Str::after($action, '.');

        return match (true) {
            in_array($verb, ['hired', 'rehired', 'created', 'login'], true) => 'text-bg-success',
            in_array($verb, ['terminated', 'deleted', 'login_failed'], true) => 'text-bg-danger',
            str_ends_with($verb, '_corrected'), str_contains($verb, 'password') => 'text-bg-warning',
            in_array($verb, ['transferred', 'promoted', 'demoted', 'title_changed', 'salary_changed', 'role_changed'], true) => 'text-bg-primary',
            default => 'text-bg-secondary',
        };
    }

    public static function subjectLabel(string $type): string
    {
        return Str::ucfirst(str_replace('_', ' ', $type));
    }

    /**
     * Link to the record an entry is about, if there is a page for it.
     */
    public static function subjectUrl(string $type, ?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return match ($type) {
            ActivityLog::SUBJECT_EMPLOYEE => ctype_digit($id) ? route('employees.show', (int) $id) : null,
            ActivityLog::SUBJECT_USER => Route::has('users.edit') && auth()->user()?->can(Ability::MANAGE_USERS) && ctype_digit($id)
                ? route('users.edit', (int) $id)
                : null,
            ActivityLog::SUBJECT_DEPARTMENT => Route::has('departments.show') ? route('departments.show', $id) : null,
            default => null,
        };
    }

    /**
     * The payload as rows: {before, after} pairs first, then the other keys.
     *
     * @param  array<string, mixed>|null  $changes
     * @return list<array{field: string, before: ?string, after: ?string, change: bool}>
     */
    public function rows(?array $changes): array
    {
        if ($changes === null || $changes === []) {
            return [];
        }

        $rows = [];
        $before = is_array($changes['before'] ?? null) ? $changes['before'] : [];
        $after = is_array($changes['after'] ?? null) ? $changes['after'] : [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if ($this->hidden((string) $key)) {
                continue;
            }

            $rows[] = [
                'field' => $this->fieldLabel((string) $key),
                'before' => array_key_exists($key, $before) ? $this->value((string) $key, $before[$key]) : null,
                'after' => array_key_exists($key, $after) ? $this->value((string) $key, $after[$key]) : null,
                'change' => true,
            ];
        }

        foreach ($changes as $key => $value) {
            if (in_array($key, ['before', 'after'], true) && (is_array($value) || $value === null)) {
                continue;
            }
            if ($this->hidden((string) $key)) {
                continue;
            }

            $rows[] = ['field' => $this->fieldLabel((string) $key), 'before' => null, 'after' => $this->value((string) $key, $value), 'change' => false];
        }

        return $rows;
    }

    public function fieldLabel(string $key): string
    {
        return __(self::FIELD_LABELS[$key] ?? Str::ucfirst(str_replace('_', ' ', $key)));
    }

    /**
     * A stored value as text: departments with their name, amounts with
     * separators, Y-m-d dates as d.m.Y, lists comma-separated.
     */
    public function value(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if (is_array($value)) {
            $value = $this->strip($value);

            return array_is_list($value) && array_filter($value, fn ($item) => ! is_scalar($item) && $item !== null) === []
                ? implode(', ', array_map(fn ($item) => $this->value($key, $item), $value))
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (in_array($key, self::DEPARTMENT_KEYS, true) && is_string($value)) {
            return implode(', ', array_map(
                fn (string $no) => isset($this->departmentNames[$no]) ? $this->departmentNames[$no].' ('.$no.')' : $no,
                array_map('trim', explode(',', $value)),
            ));
        }

        if ($key === 'undone_action' && is_string($value)) {
            return self::actionLabel($value).' ('.$value.')';
        }

        if ($key === 'salary' && is_numeric($value)) {
            return number_format((float) $value);
        }

        if (str_contains($key, 'percent') && is_numeric($value)) {
            return ((float) $value > 0 ? '+' : '').rtrim(rtrim(number_format((float) $value, 2), '0'), '.').' %';
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value === EmploymentRules::CURRENT ? __('present') : EmploymentRules::display($value);
        }

        if ($key === 'gender' && in_array($value, ['M', 'F'], true)) {
            return $value === 'M' ? __('Male') : __('Female');
        }

        return (string) $value;
    }

    private function hidden(string $key): bool
    {
        return str_starts_with($key, '_')
            || (! $this->withSalaries && (str_contains($key, 'salary') || str_contains($key, 'percent')));
    }

    /**
     * $value without hidden keys, at any depth.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function strip(array $value): array
    {
        $list = array_is_list($value);
        $kept = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && $this->hidden($key)) {
                continue;
            }
            $kept[$key] = is_array($item) ? $this->strip($item) : $item;
        }

        return $list ? array_values($kept) : $kept;
    }
}
