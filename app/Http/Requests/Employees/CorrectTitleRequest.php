<?php

namespace App\Http\Requests\Employees;

class CorrectTitleRequest extends EmployeeOperationRequest
{
    protected $errorBag = 'correctTitle';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['title' => $this->titleRules()];
    }
}
