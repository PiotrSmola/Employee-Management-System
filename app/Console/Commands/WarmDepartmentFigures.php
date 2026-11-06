<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Services\DepartmentStatistics;
use Illuminate\Console\Command;

/**
 * Recomputes the cached department figures, so that readers normally never pay
 * for computing them (scheduled hourly in routes/console.php). A reader who got
 * a stale copy starts it with --only and --expired for just that page
 * (DepartmentStatistics).
 */
class WarmDepartmentFigures extends Command
{
    public const OVERVIEW = 'overview';

    protected $signature = 'departments:warm
        {--only=* : Only these figures: overview or a dept_no; default: all}
        {--expired : Keep figures that are still fresh}';

    protected $description = 'Recompute the cached figures of the departments index and pages';

    public function handle(DepartmentStatistics $statistics): int
    {
        $only = $this->option('only');
        $expired = (bool) $this->option('expired');

        if ($only === [] || in_array(self::OVERVIEW, $only, true)) {
            $this->report(self::OVERVIEW, $statistics->refreshOverview($expired));
        }

        $departments = Department::query()->orderBy('dept_no')
            ->when($only !== [], fn ($query) => $query->whereIn('dept_no', $only))
            ->pluck('dept_no');

        foreach ($departments as $deptNo) {
            $this->report($deptNo, $statistics->refreshDetails($deptNo, $expired));
        }

        return self::SUCCESS;
    }

    private function report(string $name, bool $computed): void
    {
        $this->line($name.': '.($computed ? 'refreshed' : 'skipped, fresh or already being computed'));
    }
}
