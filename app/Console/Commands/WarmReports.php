<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use App\Reports\ReportCache;
use App\Reports\ReportCatalog;
use App\Reports\ReportContext;
use App\Support\EmployeeVisibility;
use Illuminate\Console\Command;
use Throwable;

/**
 * Precomputes the cached report figures for the current state (or --as-of) of
 * the whole company, every department filter and every manager's department
 * scope, so the report pages are served from the cache. Figures that are
 * already cached are kept unless --fresh is given. Scheduled hourly
 * (routes/console.php): after EmployeeDataCache::flush() the next run rebuilds them.
 * A scope that fails does not stop the others; the command then ends with an error.
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
        if ($this->option('as-of') !== null) {
            $asOf = ReportContext::parseDate((string) $this->option('as-of'));

            if ($asOf === null) {
                $this->error('--as-of must be a real date in the format Y-m-d, from '.ReportContext::EARLIEST_YEAR.' on.');

                return self::FAILURE;
            }
        }

        $departments = $this->departments();
        if ($departments === null) {
            return self::FAILURE;
        }

        app()->instance(ReportCache::class, (new ReportCache)->refreshing((bool) $this->option('fresh'))->unguarded());
        $catalog = app(ReportCatalog::class);

        $contexts = ['all' => ReportContext::make(EmployeeVisibility::unrestricted(), null, $asOf)];
        foreach ($departments as $deptNo) {
            $contexts['dept '.$deptNo] = ReportContext::make(EmployeeVisibility::unrestricted(), $deptNo, $asOf);
            // A manager of the department (not saved): same filter, restricted to the visible employees.
            $manager = (new User)->forceFill(['role' => Role::Manager, 'dept_no' => $deptNo, 'is_active' => true]);
            $contexts['manager '.$deptNo] = ReportContext::make(EmployeeVisibility::for($manager), null, $asOf);
        }

        $total = microtime(true);
        $failed = [];
        foreach ($contexts as $label => $context) {
            $started = microtime(true);

            try {
                $catalog->warm($context);
                $this->line(sprintf('  %-14s %6.2f s', $label, microtime(true) - $started));
            } catch (Throwable $e) {
                report($e);
                $failed[] = $label;
                $this->error(sprintf('  %-14s failed: %s', $label, $e->getMessage()));
            }
        }

        $warmed = count($contexts) - count($failed);
        $summary = sprintf('Warmed %d of %d report scopes (%s) in %.1f s.', $warmed, count($contexts), $contexts['all']->asOfKey(), microtime(true) - $total);

        if ($failed !== []) {
            $this->error($summary.' Failed: '.implode(', ', $failed).'.');

            return self::FAILURE;
        }

        $this->info($summary);

        return self::SUCCESS;
    }

    /**
     * The requested departments as stored in the database (default: all), null after an error.
     *
     * @return list<string>|null
     */
    private function departments(): ?array
    {
        $requested = $this->option('department');

        if ($requested === []) {
            return Department::query()->orderBy('dept_no')->pluck('dept_no')->all();
        }

        $known = [];
        foreach ($requested as $deptNo) {
            $department = Department::find($deptNo);

            if ($department === null) {
                $this->error("Unknown department: {$deptNo}.");

                return null;
            }

            $known[] = $department->dept_no;
        }

        return array_values(array_unique($known));
    }
}
