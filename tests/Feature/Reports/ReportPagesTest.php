<?php

namespace Tests\Feature\Reports;

use App\Enums\Role;
use App\Models\Salary;
use App\Models\User;
use App\Reports\CompensationReports;
use App\Reports\ReportCache;
use App\Reports\ReportContext;
use App\Reports\WorkforceReports;
use App\Support\Ability;
use App\Support\EmployeeDataCache;
use App\Support\EmployeeVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BuildsReportData;
use Tests\TestCase;

class ReportPagesTest extends TestCase
{
    use ActsAsAdmin;
    use BuildsReportData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReportData();
    }

    /**
     * @return list<list<string>>
     */
    private function csv(TestResponse $response): array
    {
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = preg_split('/\R/', trim(substr($content, 3)));

        return array_map('str_getcsv', $lines);
    }

    /**
     * @return array<string, array{Role, int, int}>
     */
    public static function roleProvider(): array
    {
        return [
            'admin' => [Role::Admin, 200, 200],
            'hr' => [Role::Hr, 200, 200],
            'manager' => [Role::Manager, 200, 200],
            'viewer' => [Role::Viewer, 403, 403],
        ];
    }

    #[DataProvider('roleProvider')]
    public function test_access_per_role(Role $role, int $workforce, int $compensation): void
    {
        $this->signInAs($role, $role === Role::Manager ? $this->development : null);

        $this->get(route('reports.workforce'))->assertStatus($workforce);
        $this->get(route('reports.compensation'))->assertStatus($compensation);
        $this->get(route('reports.csv', 'headcount'))->assertStatus($workforce);
        $this->get(route('reports.csv', 'pay-gap'))->assertStatus($compensation);

        if ($workforce === 200) {
            $this->get(route('reports.index'))->assertRedirect(route('reports.workforce'));
        } else {
            $this->get(route('reports.index'))->assertForbidden();
        }
    }

    public function test_salary_reports_require_view_salaries(): void
    {
        // No role has view-reports without view-salaries today; the rule must hold anyway.
        Gate::define(Ability::VIEW_SALARIES, fn () => false);

        $this->get(route('reports.workforce'))->assertOk()->assertDontSee(route('reports.compensation'));
        $this->get(route('reports.compensation'))->assertForbidden();
        foreach (CompensationReports::KEYS as $key) {
            $this->get(route('reports.csv', $key))->assertForbidden();
        }
        $this->get(route('reports.csv', 'age'))->assertOk();
    }

    public function test_workforce_page_renders_every_report(): void
    {
        $response = $this->get(route('reports.workforce'))->assertOk();

        foreach (['Headcount over time', 'Hires, leavers and turnover', 'Average tenure', 'Gender distribution', 'Age distribution'] as $title) {
            $response->assertSee($title);
        }
        foreach (WorkforceReports::KEYS as $key) {
            $response->assertSee(route('reports.csv', $key), false);
        }
        $response->assertSee('canvas id="headcount-chart"', false)
            ->assertSee('cdn.jsdelivr.net/npm/chart.js', false)
            ->assertViewHas('summary', fn (array $s) => $s['headcount'] === 4);
    }

    public function test_compensation_page_renders_every_report(): void
    {
        $response = $this->get(route('reports.compensation'))->assertOk();

        foreach (['Salaries by department', 'Salaries by title', 'Salary distribution', 'Gender pay gap', 'Average salary over time'] as $title) {
            $response->assertSee($title);
        }
        $response->assertSee('$66,250')   // average
            ->assertSee('$67,500');       // median
    }

    public function test_filters_are_applied_and_kept_in_links(): void
    {
        $response = $this->get(route('reports.workforce', ['department' => 'd002', 'as_of' => '2000-01-01']))->assertOk();

        $response->assertViewHas('context', fn (ReportContext $c) => $c->department === 'd002' && $c->date() === '2000-01-01')
            ->assertViewHas('summary', fn (array $s) => $s['headcount'] === 3)
            ->assertSee(route('reports.csv', ['report' => 'age', 'department' => 'd002', 'as_of' => '2000-01-01']));
    }

    public function test_invalid_filters_fall_back_with_a_warning(): void
    {
        $this->get(route('reports.workforce', ['department' => 'x999', 'as_of' => '2001-02-30']))
            ->assertOk()
            ->assertViewHas('context', fn (ReportContext $c) => $c->department === null && $c->current)
            ->assertSee('Unknown department')
            ->assertSee('Invalid date');

        $this->get(route('reports.csv', 'no-such-report'))->assertNotFound();
    }

    public function test_a_manager_cannot_choose_another_department(): void
    {
        $this->signInAs(Role::Manager, $this->sales);

        $this->get(route('reports.workforce', ['department' => 'd002']))
            ->assertOk()
            ->assertViewHas('context', fn (ReportContext $c) => $c->department === 'd001')
            ->assertViewHas('summary', fn (array $s) => $s['headcount'] === 1)
            ->assertDontSee('id="report-department"', false)
            ->assertSee('Your department: Sales');

        $lines = $this->csv($this->get(route('reports.csv', ['report' => 'salary-departments', 'department' => 'd002'])));
        $this->assertSame(['Department', 'Employees', 'Average', 'Median', 'Minimum', 'Maximum'], $lines[0]);
        $this->assertSame([['Sales', '1', '50000', '50000', '50000', '50000']], array_slice($lines, 1));
    }

    public function test_csv_download_contains_the_table(): void
    {
        $response = $this->get(route('reports.csv', ['report' => 'hires-leavers']));
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('report_hires-leavers_all_', $response->headers->get('Content-Disposition'));

        $lines = $this->csv($response);
        $this->assertSame(['Year', 'Until', 'Hires', 'Leavers', 'Transfers in', 'Transfers out', 'Net change', 'Headcount at start', 'Headcount at end', 'Turnover rate'], $lines[0]);
        $this->assertSame(['1993', '1993-12-31', '1', '1', '0', '0', '0', '3', '3', '33.33'], $lines[4]);

        $gap = $this->csv($this->get(route('reports.csv', ['report' => 'pay-gap'])));
        $this->assertSame(['Overall', 'All employees', '2', '2', '65000', '67500', '3.7'], $gap[1]);
        $this->assertSame(['Department', 'Sales', '1', '0', '50000', '', ''], $gap[2]);

        $departments = $this->csv($this->get(route('reports.csv', ['report' => 'salary-departments'])));
        $this->assertSame(['All employees', '4', '66250', '67500', '50000', '80000'], end($departments));
    }

    public function test_reports_are_cached_until_the_employee_data_cache_is_flushed(): void
    {
        $context = ReportContext::make(EmployeeVisibility::for($this->user));
        $this->get(route('reports.compensation'))->assertOk();
        $this->assertTrue(Cache::has(ReportCache::key('salary_snapshot', $context)));
        $this->assertFalse(Cache::has(ReportCache::key('movements', $context)), 'Workforce figures are only computed by their page.');

        Salary::query()->where('emp_no', $this->people['E1']->emp_no)->where('salary', 50000)->update(['salary' => 90000]);
        $this->get(route('reports.compensation'))->assertSee('$66,250');

        $before = EmployeeDataCache::reportsVersion();
        EmployeeDataCache::flush();
        $this->assertNotSame($before, EmployeeDataCache::reportsVersion());
        $this->assertFalse(Cache::has(ReportCache::key('salary_snapshot', $context)));

        // (90000 + 70000 + 80000 + 65000) / 4
        $this->get(route('reports.compensation'))->assertSee('$76,250');
    }

    public function test_cache_keys_are_per_figure_scope_and_date(): void
    {
        $version = EmployeeDataCache::reportsVersion();
        $admin = EmployeeVisibility::for($this->user);
        $manager = EmployeeVisibility::for(User::factory()->manager('d001')->create());

        $this->assertSame("reports:{$version}:snapshot:all:current", ReportCache::key('snapshot', ReportContext::make($admin)));
        $this->assertSame("reports:{$version}:snapshot:dept-d001:1995-12-31", ReportCache::key('snapshot', ReportContext::make($admin, 'd001', CarbonImmutable::parse('1995-12-31'))));
        $this->assertSame("reports:{$version}:snapshot:mgr-d001:current", ReportCache::key('snapshot', ReportContext::make($manager, 'd002')));
    }

    public function test_history_tables_are_only_reached_through_report_sources(): void
    {
        foreach (File::allFiles(app_path('Reports')) as $file) {
            if ($file->getFilename() === 'ReportSources.php') {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                "/(table|join|leftJoin|from)\\(\\s*'(dept_emp|titles|salaries)\\b/",
                $file->getContents(),
                $file->getPathname(),
            );
        }
    }

    public function test_navbar_and_dashboard_link_to_the_reports(): void
    {
        $this->get(route('index'))->assertOk()->assertSee(route('reports.index'));

        $this->signInAs(Role::Viewer);
        $this->get(route('index'))->assertOk()->assertDontSee(route('reports.index'));
    }
}
