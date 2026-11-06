<?php

namespace Tests\Feature\DataTools;

use App\Enums\Role;
use App\Livewire\EmployeeFilter;
use App\Livewire\EmployeeList;
use App\Models\Employee;
use App\Queries\EmployeeFilters;
use App\Queries\EmployeeSearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BuildsDirectory;
use Tests\TestCase;

/**
 * Employee-number search and the directory state kept in the URL.
 */
class DirectoryUrlAndSearchTest extends TestCase
{
    use ActsAsAdmin;
    use BuildsDirectory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDirectory();
    }

    private function listed($component): array
    {
        return collect($component->viewData('employees')->items())->pluck('emp_no')->map(fn ($n) => (int) $n)->all();
    }

    // ------------------------------------------------------- emp_no search

    public function test_a_numeric_search_matches_exactly_that_employee_number(): void
    {
        Employee::factory()->create(['emp_no' => 70, 'first_name' => 'Seven', 'last_name' => 'Zero']);

        $this->assertSame([7], EmployeeSearchQuery::make(['search' => '7'])->ids());
        $this->assertSame([70], EmployeeSearchQuery::make(['search' => ' 70 '])->ids());
        $this->assertSame([2], EmployeeSearchQuery::make(['search' => '#2'])->ids());
        $this->assertSame(1, EmployeeSearchQuery::make(['search' => '7'])->count());
        $this->assertSame([], EmployeeSearchQuery::make(['search' => '999'])->ids());
    }

    public function test_numeric_search_is_combined_with_the_other_filters(): void
    {
        $this->assertSame([1], EmployeeSearchQuery::make(['search' => '1', 'gender' => 'F'])->ids());
        $this->assertSame([], EmployeeSearchQuery::make(['search' => '1', 'gender' => 'M'])->ids());
        $this->assertSame([], EmployeeSearchQuery::make(['search' => '3', 'employmentStatus' => 'current'])->ids());
    }

    public function test_non_numeric_terms_still_search_names(): void
    {
        $this->assertSame([1, 3, 6], EmployeeSearchQuery::make(['search' => 'Smith'])->ids());
        $this->assertSame([], EmployeeSearchQuery::make(['search' => '1a'])->ids(), 'mixed terms are a name search');
    }

    public function test_employee_number_parsing(): void
    {
        $this->assertSame(10001, (new EmployeeFilters(search: '10001'))->employeeNumber());
        $this->assertSame(5, (new EmployeeFilters(search: ' #5 '))->employeeNumber());
        $this->assertNull((new EmployeeFilters(search: 'Anna'))->employeeNumber());
        $this->assertNull((new EmployeeFilters(search: '-5'))->employeeNumber());
        $this->assertNull((new EmployeeFilters(search: '1.5'))->employeeNumber());
        $this->assertNull((new EmployeeFilters(search: '99999999999'))->employeeNumber(), 'beyond the INT column');
    }

    public function test_the_numeric_search_is_a_primary_key_lookup(): void
    {
        $sql = EmployeeSearchQuery::make(['search' => '10001'])->filtered()->toSql();

        $this->assertStringContainsString('`e`.`emp_no` = ?', $sql);
        $this->assertStringNotContainsString('like', strtolower($sql));
    }

    public function test_the_directory_list_finds_an_employee_number(): void
    {
        $component = Livewire::test(EmployeeList::class)->call('updateFilters', ['search' => '4']);

        $this->assertSame([4], $this->listed($component));
        $component->assertSee('1 employee(s) found');
    }

    // ------------------------------------------------------------ URL state

    public function test_the_list_reads_filters_sort_and_page_from_the_url(): void
    {
        $component = Livewire::withQueryParams([
            'search' => 'Smith', 'gender' => 'M', 'sort' => 'name', 'direction' => 'desc',
        ])->test(EmployeeList::class);

        $component->assertSet('search', 'Smith')
            ->assertSet('gender', 'M')
            ->assertSet('sortBy', 'name')
            ->assertSet('sortDirection', 'desc');
        $this->assertSame([3, 6], $this->listed($component));
    }

    public function test_url_aliases_for_status_department_title_and_salary(): void
    {
        $component = Livewire::withQueryParams([
            'status' => 'current', 'department' => $this->alpha->dept_no, 'title' => 'Engineer', 'salary_min' => '40000',
        ])->test(EmployeeList::class);

        $component->assertSet('employmentStatus', 'current')
            ->assertSet('department', $this->alpha->dept_no)
            ->assertSet('jobTitleFilter', 'Engineer')
            ->assertSet('salaryMin', '40000');
        $this->assertSame([1, 6], $this->listed($component));
    }

    public function test_the_page_is_read_from_the_url(): void
    {
        Employee::factory()->count(25)->create();

        $component = Livewire::withQueryParams(['page' => 2])->test(EmployeeList::class);

        $this->assertSame(2, $component->viewData('employees')->currentPage());
        $this->assertCount(12, $component->viewData('employees')->items());
    }

    public function test_invalid_url_values_are_normalized(): void
    {
        $component = Livewire::withQueryParams([
            'status' => 'nonsense', 'gender' => 'X', 'sort' => 'salary; DROP', 'direction' => 'up', 'salary_min' => 'abc',
        ])->test(EmployeeList::class);

        $component->assertSet('employmentStatus', 'all')
            ->assertSet('gender', 'all')
            ->assertSet('sortBy', 'emp_no')
            ->assertSet('sortDirection', 'asc')
            ->assertSet('salaryMin', '');
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->listed($component));
    }

    public function test_url_salary_filter_and_sort_are_ignored_for_viewers(): void
    {
        $this->signInAs(Role::Viewer);

        $component = Livewire::withQueryParams(['salary_min' => '80000', 'sort' => 'salary'])->test(EmployeeList::class);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->listed($component));
        $component->assertDontSee('Current Salary');
    }

    public function test_a_managers_url_cannot_widen_the_department_scope(): void
    {
        $this->signInAs(Role::Manager, $this->omega);

        $component = Livewire::withQueryParams(['department' => $this->alpha->dept_no])->test(EmployeeList::class);

        $this->assertSame([2], $this->listed($component), 'only Omega, whatever the URL says');
    }

    public function test_filter_and_sort_changes_are_reflected_in_the_url_properties(): void
    {
        Livewire::test(EmployeeList::class)
            ->call('updateFilters', ['search' => 'Anna', 'gender' => 'F', 'jobTitle' => 'Engineer'])
            ->call('sortByField', 'name')
            ->assertSet('search', 'Anna')
            ->assertSet('gender', 'F')
            ->assertSet('jobTitleFilter', 'Engineer')
            ->assertSet('sortBy', 'name');
    }

    public function test_restoring_a_url_value_resets_the_selection_but_keeps_the_page(): void
    {
        Employee::factory()->count(25)->create();

        $component = Livewire::withQueryParams(['page' => 2])->test(EmployeeList::class)
            ->call('toggleEmployee', 1)
            ->set('gender', 'M')       // what Back does: set the property from the history entry
            ->assertSet('excludedEmployees', [])
            ->assertDispatched('filtersRestored');

        $this->assertSame(2, $component->viewData('employees')->currentPage());
    }

    public function test_a_restored_sort_value_is_validated(): void
    {
        Livewire::test(EmployeeList::class)
            ->set('sortBy', 'nonsense')
            ->assertSet('sortBy', 'emp_no');
    }

    public function test_the_filter_inputs_start_with_the_url_values_and_open_the_panel(): void
    {
        Livewire::withQueryParams(['search' => 'Smith', 'gender' => 'F', 'title' => 'Engineer'])
            ->test(EmployeeFilter::class)
            ->assertSet('search', 'Smith')
            ->assertSet('gender', 'F')
            ->assertSet('jobTitle', 'Engineer')
            ->assertSet('showFilters', true);

        Livewire::withQueryParams(['search' => 'Smith'])
            ->test(EmployeeFilter::class)
            ->assertSet('showFilters', false);
    }

    public function test_the_filter_inputs_follow_restored_values_without_sending_them_back(): void
    {
        Livewire::test(EmployeeFilter::class)
            ->call('restoreFilters', ['search' => 'Bob', 'gender' => 'M', 'salaryMin' => 'x'])
            ->assertSet('search', 'Bob')
            ->assertSet('gender', 'M')
            ->assertSet('salaryMin', '')
            ->assertNotDispatched('filtersUpdated');
    }

    public function test_the_directory_page_renders_with_url_parameters(): void
    {
        $this->get(route('employees.index', ['search' => 'Jones', 'sort' => 'name']))
            ->assertOk()
            ->assertSee('Bob')
            ->assertDontSee('Hank')
            ->assertSee('Search by name or employee number');
    }
}
