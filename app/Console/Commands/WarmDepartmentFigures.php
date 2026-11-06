<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Services\DepartmentStatistics;
use Illuminate\Console\Command;

/**
 * Recomputes the cached department figures, so that readers normally never pay
 * for computing them (scheduled hourly in routes/console.php).
 */
class WarmDepartmentFigures extends Command
{
    protected $signature = 'departments:warm';

    protected $description = 'Recompute the cached figures of the departments index and pages';

    public function handle(DepartmentStatistics $statistics): int
    {
        $this->report('overview', $statistics->refreshOverview());

        foreach (Department::query()->orderBy('dept_no')->pluck('dept_no') as $deptNo) {
            $this->report($deptNo, $statistics->refreshDetails($deptNo));
        }

        return self::SUCCESS;
    }

    private function report(string $name, bool $computed): void
    {
        $this->line($name.': '.($computed ? 'refreshed' : 'skipped, already being computed'));
    }
}
