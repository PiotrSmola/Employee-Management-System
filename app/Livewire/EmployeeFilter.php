<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReadsInitialDepartment;
use App\Models\Department;
use App\Support\EmployeeDataCache;
use App\Support\EmployeeVisibility;
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

    protected $listeners = ['resetFilters' => 'resetAllFilters'];

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
            return DB::table('titles')->select('title')->distinct()->orderBy('title')->get();
        }

        $key = EmployeeDataCache::departmentJobTitles($visibility->department() ?? EmployeeVisibility::NO_DEPARTMENT);

        return Cache::remember($key, self::DEPARTMENT_TITLES_TTL, fn () => $visibility
            ->constrain(DB::table('titles as t'), 't.emp_no')
            ->select('t.title')
            ->distinct()
            ->orderBy('t.title')
            ->get());
    }
}
