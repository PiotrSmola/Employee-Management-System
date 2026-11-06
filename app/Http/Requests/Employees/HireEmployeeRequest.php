<?php

namespace App\Http\Requests\Employees;

/**
 * New employee (hire form).
 */
class HireEmployeeRequest extends EmployeeOperationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->personalRules(),
            'dept_no' => $this->departmentRules(),
            'title' => $this->titleRules(),
            'salary' => $this->salaryRules(),
            // One-time token of the form (a double submit hires once) and the explicit
            // confirmation after the duplicate warning.
            'form_token' => ['required', 'uuid'],
            'confirm_duplicate' => ['boolean'],
        ];
    }
}
