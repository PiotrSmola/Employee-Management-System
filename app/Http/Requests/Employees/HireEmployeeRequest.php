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
        ];
    }
}
