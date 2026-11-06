<?php

namespace Tests\Feature\Reports;

use App\Enums\Role;
use App\Models\Department;
use App\Models\DeptEmp;
use App\Models\Employee;
use App\Models\User;
use App\Reports\CompanyHistory;
use App\Reports\CompensationReports;
use App\Reports\ReportCache;
use App\Reports\ReportContext;
use App\Reports\ReportTable;
use App\Reports\WorkforceReports;
use App\Support\EmployeeDataCache;
use App\Support\EmployeeVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BuildsReportData;
use Tests\TestCase;

/**
 * Boundaries of the as-of date, input handling, cache isolation and data the
 * sample company of BuildsReportData does not contain.
 */
class ReportEdgeCasesTest extends TestCase
{
    use ActsAsAdmin;
    use BuildsReportData;
    use RefreshDatabase;

    private const OPEN = '9999-01-01';

    private function context(?string $department = null, ?string $asOf = null): ReportContext
    {
        return ReportContext::make(EmployeeVisibility::unrestricted(), $department, $asOf ? CarbonImmutable::parse($asOf) : null);
    }

    private function headcount(?string $asOf): int
    {
        return app(WorkforceReports::class)->summary($this->context(asOf: $asOf))['headcount'];
    }

    /**
     * @param  list<int>  $salaries
     * @param  list<string>|null  $genders
     */
    private function staffWithSalaries(array $salaries, ?array $genders = null): void
    {
        Department::factory()->create(['dept_no' => 'd009', 'dept_name' => 'Staffing']);

        foreach ($salaries as $i => $salary) {
            $this->person('S'.$i, $genders[$i] ?? 'M', '1970-01-01', '1990-01-01',
                [['d009', '1990-01-01', self::OPEN]],
                [['Staff', '1990-01-01', self::OPEN]],
                [[$salary, '1990-01-01', self::OPEN]]);
        }
    }

    /**
     * from_date <= day < to_date
     *
     * @return array<string, array{string, int}>
     */
    public static function dayBoundaries(): array
    {
        return [
            'day before from_date' => ['2000-02-29', 0],
            'from_date' => ['2000-03-01', 1],
            'day before to_date' => ['2001-02-28', 1],
            'to_date' => ['2001-03-01', 0],
        ];
    }

    #[DataProvider('dayBoundaries')]
    public function test_a_row_counts_from_its_from_date_until_the_day_before_its_to_date(string $day, int $expected): void
    {
        $this->buildReportData();
        $this->person('B1', 'F', '1970-01-01', '2000-03-01',
            [['d001', '2000-03-01', '2001-03-01']],
            [['Staff', '2000-03-01', '2001-03-01']],
            [[50000, '2000-03-01', '2001-03-01']]);

        $this->assertSame(4 + $expected, $this->headcount($day));
        $this->assertSame(4 + $expected, app(CompensationReports::class)->summary($this->context(asOf: $day))['employees']);
    }

    public function test_31_december_belongs_to_the_year_that_ends(): void
    {
        $department = Department::factory()->create(['dept_no' => 'd001', 'dept_name' => 'Sales']);
        $this->person('A', 'F', '1970-01-01', '1990-12-31', [['d001', '1990-12-31', '1991-12-31']], [], []);
        $this->person('B', 'M', '1970-01-01', '1990-12-30', [['d001', '1990-12-30', self::OPEN]], [], []);

        $rows = collect(app(WorkforceReports::class)->headcount($this->context($department->dept_no))->rows)->keyBy('year');

        // A starts on 31 Dec 1990 and has left on 31 Dec 1991.
        $this->assertSame([2, 1], [$rows[1990]['d_d001'], $rows[1991]['d_d001']]);
    }

