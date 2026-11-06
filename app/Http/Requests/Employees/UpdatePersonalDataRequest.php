<?php

namespace App\Http\Requests\Employees;

class UpdatePersonalDataRequest extends EmployeeOperationRequest
{
    protected $errorBag = 'personal';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->personalRules();
    }
}
