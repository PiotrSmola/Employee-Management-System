<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Livewire\EmployeeFilter;
use App\Livewire\EmployeeList;
use App\Queries\EmployeeSearchQuery;
use App\Services\EmployeeExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BuildsDirectory;
use Tests\TestCase;

/**
 * Users without `view-salaries` (viewers) never receive salary data.
 */
class SalaryVisibilityTest extends TestCase
{
    use ActsAsAdmin;
    use BuildsDirectory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildDirectory();
        $this->signInAs(Role::Viewer);
    }

    public function test_list_has_no_salary_column(): void
    {
        Livewire::test(EmployeeList::class)
            ->assertSee('Anna')
            ->assertDontSee('Current Salary')
            ->assertDontSee('50,000')
            ->assertDontSee('90,000');
    }

    public function test_salary_filter_is_ignored_and_not_offered(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('updateFilters', ['salaryMin' => '80000', 'salaryMax' => '100000'])
            ->assertSee('7 employee(s) found')
            ->assertSet('salaryMin', '80000');

        $this->assertNull($component->instance()->filters()->salaryMin);
        $this->assertNull($component->instance()->filters()->salaryMax);

        Livewire::test(EmployeeFilter::class)->assertDontSee('Salary Range');
    }

    public function test_salary_sort_is_refused(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('sortByField', 'salary')
            ->assertSet('sortBy', 'emp_no');

        // Even a sort forced through the property is dropped.
        $component->set('sortBy', 'salary');
        $this->assertSame('emp_no', $component->instance()->filters()->sortBy);
        $this->assertSame('', $component->instance()->getExportPayload()['salaryMin']);
    }

    public function test_profile_shows_no_salary_and_never_reads_salaries(): void
    {
        DB::enableQueryLog();

        $this->get(route('employees.show', 2))
            ->assertOk()
            ->assertSee('Bob Jones')
            ->assertSee('Manager')
            ->assertDontSee('90,000')
            ->assertDontSee('Total Earnings')
            ->assertDontSee('Average Salary')
            ->assertDontSee('salaryChart')
            ->assertViewHas('canSeeSalaries', false)
            ->assertViewHas('salaryHistory', fn ($history) => $history->isEmpty())
            ->assertViewHas('combinedHistory', fn ($rows) => $rows->isNotEmpty() && $rows->every(fn ($row) => $row->salary === null))
            ->assertViewHas('stats', fn ($stats) => $stats['totalEarnings'] === null && $stats['avgSalary'] === null);

        $this->assertNoSalaryQuery();
    }

    public function test_profile_pdf_does_not_read_salaries(): void
    {
        DB::enableQueryLog();

        $this->get(route('employees.export-show-pdf', 2))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertNoSalaryQuery();
    }

    public function test_dashboard_hides_the_average_salary(): void
    {
        $this->get(route('index'))
            ->assertOk()
            ->assertViewHas('averageSalary', null)
            ->assertDontSee('Average Salary');
    }

    public function test_export_service_leaves_out_salary_columns_without_salaries(): void
    {
        $content = $this->streamed(app(EmployeeExportService::class)->csv(EmployeeSearchQuery::make([]), withSalaries: false));

        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($content))));
        $this->assertNotContains('Current Salary', $lines[0]);
        $this->assertNotContains('Total Earnings', $lines[0]);
        $this->assertCount(9, $lines[0]);
        $this->assertCount(9, $lines[1]);
        $this->assertStringNotContainsString('50000', $content);
    }

    public function test_roles_with_view_salaries_still_see_them(): void
    {
        $this->signInAs(Role::Hr);

        $this->get(route('employees.show', 2))->assertOk()->assertSee('90,000')->assertSee('Total Earnings');
        Livewire::test(EmployeeList::class)->assertSee('Current Salary')->assertSee('50,000');
        $this->get(route('index'))->assertSee('Average Salary');
    }

    private function assertNoSalaryQuery(): void
    {
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertNotEmpty($queries);
        $this->assertTrue(
            $queries->every(fn ($sql) => ! str_contains($sql, '`salaries`')),
            "A salary query was run:\n".$queries->implode("\n")
        );
    }

    private function streamed($response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
