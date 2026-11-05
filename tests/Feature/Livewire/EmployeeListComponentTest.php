<?php

namespace Tests\Feature\Livewire;

use App\Livewire\EmployeeList;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\BuildsDirectory;
use Tests\TestCase;

class EmployeeListComponentTest extends TestCase
{
    use BuildsDirectory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDirectory();
    }

    private function filters(array $overrides = []): array
    {
        return $overrides + [
            'search' => '', 'employmentStatus' => 'all', 'gender' => 'all', 'department' => 'all',
            'salaryMin' => '', 'salaryMax' => '', 'jobTitle' => 'all',
        ];
    }

    private function listed($component): array
    {
        return collect($component->viewData('employees')->items())->pluck('emp_no')->map(fn ($n) => (int) $n)->all();
    }

    // ----------------------------------------------------------- rendering

    public function test_initial_state_lists_everybody_with_everything_selected(): void
    {
        $component = Livewire::test(EmployeeList::class);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->listed($component));
        $component->assertSet('selectAll', true)
            ->assertSet('selectedEmployees', [])
            ->assertSet('excludedEmployees', [])
            ->assertSet('sortBy', 'emp_no')
            ->assertSet('sortDirection', 'asc')
            ->assertSee('7 employee(s) found')
            ->assertSee('Selected 7 of 7 records');
    }

    public function test_rows_show_current_and_former_employee_details(): void
    {
        $rows = Livewire::test(EmployeeList::class)->viewData('employees')->items();
        $byNo = collect($rows)->keyBy('emp_no');

        $this->assertSame('Alpha', $byNo[1]->current_dept_name);
        $this->assertSame('Engineer', $byNo[1]->currentTitle->title);
        $this->assertEquals(50000, $byNo[1]->currentSalary->salary);
        $this->assertTrue($byNo[1]->isSelected);

        $this->assertNull($byNo[3]->currentSalary);
        $this->assertCount(0, $byNo[3]->currentDepartment);
        $this->assertSame('Mid', $byNo[3]->last_dept_name);
        $this->assertSame('Staff', $byNo[3]->last_job_title);
    }

    public function test_list_is_paginated_by_twenty(): void
    {
        Employee::factory()->count(25)->create(); // 32 employees in total

        $component = Livewire::test(EmployeeList::class);
        $this->assertCount(20, $component->viewData('employees')->items());
        $this->assertSame(32, $component->viewData('employees')->total());

        $component->call('gotoPage', 2);
        $this->assertCount(12, $component->viewData('employees')->items());
        $this->assertSame(
            Employee::orderBy('emp_no')->pluck('emp_no')->slice(20)->map(fn ($n) => (int) $n)->values()->all(),
            $this->listed($component)
        );
    }

    // ------------------------------------------------------ filter event flow

    public function test_filters_updated_event_applies_the_filters(): void
    {
        Livewire::test(EmployeeList::class)
            ->dispatch('filtersUpdated', $this->filters(['gender' => 'F', 'employmentStatus' => 'former']))
            ->assertSet('gender', 'F')
            ->assertSet('employmentStatus', 'former')
            ->assertSee('2 employee(s) found')
            ->assertSee('Dana')
            ->assertSee('Eve')
            ->assertDontSee('Hank');
    }

    public function test_update_filters_maps_every_field_and_defaults_missing_ones(): void
    {
        Livewire::test(EmployeeList::class)
            ->call('updateFilters', [
                'search' => 'Smith', 'employmentStatus' => 'current', 'gender' => 'M', 'department' => $this->alpha->dept_no,
                'salaryMin' => '1000', 'salaryMax' => '99999', 'jobTitle' => 'Engineer',
            ])
            ->assertSet('search', 'Smith')
            ->assertSet('employmentStatus', 'current')
            ->assertSet('gender', 'M')
            ->assertSet('department', $this->alpha->dept_no)
            ->assertSet('salaryMin', '1000')
            ->assertSet('salaryMax', '99999')
            ->assertSet('jobTitleFilter', 'Engineer')
            ->call('updateFilters', [])
            ->assertSet('search', '')
            ->assertSet('employmentStatus', 'all')
            ->assertSet('gender', 'all')
            ->assertSet('department', 'all')
            ->assertSet('salaryMin', '')
            ->assertSet('salaryMax', '')
            ->assertSet('jobTitleFilter', 'all')
            ->assertSee('7 employee(s) found');
    }

    public function test_update_filters_sanitizes_the_payload(): void
    {
        Livewire::test(EmployeeList::class)
            ->call('updateFilters', [
                'search' => ['Smith'], 'employmentStatus' => 'bogus', 'gender' => ['F'], 'department' => ['d001'],
                'salaryMin' => 'abc', 'salaryMax' => ['80000'], 'jobTitle' => (object) ['Engineer'],
            ])
            ->assertSet('search', '')
            ->assertSet('employmentStatus', 'all')
            ->assertSet('gender', 'all')
            ->assertSet('department', 'all')
            ->assertSet('salaryMin', '')
            ->assertSet('salaryMax', '')
            ->assertSet('jobTitleFilter', 'all')
            ->assertSee('7 employee(s) found')
            ->call('updateFilters', ['gender' => 'M', 'salaryMin' => '0', 'salaryMax' => '70000.5', 'department' => ''])
            ->assertSet('gender', 'M')
            ->assertSet('salaryMin', '')
            ->assertSet('salaryMax', '70000.5')
            ->assertSet('department', 'all');
    }

    public function test_update_filters_ignores_non_array_payloads(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('updateFilters', $this->filters(['gender' => 'F']))
            ->call('toggleEmployee', 1);

        foreach (['F', 42, null, true] as $payload) {
            $component->call('updateFilters', $payload)
                ->assertHasNoErrors()
                ->assertSet('gender', 'F')
                ->assertSet('excludedEmployees', [1], 'an ignored payload does not reset the selection either');
        }
    }

    public function test_each_filter_narrows_the_list(): void
    {
        $component = Livewire::test(EmployeeList::class);

        $cases = [
            [['search' => 'Hank'], [7]],
            [['employmentStatus' => 'current'], [1, 2, 6, 7]],
            [['gender' => 'F'], [1, 4, 5]],
            [['department' => $this->omega->dept_no], [2]],
            [['jobTitle' => 'Manager'], [2, 4]],
            [['salaryMin' => 60000, 'salaryMax' => 80000], [7]],
            [['gender' => 'M', 'search' => 'Smith', 'jobTitle' => 'Engineer'], [6]],
        ];

        foreach ($cases as [$override, $expected]) {
            $component->call('updateFilters', $this->filters($override));
            $this->assertSame($expected, $this->listed($component), json_encode($override));
        }
    }

    public function test_filter_with_no_matches_shows_zero(): void
    {
        Livewire::test(EmployeeList::class)
            ->call('updateFilters', $this->filters(['search' => 'nobody-like-this']))
            ->assertSee('0 employee(s) found')
            ->assertSee('Selected 0 of 0 records');
    }

    public function test_changing_filters_resets_the_page(): void
    {
        Employee::factory()->count(25)->create();

        Livewire::test(EmployeeList::class)
            ->call('gotoPage', 2)
            ->assertSet('paginators.page', 2)
            ->call('updateFilters', $this->filters(['gender' => 'F']))
            ->assertSet('paginators.page', 1);
    }

    public function test_changing_filters_resets_the_selection(): void
    {
        Livewire::test(EmployeeList::class)
            ->call('toggleEmployee', 1)
            ->set('selectAll', false)
            ->call('toggleEmployee', 2)
            ->assertSet('selectedEmployees', [2])
            ->call('updateFilters', $this->filters(['gender' => 'F']))
            ->assertSet('selectAll', true)
            ->assertSet('selectedEmployees', [])
            ->assertSet('excludedEmployees', []);
    }

    // --------------------------------------------------------------- sorting

    public function test_sorting_toggles_direction_on_the_same_field_and_starts_ascending_on_a_new_one(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('sortByField', 'name')
            ->assertSet('sortBy', 'name')
            ->assertSet('sortDirection', 'asc');
        $this->assertSame([1, 6, 2, 3, 4, 5, 7], $this->listed($component));

        $component->call('sortByField', 'name')->assertSet('sortDirection', 'desc');
        $this->assertSame([7, 5, 4, 3, 2, 6, 1], $this->listed($component));

        $component->call('sortByField', 'name')->assertSet('sortDirection', 'asc');

        $component->call('sortByField', 'name')->assertSet('sortDirection', 'desc')
            ->call('sortByField', 'salary')
            ->assertSet('sortBy', 'salary')
            ->assertSet('sortDirection', 'asc');
        $this->assertSame([3, 4, 5, 1, 6, 7, 2], $this->listed($component));
    }

    public function test_every_whitelisted_field_can_be_sorted_both_ways(): void
    {
        $component = Livewire::test(EmployeeList::class);

        foreach (['emp_no', 'name', 'department', 'title', 'salary', 'hire_date', 'gender'] as $field) {
            $component->call('sortByField', $field);
            if ($field === 'emp_no') { // already sorted by it -> the first call flips to desc
                $this->assertSame([7, 6, 5, 4, 3, 2, 1], $this->listed($component));
                $component->call('sortByField', $field);
            }
            $asc = $this->listed($component);
            $component->call('sortByField', $field);
            $desc = $this->listed($component);

            $this->assertCount(7, $asc, $field);
            $this->assertSame(array_reverse($asc), $desc, "$field: desc is the exact reverse of asc (ties by emp_no)");
        }
    }

    public function test_sorting_by_an_unknown_field_is_ignored(): void
    {
        Livewire::test(EmployeeList::class)
            ->call('sortByField', 'salary; DROP TABLE employees')
            ->assertSet('sortBy', 'emp_no')
            ->assertSet('sortDirection', 'asc')
            ->call('sortByField', 'password')
            ->assertSet('sortBy', 'emp_no')
            ->assertSee('7 employee(s) found');

        $this->assertSame(7, Employee::count());
    }

    public function test_a_tampered_sort_property_falls_back_to_the_default_order(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->set('sortBy', 'birth_date; DROP TABLE employees')
            ->set('sortDirection', 'sideways');

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->listed($component));
        $this->assertSame(7, Employee::count());
    }

    public function test_sorting_resets_the_page_but_keeps_filters_and_selection(): void
    {
        Employee::factory()->count(25)->create();

        Livewire::test(EmployeeList::class)
            ->call('gotoPage', 2)
            ->call('toggleEmployee', 3)
            ->call('sortByField', 'name')
            ->assertSet('paginators.page', 1)
            ->assertSet('excludedEmployees', [3]);
    }

    // ------------------------------------------------------------- selection

    public function test_toggling_in_select_all_mode_excludes_and_reincludes(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('toggleEmployee', 2)
            ->assertSet('excludedEmployees', [2])
            ->assertSee('Selected 6 of 7 records')
            ->call('toggleEmployee', 5)
            ->assertSet('excludedEmployees', [2, 5])
            ->assertSee('Selected 5 of 7 records')
            ->call('toggleEmployee', 2)
            ->assertSet('excludedEmployees', [5])
            ->assertSee('Selected 6 of 7 records');

        $this->assertTrue($component->instance()->isEmployeeSelected(2));
        $this->assertFalse($component->instance()->isEmployeeSelected(5));
    }

    public function test_unchecking_select_all_clears_selection_and_individual_picks_count(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('toggleEmployee', 1)
            ->set('selectAll', false)
            ->assertSet('excludedEmployees', [])
            ->assertSet('selectedEmployees', [])
            ->assertSee('Selected 0 of 7 records')
            ->call('toggleEmployee', 3)
            ->call('toggleEmployee', 4)
            ->assertSet('selectedEmployees', [3, 4])
            ->assertSee('Selected 2 of 7 records')
            ->call('toggleEmployee', 3)
            ->assertSet('selectedEmployees', [4])
            ->assertSee('Selected 1 of 7 records');

        $this->assertTrue($component->instance()->isEmployeeSelected(4));
        $this->assertFalse($component->instance()->isEmployeeSelected(3));
    }

    public function test_re_checking_select_all_discards_individual_picks(): void
    {
        Livewire::test(EmployeeList::class)
            ->set('selectAll', false)
            ->call('toggleEmployee', 3)
            ->set('selectAll', true)
            ->assertSet('selectedEmployees', [])
            ->assertSet('excludedEmployees', [])
            ->assertSee('Selected 7 of 7 records');
    }

    public function test_toggle_accepts_numeric_strings(): void
    {
        Livewire::test(EmployeeList::class)
            ->call('toggleEmployee', '4')
            ->assertSet('excludedEmployees', [4]);
    }

    public function test_reset_selection_selects_everything_again(): void
    {
        Livewire::test(EmployeeList::class)
            ->call('toggleEmployee', 1)
            ->call('toggleEmployee', 2)
            ->call('resetSelection')
            ->assertSet('selectAll', true)
            ->assertSet('excludedEmployees', [])
            ->assertSee('Selected 7 of 7 records');
    }

    public function test_selected_count_follows_the_filtered_total(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('updateFilters', $this->filters(['employmentStatus' => 'former']))
            ->assertSee('Selected 3 of 3 records')
            ->call('toggleEmployee', 4)
            ->assertSee('Selected 2 of 3 records');

        $this->assertSame(2, $component->instance()->getSelectedCount());
    }

    public function test_selected_count_never_goes_below_zero(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('updateFilters', $this->filters(['gender' => 'F']));

        // Excluding employees that are not part of the filtered list (e.g. stale ids) must not give negatives.
        $component->set('excludedEmployees', [1, 2, 3, 4, 5, 6, 7]);

        $this->assertSame(0, $component->instance()->getSelectedCount());
        $this->assertFalse($component->instance()->getSelectionInfo()['hasSelection']);
    }

    public function test_selected_employee_ids_in_select_all_mode_respect_filters_exclusions_and_sort(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('updateFilters', $this->filters(['gender' => 'M']))
            ->call('sortByField', 'hire_date')
            ->call('toggleEmployee', 3);

        // M: 2 (1985), 3 (1988, excluded), 6 (1990), 7 (1991)
        $this->assertSame([2, 6, 7], $component->instance()->getSelectedEmployeeIds());
    }

    public function test_selected_employee_ids_in_manual_mode_are_the_picked_ones(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->set('selectAll', false)
            ->call('toggleEmployee', 6)
            ->call('toggleEmployee', 1);

        $this->assertSame([6, 1], $component->instance()->getSelectedEmployeeIds());
    }

    public function test_selection_info(): void
    {
        $component = Livewire::test(EmployeeList::class)->call('toggleEmployee', 1);

        $this->assertSame([
            'selectAll' => true,
            'selectedEmployees' => [],
            'excludedEmployees' => [1],
            'totalEmployees' => 7,
            'selectedCount' => 6,
            'hasSelection' => true,
        ], $component->instance()->getSelectionInfo());
    }

    public function test_rows_reflect_the_selection_state(): void
    {
        $component = Livewire::test(EmployeeList::class)->call('toggleEmployee', 2);
        $selected = collect($component->viewData('employees')->items())->mapWithKeys(fn ($e) => [(int) $e->emp_no => $e->isSelected]);

        $this->assertFalse($selected[2]);
        $this->assertTrue($selected[1]);
    }

    // ------------------------------------------------------ cached total

    public function test_total_is_cached_between_requests_and_survives_sorting_selecting_and_paging(): void
    {
        $component = Livewire::test(EmployeeList::class)->assertSet('totalEmployees', 7);

        Employee::factory()->create(); // appears in the table but the total is cached

        $component->call('sortByField', 'name')
            ->call('toggleEmployee', 1)
            ->assertSet('totalEmployees', 7)
            ->assertSee('Selected 6 of 7 records');
    }

    public function test_cached_total_is_recomputed_when_the_filters_change(): void
    {
        $component = Livewire::test(EmployeeList::class)->assertSet('totalEmployees', 7);

        Employee::factory()->create(['gender' => 'F']);

        $component->call('updateFilters', $this->filters(['gender' => 'F']))
            ->assertSet('totalEmployees', 4)
            ->call('updateFilters', $this->filters())
            ->assertSet('totalEmployees', 8)
            ->assertSee('8 employee(s) found');
    }

    public function test_cached_total_is_recomputed_for_each_individual_filter(): void
    {
        $component = Livewire::test(EmployeeList::class)->assertSet('totalEmployees', 7);

        $steps = [
            ['search' => 'Smith', 'expected' => 3],
            ['employmentStatus' => 'former', 'expected' => 3],
            ['gender' => 'F', 'expected' => 3],
            ['department' => $this->alpha->dept_no, 'expected' => 3],
            ['jobTitle' => 'Manager', 'expected' => 2],
            ['salaryMin' => 60000, 'expected' => 2],
            ['salaryMax' => 50000, 'expected' => 2],
        ];

        foreach ($steps as $step) {
            $expected = array_pop($step);
            $component->call('updateFilters', $this->filters($step))->assertSet('totalEmployees', $expected);
        }
    }

    public function test_cache_key_ignores_sorting(): void
    {
        $component = Livewire::test(EmployeeList::class);
        $key = $component->get('totalKey');

        $component->call('sortByField', 'salary');

        $this->assertNotSame('', $key);
        $component->assertSet('totalKey', $key);
    }

    public function test_total_cannot_be_overwritten_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(EmployeeList::class)->set('totalEmployees', 5);
    }

    public function test_total_key_cannot_be_overwritten_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(EmployeeList::class)->set('totalKey', 'x');
    }

    // ----------------------------------------------------------- export payload

    public function test_export_payload_in_select_all_mode(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('updateFilters', $this->filters([
                'search' => 'Smith', 'employmentStatus' => 'current', 'gender' => 'M',
                'department' => $this->alpha->dept_no, 'jobTitle' => 'Engineer', 'salaryMin' => '10000', 'salaryMax' => '60000',
            ]))
            ->call('sortByField', 'name')
            ->call('sortByField', 'name');

        $this->assertSame([
            'search' => 'Smith',
            'employmentStatus' => 'current',
            'gender' => 'M',
            'department' => $this->alpha->dept_no,
            'jobTitle' => 'Engineer',
            'salaryMin' => '10000',
            'salaryMax' => '60000',
            'sortBy' => 'name',
            'sortDirection' => 'desc',
            'selectAll' => true,
            'selectedEmployees' => [],
            'excludedEmployees' => [],
            'selectedCount' => 1,
        ], $component->instance()->getExportPayload());
    }

    public function test_export_payload_carries_exclusions_and_manual_selection(): void
    {
        $component = Livewire::test(EmployeeList::class)->call('toggleEmployee', 4)->call('toggleEmployee', 5);

        $payload = $component->instance()->getExportPayload();
        $this->assertTrue($payload['selectAll']);
        $this->assertSame([4, 5], $payload['excludedEmployees']);
        $this->assertSame([], $payload['selectedEmployees']);
        $this->assertSame(5, $payload['selectedCount']);

        $component->set('selectAll', false)->call('toggleEmployee', 7);
        $payload = $component->instance()->getExportPayload();
        $this->assertFalse($payload['selectAll']);
        $this->assertSame([7], $payload['selectedEmployees']);
        $this->assertSame([], $payload['excludedEmployees']);
        $this->assertSame(1, $payload['selectedCount']);
    }

    public function test_export_payload_normalizes_invalid_filter_values(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->set('employmentStatus', 'bogus')
            ->set('gender', 'X')
            ->set('sortBy', 'evil')
            ->set('sortDirection', 'up');

        $payload = $component->instance()->getExportPayload();

        $this->assertSame('all', $payload['employmentStatus']);
        $this->assertSame('all', $payload['gender']);
        $this->assertSame('emp_no', $payload['sortBy']);
        $this->assertSame('asc', $payload['sortDirection']);
    }

    public function test_export_payload_can_be_posted_to_the_export_endpoint(): void
    {
        $component = Livewire::test(EmployeeList::class)
            ->call('updateFilters', $this->filters(['gender' => 'M']))
            ->call('toggleEmployee', 2);

        $payload = $component->instance()->getExportPayload();
        // Encoded the way the export form does: one comma-separated field per id list.
        $payload['selectAll'] = $payload['selectAll'] ? '1' : '0';
        $payload['excludedEmployees'] = implode(',', $payload['excludedEmployees']);
        $payload['selectedEmployees'] = implode(',', $payload['selectedEmployees']);
        $payload['format'] = 'csv';

        $content = $this->post(route('employees.export'), $payload)->assertOk()->streamedContent();
        $rows = array_filter(explode("\n", trim($content)));

        $this->assertCount(4, $rows, 'header + the 3 remaining male employees (2 is excluded)');
        $this->assertStringContainsString('Hank', $content);
        $this->assertStringNotContainsString('Jones', $content);
        $this->assertStringNotContainsString('Dana', $content);
    }
}
