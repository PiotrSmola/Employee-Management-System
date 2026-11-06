<?php

namespace App\Reports;

use App\Support\EmployeeDataCache;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Caches the raw figures of the reports per (figure, department scope, as-of
 * date) in the versioned namespace of EmployeeDataCache, so
 * EmployeeDataCache::flush() retires all of them at once.
 *
 *   reports:<version>:<figure>:<scope>:<as-of>     e.g. reports:k3j9x0aa:movements:mgr-d005:1995-12-31
 *
 * The figures only change when the employee data changes (writers flush), so
 * the TTL is long; `php artisan reports:warm` (scheduled hourly) recomputes the
 * current state of every department after a flush or expiry.
 *
 * Computing a figure can take minutes on a loaded server, so requests compute one
 * at a time: the first missing figure takes a global lock, held until the request
 * calls {@see release()}, and meanwhile other requests that miss the cache get a
 * {@see ReportsBusyException}. Cached figures are served regardless.
 */
#[Scoped]
class ReportCache
{
    /** Lifetime of a cached figure in seconds. */
    public const TTL = 86400;

    public const LOCK = 'reports:compute';

    /** Longest a request may compute (PHP time limit), and when a dead request's lock expires. */
    public const COMPUTE_SECONDS = 300;

    /** Bypass the cache (e.g. the warm command with --fresh recomputes on purpose). */
    private bool $refresh = false;

    private bool $guarded = true;

    private ?Lock $lock = null;

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

        if (! $this->refresh) {
            $value = Cache::get($key);

            if ($value !== null) {
                return $value;
            }
        }

        $this->acquire();
        $value = $compute();
        Cache::put($key, $value, self::TTL);

        return $value;
    }

    /** End of a request: let the next one compute. */
    public function release(): void
    {
        $this->lock?->release();
        $this->lock = null;
    }

    /** Compute without the lock: the warm command is one process that nobody waits for. */
    public function unguarded(): static
    {
        $this->guarded = false;

        return $this;
    }

    public function refreshing(bool $refresh = true): static
    {
        $this->refresh = $refresh;

        return $this;
    }

    private function acquire(): void
    {
        if (! $this->guarded || $this->lock !== null) {
            return;
        }

        $lock = Cache::lock(self::LOCK, self::COMPUTE_SECONDS);

        if (! $lock->get()) {
            throw new ReportsBusyException;
        }

        $this->lock = $lock;
        set_time_limit(self::COMPUTE_SECONDS);
    }
}
