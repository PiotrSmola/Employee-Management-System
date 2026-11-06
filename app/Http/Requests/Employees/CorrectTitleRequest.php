<?php

namespace App\Http\Requests\Employees;

use App\Support\EmploymentRules;

class CorrectTitleRequest extends EmployeeOperationRequest
{
    protected $errorBag = 'correctTitle';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => $this->titleRules(),
            'reason' => ['required', 'string', 'max:500'],
            // The row the form showed; the correction is refused if it is not the latest any more.
            'row_from_date' => ['required', 'date_format:Y-m-d'],
            'row_title' => ['required', 'string', 'max:'.EmploymentRules::TITLE_MAX],
        ];
    }
}
