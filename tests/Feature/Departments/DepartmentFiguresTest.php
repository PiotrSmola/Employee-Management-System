<?php

namespace Tests\Feature\Departments;

use App\Enums\Role;
use App\Jobs\RefreshDepartmentFigures;
use App\Models\Department;
use App\Models\DeptEmp;
use App\Models\DeptManager;
use App\Models\Employee;
use App\Services\DepartmentStatistics;
use App\Support\EmployeeDataCache;
use App\Support\FigureCache;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/**
 * How the cached department figures behave: who gets what from a shared cache,
 * and how they are prepared, served while stale and refreshed.
 */
class DepartmentFiguresTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    private Department $dev;

    private Department $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dev = Department::factory()->create(['dept_no' => 'd005', 'dept_name' => 'Development']);
        $this->sales = Department::factory()->create(['dept_no' => 'd007', 'dept_name' => 'Sales']);

        Employee::factory()->current($this->dev, 'Engineer', 60000)->create(['hire_date' => '1990-01-01']);
        Employee::factory()->current($this->sales, 'Staff', 50000)->create(['hire_date' => '1993-01-01']);
    }

    public function test_salary_figures_follow_the_user_not_the_shared_cache(): void
    {
        $this->signInAs(Role::Viewer);
        $this->get(route('departments.index'))->assertOk()->assertDontSee('$60,000');
        $viewerPage = $this->get(route('departments.show', $this->dev))->assertOk()->assertDontSee('$60,000');
        $this->assertNull($viewerPage->viewData('salary'));
        $this->assertSame(FigureCache::FRESH, $viewerPage->viewData('figuresStatus'));

        $this->signInAs(Role::Admin);
        $this->get(route('departments.index'))->assertOk()->assertSee('$60,000');
        $this->get(route('departments.show', $this->dev))->assertOk()->assertSee('$60,000');

        // And the other way round, with the figures now cached for the administrator.
        $this->signInAs(Role::Viewer);
        $this->get(route('departments.index'))->assertOk()->assertDontSee('$60,000');
        $this->get(route('departments.show', $this->dev))->assertOk()->assertDontSee('$60,000');
    }

    public function test_after_a_flush_the_page_serves_the_last_figures_and_the_next_one_is_refilled(): void
    {
        $this->assertSame(1, $this->get(route('departments.show', $this->dev))->viewData('headcount'));

        Employee::factory()->current($this->dev)->create();
        EmployeeDataCache::flush(['d005']);

        $stale = $this->get(route('departments.show', $this->dev))->assertOk()->assertSee('id="figures-stale"', false);
        $this->assertSame(1, $stale->viewData('headcount'));
        $this->assertSame(FigureCache::STALE, $stale->viewData('figuresStatus'));

        // The refresh ran after that response.
        $this->assertTrue(FigureCache::isFresh(EmployeeDataCache::departmentDetails('d005')));
        $fresh = $this->get(route('departments.show', $this->dev))->assertOk()->assertDontSee('id="figures-stale"', false);
        $this->assertSame(2, $fresh->viewData('headcount'));
    }

    public function test_the_refresh_is_dispatched_after_the_response(): void
    {
        $this->get(route('departments.index'))->assertOk();
        $this->get(route('departments.show', $this->dev))->assertOk();
        EmployeeDataCache::flush();
        Bus::fake();

        $this->get(route('departments.index'))->assertOk();
        $this->get(route('departments.show', $this->dev))->assertOk();

        Bus::assertDispatchedAfterResponse(RefreshDepartmentFigures::class, fn ($job) => $job->deptNo === null);
        Bus::assertDispatchedAfterResponse(RefreshDepartmentFigures::class, fn ($job) => $job->deptNo === 'd005');
    }

    public function test_a_targeted_flush_keeps_the_overview_and_the_other_departments(): void
    {
        $this->get(route('departments.index'))->assertOk();
        $this->get(route('departments.show', $this->dev))->assertOk();
        $this->get(route('departments.show', $this->sales))->assertOk();

        EmployeeDataCache::flush(['d007']);

        $this->assertTrue(FigureCache::isFresh(EmployeeDataCache::DEPARTMENTS_OVERVIEW));
        $this->assertTrue(FigureCache::isFresh(EmployeeDataCache::departmentDetails('d005')));
        $this->assertFalse(FigureCache::isFresh(EmployeeDataCache::departmentDetails('d007')));

        EmployeeDataCache::flush();

        $this->assertFalse(FigureCache::isFresh(EmployeeDataCache::DEPARTMENTS_OVERVIEW));
        $this->assertFalse(FigureCache::isFresh(EmployeeDataCache::departmentDetails('d005')));
    }

    public function test_the_index_says_so_while_the_figures_are_being_prepared(): void
    {
        $this->assertTrue(Cache::lock(FigureCache::lockKey(EmployeeDataCache::DEPARTMENTS_OVERVIEW), 60)->get());

        $response = $this->get(route('departments.index'))->assertOk()
            ->assertSee('id="figures-preparing"', false)
            ->assertSee('Development');

        $this->assertNull($response->viewData('departments')->first()->headcount);
    }

    public function test_the_department_page_says_so_while_the_figures_are_being_prepared(): void
    {
        $boss = Employee::factory()->current($this->dev)->create(['first_name' => 'Bella', 'last_name' => 'Boss']);
        DeptManager::create(['emp_no' => $boss->emp_no, 'dept_no' => 'd005', 'from_date' => '1995-01-01', 'to_date' => '9999-01-01']);
        $this->assertTrue(Cache::lock(FigureCache::lockKey(EmployeeDataCache::departmentDetails('d005')), 60)->get());

        $this->get(route('departments.show', $this->dev))->assertOk()
            ->assertSee('id="figures-preparing"', false)
            ->assertSee('Bella Boss')             // managers are read live
            ->assertDontSee('Current employees')
            ->assertDontSee('Recently joined');
    }

    public function test_the_warm_command_prepares_every_figure(): void
    {
        $this->artisan('departments:warm')->assertSuccessful();

        $this->assertTrue(FigureCache::isFresh(EmployeeDataCache::DEPARTMENTS_OVERVIEW));
        $this->assertTrue(FigureCache::isFresh(EmployeeDataCache::departmentDetails('d005')));
        $this->assertTrue(FigureCache::isFresh(EmployeeDataCache::departmentDetails('d007')));
    }

    public function test_the_warm_command_refreshes_figures_that_are_still_fresh(): void
    {
        $this->artisan('departments:warm')->assertSuccessful();
        Employee::factory()->current($this->dev)->create();

        $this->artisan('departments:warm')->assertSuccessful();

        $this->assertSame(2, $this->get(route('departments.show', $this->dev))->viewData('headcount'));
    }

    public function test_the_warm_command_skips_what_another_process_computes(): void
    {
        $this->assertTrue(Cache::lock(FigureCache::lockKey(EmployeeDataCache::departmentDetails('d005')), 60)->get());

        $this->artisan('departments:warm')
            ->expectsOutput('d005: skipped, already being computed')
            ->expectsOutput('d007: refreshed')
            ->assertSuccessful();
    }

    public function test_the_warm_command_is_scheduled_hourly(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'departments:warm'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }

    public function test_the_index_hinted_for_the_salary_figures_exists(): void
    {
        $this->assertContains(
            DepartmentStatistics::SALARY_INDEX,
            array_column(Schema::getIndexes('salaries'), 'name'),
        );
    }

    public function test_figures_count_assignments(): void
    {
        // Two current assignments (the dataset has none, the schema allows it)
        // and an earlier assignment that ended count per department.
        $both = Employee::factory()->current($this->dev, 'Engineer', 70000)->create(['hire_date' => '1991-01-01']);
        DeptEmp::create(['emp_no' => $both->emp_no, 'dept_no' => 'd007', 'from_date' => '1999-01-01', 'to_date' => '9999-01-01']);
        Employee::factory()->current($this->sales, 'Staff', 40000, previousDepartment: $this->dev)
            ->create(['hire_date' => '1992-01-01']);

        $rows = $this->get(route('departments.index'))->viewData('departments')->keyBy('dept_no');

        $this->assertSame(2, $rows['d005']->headcount);
        $this->assertSame(3, $rows['d007']->headcount);
        $this->assertEqualsWithDelta(53333.33, $rows['d007']->avg_salary, 0.01);

        $page = $this->get(route('departments.show', $this->dev));
        $this->assertSame(2, $page->viewData('headcount'));
        $this->assertSame(1, $page->viewData('recentLeavers')->count());
    }

    public function test_headcount_by_year_runs_to_the_last_year_with_data_for_every_department(): void
    {
        Employee::factory()->former($this->sales, 'Staff', '2002-03-01')->create(['hire_date' => '1999-01-01']);

        $years = $this->get(route('departments.show', $this->dev))->viewData('headcountByYear');

        $this->assertSame(1990, $years->first()['year']);
        $this->assertSame(['year' => 2002, 'headcount' => 1], $years->last());
        $this->assertCount(13, $years);
    }

    public function test_a_rename_shows_at_once_in_figures_that_stay_cached(): void
    {
        Employee::factory()->current($this->sales, previousDepartment: $this->dev)->create();
        $this->get(route('departments.show', $this->dev))->assertOk()->assertSee('Moved to Sales');

        $this->put(route('departments.update', $this->sales), ['dept_name' => 'Commerce'])->assertSessionHasNoErrors();

        $this->assertTrue(FigureCache::isFresh(EmployeeDataCache::departmentDetails('d005')));
        $this->get(route('departments.show', $this->dev))->assertOk()->assertSee('Moved to Commerce');
    }
}
