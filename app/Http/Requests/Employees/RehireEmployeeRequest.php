<?php

namespace App\Http\Requests\Employees;

class RehireEmployeeRequest extends EmployeeOperationRequest
{
    protected $errorBag = 'rehire';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dept_no' => $this->departmentRules(),
            'title' => $this->titleRules(),
            'salary' => $this->salaryRules(),
            'effective_date' => $this->dateRules(),
        ];
    }
}
