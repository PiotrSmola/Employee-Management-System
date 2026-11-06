<?php

namespace App\Http\Requests\Employees;

class TerminateEmployeeRequest extends EmployeeOperationRequest
{
    protected $errorBag = 'terminate';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'end_date' => $this->dateRules(),
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
