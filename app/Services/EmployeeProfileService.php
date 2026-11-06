<?php

namespace App\Services;

use App\Models\Employee;
use App\Support\HistoryRows;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds everything the employee profile page and its PDF export need:
 * the employee with current department/title/salary, the full histories,
 * a combined salary/title/department timeline, summary stats and chart data.
 */
class EmployeeProfileService
{
    public const CURRENT_TO_DATE = '9999-01-01';

    /**
     * Without $withSalaries (no `view-salaries` ability) no salary row is read
     * at all: the salary history, chart, earnings and current salary are empty,
     * and the combined history is built from the title periods instead.
     *
     * @return array{
     *     employee: Employee,
     *     departmentHistory: Collection,
     *     titleHistory: Collection,
     *     salaryHistory: Collection,
     *     combinedHistory: Collection,
     *     stats: array<string, mixed>,
     *     salaryChartData: Collection,
     *     canSeeSalaries: bool
     * }
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function build(Employee|int|string $employee, bool $withSalaries = true): array
    {
        $relations = $withSalaries
            ? ['currentDepartment', 'currentTitle', 'currentSalary']
            : ['currentDepartment', 'currentTitle'];

        $employee = $employee instanceof Employee
            ? $employee->loadMissing($relations)
            : Employee::with($relations)->findOrFail($employee);

        if (! $withSalaries) {
            $employee->setRelation('currentSalary', null);
        }

        $departmentHistory = HistoryRows::active(DB::table('dept_emp'), 'dept_emp')
            ->join('departments', 'dept_emp.dept_no', '=', 'departments.dept_no')
            ->where('dept_emp.emp_no', $employee->emp_no)
            ->select('departments.dept_name', 'departments.dept_no', 'dept_emp.from_date', 'dept_emp.to_date')
            ->orderBy('dept_emp.from_date')
            ->get();

        $titleHistory = HistoryRows::active(DB::table('titles'))
            ->where('emp_no', $employee->emp_no)
            ->select('title', 'from_date', 'to_date')
            ->orderBy('from_date')
            ->get();

        $salaryHistory = $withSalaries
            ? HistoryRows::active(DB::table('salaries'))
                ->where('emp_no', $employee->emp_no)
                ->select('salary', 'from_date', 'to_date')
                ->orderBy('from_date')
                ->get()
            : collect();

        $stats = $this->stats($employee, $departmentHistory, $titleHistory, $salaryHistory);

        if (! $withSalaries) {
            $stats['totalEarnings'] = null;
            $stats['avgSalary'] = null;
            $stats['salaryChanges'] = null;
        }

        return [
            'employee' => $employee,
            'departmentHistory' => $departmentHistory,
            'titleHistory' => $titleHistory,
            'salaryHistory' => $salaryHistory,
            'combinedHistory' => $withSalaries
                ? $this->combinedHistory($departmentHistory, $titleHistory, $salaryHistory)
                : $this->combinedHistory($departmentHistory, $titleHistory, null),
            'stats' => $stats,
            'salaryChartData' => $withSalaries ? $this->salaryChartData($salaryHistory) : collect(),
            'canSeeSalaries' => $withSalaries,
        ];
    }

    /**
     * One row per salary period, enriched with the department and title that
     * overlapped it. Newest first.
     *
     * Without a salary history (salaries hidden) there is one row per title
     * period instead, with `salary` = null.
     */
    public function combinedHistory(Collection $departmentHistory, Collection $titleHistory, ?Collection $salaryHistory): Collection
    {
        $combined = ($salaryHistory ?? $titleHistory)->map(function ($period) use ($departmentHistory, $titleHistory, $salaryHistory) {
            $from = Carbon::parse($period->from_date);
            $to = $this->endDate($period->to_date);

            $department = $departmentHistory->first(fn ($dept) => $this->overlaps(
                $from, $to, Carbon::parse($dept->from_date), $this->endDate($dept->to_date)
            ));

            $title = $salaryHistory === null ? $period : $titleHistory->first(fn ($item) => $this->overlaps(
                $from, $to, Carbon::parse($item->from_date), $this->endDate($item->to_date)
            ));

            return (object) [
                'from_date' => $period->from_date,
                'to_date' => $period->to_date,
                'salary' => $salaryHistory === null ? null : $period->salary,
                'department_name' => $department ? $department->dept_name : 'Unknown',
                'title' => $title ? $title->title : 'Unknown',
                'is_current' => $period->to_date === self::CURRENT_TO_DATE,
            ];
        });

        return $combined->sortByDesc('from_date');
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(Employee $employee, Collection $departmentHistory, Collection $titleHistory, Collection $salaryHistory): array
    {
        $isCurrentEmployee = $employee->currentDepartment->count() > 0;
        $employmentEndDate = null;

        if (! $isCurrentEmployee && $departmentHistory->count() > 0) {
            $lastDepartmentRecord = $departmentHistory
                ->filter(fn ($dept) => $dept->to_date !== self::CURRENT_TO_DATE)
                ->sortByDesc('to_date')
                ->first();

            if ($lastDepartmentRecord) {
                $employmentEndDate = Carbon::parse($lastDepartmentRecord->to_date);
            }
        }

        $hireDate = Carbon::parse($employee->hire_date);
        $endDate = $isCurrentEmployee ? Carbon::now() : ($employmentEndDate ?? Carbon::now());

        if ($hireDate->gt($endDate)) {
            $yearsEmployed = 0;
            $remainingDays = 0;
            $totalDays = 0;
        } else {
            $yearsEmployed = (int) $hireDate->diffInYears($endDate);
            $remainingDays = (int) $hireDate->copy()->addYears($yearsEmployed)->diffInDays($endDate);
            $totalDays = (int) $hireDate->diffInDays($endDate);
        }

        return [
            'totalEarnings' => $salaryHistory->sum('salary'),
            'avgSalary' => $salaryHistory->avg('salary') ?? 0,
            'yearsEmployed' => $yearsEmployed,
            'remainingDays' => $remainingDays,
            'totalDays' => $totalDays,
            'departmentChanges' => $departmentHistory->count(),
            'titleChanges' => $titleHistory->count(),
            'salaryChanges' => $salaryHistory->count(),
            'isCurrentEmployee' => $isCurrentEmployee,
            'employmentEndDate' => $employmentEndDate,
        ];
    }

    /**
     * Average salary per calendar year (by period start), oldest first.
     */
    public function salaryChartData(Collection $salaryHistory): Collection
    {
        return $salaryHistory
            ->groupBy(fn ($salary) => Carbon::parse($salary->from_date)->year)
            ->map(fn ($yearSalaries, $year) => [
                'year' => (int) $year,
                'salary' => round($yearSalaries->avg('salary')),
                'count' => $yearSalaries->count(),
            ])
            ->sortBy('year')
            ->values();
    }

    private function endDate(?string $toDate): ?Carbon
    {
        return $toDate === self::CURRENT_TO_DATE ? null : Carbon::parse($toDate);
    }

    /**
     * Does the period [$from, $to] overlap [$otherFrom, $otherTo]? A null end means "still ongoing".
     */
    private function overlaps(Carbon $from, ?Carbon $to, Carbon $otherFrom, ?Carbon $otherTo): bool
    {
        if ($to === null && $otherTo === null) {
            return $from >= $otherFrom;
        }

        if ($to === null) {
            return $from <= $otherTo;
        }

        if ($otherTo === null) {
            return $to >= $otherFrom;
        }

        return $from <= $otherTo && $to >= $otherFrom;
    }
}
