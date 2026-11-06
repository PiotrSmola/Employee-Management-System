<?php

namespace Tests\Feature;

use App\Support\AfterResponse;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AfterResponseTest extends TestCase
{
    public function test_the_command_runs_when_the_request_is_over(): void
    {
        Artisan::spy();

        AfterResponse::artisan('reports:warm', ['--scope' => 'all']);
        Artisan::shouldNotHaveReceived('call');

        $this->app->terminate();
        Artisan::shouldHaveReceived('call')->with('reports:warm', ['--scope' => 'all']);
    }

    public function test_a_failing_command_does_not_reach_the_visitor(): void
    {
        Artisan::shouldReceive('call')->andThrow(new \RuntimeException('Disk full'));

        AfterResponse::artisan('reports:warm');

        $this->app->terminate();
        $this->addToAssertionCount(1);
    }

    public function test_without_a_way_to_end_the_response_it_is_a_process_of_its_own(): void
    {
        Process::fake();
        $detached = Closure::bind(fn (string $command, array $parameters) => self::detached($command, $parameters), null, AfterResponse::class);

        $detached('reports:warm', ['--scope' => 'dept-d005', '--as-of' => '1995-12-31']);
        $detached('departments:warm', ['--only' => ['d005'], '--expired' => true]);
        $detached('reports:warm', ['--scope' => 'dept-d005', '--as-of' => '1995-12-31']);

        Process::assertRanTimes(fn ($process) => str_contains($process->command, "reports:warm --scope='dept-d005' --as-of='1995-12-31' > /dev/null 2>&1 &"), 1);
        Process::assertRanTimes(fn ($process) => str_contains($process->command, "departments:warm --only='d005' --expired > /dev/null 2>&1 &"), 1);
    }
}
