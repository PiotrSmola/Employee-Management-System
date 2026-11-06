<?php

namespace App\Jobs;

use App\Services\DepartmentStatistics;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Recomputes expired department figures after a reader got the stale copy:
 *
 *   RefreshDepartmentFigures::dispatch()->afterResponse();          // the overview
 *   RefreshDepartmentFigures::dispatch('d005')->afterResponse();    // one department
 *
 * Does nothing when another process is on it or the figures are fresh again.
 */
class RefreshDepartmentFigures
{
    use Dispatchable;

    public function __construct(public readonly ?string $deptNo = null)
    {
    }

    public function handle(DepartmentStatistics $statistics): void
    {
        $this->deptNo === null
            ? $statistics->refreshOverview(onlyWhenExpired: true)
            : $statistics->refreshDetails($this->deptNo, onlyWhenExpired: true);
    }
}
