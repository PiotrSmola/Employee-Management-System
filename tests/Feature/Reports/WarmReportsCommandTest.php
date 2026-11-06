<?php

namespace Tests\Feature\Reports;

use App\Models\Salary;
use App\Models\User;
use App\Reports\CompensationReports;
use App\Reports\ReportCache;
use App\Reports\ReportCatalog;
use App\Reports\ReportContext;
use App\Support\EmployeeVisibility;
use App\Support\FigureCache;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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
        $this->artisan('reports:warm')->assertSuccessful()->expectsOutputToContain('Warmed 5 of 5 report scopes');

        $manager = EmployeeVisibility::for(User::factory()->manager('d002')->make());
        $contexts = [
            ReportContext::make(EmployeeVisibility::unrestricted()),
            ReportContext::make(EmployeeVisibility::unrestricted(), 'd001'),
            ReportContext::make(EmployeeVisibility::unrestricted(), 'd002'),
            ReportContext::make($manager),
        ];
        foreach ($contexts as $context) {
            foreach (['movements', 'snapshot', 'title_gender', 'salary_snapshot', 'salary_growth'] as $figure) {
                $this->assertTrue($this->figureIsCached($figure, $context), "{$figure} {$context->scopeKey()}");
            }
        }
    }

    public function test_as_of_and_department_options(): void
    {
        $this->artisan('reports:warm', ['--as-of' => '1995-12-31', '--department' => ['d002']])->assertSuccessful();

        $context = ReportContext::make(EmployeeVisibility::unrestricted(), 'd002', CarbonImmutable::parse('1995-12-31'));
        $this->assertTrue($this->figureIsCached('snapshot', $context));
        $this->assertFalse($this->figureIsCached('snapshot', ReportContext::make(EmployeeVisibility::unrestricted(), 'd001', CarbonImmutable::parse('1995-12-31'))));

    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_options_stop_the_command(array $options): void
    {
        $this->artisan('reports:warm', $options)->assertFailed();

        $context = ReportContext::make(EmployeeVisibility::unrestricted());
        $this->assertFalse($this->figureIsCached('movements', $context));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): array
    {
        return [
            'not a date' => [['--as-of' => 'yesterday']],
            'impossible date' => [['--as-of' => '2020-02-31']],
            'before 1900' => [['--as-of' => '1899-12-31']],
            'unknown department' => [['--department' => ['d999']]],
        ];
    }

    public function test_scope_option_warms_one_scope(): void
    {
        $this->artisan('reports:warm', ['--scope' => 'dept-d002'])
            ->expectsOutputToContain('Warmed 1 of 1 report scopes')
            ->assertSuccessful();

        $this->assertTrue($this->figureIsCached('snapshot', ReportContext::make(EmployeeVisibility::unrestricted(), 'd002')));
        $this->assertFalse($this->figureIsCached('snapshot', ReportContext::make(EmployeeVisibility::unrestricted())));
    }

    public function test_a_scope_another_process_computes_for_the_first_time_is_skipped(): void
    {
        $context = ReportContext::make(EmployeeVisibility::unrestricted());
        $this->assertTrue(Cache::lock(FigureCache::lockKey(ReportCache::key('movements', $context)), 60)->get());

        $this->artisan('reports:warm', ['--scope' => 'all'])
            ->expectsOutputToContain('skipped, already being computed')
            ->assertSuccessful();
    }

    public function test_department_option_uses_the_database_value(): void
    {
        $this->artisan('reports:warm', ['--department' => ['D002']])->assertSuccessful();

        $this->assertTrue($this->figureIsCached('snapshot', ReportContext::make(EmployeeVisibility::unrestricted(), 'd002')));
    }

    public function test_a_failing_scope_does_not_stop_the_others(): void
    {
        Exceptions::fake();
        $this->partialMock(ReportCatalog::class, function (MockInterface $mock) {
            $mock->shouldReceive('warm')->times(5)->andReturnUsing(function (ReportContext $context) {
                if ($context->scopeKey() === 'dept-d001') {
                    throw new RuntimeException('Disk full');
                }
            });
        });

        $this->artisan('reports:warm')
            ->expectsOutputToContain('dept d001      failed: Disk full')
            ->expectsOutputToContain('Warmed 4 of 5 report scopes')
            ->assertFailed();
        Exceptions::assertReported(RuntimeException::class);
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
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertSame(30, $events->first()->expiresAt);
    }
}
