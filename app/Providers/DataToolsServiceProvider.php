<?php

namespace App\Providers;

use App\Http\Api\ApiErrorRenderer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Data tools (exports, import) and the REST API: the API rate limit, the JSON
 * error format of /api/* and the data of the "API tokens" section of My account.
 */
class DataToolsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // `throttle:api`: per token owner (all tokens of a user share the limit), per IP before authentication.
        // Registered when the limiter is first used: resolving it here would bind it to the cache store
        // configured at boot time (the test suite switches the store after booting).
        $this->callAfterResolving(RateLimiter::class, fn (RateLimiter $limiter) => $limiter->for('api', fn (Request $request) => Limit::perMinute((int) config('data_tools.api.rate_limit', 60))
            ->by($request->user() ? 'user:'.$request->user()->getKey() : 'ip:'.$request->ip())));

        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            if (method_exists($handler, 'renderable')) {
                $handler->renderable(fn (Throwable $e, Request $request) => $request->is('api', 'api/*')
                    ? ApiErrorRenderer::render($e)
                    : null);
            }
        });

        View::composer('account.api-tokens', function ($view) {
            $user = auth()->user();

            $view->with([
                'apiTokens' => $user ? $user->tokens()->latest('id')->get() : collect(),
                'maxApiTokens' => (int) config('data_tools.api.max_tokens', 20),
            ]);
        });
    }
}
