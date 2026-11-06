<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReadsInitialDepartment;
use App\Models\Department;
use App\Queries\EmployeeFilters;
use App\Support\EmployeeDataCache;
use App\Support\EmployeeVisibility;
use App\Support\HistoryRows;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class EmployeeFilter extends Component
{
    use ReadsInitialDepartment;

    private const DEPARTMENT_TITLES_TTL = 1800;

    public $search = '';

    public $employmentStatus = 'all';

    public $gender = 'all';

    public $department = 'all';

    public $salaryMin = '';

    public $salaryMax = '';

    public $jobTitle = 'all';

    public $showFilters = false;

    protected $listeners = ['resetFilters' => 'resetAllFilters', 'filtersRestored' => 'restoreFilters'];

    /** URL query parameters of the directory (owned by EmployeeList) => filter input. */
    private const URL_PARAMETERS = [
        'search' => 'search',
        'status' => 'employmentStatus',
        'gender' => 'gender',
        'department' => 'department',
        'title' => 'jobTitle',
        'salary_min' => 'salaryMin',
        'salary_max' => 'salaryMax',
    ];

    public function mount($search = '', $employmentStatus = 'all', $gender = 'all', $department = 'all', $salaryMin = '', $salaryMax = '', $jobTitle = 'all')
    {
        $this->search = $search;
        $this->employmentStatus = $employmentStatus;
        $this->gender = $gender;
        $this->department = $department !== 'all' ? $department : $this->initialDepartment();
        $this->showFilters = $this->department !== 'all';
        $this->salaryMin = $salaryMin;
        $this->salaryMax = $salaryMax;
        $this->jobTitle = $jobTitle;

        // A shared link / reloaded page: start with the filters of the URL (the list reads
        // the same parameters) and show the filter panel when more than the search is set.
        $fromUrl = array_intersect_key(request()->query(), self::URL_PARAMETERS);
        if ($fromUrl !== []) {
            $this->fill($this->normalized(array_combine(
                array_map(fn ($key) => self::URL_PARAMETERS[$key], array_keys($fromUrl)),
                $fromUrl
            ) + $this->getFilters()));
            $this->showFilters = array_diff_key($fromUrl, ['search' => true]) !== [];
        }

        // A manager's list is always limited to their department (enforced by
        // EmployeeList); show that department as the selected, locked filter.
        $visibility = EmployeeVisibility::current();
        if ($visibility->isDepartmentScoped()) {
            $this->department = $visibility->department() ?? EmployeeVisibility::NO_DEPARTMENT;
        }
    }

    public function updatedSearch()
    {
        $this->dispatch('filtersUpdated', $this->getFilters());
    }

    public function updatedEmploymentStatus()
    {
        $this->dispatch('filtersUpdated', $this->getFilters());
    }

    public function updatedGender()
    {
        $this->dispatch('filtersUpdated', $this->getFilters());
    }

    public function updatedDepartment()
    {
        $this->dispatch('filtersUpdated', $this->getFilters());
    }

    public function updatedSalaryMin()
    {
        $this->dispatch('filtersUpdated', $this->getFilters());
    }

    public function updatedSalaryMax()
    {
        $this->dispatch('filtersUpdated', $this->getFilters());
    }

    public function updatedJobTitle()
    {
        $this->dispatch('filtersUpdated', $this->getFilters());
    }

    public function resetAllFilters()
    {
        $this->search = '';
        $this->employmentStatus = 'all';
        $this->gender = 'all';
        $this->department = 'all';
        $this->salaryMin = '';
        $this->salaryMax = '';
        $this->jobTitle = 'all';

        $visibility = EmployeeVisibility::current();
        if ($visibility->isDepartmentScoped()) {
            $this->department = $visibility->department() ?? EmployeeVisibility::NO_DEPARTMENT;
        }

        $this->dispatch('filtersUpdated', $this->getFilters());
    }

    /**
     * The list restored filters from the URL (Back / Forward): show them in the inputs
     * without sending them back.
     */
    public function restoreFilters($filters)
    {
        if (! is_array($filters)) {
            return;
        }

        $this->fill($this->normalized($filters + $this->getFilters()));

        $visibility = EmployeeVisibility::current();
        if ($visibility->isDepartmentScoped()) {
            $this->department = $visibility->department() ?? EmployeeVisibility::NO_DEPARTMENT;
        }
    }

    /**
     * Filter input values normalized like the list does (EmployeeFilters).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function normalized(array $values): array
    {
        $filters = EmployeeFilters::fromArray($values);

        return [
            'search' => $filters->search,
            'employmentStatus' => $filters->employmentStatus,
            'gender' => $filters->gender,
            'department' => $filters->department,
            'jobTitle' => $filters->jobTitle,
            'salaryMin' => $filters->salaryMin === null ? '' : (string) $filters->salaryMin,
            'salaryMax' => $filters->salaryMax === null ? '' : (string) $filters->salaryMax,
        ];
    }

    private function getFilters()
    {
        return [
            'search' => $this->search,
            'employmentStatus' => $this->employmentStatus,
            'gender' => $this->gender,
            'department' => $this->department,
            'salaryMin' => $this->salaryMin,
            'salaryMax' => $this->salaryMax,
            'jobTitle' => $this->jobTitle,
        ];
    }

    public function render()
    {
        $visibility = EmployeeVisibility::current();

        $departments = Department::orderBy('dept_name')
            ->when($visibility->isDepartmentScoped(), fn ($q) => $q->where('dept_no', $visibility->department() ?? EmployeeVisibility::NO_DEPARTMENT))
            ->get();
        $jobTitles = $this->jobTitles($visibility);

        return view('livewire.employee-filter', [
            'departments' => $departments,
            'jobTitles' => $jobTitles,
            'departmentLocked' => $visibility->isDepartmentScoped(),
            'canSeeSalaries' => $visibility->canSeeSalaries(),
        ]);
    }

    /**
     * Job titles offered in the filter. A manager only gets the titles held by
     * employees of their department (no metadata about other departments),
     * cached per department.
     */
    private function jobTitles(EmployeeVisibility $visibility): Collection
    {
        if (! $visibility->isDepartmentScoped()) {
            return HistoryRows::active(DB::table('titles'))->select('title')->distinct()->orderBy('title')->get();
        }

        $key = EmployeeDataCache::departmentJobTitles($visibility->department() ?? EmployeeVisibility::NO_DEPARTMENT);

        return Cache::remember($key, self::DEPARTMENT_TITLES_TTL, fn () => $visibility
            ->constrain(HistoryRows::active(DB::table('titles as t'), 't'), 't.emp_no')
            ->select('t.title')
            ->distinct()
            ->orderBy('t.title')
            ->get());
    }
}
