<?php

namespace App\Http\Requests\Employees;

/**
 * Undo the last change of an employee; entry_id is the audit entry the user saw.
 */
class UndoChangeRequest extends EmployeeOperationRequest
{
    protected $errorBag = 'undo';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['entry_id' => ['required', 'integer']];
    }
}
