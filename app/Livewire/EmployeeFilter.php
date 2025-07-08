<?php

namespace App\Livewire;

use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class EmployeeFilter extends Component
{
    public $search = '';
    public $employmentStatus = 'all';
    public $gender = 'all';
    public $department = 'all';
    public $salaryMin = '';
    public $salaryMax = '';
    public $jobTitle = 'all';
    public $showFilters = false;

    protected $listeners = ['resetFilters' => 'resetAllFilters'];

    public function mount($search = '', $employmentStatus = 'all', $gender = 'all', $department = 'all', $salaryMin = '', $salaryMax = '', $jobTitle = 'all') // Dodaj jobTitle
    {
        $this->search = $search;
        $this->employmentStatus = $employmentStatus;
        $this->gender = $gender;
        $this->department = $department;
        $this->salaryMin = $salaryMin;
        $this->salaryMax = $salaryMax;
        $this->jobTitle = $jobTitle;
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
        $departments = Department::orderBy('dept_name')->get();
        $jobTitles = DB::table('titles')->select('title')->distinct()->orderBy('title')->get();

        return view('livewire.employee-filter', [
            'departments' => $departments,
            'jobTitles' => $jobTitles
        ]);
    }
}
