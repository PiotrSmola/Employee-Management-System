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
 *  - stale write-back: an entry remembers the generation it was computed for;
 *    {@see invalidate()} changes the generation, so a compute that started before
 *    the invalidation cannot make the figures fresh again.
 *  - cold cost: the last good value stays available for a week, so a reader
 *    gets it at once ({@see STALE}) and the refresh runs after its response.
 *
 * A key has exactly one cache entry, stale or fresh, replaced by every compute.
 * The file store only deletes expired entries when they are read, so this keeps
 * the number of files at the number of keys, however often the data changes.
 *
 *   ['value' => $figures, 'status' => $status] = FigureCache::read($key, 900, $compute, $refreshLater);
 *   FigureCache::invalidate($key);                     // the data changed
 *   FigureCache::refresh($key, 900, $compute);         // scheduled warming
 *
 * $version (optional, on read(), refresh() and isFresh()) is what else the value
 * depends on: when it changes the value is no longer fresh, the stale copy stays.
 * Reports pass the version of all reports, plus the day for the current state,
 * instead of invalidating an unbounded number of keys one by one.
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
    public static function read(string $key, int $ttl, Closure $compute, Closure $refreshLater, string $version = ''): array
    {
        $entry = Cache::get(self::entryKey($key));

        if ($entry !== null) {
            if (self::entryIsFresh($entry, $key, $version)) {
                return ['value' => $entry['value'], 'status' => self::FRESH];
            }

            $refreshLater();

            return ['value' => $entry['value'], 'status' => self::STALE];
        }

        try {
            return Cache::lock(self::lockKey($key), self::LOCK_SECONDS)->block(self::WAIT_SECONDS, function () use ($key, $ttl, $compute, $version) {
                // Another process may have finished while this one waited for the lock.
                $entry = Cache::get(self::entryKey($key));

                return ['value' => $entry !== null ? $entry['value'] : self::compute($key, $ttl, $compute, $version), 'status' => self::FRESH];
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
    public static function refresh(string $key, int $ttl, Closure $compute, bool $onlyWhenExpired = false, string $version = ''): bool
    {
        $lock = Cache::lock(self::lockKey($key), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        try {
            if ($onlyWhenExpired && self::isFresh($key, $version)) {
                return false;
            }

            self::compute($key, $ttl, $compute, $version);

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

    public static function isFresh(string $key, string $version = ''): bool
    {
        $entry = Cache::get(self::entryKey($key));

        return $entry !== null && self::entryIsFresh($entry, $key, $version);
    }

    /**
     * Key of the lock around computing $key.
     */
    public static function lockKey(string $key): string
    {
        return $key.':lock';
    }

    private static function compute(string $key, int $ttl, Closure $compute, string $version): mixed
    {
        $generation = self::generation($key, $version);
        $value = $compute();

        // A compute overtaken by invalidate() must not replace a newer copy. Its generation
        // is out of date, so stored in an empty slot the value only counts as stale.
        if (self::generation($key, $version) === $generation || ! Cache::has(self::entryKey($key))) {
            Cache::put(self::entryKey($key), [
                'value' => $value,
                'generation' => $generation,
                'fresh_until' => now()->getTimestamp() + $ttl,
            ], self::STALE_TTL);
        }

        return $value;
    }

    /**
     * @param  array{value: mixed, generation: string, fresh_until: int}  $entry
     */
    private static function entryIsFresh(array $entry, string $key, string $version): bool
    {
        return $entry['generation'] === self::generation($key, $version)
            && $entry['fresh_until'] > now()->getTimestamp();
    }

    private static function generation(string $key, string $version): string
    {
        return Cache::get(self::generationKey($key), '0').':'.$version;
    }

    private static function entryKey(string $key): string
    {
        return $key.':figure';
    }

    private static function generationKey(string $key): string
    {
        return $key.':generation';
    }
}
