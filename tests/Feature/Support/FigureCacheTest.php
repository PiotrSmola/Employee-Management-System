<?php

namespace Tests\Feature\Support;

use App\Support\FigureCache;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class FigureCacheTest extends TestCase
{
    private const KEY = 'figures';

    private int $computed = 0;

    private int $refreshRequests = 0;

    /**
     * @return array{value: mixed, status: string}
     */
    private function read(mixed $value = 'value', ?callable $during = null): array
    {
        return FigureCache::read(self::KEY, 60, function () use ($value, $during) {
            $this->computed++;
            $during && $during();

            return $value;
        }, function () {
            $this->refreshRequests++;
        });
    }

    public function test_the_first_read_computes_and_the_next_ones_do_not(): void
    {
        $this->assertSame(['value' => 'value', 'status' => FigureCache::FRESH], $this->read());
        $this->assertSame(['value' => 'value', 'status' => FigureCache::FRESH], $this->read('other'));

        $this->assertSame(1, $this->computed);
        $this->assertSame(0, $this->refreshRequests);
    }

    public function test_an_empty_result_counts_as_cached(): void
    {
        $this->read([]);
        $this->read([]);

        $this->assertSame(1, $this->computed);
    }

    public function test_a_read_with_nothing_cached_reports_preparing_while_another_process_computes(): void
    {
        $this->assertTrue(Cache::lock(FigureCache::lockKey(self::KEY), 60)->get());

        $this->assertSame(['value' => null, 'status' => FigureCache::PREPARING], $this->read());

        $this->assertSame(0, $this->computed);
    }

    public function test_a_failed_compute_releases_the_lock(): void
    {
        try {
            $this->read(during: fn () => throw new RuntimeException('database went away'));
            $this->fail('The exception should reach the caller.');
        } catch (RuntimeException) {
        }

        $this->assertSame('value', $this->read()['value']);
    }

    public function test_after_invalidating_the_last_good_value_is_served_and_a_refresh_requested(): void
    {
        $this->read('old');

        FigureCache::invalidate(self::KEY);

        $this->assertFalse(FigureCache::isFresh(self::KEY));
        $this->assertSame(['value' => 'old', 'status' => FigureCache::STALE], $this->read('new'));
        $this->assertSame(1, $this->computed);
        $this->assertSame(1, $this->refreshRequests);

        FigureCache::refresh(self::KEY, 60, fn () => 'new');

        $this->assertSame(['value' => 'new', 'status' => FigureCache::FRESH], $this->read());
    }

    public function test_an_expired_value_is_served_stale(): void
    {
        $this->read('old');

        $this->travel(61)->seconds();

        $this->assertSame(['value' => 'old', 'status' => FigureCache::STALE], $this->read('new'));
    }

    public function test_a_compute_overtaken_by_an_invalidation_does_not_become_the_current_value(): void
    {
        $this->read('first');
        FigureCache::invalidate(self::KEY);

        // Started before the change, finishes after it.
        FigureCache::refresh(self::KEY, 60, function () {
            FigureCache::invalidate(self::KEY);

            return 'outdated';
        });

        $this->assertFalse(FigureCache::isFresh(self::KEY));
        $this->assertSame(['value' => 'first', 'status' => FigureCache::STALE], $this->read());
    }

    public function test_refresh_skips_while_another_process_computes(): void
    {
        $this->assertTrue(Cache::lock(FigureCache::lockKey(self::KEY), 60)->get());

        $this->assertFalse(FigureCache::refresh(self::KEY, 60, fn () => $this->fail('Must not compute.')));
    }

    public function test_refresh_of_expired_figures_leaves_fresh_ones_alone(): void
    {
        $this->read('kept');

        $this->assertFalse(FigureCache::refresh(self::KEY, 60, fn () => 'replaced', onlyWhenExpired: true));
        $this->assertTrue(FigureCache::refresh(self::KEY, 60, fn () => 'replaced'));
        $this->assertSame('replaced', $this->read()['value']);
    }
}
