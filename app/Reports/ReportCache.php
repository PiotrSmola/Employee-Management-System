<?php

namespace App\Reports;

use App\Support\EmployeeDataCache;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caches the raw figures of the reports per (figure, department scope, as-of
 * date) in the versioned namespace of EmployeeDataCache, so
 * EmployeeDataCache::flush() retires all of them at once.
 *
 *   reports:<version>:<figure>:<scope>:<as-of>     e.g. reports:k3j9x0aa:movements:mgr-d005:current
 *
 * The figures only change when the employee data changes (writers flush), so
 * the TTL is long; `php artisan reports:warm` (scheduled hourly) recomputes the
 * current state of every department after a flush or expiry.
 */
class ReportCache
{
    /** Lifetime of a cached figure in seconds. */
    public const TTL = 86400;

    /** Bypass the cache (e.g. the warm command with --fresh recomputes on purpose). */
    private bool $refresh = false;

    public static function key(string $figure, ReportContext $context): string
    {
        return EmployeeDataCache::report($figure, $context->scopeKey(), $context->asOfKey());
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(string $figure, ReportContext $context, Closure $compute): mixed
    {
        $key = self::key($figure, $context);

        if ($this->refresh) {
            $value = $compute();
            Cache::put($key, $value, self::TTL);

            return $value;
        }

        return Cache::remember($key, self::TTL, $compute);
    }

    public function refreshing(bool $refresh = true): static
    {
        $this->refresh = $refresh;

        return $this;
    }
}
