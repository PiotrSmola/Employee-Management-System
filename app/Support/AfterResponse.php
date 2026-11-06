<?php

namespace App\Support;

use Illuminate\Console\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Work a visitor must not wait for, started when their response has been sent:
 *
 *   AfterResponse::artisan('reports:warm', ['--scope' => 'all']);
 *
 * "After the response" only means that with FPM (fastcgi_finish_request). Apache
 * with mod_php - the Docker image - and the built-in server keep the connection
 * open until the script ends: the browser has the page but no DOMContentLoaded, so
 * scripts and charts would wait for the work. There the command runs as a
 * process of its own and the script ends at once.
 */
final class AfterResponse
{
    /** Seconds during which the same detached command is not started again. */
    private const DEDUPLICATE_SECONDS = 30;

    /**
     * @param  array<string, mixed>  $parameters  as for Artisan::call()
     */
    public static function artisan(string $command, array $parameters = []): void
    {
        app()->terminating(function () use ($command, $parameters) {
            try {
                self::endsBeforeTheScript() ? Artisan::call($command, $parameters) : self::detached($command, $parameters);
            } catch (Throwable $e) {
                // The response is out and the next visitor asks again.
                report($e);
            }
        });
    }

    private static function endsBeforeTheScript(): bool
    {
        return function_exists('fastcgi_finish_request') || in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private static function detached(string $command, array $parameters): void
    {
        $line = $command;

        foreach ($parameters as $option => $value) {
            foreach ($value === true ? [null] : (array) $value as $item) {
                $line .= ' '.$option.($item === null ? '' : '='.escapeshellarg($item));
            }
        }

        if (Cache::add('after-response:'.sha1($line), true, self::DEDUPLICATE_SECONDS)) {
            Process::path(base_path())->run(Application::formatCommandString($line).' > /dev/null 2>&1 &');
        }
    }
}
