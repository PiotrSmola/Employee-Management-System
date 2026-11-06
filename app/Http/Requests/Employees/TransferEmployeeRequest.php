<?php

namespace App\Http\Requests\Employees;

class TransferEmployeeRequest extends EmployeeOperationRequest
{
    protected $errorBag = 'transfer';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dept_no' => $this->departmentRules(),
            'effective_date' => $this->dateRules(),
        ];
    }
}
