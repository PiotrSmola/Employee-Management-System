<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestEnvironmentTest extends TestCase
{
    public function test_suite_runs_in_the_testing_environment_with_volatile_stores(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
    }

    public function test_environment_values_are_forced_over_the_process_environment(): void
    {
        $this->assertSame('testing', getenv('APP_ENV'));
        $this->assertSame('mysql', getenv('DB_CONNECTION'));
        $this->assertSame('array', getenv('CACHE_DRIVER'));
        $this->assertSame('array', getenv('CACHE_STORE'));
        $this->assertSame('array', getenv('SESSION_DRIVER'));
    }
}
