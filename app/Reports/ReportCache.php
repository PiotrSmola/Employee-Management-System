<?php

namespace App\Reports;

use App\Support\AfterResponse;
use App\Support\EmployeeDataCache;
use App\Support\FigureCache;
use Closure;
use Illuminate\Container\Attributes\Scoped;

/**
 * Caches the raw figures of the reports per (figure, department scope, as-of
 * date) in FigureCache:
 *
 *   reports:<figure>:<scope>:<as-of>     e.g. reports:movements:mgr-d005:1995-12-31
 *
 * A figure is fresh for its TTL, for one version of the reports (changed by
 * EmployeeDataCache::flush(), so one write retires all of them at once) and, for
 * the current state, for one day: ages and tenures change with the date. A
 * figure that is no longer fresh stays available, so neither a write nor midnight
 * makes the next visitor wait: they get the last good copy and `reports:warm`
 * recomputes their scope after the response ({@see AfterResponse}). Scheduled hourly,
 * it also recomputes the current state of every department ahead of the visitors.
 *
 * Only a figure that was never computed (e.g. a new as-of date) is computed in
 * the request. One process per figure does that; meanwhile others get a
 * {@see ReportsBusyException}.
 */
#[Scoped]
class ReportCache
{
    /** Seconds a cached figure is fresh. */
    public const TTL = 86400;

    /** Longest a request may compute (PHP time limit). */
    public const COMPUTE_SECONDS = 300;

    private bool $warming = false;

    /** Warming also recomputes figures that are still fresh. */
    private bool $force = false;

    /**
     * Scopes and dates already waiting for the end of this request.
     *
     * @var array<string, true>
     */
    private array $refreshing = [];

    /** FigureCache::STALE once this request got a figure that is being refreshed. */
    private string $status = FigureCache::FRESH;

    public static function key(string $figure, ReportContext $context): string
    {
        return EmployeeDataCache::report($figure, $context->scopeKey(), $context->current ? 'current' : $context->date());
    }

    /**
     * What a figure depends on besides its key: the reports version and, for the
     * current state, the day.
     */
    public static function version(ReportContext $context): string
    {
        return EmployeeDataCache::reportsVersion().($context->current ? ':'.$context->date() : '');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     *
     * @throws ReportsBusyException nothing cached yet and another process is computing it
     */
    public function remember(string $figure, ReportContext $context, Closure $compute): mixed
    {
        $key = self::key($figure, $context);
        $version = self::version($context);

        if ($this->warming) {
            FigureCache::refresh($key, self::TTL, $compute, ! $this->force, $version);
        }

        $read = FigureCache::read(
            $key,
            self::TTL,
            fn () => $this->limited($compute),
            fn () => $this->refreshAfterResponse($context),
            $version,
        );

        if ($read['status'] === FigureCache::PREPARING) {
            throw new ReportsBusyException;
        }

        if ($read['status'] === FigureCache::STALE) {
            $this->status = FigureCache::STALE;
        }

        return $read['value'];
    }

    /** How current the figures of this request are. */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * Compute figures here instead of after a response (the warm command).
     */
    public function warming(bool $force = false): static
    {
        $this->warming = true;
        $this->force = $force;

        return $this;
    }

    private function limited(Closure $compute): mixed
    {
        set_time_limit(self::COMPUTE_SECONDS);

        return $compute();
    }

    private function refreshAfterResponse(ReportContext $context): void
    {
        $scope = $context->scopeKey().':'.$context->asOfKey();

        if ($this->warming || isset($this->refreshing[$scope])) {
            return;
        }

        $this->refreshing[$scope] = true;

        AfterResponse::artisan('reports:warm', array_filter([
            '--scope' => $context->scopeKey(),
            '--as-of' => $context->current ? null : $context->date(),
        ]));
    }
}
