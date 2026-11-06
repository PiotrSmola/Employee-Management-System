<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `api` rate limiter, applied before authentication so that guessing tokens is
 * limited too. A separate class instead of `throttle:api`, so that it can go first in
 * the middleware priority without changing the order of the web routes' throttles.
 * Limiter and priority are defined in DataToolsServiceProvider.
 */
class ThrottleApi
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->throttle->handle($request, $next, 'api');
    }
}
