<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

class EmployeeList extends Component
{
    use WithPagination;

    public $search = '';
    public $employmentStatus = 'all';
    public $gender = 'all';
    public $department = 'all';
    public $salaryMin = '';
    public $salaryMax = '';
    public $jobTitleFilter = 'all';

    public $sortBy = 'emp_no';
    public $sortDirection = 'asc';

    public $selectAll = true;
    public $selectedEmployees = [];
    public $excludedEmployees = [];
    private $totalEmployees = null;

    protected $listeners = ['filtersUpdated' => 'updateFilters'];

    public function mount()
    {
        $this->selectAll = true;
        $this->selectedEmployees = [];
        $this->excludedEmployees = [];

        \Log::info('EmployeeList mounted', [
            'component_class' => get_class($this),
            'selectAll' => $this->selectAll,
            'selectedEmployees' => $this->selectedEmployees,
            'excludedEmployees' => $this->excludedEmployees,
            'public_methods' => get_class_methods($this)
        ]);
    }

    public function updateFilters($filters)
    {
        $this->search = $filters['search'];
        $this->employmentStatus = $filters['employmentStatus'];
        $this->gender = $filters['gender'];
        $this->department = $filters['department'];
        $this->salaryMin = $filters['salaryMin'];
        $this->salaryMax = $filters['salaryMax'];
        $this->jobTitleFilter = $filters['jobTitle'];

        $this->resetPage();
        $this->resetSelection();
    }

    public function sortByField($field)
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    public function updatedSelectAll()
    {
        \Log::info('updatedSelectAll called', [
            'selectAll' => $this->selectAll,
            'selectedEmployees_before' => $this->selectedEmployees,
            'excludedEmployees_before' => $this->excludedEmployees
        ]);

        if ($this->selectAll) {
            $this->selectedEmployees = [];
            $this->excludedEmployees = [];
        } else {
            $this->selectedEmployees = [];
            $this->excludedEmployees = [];
        }
        $this->totalEmployees = null;
        $this->dispatch('$refresh');
        \Log::info('updatedSelectAll finished', [
            'selectAll' => $this->selectAll,
            'selectedEmployees_after' => $this->selectedEmployees,
            'excludedEmployees_after' => $this->excludedEmployees,
            'selectedCount' => $this->getSelectedCount()
        ]);
    }

    public function toggleEmployee($empNo)
    {
        \Log::info('toggleEmployee called', [
            'empNo' => $empNo,
            'selectAll_before' => $this->selectAll,
            'selectedEmployees_before' => $this->selectedEmployees,
            'excludedEmployees_before' => $this->excludedEmployees
        ]);

        if ($this->selectAll) {
            if (in_array($empNo, $this->excludedEmployees)) {
                $this->excludedEmployees = array_values(array_diff($this->excludedEmployees, [$empNo]));
            } else {
                $this->excludedEmployees[] = $empNo;
            }
        } else {
            if (in_array($empNo, $this->selectedEmployees)) {
                $this->selectedEmployees = array_values(array_diff($this->selectedEmployees, [$empNo]));
            } else {
                $this->selectedEmployees[] = $empNo;
            }
        }
        $this->totalEmployees = null;
        \Log::info('toggleEmployee finished', [
            'empNo' => $empNo,
            'selectAll_after' => $this->selectAll,
            'selectedEmployees_after' => $this->selectedEmployees,
            'excludedEmployees_after' => $this->excludedEmployees,
            'selectedCount' => $this->getSelectedCount()
        ]);
    }

    public function isEmployeeSelected($empNo)
    {
        if ($this->selectAll) {
            return !in_array($empNo, $this->excludedEmployees);
        } else {
            return in_array($empNo, $this->selectedEmployees);
        }
    }

    public function resetSelection()
    {
        $this->selectAll = true;
        $this->selectedEmployees = [];
        $this->excludedEmployees = [];
        $this->totalEmployees = null;
        $this->dispatch('$refresh');
    }

    public function getSelectedCount()
    {
        try {
            if ($this->selectAll) {
                $total = $this->getTotalEmployees();
                $excluded = count($this->excludedEmployees);
                $result = max(0, $total - $excluded);
                \Log::info('getSelectedCount (selectAll=true)', ['total' => $total, 'excluded' => $excluded, 'result' => $result]);
                return $result;
            } else {
                $selected = count($this->selectedEmployees);
                \Log::info('getSelectedCount (selectAll=false)', ['selected' => $selected, 'selectedEmployees' => $this->selectedEmployees]);
                return $selected;
            }
        } catch (\Exception $e) {
            \Log::error('Error in getSelectedCount: ' . $e->getMessage());
            return 0;
        }
    }

