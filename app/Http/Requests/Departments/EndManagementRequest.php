<?php

namespace App\Http\Requests\Departments;

use App\Services\DepartmentManagementService;
use App\Support\Ability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * End the current manager's period without a successor.
 */
class EndManagementRequest extends FormRequest
{
    protected $errorBag = DepartmentManagementService::END_BAG;

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
            'to_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['to_date' => __('end date')];
    }
}
