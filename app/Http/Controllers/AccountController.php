<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\AuditLogger;
use App\Support\SessionPasswordHash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * "My account": every user can change their own name and password.
 * E-mail, role, department and status are managed by administrators.
 */
class AccountController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.edit', ['user' => $request->user()]);
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

    public function updatePassword(Request $request): RedirectResponse
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

        return redirect()->route('account.edit')->with('status', __('Your password has been changed.'));
    }
}
