<?php

namespace App\Http\Requests\Employees;

use App\Services\EmployeeOperationsService;
use Illuminate\Validation\Rule;

class ChangeTitleRequest extends EmployeeOperationRequest
{
    protected $errorBag = 'title';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => $this->titleRules(),
            'kind' => ['required', Rule::in(array_keys(EmployeeOperationsService::TITLE_CHANGE_ACTIONS))],
            'effective_date' => $this->dateRules(),
        ];
    }
}
