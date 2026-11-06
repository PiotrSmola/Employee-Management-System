<?php

namespace App\Http\Requests\Departments;

use App\Models\Department;
use App\Support\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a department (code + name) or rename one (name only; the code is the
 * key of the whole history and cannot change).
 */
class DepartmentRequest extends FormRequest
{
    /** Department codes look like the dataset's: "d" and three digits. */
    public const CODE_PATTERN = '/^d[0-9]{3}$/';

    public function authorize(): bool
    {
        return $this->user()?->can(Ability::MANAGE_DEPARTMENTS) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'dept_name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('dept_name'))),
        ]);

        if ($this->department() === null) {
            $this->merge(['dept_no' => strtolower(trim((string) $this->input('dept_no')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $department = $this->department();

        $rules = [
            'dept_name' => [
                'required', 'string', 'max:40',
                Rule::unique('departments', 'dept_name')->ignore($department?->dept_no, 'dept_no'),
            ],
        ];

        if ($department === null) {
            $rules['dept_no'] = ['required', 'string', 'regex:'.self::CODE_PATTERN, Rule::unique('departments', 'dept_no')];
        } else {
            $rules['dept_no'] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dept_no.regex' => __('The code must be "d" followed by three digits, e.g. d010.'),
            'dept_no.prohibited' => __('The department code cannot be changed.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['dept_no' => __('code'), 'dept_name' => __('name')];
    }

    private function department(): ?Department
    {
        $department = $this->route('department');

        return $department instanceof Department ? $department : null;
    }
}
