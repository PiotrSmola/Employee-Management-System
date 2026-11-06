<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API counterpart of `active`: tokens of a deactivated account are refused
 * (403) while it is inactive; there is no session to end.
 */
class EnsureTokenUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            abort(403, __('Your account has been deactivated.'));
        }

        return $next($request);
    }
}
