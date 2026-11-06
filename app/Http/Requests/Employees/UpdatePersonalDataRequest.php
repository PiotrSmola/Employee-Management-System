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
        return [
            ...$this->personalRules(),
            // EmployeeOperationsService::personalSnapshot() of the data the form showed.
            'snapshot' => ['required', 'string', 'size:40'],
        ];
    }
}
