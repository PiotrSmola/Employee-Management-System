<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use App\Reports\ReportCache;
use App\Reports\ReportCatalog;
use App\Reports\ReportContext;
use App\Support\EmployeeVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Precomputes the cached report figures for the current state (or --as-of) of
 * the whole company, every department filter and every manager's department
 * scope, so the report pages are served from the cache. Figures that are
 * already cached are kept unless --fresh is given. Scheduled hourly
 * (routes/console.php): after EmployeeDataCache::flush() the next run rebuilds them.
 */
class WarmReports extends Command
{
    protected $signature = 'reports:warm
        {--as-of= : Date (Y-m-d) to warm instead of the current state}
        {--department=* : Only these departments (dept_no); default: all}
        {--fresh : Recompute figures that are already cached}';

    protected $description = 'Precompute the cached report figures';

    public function handle(): int
    {
        $asOf = null;
        if ($this->option('as-of')) {
            try {
                $asOf = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('as-of')) ?: null;
            } catch (\Throwable) {
                $asOf = null;
            }

            if ($asOf === null) {
                $this->error('--as-of must be a date in the format Y-m-d.');

                return self::FAILURE;
            }
        }

        if ($this->option('fresh')) {
            app()->instance(ReportCache::class, (new ReportCache)->refreshing());
        }

        $catalog = app(ReportCatalog::class);
        $departments = $this->option('department') ?: Department::query()->orderBy('dept_no')->pluck('dept_no')->all();

        $contexts = ['all' => ReportContext::make(EmployeeVisibility::unrestricted(), null, $asOf)];
        foreach ($departments as $deptNo) {
            $contexts['dept '.$deptNo] = ReportContext::make(EmployeeVisibility::unrestricted(), $deptNo, $asOf);
            // A manager of the department (not saved): same filter, restricted to the visible employees.
            $manager = (new User)->forceFill(['role' => Role::Manager, 'dept_no' => $deptNo, 'is_active' => true]);
            $contexts['manager '.$deptNo] = ReportContext::make(EmployeeVisibility::for($manager), null, $asOf);
        }

        $total = microtime(true);
        foreach ($contexts as $label => $context) {
            $started = microtime(true);
            $catalog->warm($context);
            $this->line(sprintf('  %-14s %6.2f s', $label, microtime(true) - $started));
        }

        $this->info(sprintf('Warmed %d report scopes (%s) in %.1f s.', count($contexts), $contexts['all']->asOfKey(), microtime(true) - $total));

        return self::SUCCESS;
    }
}
