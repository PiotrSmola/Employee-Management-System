<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The `auth.session` middleware (Illuminate\Session\Middleware\AuthenticateSession)
 * remembers the signed-in user's password hash in the session and signs the
 * user out as soon as it no longer matches - that is how a password change or
 * reset ends the user's other sessions.
 *
 * It stores the hash only when the key is missing. A value left in the session
 * by a previous account (e.g. one deleted while signed in, without a logout)
 * would therefore sign a newly logged-in user out on their next request, so the
 * login stores the new user's hash explicitly.
 *
 * Coupled to framework internals: the session key "password_hash_{guard}" is
 * the one AuthenticateSession uses in Laravel 11 (see its storePasswordHashInSession()).
 * tests/Feature/Auth/LoginTest::test_sign_in_works_in_a_session_left_over_from_another_account
 * fails if a framework upgrade changes it.
 */
final class SessionPasswordHash
{
    public static function key(?string $guard = null): string
    {
        return 'password_hash_'.($guard ?? Auth::getDefaultDriver());
    }

    public static function store(Request $request, Authenticatable $user, ?string $guard = null): void
    {
        $request->session()->put(self::key($guard), $user->getAuthPassword());
    }
}
