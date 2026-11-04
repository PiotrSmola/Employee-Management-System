<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * E-mail + password login. There is no public registration and no e-mail
 * password reset: accounts and password resets are handled by administrators.
 */
class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();

        $request->session()->regenerate();

        // Not a profile change - keep updated_at as it is.
        $user->timestamps = false;
        $user->forceFill(['last_login_at' => now()])->save();
        $user->timestamps = true;

        return redirect()->intended(route('index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