    public function getTotalEmployees()
    {
        if ($this->totalEmployees === null) {
            $this->totalEmployees = $this->getEmployeesQuery()->count();
            \Log::info('getTotalEmployees calculated', [
                'total' => $this->totalEmployees,
                'search' => $this->search,
                'employmentStatus' => $this->employmentStatus,
                'gender' => $this->gender,
                'department' => $this->department,
                'jobTitleFilter' => $this->jobTitleFilter
            ]);
        }
        return $this->totalEmployees;
    }

    public function getSelectedEmployeeIds()
    {
        return $this->getAllSelectedEmployeeIds();
    }

    public function getSelectionInfo()
    {
        $totalEmployees = $this->getTotalEmployees();
        $selectedCount = $this->getSelectedCount();
        $result = [
            'selectAll' => $this->selectAll,
            'selectedEmployees' => $this->selectedEmployees,
            'excludedEmployees' => $this->excludedEmployees,
            'totalEmployees' => $totalEmployees,
            'selectedCount' => $selectedCount,
            'hasSelection' => $selectedCount > 0
        ];
        \Log::info('getSelectionInfo called', $result);
        return $result;
    }

    public function getAllSelectedEmployeeIds()
    {
        try {
            if ($this->selectAll) {
                $allIds = $this->getEmployeesQuery()->pluck('emp_no')->toArray();
                $selectedIds = array_values(array_diff($allIds, $this->excludedEmployees));
                \Log::info('getAllSelectedEmployeeIds (selectAll=true)', ['total_found' => count($allIds), 'excluded_count' => count($this->excludedEmployees), 'final_selected' => count($selectedIds)]);
                return $selectedIds;
            } else {
                \Log::info('getAllSelectedEmployeeIds (selectAll=false)', ['selected_count' => count($this->selectedEmployees), 'selected_ids' => $this->selectedEmployees]);
                return $this->selectedEmployees;
            }
        } catch (\Exception $e) {
            \Log::error('Error in getAllSelectedEmployeeIds: ' . $e->getMessage());
            return [];
        }
    }

    public function render()
    {
        $query = $this->getEmployeesQuery();
        $employees = $query->paginate(20);

        $employees->through(function ($emp) {
            return (object) [
                'emp_no' => $emp->emp_no,
                'first_name' => $emp->first_name,
                'last_name' => $emp->last_name,
                'hire_date' => $emp->hire_date,
                'gender' => $emp->gender,
                'current_dept_name' => $emp->current_dept_name,
                'current_title' => $emp->current_title,
                'current_salary' => $emp->current_salary,
                'last_dept_name' => $emp->last_dept_name,
                'last_job_title' => $emp->last_job_title,
                'currentDepartment' => $emp->current_dept_name ? collect([(object)['dept_name' => $emp->current_dept_name]]) : collect(),
                'currentTitle' => $emp->current_title ? (object)['title' => $emp->current_title] : null,
                'currentSalary' => $emp->current_salary ? (object)['salary' => $emp->current_salary] : null,
                'isSelected' => $this->isEmployeeSelected($emp->emp_no),
            ];
        });

        return view('livewire.employee-list', [
            'employees' => $employees
        ]);
    }

