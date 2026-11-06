<?php

namespace App\Http\Requests\Departments;

use App\Services\DepartmentManagementService;
use App\Support\Ability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * New department manager: employee + effective date. The rules that depend on
 * the data (current employee of the department, date after the current
 * manager's start, ...) are checked by DepartmentManagementService::assignManager().
 */
class AssignManagerRequest extends FormRequest
{
    protected $errorBag = DepartmentManagementService::ASSIGN_BAG;

    public function authorize(): bool
    {
        return $this->user()?->can(Ability::MANAGE_DEPARTMENTS) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emp_no' => ['required', 'integer', 'min:1'],
            'from_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['emp_no.required' => __('Choose an employee of the department.')];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['emp_no' => __('employee'), 'from_date' => __('effective date')];
    }
}
