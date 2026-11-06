<?php

namespace App\Reports;

use Illuminate\Http\Response;
use RuntimeException;

/**
 * A figure is not cached and another request is computing one: the visitor is
 * asked to come back instead of starting a second expensive computation.
 */
final class ReportsBusyException extends RuntimeException
{
    /** Seconds suggested to the browser (Retry-After). */
    private const RETRY_AFTER = 30;

    /** Expected under load, not an error for the log. */
    public function report(): void {}

    public function render(): Response
    {
        return response()->view('reports.busy', ['retryAfter' => self::RETRY_AFTER], 503)
            ->header('Retry-After', (string) self::RETRY_AFTER);
    }
}