    private function getEmployeesQuery()
    {
        $query = DB::table('employees as e')
            ->leftJoin('dept_emp as de_current', function ($join) {
                $join->on('e.emp_no', '=', 'de_current.emp_no')
                    ->where('de_current.to_date', '=', '9999-01-01');
            })
            ->leftJoin('departments as d_current', 'de_current.dept_no', '=', 'd_current.dept_no')
            ->leftJoin('titles as t_current', function ($join) {
                $join->on('e.emp_no', '=', 't_current.emp_no')
                    ->where('t_current.to_date', '=', '9999-01-01');
            })
            ->leftJoin('salaries as s_current', function ($join) {
                $join->on('e.emp_no', '=', 's_current.emp_no')
                    ->where('s_current.to_date', '=', '9999-01-01');
            })
            ->select([
                'e.emp_no',
                'e.first_name',
                'e.last_name',
                'e.hire_date',
                'e.gender',
                'd_current.dept_name as current_dept_name',
                't_current.title as current_title',
                's_current.salary as current_salary',
                DB::raw("(SELECT d.dept_name 
                           FROM departments d 
                           JOIN dept_emp de_last ON d.dept_no = de_last.dept_no 
                           WHERE de_last.emp_no = e.emp_no 
                             AND de_last.to_date != '9999-01-01' 
                           ORDER BY de_last.to_date DESC 
                           LIMIT 1) as last_dept_name"),
                DB::raw("(SELECT t_last.title 
                           FROM titles t_last 
                           WHERE t_last.emp_no = e.emp_no 
                             AND t_last.to_date != '9999-01-01' 
                           ORDER BY t_last.to_date DESC 
                           LIMIT 1) as last_job_title")
            ]);

        // Filtering
        if ($this->search) {
            $searchTerm = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('e.first_name', 'like', $searchTerm)
                    ->orWhere('e.last_name', 'like', $searchTerm)
                    ->orWhere(DB::raw("CONCAT(e.first_name, ' ', e.last_name)"), 'like', $searchTerm);
            });
        }

        if ($this->employmentStatus === 'current') {
            $query->whereNotNull('de_current.dept_no');
        } elseif ($this->employmentStatus === 'former') {
            $query->whereNull('de_current.dept_no');
        }

        if ($this->gender !== 'all') {
            $query->where('e.gender', $this->gender);
        }

        if ($this->department !== 'all') {
            $query->where(function ($q) {
                $q->where('d_current.dept_no', $this->department)
                    ->orWhere(function ($q2) { // Last department for former employees
                        $q2->whereNull('d_current.dept_no')
                            ->whereRaw('? = (SELECT de_last.dept_no 
                                           FROM dept_emp de_last 
                                           WHERE de_last.emp_no = e.emp_no 
                                             AND de_last.to_date != \'9999-01-01\' 
                                           ORDER BY de_last.to_date DESC 
                                           LIMIT 1)', [$this->department]);
                    });
            });
        }

        if ($this->jobTitleFilter !== 'all') {
            $query->where(function ($q) {
                $q->where('t_current.title', $this->jobTitleFilter)
                    ->orWhere(function ($q2) { // Last title for former employees
                        $q2->whereNull('t_current.title')
                            ->whereRaw('? = (SELECT t_last.title 
                                             FROM titles t_last 
                                             WHERE t_last.emp_no = e.emp_no 
                                               AND t_last.to_date != \'9999-01-01\' 
                                             ORDER BY t_last.to_date DESC 
                                             LIMIT 1)', [$this->jobTitleFilter]);
                    });
            });
        }


        if ($this->salaryMin) {
            $query->where('s_current.salary', '>=', $this->salaryMin);
        }

        if ($this->salaryMax) {
            $query->where('s_current.salary', '<=', $this->salaryMax);
        }

        // Sorting
        if ($this->sortBy === 'name') {
            $query->orderBy('e.first_name', $this->sortDirection)
                ->orderBy('e.last_name', $this->sortDirection);
        } elseif ($this->sortBy === 'department') {
            // COALESCE is a SQL function that returns the first non-null argument
            $query->orderByRaw(DB::raw("COALESCE(d_current.dept_name, (SELECT d.dept_name FROM departments d JOIN dept_emp de_last ON d.dept_no = de_last.dept_no WHERE de_last.emp_no = e.emp_no AND de_last.to_date != '9999-01-01' ORDER BY de_last.to_date DESC LIMIT 1)) {$this->sortDirection}"));
        } elseif ($this->sortBy === 'title') {
            $query->orderByRaw(DB::raw("COALESCE(t_current.title, (SELECT t_last.title FROM titles t_last WHERE t_last.emp_no = e.emp_no AND t_last.to_date != '9999-01-01' ORDER BY t_last.to_date DESC LIMIT 1)) {$this->sortDirection}"));
        } elseif ($this->sortBy === 'salary') {
            $query->orderBy('s_current.salary', $this->sortDirection);
        } elseif ($this->sortBy === 'hire_date') {
            $query->orderBy('e.hire_date', $this->sortDirection);
        } elseif ($this->sortBy === 'gender') {
            $query->orderBy('e.gender', $this->sortDirection);
        } else {
            $query->orderBy('e.emp_no', $this->sortDirection);
        }

        return $query;
    }
}
