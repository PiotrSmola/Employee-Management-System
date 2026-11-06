<?php

namespace Tests\Feature\Reports;

use App\Models\Salary;
use App\Models\User;
use App\Reports\CompensationReports;
use App\Reports\ReportCache;
use App\Reports\ReportContext;
use App\Support\EmployeeVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsReportData;
use Tests\TestCase;

class WarmReportsCommandTest extends TestCase
{
    use BuildsReportData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReportData();
    }

    public function test_warm_caches_every_figure_of_every_scope(): void
    {
        $this->artisan('reports:warm')->assertSuccessful()->expectsOutputToContain('Warmed 5 report scopes');

        $manager = EmployeeVisibility::for(User::factory()->manager('d002')->make());
        $contexts = [
            ReportContext::make(EmployeeVisibility::unrestricted()),
            ReportContext::make(EmployeeVisibility::unrestricted(), 'd001'),
            ReportContext::make(EmployeeVisibility::unrestricted(), 'd002'),
            ReportContext::make($manager),
        ];
        foreach ($contexts as $context) {
            foreach (['movements', 'snapshot', 'title_gender', 'salary_snapshot', 'salary_growth'] as $figure) {
                $this->assertTrue(Cache::has(ReportCache::key($figure, $context)), "{$figure} {$context->scopeKey()}");
            }
        }
    }

    public function test_as_of_and_department_options(): void
    {
        $this->artisan('reports:warm', ['--as-of' => '1995-12-31', '--department' => ['d002']])->assertSuccessful();

        $context = ReportContext::make(EmployeeVisibility::unrestricted(), 'd002', CarbonImmutable::parse('1995-12-31'));
        $this->assertTrue(Cache::has(ReportCache::key('snapshot', $context)));
        $this->assertFalse(Cache::has(ReportCache::key('snapshot', ReportContext::make(EmployeeVisibility::unrestricted(), 'd001', CarbonImmutable::parse('1995-12-31')))));

        $this->artisan('reports:warm', ['--as-of' => 'yesterday'])->assertFailed();
    }

    public function test_fresh_recomputes_cached_figures(): void
    {
        $this->artisan('reports:warm', ['--department' => []])->assertSuccessful();
        Salary::query()->where('emp_no', $this->people['E1']->emp_no)->where('salary', 50000)->update(['salary' => 90000]);
        $context = ReportContext::make(EmployeeVisibility::unrestricted());

        $this->artisan('reports:warm')->assertSuccessful();
        $this->assertEquals(66250, app(CompensationReports::class)->summary($context)['average']);

        $this->artisan('reports:warm', ['--fresh' => true])->assertSuccessful();
        $this->app->forgetInstance(ReportCache::class);
        $this->assertEquals(76250, app(CompensationReports::class)->summary($context)['average']);
    }

    public function test_warm_is_scheduled_hourly(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'reports:warm'));

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
    }
}
