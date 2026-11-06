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
        'voided_rows' => 'Voided records',
        'reopened_rows' => 'Reopened records',
        'restored_values' => 'Restored values',
    ];

    /** Payload keys holding lists of history rows (undo entries). */
    private const HISTORY_ROW_KEYS = ['voided_rows', 'reopened_rows', 'restored_values'];

    private const TABLE_LABELS = [
        'dept_emp' => 'Department',
        'dept_manager' => 'Management',
        'titles' => 'Job title',
        'salaries' => 'Salary',
        'employees' => 'Personal data',
    ];

    /** Keys whose values are department numbers. */
    private const DEPARTMENT_KEYS = ['dept_no', 'ended_management'];

    /**
     * @param  array<string, string>  $departmentNames  dept_no => dept_name
     * @param  array<string, string>  $employeeNames  emp_no => name, of the entries shown
     */
    public function __construct(
        private readonly array $departmentNames = [],
        private readonly bool $withSalaries = true,
        private readonly array $employeeNames = [],
    ) {
    }

    /**
     * The name of the record an entry is about, if it is known here.
     */
    public function subjectName(ActivityLog $entry): ?string
    {
        return match ($entry->subject_type) {
            ActivityLog::SUBJECT_EMPLOYEE => $this->employeeNames[$entry->subject_id] ?? null,
            ActivityLog::SUBJECT_DEPARTMENT => $this->departmentNames[$entry->subject_id] ?? null,
            default => null,
        };
    }

    /**
     * What to call the record of an entry: its name, or its id.
     */
    public function subjectTitle(ActivityLog $entry): string
    {
        return $this->subjectName($entry) ?? ($entry->subject_id !== null ? '#'.$entry->subject_id : '-');
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

        if (in_array($key, self::HISTORY_ROW_KEYS, true) && is_array($value)) {
            return implode('; ', array_map(fn (array $row) => $this->historyRow($key, $this->strip($row)), $value));
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

    /**
     * One row of an undo entry: what was voided, reopened or restored.
     *
     * @param  array<string, mixed>  $row  without hidden keys
     */
    private function historyRow(string $key, array $row): string
    {
        $table = (string) ($row['table'] ?? '');
        $label = __(self::TABLE_LABELS[$table] ?? $table);
        $what = array_filter([
            isset($row['dept_no']) ? $this->value('dept_no', $row['dept_no']) : null,
            isset($row['title']) ? $this->value('title', $row['title']) : null,
            isset($row['salary']) ? $this->value('salary', $row['salary']) : null,
        ]);
        $subject = trim($label.' '.implode(', ', $what));
        $from = $this->value('from_date', $row['from_date'] ?? null);

        return match ($key) {
            'voided_rows' => $subject.', '.$from.' - '.$this->value('to_date', $row['to_date'] ?? null),
            'reopened_rows' => __(':record, from :from: end date :date removed', [
                'record' => $subject,
                'from' => $from,
                'date' => $this->value('previous_to_date', $row['previous_to_date'] ?? null),
            ]),
            default => $subject.': '.$this->changedValues($row['replaced'] ?? [], $row['restored'] ?? []),
        };
    }

    /**
     * @param  array<string, mixed>  $replaced
     * @param  array<string, mixed>  $restored
     */
    private function changedValues(array $replaced, array $restored): string
    {
        $changes = [];
        foreach ($restored as $field => $value) {
            $changes[] = $this->fieldLabel((string) $field).': '.$this->value((string) $field, $replaced[$field] ?? null)
                .' -> '.$this->value((string) $field, $value);
        }

        return $changes === [] ? '-' : implode(', ', $changes);
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
