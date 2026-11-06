<?php

namespace Tests\Feature;

use App\Support\FigureCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FigureCacheTest extends TestCase
{
    private const KEY = 'figure';

    private const TTL = 60;

    private function read(mixed $value, string $version = '', ?bool &$refreshRequested = null): array
    {
        return FigureCache::read(self::KEY, self::TTL, fn () => $value, function () use (&$refreshRequested) {
            $refreshRequested = true;
        }, $version);
    }

    public function test_the_first_read_computes_and_the_next_one_is_fresh(): void
    {
        $this->assertSame(['value' => 1, 'status' => FigureCache::FRESH], $this->read(1));
        $this->assertSame(['value' => 1, 'status' => FigureCache::FRESH], $this->read(2));
    }

    public function test_an_invalidated_value_is_stale_and_requests_a_refresh(): void
    {
        $this->read(1);
        FigureCache::invalidate(self::KEY);

        $this->assertSame(['value' => 1, 'status' => FigureCache::STALE], $this->read(2, refreshRequested: $requested));
        $this->assertTrue($requested);
        $this->assertFalse(FigureCache::isFresh(self::KEY));
    }

    public function test_an_expired_value_is_stale(): void
    {
        $this->read(1);
        $this->travel(self::TTL + 1)->seconds();

        $this->assertSame(FigureCache::STALE, $this->read(2)['status']);
    }

    public function test_the_stale_copy_goes_after_a_week(): void
    {
        $this->read(1);
        $this->travel(8)->days();

        $this->assertSame(['value' => 2, 'status' => FigureCache::FRESH], $this->read(2));
    }

    public function test_a_new_version_makes_the_value_stale_and_a_refresh_makes_it_fresh_again(): void
    {
        $this->read(1, 'a');

        $this->assertSame(['value' => 1, 'status' => FigureCache::STALE], $this->read(2, 'b'));
        $this->assertTrue(FigureCache::refresh(self::KEY, self::TTL, fn () => 2, version: 'b'));
        $this->assertSame(['value' => 2, 'status' => FigureCache::FRESH], $this->read(3, 'b'));
    }

    public function test_only_when_expired_leaves_a_fresh_value_alone(): void
    {
        $this->read(1);

        $this->assertFalse(FigureCache::refresh(self::KEY, self::TTL, fn () => 2, onlyWhenExpired: true));
        $this->assertSame(1, $this->read(3)['value']);
    }

    public function test_a_compute_overtaken_by_an_invalidation_does_not_make_the_value_fresh(): void
    {
        $this->read(1);

        FigureCache::refresh(self::KEY, self::TTL, function () {
            FigureCache::invalidate(self::KEY);

            return 2;
        });

        $this->assertFalse(FigureCache::isFresh(self::KEY));
        $this->assertSame(['value' => 1, 'status' => FigureCache::STALE], $this->read(3), 'The older compute does not replace the copy.');
    }

    public function test_a_reader_with_nothing_to_show_does_not_wait_for_another_process(): void
    {
        $this->assertTrue(Cache::lock(FigureCache::lockKey(self::KEY), 60)->get());

        $this->assertSame(['value' => null, 'status' => FigureCache::PREPARING], $this->read(1));
    }

    public function test_a_refresh_skips_a_key_another_process_is_computing(): void
    {
        $this->assertTrue(Cache::lock(FigureCache::lockKey(self::KEY), 60)->get());

        $this->assertFalse(FigureCache::refresh(self::KEY, self::TTL, fn () => 1));
    }

    public function test_changes_of_the_data_leave_no_retired_files_behind(): void
    {
        $path = storage_path('framework/testing/figure-cache-'.uniqid());
        config(['cache.default' => 'file', 'cache.stores.file.path' => $path, 'cache.stores.file.lock_path' => $path]);
        Cache::forgetDriver('file');

        try {
            $this->read(1);
            $files = count(File::allFiles($path));

            foreach (range(2, 6) as $value) {
                FigureCache::invalidate(self::KEY);
                FigureCache::refresh(self::KEY, self::TTL, fn () => $value);
            }

            // One more file for the generation, none per change.
            $this->assertSame($files + 1, count(File::allFiles($path)));
            $this->assertSame(6, $this->read(7)['value']);
        } finally {
            File::deleteDirectory($path);
        }
    }
}
