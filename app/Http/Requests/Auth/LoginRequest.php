<?php

namespace App\Http\Requests\Auth;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Failed attempts allowed per e-mail + IP before that pair is locked. */
    public const MAX_ATTEMPTS = 5;

    /** Failed attempts allowed per IP address (any e-mail), against password spraying. */
    public const MAX_ATTEMPTS_PER_IP = 20;

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
     * rejected only after a correct password, and an unknown e-mail costs a
     * password hash check too, so neither reveals which accounts exist.
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $guard = Auth::guard('web');
        $provider = $guard->getProvider();
        $credentials = $this->only('email', 'password');

        $user = $provider->retrieveByCredentials(['email' => $credentials['email']]);

        if (! $user instanceof User) {
            Hash::check((string) $credentials['password'], self::dummyHash());
            $this->failed(__('auth.failed'), null, 'unknown_email');
        }

        if (! $provider->validateCredentials($user, $credentials)) {
            $this->failed(__('auth.failed'), $user, 'invalid_password');
        }

        if (! $user->is_active) {
            $this->failed(__('Your account has been deactivated. Please contact an administrator.'), $user, 'inactive');
        }

        $guard->login($user, $this->boolean('remember'));
        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * Count the failed attempt, record it in the audit log (attempted e-mail and
     * reason, never the password) and reject the login. Throttled requests are
     * not logged, so the log grows at most at the rate limit.
     *
     * @throws ValidationException
     */
    private function failed(string $message, ?User $user, string $reason): never
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
        RateLimiter::hit($this->ipThrottleKey(), self::DECAY_SECONDS);

        AuditLogger::log('auth.login_failed', ActivityLog::SUBJECT_USER, $user?->id, [
            'email' => Str::limit(Str::lower((string) $this->input('email')), 120, ''),
            'reason' => $reason,
        ]);

        throw ValidationException::withMessages(['email' => $message]);
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        $key = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->ipThrottleKey(), self::MAX_ATTEMPTS_PER_IP) => $this->ipThrottleKey(),
            default => null,
        };

        if ($key === null) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return 'login:'.Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    public function ipThrottleKey(): string
    {
        return 'login-ip:'.$this->ip();
    }

    /**
     * A hash made with the current hashing settings, so checking a password
     * against it takes as long as checking a real account's password.
     */
    private static function dummyHash(): string
    {
        return Cache::rememberForever('auth:login-dummy-hash:'.config('hashing.driver'), fn () => Hash::make(Str::random(32)));
    }
}
