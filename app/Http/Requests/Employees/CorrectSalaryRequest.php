<?php

namespace App\Http\Requests\Employees;

class CorrectSalaryRequest extends EmployeeOperationRequest
{
    protected $errorBag = 'correctSalary';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['salary' => $this->salaryRules()];
    }
}
