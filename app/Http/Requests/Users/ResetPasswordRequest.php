<?php

namespace App\Http\Requests\Users;

use App\Support\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    protected $errorBag = 'resetPassword';

    public function authorize(): bool
    {
        return $this->user()?->can(Ability::MANAGE_USERS) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
