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
        return [
            'salary' => $this->salaryRules(),
            'reason' => ['required', 'string', 'max:500'],
            'confirm_unusual' => ['boolean'],
            // The row the form showed; the correction is refused if it is not the latest any more.
            'row_from_date' => ['required', 'date_format:Y-m-d'],
            'row_salary' => ['required', 'integer'],
        ];
    }
}
