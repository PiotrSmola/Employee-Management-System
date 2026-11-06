<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use App\Reports\ReportCache;
use App\Reports\ReportCatalog;
use App\Reports\ReportContext;
use App\Reports\ReportsBusyException;
use App\Support\EmployeeVisibility;
use Illuminate\Console\Command;
use Throwable;

/**
 * Precomputes the cached report figures for the current state (or --as-of) of
 * the whole company, every department filter and every manager's department
 * scope, so the report pages are served from the cache. Figures that are
 * still fresh are kept unless --fresh is given. Scheduled hourly
 * (routes/console.php): the next run after EmployeeDataCache::flush() or midnight
 * rebuilds them. A scope that fails does not stop the others; the command then
 * ends with an error. A scope another process is computing for the first time
 * is skipped.
 */
class WarmReports extends Command
{
    protected $signature = 'reports:warm
        {--as-of= : Date (Y-m-d) to warm instead of the current state}
        {--department=* : Only these departments (dept_no); default: all}
        {--scope= : Only this scope: all, dept-<dept_no> or mgr-<dept_no>}
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

        app()->instance(ReportCache::class, (new ReportCache)->warming((bool) $this->option('fresh')));
        $catalog = app(ReportCatalog::class);

        $contexts = ['all' => ReportContext::make(EmployeeVisibility::unrestricted(), null, $asOf)];
        foreach ($departments as $deptNo) {
            $contexts['dept '.$deptNo] = ReportContext::make(EmployeeVisibility::unrestricted(), $deptNo, $asOf);
            // A manager of the department (not saved): same filter, restricted to the visible employees.
            $manager = (new User)->forceFill(['role' => Role::Manager, 'dept_no' => $deptNo, 'is_active' => true]);
            $contexts['manager '.$deptNo] = ReportContext::make(EmployeeVisibility::for($manager), null, $asOf);
        }

        if ($this->option('scope') !== null) {
            $contexts = array_filter($contexts, fn (ReportContext $context) => $context->scopeKey() === $this->option('scope'));
        }

        $total = microtime(true);
        $failed = [];
        foreach ($contexts as $label => $context) {
            $started = microtime(true);

            try {
                $catalog->warm($context);
                $this->line(sprintf('  %-14s %6.2f s', $label, microtime(true) - $started));
            } catch (ReportsBusyException) {
                $this->line(sprintf('  %-14s skipped, already being computed', $label));
            } catch (Throwable $e) {
                report($e);
                $failed[] = $label;
                $this->error(sprintf('  %-14s failed: %s', $label, $e->getMessage()));
            }
        }

        // A later request of this process (the refresh after a response) reads again.
        app()->forgetInstance(ReportCache::class);

        $warmed = count($contexts) - count($failed);
        $summary = sprintf('Warmed %d of %d report scopes in %.1f s.', $warmed, count($contexts), microtime(true) - $total);

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
