<?php

namespace Tests\Feature\Livewire;

use App\Livewire\EmployeeFilter;
use App\Livewire\EmployeeList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDirectory;
use Tests\TestCase;

class EmployeeFilterComponentTest extends TestCase
{
    use BuildsDirectory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDirectory();
    }

    private function emptyFilters(array $overrides = []): array
    {
        return array_merge([
            'search' => '', 'employmentStatus' => 'all', 'gender' => 'all', 'department' => 'all',
            'salaryMin' => '', 'salaryMax' => '', 'jobTitle' => 'all',
        ], $overrides);
    }

    public function test_renders_with_defaults_and_offers_every_department_and_job_title(): void
    {
        Livewire::test(EmployeeFilter::class)
            ->assertSet('search', '')
            ->assertSet('employmentStatus', 'all')
            ->assertSet('gender', 'all')
            ->assertSet('department', 'all')
            ->assertSet('salaryMin', '')
            ->assertSet('salaryMax', '')
            ->assertSet('jobTitle', 'all')
            ->assertSee(['Alpha', 'Mid', 'Omega'])
            ->assertSee(['Engineer', 'Manager', 'Senior Staff', 'Staff']);
    }

    public function test_job_titles_are_listed_once_each(): void
    {
        $titles = Livewire::test(EmployeeFilter::class)->viewData('jobTitles')->pluck('title')->all();

        $this->assertSame(['Engineer', 'Manager', 'Senior Staff', 'Staff'], $titles);
        $this->assertSame(['Alpha', 'Mid', 'Omega'], Livewire::test(EmployeeFilter::class)->viewData('departments')->pluck('dept_name')->all());
    }

    public function test_mount_takes_initial_values(): void
    {
        Livewire::test(EmployeeFilter::class, [
            'search' => 'Smith', 'employmentStatus' => 'current', 'gender' => 'F', 'department' => 'd005',
            'salaryMin' => 100, 'salaryMax' => 200, 'jobTitle' => 'Staff',
        ])
            ->assertSet('search', 'Smith')
            ->assertSet('employmentStatus', 'current')
            ->assertSet('gender', 'F')
            ->assertSet('department', 'd005')
            ->assertSet('salaryMin', 100)
            ->assertSet('salaryMax', 200)
            ->assertSet('jobTitle', 'Staff');
    }

    #[DataProvider('fieldProvider')]
    public function test_changing_a_field_dispatches_all_current_filters(string $property, mixed $value): void
    {
        Livewire::test(EmployeeFilter::class)
            ->set($property, $value)
            ->assertDispatched('filtersUpdated', fn (string $event, array $params) => $params[0] === $this->emptyFilters([$property => $value]));
    }

    public static function fieldProvider(): array
    {
        return [
            'search' => ['search', 'Smith'],
            'employmentStatus' => ['employmentStatus', 'former'],
            'gender' => ['gender', 'M'],
            'department' => ['department', 'd005'],
            'salaryMin' => ['salaryMin', '40000'],
            'salaryMax' => ['salaryMax', '90000'],
            'jobTitle' => ['jobTitle', 'Manager'],
        ];
    }

    public function test_the_dispatched_payload_accumulates_all_fields(): void
    {
        Livewire::test(EmployeeFilter::class)
            ->set('search', 'Anna')
            ->set('gender', 'F')
            ->set('salaryMin', '1000')
            ->assertDispatched('filtersUpdated', fn (string $event, array $params) => $params[0] === $this->emptyFilters([
                'search' => 'Anna', 'gender' => 'F', 'salaryMin' => '1000',
            ]));
    }

    public function test_toggling_the_panel_does_not_dispatch_filters(): void
    {
        Livewire::test(EmployeeFilter::class)
            ->set('showFilters', true)
            ->assertNotDispatched('filtersUpdated');
    }

    public function test_reset_event_clears_every_filter_and_notifies_the_list(): void
    {
        Livewire::test(EmployeeFilter::class)
            ->set('search', 'Anna')
            ->set('employmentStatus', 'current')
            ->set('gender', 'F')
            ->set('department', 'd005')
            ->set('salaryMin', '1')
            ->set('salaryMax', '2')
            ->set('jobTitle', 'Staff')
            ->dispatch('resetFilters')
            ->assertSet('search', '')
            ->assertSet('employmentStatus', 'all')
            ->assertSet('gender', 'all')
            ->assertSet('department', 'all')
            ->assertSet('salaryMin', '')
            ->assertSet('salaryMax', '')
            ->assertSet('jobTitle', 'all')
            ->assertDispatched('filtersUpdated', fn (string $event, array $params) => $params[0] === $this->emptyFilters());
    }

    public function test_reset_all_filters_method_can_be_called_directly(): void
    {
        Livewire::test(EmployeeFilter::class)
            ->set('gender', 'M')
            ->call('resetAllFilters')
            ->assertSet('gender', 'all');
    }

    public function test_dispatched_filters_drive_the_list_component(): void
    {
        $filter = Livewire::test(EmployeeFilter::class)->set('employmentStatus', 'former')->set('gender', 'F');
        $payload = collect($filter->effects['dispatches'] ?? [])->where('name', 'filtersUpdated')->last()['params'][0];

        $this->assertSame($this->emptyFilters(['employmentStatus' => 'former', 'gender' => 'F']), $payload);

        Livewire::test(EmployeeList::class)
            ->dispatch('filtersUpdated', $payload)
            ->assertSee('2 employee(s) found')
            ->assertSee('Dana')
            ->assertDontSee('Anna');
    }

    public function test_reset_returns_the_list_to_the_unfiltered_state(): void
    {
        $list = Livewire::test(EmployeeList::class)
            ->dispatch('filtersUpdated', $this->emptyFilters(['search' => 'Hank']))
            ->assertSee('1 employee(s) found')
            ->dispatch('filtersUpdated', $this->emptyFilters())
            ->assertSee('7 employee(s) found');

        $this->assertSame(7, $list->viewData('employees')->total());
    }
}
