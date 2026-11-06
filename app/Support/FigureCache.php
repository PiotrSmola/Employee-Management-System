<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Cache for figures that take far longer to compute than a request should wait
 * (minutes on a loaded server). Three things plain Cache::remember() does not do:
 *
 *  - stampede: one compute at a time, per key. Others wait briefly, then get
 *    FigureCache::PREPARING instead of hanging ({@see read()}).
 *  - stale write-back: the fresh value lives under a key that contains a
 *    generation; {@see invalidate()} changes the generation, so a compute that
 *    started before the invalidation writes to a key nobody reads.
 *  - cold cost: the last good value stays available for a week, so a reader
 *    gets it at once ({@see STALE}) and the refresh runs after its response.
 *
 *   ['value' => $figures, 'status' => $status] = FigureCache::read($key, 900, $compute, $refreshLater);
 *   FigureCache::invalidate($key);                     // the data changed
 *   FigureCache::refresh($key, 900, $compute);         // scheduled warming
 */
final class FigureCache
{
    public const FRESH = 'fresh';

    /** The last good value; a refresh has been requested. */
    public const STALE = 'stale';

    /** Nothing cached yet and another process is computing it; value is null. */
    public const PREPARING = 'preparing';

    private const STALE_TTL = 604800;

    /** Lock lifetime: must outlast a compute, and frees the key if a process dies. */
    private const LOCK_SECONDS = 600;

    /** How long a reader with nothing to show waits for another process's compute. */
    private const WAIT_SECONDS = 2;

    /**
     * @param  Closure(): mixed  $compute  must not return null
     * @param  Closure(): void  $refreshLater  called when the stale value is returned
     * @return array{value: mixed, status: string}
     */
    public static function read(string $key, int $ttl, Closure $compute, Closure $refreshLater): array
    {
        $fresh = Cache::get(self::freshKey($key));
        if ($fresh !== null) {
            return ['value' => $fresh, 'status' => self::FRESH];
        }

        $stale = Cache::get(self::staleKey($key));
        if ($stale !== null) {
            $refreshLater();

            return ['value' => $stale['value'], 'status' => self::STALE];
        }

        try {
            return Cache::lock(self::lockKey($key), self::LOCK_SECONDS)->block(self::WAIT_SECONDS, function () use ($key, $ttl, $compute) {
                // Another process may have finished while this one waited for the lock.
                $fresh = Cache::get(self::freshKey($key));

                return ['value' => $fresh ?? self::compute($key, $ttl, $compute), 'status' => self::FRESH];
            });
        } catch (LockTimeoutException) {
            return ['value' => null, 'status' => self::PREPARING];
        }
    }

    /**
     * Compute and store the value now, unless another process is already doing
     * so. With $onlyWhenExpired a value that is still fresh is left alone.
     *
     * @param  Closure(): mixed  $compute
     * @return bool was the value computed?
     */
    public static function refresh(string $key, int $ttl, Closure $compute, bool $onlyWhenExpired = false): bool
    {
        $lock = Cache::lock(self::lockKey($key), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        try {
            if ($onlyWhenExpired && self::isFresh($key)) {
                return false;
            }

            self::compute($key, $ttl, $compute);

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * The data behind $key changed: from now on it is not fresh, and computes
     * already running cannot make it fresh again. The stale copy is kept on purpose.
     */
    public static function invalidate(string $key): void
    {
        Cache::forever(self::generationKey($key), Str::random(12));
    }

    public static function isFresh(string $key): bool
    {
        return Cache::has(self::freshKey($key));
    }

    /**
     * Key of the lock around computing $key.
     */
    public static function lockKey(string $key): string
    {
        return $key.':lock';
    }

    private static function compute(string $key, int $ttl, Closure $compute): mixed
    {
        $generation = self::generation($key);
        $value = $compute();

        Cache::put($key.':fresh:'.$generation, $value, $ttl);

        // A compute overtaken by invalidate() must not replace a newer stale copy.
        if (self::generation($key) === $generation) {
            Cache::put(self::staleKey($key), ['value' => $value], self::STALE_TTL);
        }

        return $value;
    }

    private static function generation(string $key): string
    {
        return (string) Cache::get(self::generationKey($key), '0');
    }

    private static function freshKey(string $key): string
    {
        return $key.':fresh:'.self::generation($key);
    }

    private static function staleKey(string $key): string
    {
        return $key.':stale';
    }

    private static function generationKey(string $key): string
    {
        return $key.':generation';
    }
}
