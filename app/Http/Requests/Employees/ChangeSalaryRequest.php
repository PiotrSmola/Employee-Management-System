<?php

namespace App\Http\Requests\Employees;

use App\Support\EmploymentRules;
use Illuminate\Validation\Rule;

/**
 * New salary as an amount (mode=amount, salary) or a change in percent (mode=percent, percent).
 */
class ChangeSalaryRequest extends EmployeeOperationRequest
{
    protected $errorBag = 'salary';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['amount', 'percent'])],
            'salary' => ['exclude_unless:mode,amount', ...$this->salaryRules()],
            'percent' => ['exclude_unless:mode,percent', 'required', 'numeric',
                'between:'.EmploymentRules::MIN_PERCENT.','.EmploymentRules::MAX_PERCENT, 'not_in:0'],
            // The salary the percentage was entered for (the form shows it).
            'shown_salary' => ['exclude_unless:mode,percent', 'nullable', 'integer'],
            'effective_date' => $this->dateRules(),
            'confirm_unusual' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['percent.not_in' => __('The change cannot be 0 %.')];
    }
}