    public function test_today_and_the_future(): void
    {
        $today = CarbonImmutable::today()->toDateString();
        $tomorrow = CarbonImmutable::tomorrow()->toDateString();
        Department::factory()->create(['dept_no' => 'd001']);
        $this->person('Starts now', 'F', '1970-01-01', $today, [['d001', $today, self::OPEN]], [], []);
        $this->person('Leaves now', 'F', '1970-01-01', '1990-01-01', [['d001', '1990-01-01', $today]], [], []);
        $this->person('Starts later', 'F', '1970-01-01', $tomorrow, [['d001', $tomorrow, self::OPEN]], [], []);
        $this->person('Leaves later', 'F', '1970-01-01', '1990-01-01', [['d001', '1990-01-01', $tomorrow]], [], []);

        $this->assertSame(2, $this->headcount(null));
        // A future date is the current state.
        $this->assertTrue($this->context(asOf: $tomorrow)->current);
        $this->assertSame($today, $this->context(asOf: '2999-01-01')->date());
    }

    public function test_age_changes_on_the_birthday(): void
    {
        Department::factory()->create(['dept_no' => 'd001']);
        $this->person('Birthday', 'F', '1970-06-30', '1990-01-01', [['d001', '1990-01-01', self::OPEN]], [], []);
        $reports = app(WorkforceReports::class);

        $this->assertSame('25–29', $reports->age($this->context(asOf: '2000-06-29'))->rows[0]['band']);
        $this->assertSame('30–34', $reports->age($this->context(asOf: '2000-06-30'))->rows[0]['band']);
    }

    public function test_a_hire_date_after_the_as_of_date_gives_no_negative_tenure(): void
    {
        Department::factory()->create(['dept_no' => 'd001']);
        $this->person('Late', 'F', '1970-01-01', '2005-01-01', [['d001', '2000-01-01', self::OPEN]], [], []);
        $this->person('Early', 'M', '1970-01-01', '2000-01-01', [['d001', '2000-01-01', self::OPEN]], [], []);
        $context = $this->context(asOf: '2001-01-01');

        // 366 days for Early, 0 instead of -1461 for Late.
        $this->assertEqualsWithDelta(366 / 2 / 365.25, app(WorkforceReports::class)->summary($context)['tenure'], 1e-9);

        $rows = collect(app(WorkforceReports::class)->hiresAndLeavers($context)->rows)->keyBy('year');
        $this->assertSame(1, $rows[2000]['hires']);
    }

    public function test_a_gap_between_department_rows_is_a_leaver_not_a_transfer(): void
    {
        Department::factory()->create(['dept_no' => 'd001']);
        Department::factory()->create(['dept_no' => 'd002']);
        $this->person('Returns', 'F', '1970-01-01', '1990-01-01',
            [['d001', '1990-01-01', '1992-06-01'], ['d002', '1994-03-01', self::OPEN]], [], []);
        $this->person('Moves', 'M', '1970-01-01', '1990-01-01',
            [['d001', '1990-01-01', '1993-06-01'], ['d002', '1993-06-01', self::OPEN]], [], []);

        $rows = collect(app(WorkforceReports::class)->hiresAndLeavers($this->context())->rows)->keyBy('year');

        $this->assertSame([1, 0, 0], [$rows[1992]['leavers'], $rows[1992]['in'], $rows[1992]['out']]);
        $this->assertSame([0, 1, 1], [$rows[1993]['leavers'], $rows[1993]['in'], $rows[1993]['out']]);
        $this->assertSame([0, 0], [$rows[1994]['in'], $rows[1994]['out']]);
        $this->assertSame(2, $rows[1994]['end']);
    }

    /**
     * @return array<string, array{list<int>, float}>
     */
    public static function medians(): array
    {
        return [
            'single' => [[50000], 50000.0],
            'odd' => [[70000, 50000, 60000], 60000.0],
            'even' => [[40000, 80000, 50000, 60000], 55000.0],
            'ties' => [[50000, 50000, 50000, 90000], 50000.0],
            'even with ties in the middle' => [[10000, 60000, 60000, 90000], 60000.0],
            'large' => [[1500000000, 1800000000], 1650000000.0],
        ];
    }

    /**
     * @param  list<int>  $salaries
     */
    #[DataProvider('medians')]
    public function test_medians(array $salaries, float $expected): void
    {
        $this->staffWithSalaries($salaries);
        $reports = app(CompensationReports::class);

        $this->assertEquals($expected, $reports->summary($this->context())['median']);
        $this->assertEquals($expected, $reports->byDepartment($this->context())->rows[0]['median']);
        $this->assertEquals($expected, $reports->byTitle($this->context())->rows[0]['median']);
    }

