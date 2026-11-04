<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Failed attempts allowed per e-mail + IP before the form is locked. */
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Check the credentials and log the user in. Deactivated accounts are
     * rejected (only after a correct password, so it does not reveal accounts).
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $guard = Auth::guard('web');

        if (! $guard->validate($this->only('email', 'password'))) {
            $this->failed(__('auth.failed'));
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();

        if (! $user->is_active) {
            $this->failed(__('Your account has been deactivated. Please contact an administrator.'));
        }

        $guard->login($user, $this->boolean('remember'));
        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * @throws ValidationException
     */
    private function failed(string $message): never
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

        throw ValidationException::withMessages(['email' => $message]);
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
