<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReadsInitialDepartment;
use App\Queries\EmployeeFilters;
use App\Queries\EmployeeSearchQuery;
use App\Support\Ability;
use App\Support\EmployeeVisibility;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class EmployeeList extends Component
{
    use ReadsInitialDepartment;
    use WithPagination;

    public const PER_PAGE = 20;

    /** Filter properties kept in the URL. */
    public const URL_FILTERS = ['search', 'employmentStatus', 'gender', 'department', 'jobTitleFilter', 'salaryMin', 'salaryMax'];

    /*
     * Filters, sort order and page are kept in the URL (?search=..&department=..&sort=..&page=2):
     * a filtered list can be shared as a link, and Back (e.g. from a profile) returns to it.
     * Each change adds a browser history entry; Back / Forward restore the previous values
     * (see updated()). Default values are left out of the URL.
     */
    #[Url(as: 'search', history: true, except: '')]
    public $search = '';

    #[Url(as: 'status', history: true, except: 'all')]
    public $employmentStatus = 'all';

    #[Url(as: 'gender', history: true, except: 'all')]
    public $gender = 'all';

    #[Url(as: 'department', history: true, except: 'all')]
    public $department = 'all';

    #[Url(as: 'salary_min', history: true, except: '')]
    public $salaryMin = '';

    #[Url(as: 'salary_max', history: true, except: '')]
    public $salaryMax = '';

    #[Url(as: 'title', history: true, except: 'all')]
    public $jobTitleFilter = 'all';

    #[Url(as: 'sort', history: true, except: 'emp_no')]
    public $sortBy = 'emp_no';

    #[Url(as: 'direction', history: true, except: 'asc')]
    public $sortDirection = 'asc';

    public $selectAll = true;

    public $selectedEmployees = [];

    public $excludedEmployees = [];

    /**
     * Number of employees matching the filters, kept between requests so that
     * sorting, paging and (de)selecting do not re-count. Recomputed whenever the
     * filters change ($totalKey is a fingerprint of the filters it belongs to).
     *
     * Known trade-off: while the page stays open the total is not refreshed, so
     * employees added or removed in the meantime are not reflected in the
     * "found" / "selected" counts (and the last page) until the filters change
     * or the page is reloaded. The export itself always re-runs the query, so
     * it is never affected - only the displayed numbers can be stale.
     */
    #[Locked]
    public ?int $totalEmployees = null;

    #[Locked]
    public string $totalKey = '';

    protected $listeners = ['filtersUpdated' => 'updateFilters'];

    public function mount()
    {
        // Values from the URL are user input: normalized like any other filter payload.
        $this->applyFilterValues($this->propertyFilters());
        $this->normalizeSort();

        $this->selectAll = true;
        $this->selectedEmployees = [];
        $this->excludedEmployees = [];
        $this->department = $this->initialDepartment();
    }

    /**
     * Apply the filters sent by the filter component. The payload comes from the
     * client, so anything that is not an array is ignored and every value is
     * normalized by EmployeeFilters (missing, invalid or non-scalar -> no filter).
     */
    public function updateFilters($filters)
    {
        if (! is_array($filters)) {
            return;
        }

        $this->applyFilterValues($filters);

        $this->resetPage();
        $this->resetSelection();
    }

    /**
     * A filter or the sort order was set directly from the client: the browser's Back /
     * Forward button restoring an earlier URL (the filter inputs live in EmployeeFilter,
     * which uses updateFilters()). The page belongs to the same URL, so it is kept; the
     * selection is reset as for any filter change and the filter inputs follow.
     */
    public function updated($property)
    {
        if (in_array($property, self::URL_FILTERS, true)) {
            $this->applyFilterValues($this->propertyFilters());
            $this->resetSelection();
            $this->dispatch('filtersRestored', $this->filterInputValues())->to(EmployeeFilter::class);
        } elseif (in_array($property, ['sortBy', 'sortDirection'], true)) {
            $this->normalizeSort();
        }
    }

    public function sortByField($field)
    {
        if (! in_array($field, EmployeeFilters::SORTABLE, true)) {
            return;
        }

        if ($field === 'salary' && ! $this->visibility()->canSeeSalaries()) {
            return;
        }

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
        $this->selectedEmployees = [];
        $this->excludedEmployees = [];
    }

    public function toggleEmployee($empNo)
    {
        $empNo = (int) $empNo;

        if ($this->selectAll) {
            $this->excludedEmployees = $this->toggleIn($this->excludedEmployees, $empNo);
        } else {
            $this->selectedEmployees = $this->toggleIn($this->selectedEmployees, $empNo);
        }
    }

    public function isEmployeeSelected($empNo)
    {
        if ($this->selectAll) {
            return ! in_array($empNo, $this->excludedEmployees);
        }

        return in_array($empNo, $this->selectedEmployees);
    }

    public function resetSelection()
    {
        $this->selectAll = true;
        $this->selectedEmployees = [];
        $this->excludedEmployees = [];
    }

    public function getSelectedCount()
    {
        if ($this->selectAll) {
            return max(0, $this->getTotalEmployees() - count($this->excludedEmployees));
        }

        return count($this->selectedEmployees);
    }

    public function getTotalEmployees()
    {
        $filters = $this->filters();
        $key = md5(serialize([
            $filters->search, $filters->employmentStatus, $filters->gender, $filters->department,
            $filters->jobTitle, $filters->salaryMin, $filters->salaryMax,
        ]));

        if ($this->totalEmployees === null || $this->totalKey !== $key) {
            $this->totalEmployees = $this->searchQuery()->count();
            $this->totalKey = $key;
        }

        return $this->totalEmployees;
    }

    /**
     * All selected employee numbers (in display order when "select all" is on).
     */
    public function getSelectedEmployeeIds()
    {
        if ($this->selectAll) {
            return $this->searchQuery()->exceptIds($this->excludedEmployees)->ids();
        }

        return array_values($this->selectedEmployees);
    }

    public function getSelectionInfo()
    {
        $selectedCount = $this->getSelectedCount();

        return [
            'selectAll' => $this->selectAll,
            'selectedEmployees' => $this->selectedEmployees,
            'excludedEmployees' => $this->excludedEmployees,
            'totalEmployees' => $this->getTotalEmployees(),
            'selectedCount' => $selectedCount,
            'hasSelection' => $selectedCount > 0,
        ];
    }

    /**
     * Everything the export form needs: the applied filters, the sort order and
     * the selection expressed as "all except" / "only these", plus the count.
     */
    public function getExportPayload()
    {
        $filters = $this->filters();

        return [
            'search' => $filters->search,
            'employmentStatus' => $filters->employmentStatus,
            'gender' => $filters->gender,
            'department' => $filters->department,
            'jobTitle' => $filters->jobTitle,
            // As entered, unless the bound is not applied (no view-salaries).
            'salaryMin' => $filters->salaryMin === null ? '' : $this->salaryMin,
            'salaryMax' => $filters->salaryMax === null ? '' : $this->salaryMax,
            'sortBy' => $filters->sortBy,
            'sortDirection' => $filters->sortDirection,
            'selectAll' => (bool) $this->selectAll,
            'selectedEmployees' => array_values($this->selectedEmployees),
            'excludedEmployees' => array_values($this->excludedEmployees),
            'selectedCount' => $this->getSelectedCount(),
        ];
    }

    public function render()
    {
        $canSeeSalaries = $this->visibility()->canSeeSalaries();
        $employees = $this->searchQuery()->paginate(self::PER_PAGE, $this->getPage(), $this->getTotalEmployees());

        $employees->through(function ($emp) use ($canSeeSalaries) {
            if (! $canSeeSalaries) {
                $emp->current_salary = null;
            }

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
                'currentDepartment' => $emp->current_dept_name ? collect([(object) ['dept_name' => $emp->current_dept_name]]) : collect(),
                'currentTitle' => $emp->current_title ? (object) ['title' => $emp->current_title] : null,
                'currentSalary' => $emp->current_salary ? (object) ['salary' => $emp->current_salary] : null,
                'isSelected' => $this->isEmployeeSelected($emp->emp_no),
            ];
        });

        return view('livewire.employee-list', [
            'employees' => $employees,
            'selectedCount' => $this->getSelectedCount(),
            'canSeeSalaries' => $canSeeSalaries,
            'canExport' => Gate::allows(Ability::EXPORT_DATA),
        ]);
    }

    /**
     * The applied filters: the component's properties with the user's
     * restrictions (manager department, salary rules) enforced.
     */
    public function filters(): EmployeeFilters
    {
        return $this->visibility()->filters([
            'search' => $this->search,
            'employmentStatus' => $this->employmentStatus,
            'gender' => $this->gender,
            'department' => $this->department,
            'jobTitle' => $this->jobTitleFilter,
            'salaryMin' => $this->salaryMin,
            'salaryMax' => $this->salaryMax,
            'sortBy' => $this->sortBy,
            'sortDirection' => $this->sortDirection,
        ]);
    }

    /**
     * Set the filter properties from loosely typed input, normalized by EmployeeFilters.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilterValues(array $filters): void
    {
        $sanitized = EmployeeFilters::fromArray($filters);

        $this->search = $sanitized->search;
        $this->employmentStatus = $sanitized->employmentStatus;
        $this->gender = $sanitized->gender;
        $this->department = $sanitized->department;
        $this->salaryMin = $sanitized->salaryMin === null ? '' : (string) $sanitized->salaryMin;
        $this->salaryMax = $sanitized->salaryMax === null ? '' : (string) $sanitized->salaryMax;
        $this->jobTitleFilter = $sanitized->jobTitle;
    }

    private function normalizeSort(): void
    {
        $sanitized = EmployeeFilters::fromArray(['sortBy' => $this->sortBy, 'sortDirection' => $this->sortDirection]);

        $this->sortBy = $sanitized->sortBy;
        $this->sortDirection = $sanitized->sortDirection;
    }

    /**
     * @return array<string, mixed>
     */
    private function propertyFilters(): array
    {
        return [
            'search' => $this->search,
            'employmentStatus' => $this->employmentStatus,
            'gender' => $this->gender,
            'department' => $this->department,
            'jobTitle' => $this->jobTitleFilter,
            'salaryMin' => $this->salaryMin,
            'salaryMax' => $this->salaryMax,
        ];
    }

    /**
     * The filter properties in the shape of EmployeeFilter's inputs.
     *
     * @return array<string, string>
     */
    public function filterInputValues(): array
    {
        return [
            'search' => (string) $this->search,
            'employmentStatus' => (string) $this->employmentStatus,
            'gender' => (string) $this->gender,
            'department' => (string) $this->department,
            'jobTitle' => (string) $this->jobTitleFilter,
            'salaryMin' => (string) $this->salaryMin,
            'salaryMax' => (string) $this->salaryMax,
        ];
    }

    private function searchQuery(): EmployeeSearchQuery
    {
        return EmployeeSearchQuery::make($this->filters());
    }

    private function visibility(): EmployeeVisibility
    {
        return EmployeeVisibility::current();
    }

    private function toggleIn(array $list, int $empNo): array
    {
        if (in_array($empNo, $list)) {
            return array_values(array_diff($list, [$empNo]));
        }

        $list[] = $empNo;

        return $list;
    }
}
