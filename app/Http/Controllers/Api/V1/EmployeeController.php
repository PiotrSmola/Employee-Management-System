<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Queries\EmployeeFilters;
use App\Queries\EmployeeSearchQuery;
use App\Support\EmployeeVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/v1/employees, GET /api/v1/employees/{emp_no} (docs/API.md).
 *
 * The same search as the web directory (EmployeeSearchQuery through the token
 * owner's EmployeeVisibility): managers only get their department, salary data
 * only with `view-salaries` (otherwise the keys are missing, and salary filters /
 * sorting are rejected).
 */
class EmployeeController extends Controller
{
    private const CURRENT = '9999-01-01';

    /** Seconds a counted total is reused. */
    private const TOTAL_TTL = 60;

    /** Query parameter => EmployeeFilters::fromArray() name (the web directory's URL names). */
    private const FILTERS = [
        'search' => 'search',
        'status' => 'employmentStatus',
        'gender' => 'gender',
        'department' => 'department',
        'title' => 'jobTitle',
        'salary_min' => 'salaryMin',
        'salary_max' => 'salaryMax',
        'sort' => 'sortBy',
        'direction' => 'sortDirection',
    ];

    public function index(Request $request): JsonResponse
    {
        $visibility = EmployeeVisibility::for($request->user());
        $maxPerPage = (int) config('data_tools.api.max_per_page', 100);

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(EmployeeFilters::STATUSES)],
            'gender' => ['sometimes', Rule::in(['M', 'F', 'all'])],
            'department' => ['sometimes', 'string', 'max:10'],
            'title' => ['sometimes', 'string', 'max:50'],
            'salary_min' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'salary_max' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sort' => ['sometimes', Rule::in(EmployeeFilters::SORTABLE)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
        ]);

        if (! $visibility->canSeeSalaries()) {
            $salaryParameters = array_filter([
                'salary_min' => isset($validated['salary_min']),
                'salary_max' => isset($validated['salary_max']),
                'sort' => ($validated['sort'] ?? null) === 'salary',
            ]);
            if ($salaryParameters !== []) {
                throw ValidationException::withMessages(array_map(
                    fn () => [__('Salary data is not available for your role.')],
                    $salaryParameters
                ));
            }
        }

        $filters = [];
        foreach (self::FILTERS as $parameter => $name) {
            if (isset($validated[$parameter])) {
                $filters[$name] = $validated[$parameter];
            }
        }

        $perPage = (int) ($validated['per_page'] ?? config('data_tools.api.default_per_page', 20));
        $page = (int) ($validated['page'] ?? 1);
        $query = $visibility->query($filters);
        $paginator = $query->paginate($perPage, $page, $this->total($query));
        $withSalaries = $visibility->canSeeSalaries();

        $data = $paginator->getCollection()->map(fn ($row) => $this->listItem($row, $withSalaries))->values();
        $lastPage = max(1, $paginator->lastPage());
        $link = fn (int $page) => $request->fullUrlWithQuery(['page' => $page]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $perPage,
                'total' => $paginator->total(),
                'last_page' => $lastPage,
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'filters' => $this->appliedFilters($query->filters()),
            ],
            'links' => [
                'first' => $link(1),
                'last' => $link($lastPage),
                'prev' => $page > 1 ? $link(min($page - 1, $lastPage)) : null,
                'next' => $page < $lastPage ? $link($page + 1) : null,
            ],
        ]);
    }

    public function show(Request $request, string $emp_no): JsonResponse
    {
        $employee = strlen($emp_no) <= 10 && (int) $emp_no <= EmployeeFilters::MAX_EMP_NO ? Employee::find((int) $emp_no) : null;

        if ($employee === null) {
            abort(404, __('Employee not found.'));
        }

        $this->authorize('view', $employee);

        $withSalaries = EmployeeVisibility::for($request->user())->canSeeSalaries();

        $departments = DB::table('dept_emp')
            ->join('departments', 'departments.dept_no', '=', 'dept_emp.dept_no')
            ->where('dept_emp.emp_no', $employee->emp_no)
            ->orderBy('dept_emp.from_date')
            ->get(['dept_emp.dept_no', 'departments.dept_name', 'dept_emp.from_date', 'dept_emp.to_date']);
        $titles = DB::table('titles')->where('emp_no', $employee->emp_no)->orderBy('from_date')->get(['title', 'from_date', 'to_date']);

        $current = fn ($rows) => $rows->first(fn ($row) => $row->to_date === self::CURRENT);
        $currentDepartment = $current($departments);
        $currentTitle = $current($titles);

        $data = [
            'emp_no' => (int) $employee->emp_no,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'birth_date' => $this->date($employee->birth_date),
            'gender' => $employee->gender,
            'hire_date' => $this->date($employee->hire_date),
            'status' => $currentDepartment ? 'current' : 'former',
            'department' => $currentDepartment ? ['dept_no' => $currentDepartment->dept_no, 'name' => $currentDepartment->dept_name] : null,
            'title' => $currentTitle?->title,
        ];

        if ($withSalaries) {
            $salaries = DB::table('salaries')->where('emp_no', $employee->emp_no)->orderBy('from_date')->get(['salary', 'from_date', 'to_date']);
            $data['salary'] = ($s = $current($salaries)) ? (int) $s->salary : null;
        }

        $data['history'] = [
            'departments' => $departments->map(fn ($row) => [
                'dept_no' => $row->dept_no, 'name' => $row->dept_name,
            ] + $this->period($row))->values(),
            'titles' => $titles->map(fn ($row) => ['title' => $row->title] + $this->period($row))->values(),
        ];

        if ($withSalaries) {
            $data['history']['salaries'] = $salaries->map(fn ($row) => ['salary' => (int) $row->salary] + $this->period($row))->values();
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Number of matches, kept for a minute: counting is the expensive part of a request with
     * combined filters, and a client paging through a result would repeat it for every page
     * (the web directory keeps its total the same way). The key holds the applied filters,
     * so a manager's department is part of it.
     */
    private function total(EmployeeSearchQuery $query): int
    {
        $f = $query->filters();
        $key = 'api-employees-total:'.md5(serialize([
            $f->search, $f->employmentStatus, $f->gender, $f->department, $f->jobTitle, $f->salaryMin, $f->salaryMax,
        ]));

        return Cache::remember($key, self::TOTAL_TTL, fn () => $query->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function listItem(object $row, bool $withSalaries): array
    {
        $item = [
            'emp_no' => (int) $row->emp_no,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'gender' => $row->gender,
            'hire_date' => $this->date($row->hire_date),
            'status' => $row->current_dept_name ? 'current' : 'former',
            // Former employees: their last department / title.
            'department' => $row->current_dept_name ?? $row->last_dept_name,
            'title' => $row->current_title ?? $row->last_job_title,
        ];

        if ($withSalaries) {
            $item['salary'] = $row->current_salary !== null ? (int) $row->current_salary : null;
        }

        $item['url'] = route('api.v1.employees.show', $row->emp_no);

        return $item;
    }

    /**
     * The filters actually applied (after the role restrictions), with the request names.
     *
     * @return array<string, mixed>
     */
    private function appliedFilters(EmployeeFilters $filters): array
    {
        return [
            'search' => $filters->search === '' ? null : $filters->search,
            'status' => $filters->employmentStatus,
            'gender' => $filters->gender === 'all' ? null : $filters->gender,
            'department' => $filters->department === 'all' ? null : $filters->department,
            'title' => $filters->jobTitle === 'all' ? null : $filters->jobTitle,
            'sort' => $filters->sortBy,
            'direction' => $filters->sortDirection,
        ] + ($filters->filtersBySalary() ? ['salary_min' => $filters->salaryMin, 'salary_max' => $filters->salaryMax] : []);
    }

    /**
     * from / to of a history row; to = null means "current" (9999-01-01 in the database).
     *
     * @return array{from: string|null, to: string|null}
     */
    private function period(object $row): array
    {
        return [
            'from' => $this->date($row->from_date),
            'to' => $row->to_date === null || $row->to_date === self::CURRENT ? null : $this->date($row->to_date),
        ];
    }

    private function date(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
