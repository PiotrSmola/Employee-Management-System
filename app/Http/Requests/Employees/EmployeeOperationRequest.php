<?php

namespace App\Http\Requests\Employees;

use App\Support\Ability;
use App\Support\EmploymentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Base of the HR operation requests: only `manage-employees` (the routes check it
 * as well) and the shared field rules. Rules that depend on the stored history
 * (dates after the current rows, no return to a previous department, ...) are
 * checked by App\Services\EmployeeOperationsService inside its transaction.
 *
 * Each profile form has its own error bag, so the profile reopens the right modal
 * and shows the messages only there.
 */
abstract class EmployeeOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Ability::MANAGE_EMPLOYEES) ?? false;
    }

    /**
     * @return array<int, mixed>
     */
    protected function dateRules(): array
    {
        return ['required', 'date_format:Y-m-d', 'before_or_equal:today'];
    }

    /**
     * @return array<int, mixed>
     */
    protected function departmentRules(): array
    {
        return ['required', 'string', Rule::exists('departments', 'dept_no')];
    }

    /**
     * @return array<int, mixed>
     */
    protected function titleRules(): array
    {
        return ['required', 'string', 'max:'.EmploymentRules::TITLE_MAX];
    }

    /**
     * @return array<int, mixed>
     */
    protected function salaryRules(): array
    {
        return ['required', 'integer', 'between:'.EmploymentRules::MIN_SALARY.','.EmploymentRules::MAX_SALARY];
    }

    /**
     * Name, gender, birth and hire date (hire and personal data forms).
     *
     * @return array<string, mixed>
     */
    protected function personalRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:'.EmploymentRules::FIRST_NAME_MAX],
            'last_name' => ['required', 'string', 'max:'.EmploymentRules::LAST_NAME_MAX],
            'gender' => ['required', Rule::in(['M', 'F'])],
            'birth_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.EmploymentRules::MIN_BIRTH_DATE, 'before:hire_date'],
            'hire_date' => $this->dateRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach (['first_name', 'last_name', 'title', 'reason'] as $field) {
            if (is_string($this->input($field))) {
                $trimmed[$field] = trim($this->input($field));
            }
        }
        $this->merge($trimmed);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'dept_no' => __('department'),
            'effective_date' => __('effective date'),
            'end_date' => __('end date'),
            'birth_date' => __('birth date'),
            'hire_date' => __('hire date'),
            'first_name' => __('first name'),
            'last_name' => __('last name'),
        ];
    }
}