    public function test_no_pay_gap_without_both_genders(): void
    {
        $this->staffWithSalaries([50000, 60000], ['F', 'F']);

        $summary = app(CompensationReports::class)->summary($this->context());

        $this->assertNull($summary['payGap']);
    }

    public function test_no_pay_gap_when_the_men_earn_nothing(): void
    {
        $this->staffWithSalaries([50000, 0], ['F', 'M']);

        $this->assertNull(app(CompensationReports::class)->summary($this->context())['payGap']);
    }

    public function test_departments_are_normalised_to_the_database_value(): void
    {
        $this->buildReportData();

        $this->get(route('reports.workforce', ['department' => 'D002']))
            ->assertOk()
            ->assertViewHas('context', fn (ReportContext $c) => $c->department === 'd002' && $c->scopeKey() === 'dept-d002');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function arrayInput(): array
    {
        return [
            'department' => [['department' => ['d001']], 'Unknown department'],
            'as_of' => [['as_of' => ['2000-01-01']], 'Invalid date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('arrayInput')]
    public function test_array_input_is_rejected(array $query, string $message): void
    {
        $this->buildReportData();

        $this->get(route('reports.workforce', $query))
            ->assertOk()
            ->assertSee($message)
            ->assertViewHas('context', fn (ReportContext $c) => $c->department === null && $c->current);
    }

    public function test_requested_dates_move_to_the_end_of_the_month(): void
    {
        $this->buildReportData();

        foreach (['2000-02-01', '2000-02-15', '2000-02-29'] as $date) {
            $this->get(route('reports.workforce', ['as_of' => $date]))
                ->assertViewHas('context', fn (ReportContext $c) => $c->date() === '2000-02-29' && $c->asOfKey() === '2000-02-29');
        }
    }

    public function test_the_current_month_is_the_current_state(): void
    {
        $this->buildReportData();

        $this->get(route('reports.workforce', ['as_of' => CarbonImmutable::today()->startOfMonth()->toDateString()]))
            ->assertViewHas('context', fn (ReportContext $c) => $c->current);
    }

    public function test_a_date_before_the_first_hire_is_empty_and_runs_no_report_query(): void
    {
        $this->buildReportData();
        CompanyHistory::earliestHireDate();
        $this->assertTrue($this->context(asOf: '1989-12-31')->isBeforeHistory());
        $this->assertFalse($this->context(asOf: '1990-01-01')->isBeforeHistory());

        DB::enableQueryLog();
        $this->get(route('reports.compensation', ['as_of' => '1950-06-15']))
            ->assertOk()
            ->assertSee('The company has no history before 1990-01-01')
            ->assertViewHas('summary', fn (array $s) => $s['employees'] === 0)
            ->assertViewHas('tables', fn (array $tables) => collect($tables)->every(fn (ReportTable $t) => $t->isEmpty()));

        $tables = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q) => preg_match('/dept_emp|salaries|titles/', $q));
        $this->assertSame([], $tables->values()->all());
        $this->assertFalse(Cache::has(ReportCache::key('salary_snapshot', $this->context(asOf: '1950-06-30'))));
    }

    public function test_the_first_hire_date_is_flushed_with_the_reports(): void
    {
        $this->buildReportData();
        $this->assertSame('1990-01-01', CompanyHistory::earliestHireDate());

        Employee::factory()->create(['hire_date' => '1985-02-01']);
        $this->assertSame('1990-01-01', CompanyHistory::earliestHireDate());

        EmployeeDataCache::flushReports();
        $this->assertSame('1985-02-01', CompanyHistory::earliestHireDate());
    }

    public function test_scopes_do_not_share_cached_figures(): void
    {
        $this->buildReportData();
        DeptEmp::query()->where('emp_no', $this->people['E2']->emp_no)->where('dept_no', 'd001')->delete();
        $reports = app(WorkforceReports::class);

        $scopes = [
            [EmployeeVisibility::for(User::factory()->manager('d001')->create()), 1],
            [EmployeeVisibility::unrestricted(), 'd002', 3],
            [EmployeeVisibility::unrestricted(), 4],
            [EmployeeVisibility::for(User::factory()->manager('d002')->create()), 3],
        ];

        // Twice: computed, then from the cache.
        foreach ([1, 2] as $round) {
            foreach ($scopes as $scope) {
                $expected = array_pop($scope);
                $context = ReportContext::make($scope[0], $scope[1] ?? null);

                $this->assertSame($expected, $reports->summary($context)['headcount'], $context->scopeKey()." round {$round}");
            }
        }

        $this->assertSame(['mgr-d001', 'dept-d002', 'all', 'mgr-d002'], array_map(
            fn (array $scope) => ReportContext::make($scope[0], is_string($scope[1]) ? $scope[1] : null)->scopeKey(),
            $scopes,
        ));
    }

    public function test_the_current_state_is_cached_per_day(): void
    {
        $this->buildReportData();
        $today = ReportCache::key('snapshot', $this->context());

        $this->travel(1)->days();

        $this->assertNotSame($today, ReportCache::key('snapshot', $this->context()));
    }

    public function test_a_viewer_gets_no_csv_at_all(): void
    {
        $this->buildReportData();
        $this->signInAs(Role::Viewer);

        foreach ([...WorkforceReports::KEYS, ...CompensationReports::KEYS, 'no-such-report'] as $key) {
            $this->get(route('reports.csv', $key))->assertForbidden();
        }
    }

    public function test_csv_neutralises_spreadsheet_formulas(): void
    {
        Department::factory()->create(['dept_no' => 'd001', 'dept_name' => '=HYPERLINK("http://evil")']);
        Department::factory()->create(['dept_no' => 'd002', 'dept_name' => '  @SUM(1)']);
        $this->person('A', 'F', '1970-01-01', '1990-01-01', [['d001', '1990-01-01', self::OPEN]], [['+cmd', '1990-01-01', self::OPEN]], [[50000, '1990-01-01', self::OPEN]]);
        $this->person('B', 'M', '1970-01-01', '1990-01-01', [['d002', '1990-01-01', self::OPEN]], [['Staff', '1990-01-01', self::OPEN]], [[-1, '1990-01-01', self::OPEN]]);

        $salaries = $this->get(route('reports.csv', 'salary-departments'))->streamedContent();
        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil"")"', $salaries);
        $this->assertStringContainsString("'  @SUM(1)", $salaries);

        $titles = $this->get(route('reports.csv', 'salary-titles'))->streamedContent();
        $this->assertStringContainsString("'+cmd", $titles);

        // Department names are column headers here.
        $headers = $this->get(route('reports.csv', 'headcount'))->streamedContent();
        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil"")"', $headers);
    }

    public function test_csv_values_of_numeric_columns(): void
    {
        $table = new ReportTable('x', 'X', ['a' => ['A', 'int'], 'b' => ['B', 'money'], 'c' => ['C', 'percent'], 'd' => ['D', 'text']], [
            ['a' => 1234.6, 'b' => '-5', 'c' => -1.234, 'd' => -5],
            ['a' => '=1+1', 'b' => '@x', 'c' => '+1 ', 'd' => null],
        ]);

        $this->assertSame([
            ['A', 'B', 'C', 'D'],
            [1235, -5, -1.23, -5],
            ["'=1+1", "'@x", 1.0, ''],
        ], $table->csvRows());
    }

    public function test_concurrent_first_uses_agree_on_the_reports_version(): void
    {
        // Another process stores its version between this one's get() and add().
        Cache::shouldReceive('get')->with(EmployeeDataCache::REPORTS_VERSION)->once()->andReturnNull();
        Cache::shouldReceive('add')->once()->andReturnFalse();
        Cache::shouldReceive('get')->with(EmployeeDataCache::REPORTS_VERSION, \Mockery::any())->once()->andReturn('winner');

        $this->assertSame('winner', EmployeeDataCache::reportsVersion());
    }
}
