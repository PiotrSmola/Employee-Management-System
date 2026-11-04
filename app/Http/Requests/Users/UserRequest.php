<?php

namespace App\Http\Requests\Users;

use App\Enums\Role;
use App\Models\User;
use App\Support\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Create / edit an application account (administrators only).
 */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Ability::MANAGE_USERS) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'is_active' => $this->boolean('is_active'),
            'dept_no' => $this->input('role') === Role::Manager->value ? $this->input('dept_no') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->getKey())],
            'role' => ['required', Rule::enum(Role::class)],
            'dept_no' => ['nullable', 'required_if:role,'.Role::Manager->value, 'string', Rule::exists('departments', 'dept_no')],
            'is_active' => ['boolean'],
            'password' => $user === null
                ? ['required', 'string', 'confirmed', Password::defaults()]
                : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dept_no.required_if' => __('A manager needs a department.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['dept_no' => __('department')];
    }
}
