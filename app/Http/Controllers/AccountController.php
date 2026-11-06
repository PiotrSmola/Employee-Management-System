<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\AuditLogger;
use App\Services\UserManagementService;
use App\Support\SessionPasswordHash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * "My account": every user can change their own name and password.
 * E-mail, role, department and status are managed by administrators.
 */
class AccountController extends Controller
{
    public function edit(Request $request): Response
    {
        $response = response()->view('account.edit', ['user' => $request->user()]);

        // A new API token is shown once: the page must not stay in a browser or proxy cache.
        return $request->session()->has('api_token_plain')
            ? $response->header('Cache-Control', 'no-store, private')
            : $response;
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('profile', [
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $before = $user->only('name');
        $user->update($validated);

        $changes = AuditLogger::diff($before, $user->only('name'));
        if ($changes !== []) {
            AuditLogger::log('user.updated', ActivityLog::SUBJECT_USER, $user->id, $changes, $user);
        }

        return redirect()->route('account.edit')->with('status', __('Your name has been updated.'));
    }

    public function updatePassword(Request $request, UserManagementService $users): RedirectResponse
    {
        $validated = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);

        $user = $request->user();
        $user->update(['password' => $validated['password']]);

        // Other sessions are signed out by the auth.session middleware (password
        // hash changed); keep this one, including a "remember me" cookie.
        Auth::guard('web')->login($user, $request->hasCookie(Auth::guard('web')->getRecallerName()));
        $request->session()->regenerate();
        SessionPasswordHash::store($request, $user, 'web');

        AuditLogger::log('user.password_changed', ActivityLog::SUBJECT_USER, $user->id, [], $user);
        $users->revokeApiTokens($user, 'password_changed', $user);

        return redirect()->route('account.edit')->with('status', __('Your password has been changed.'));
    }
}
