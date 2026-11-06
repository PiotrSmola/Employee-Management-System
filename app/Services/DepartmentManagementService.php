<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
use App\Support\EmployeeDataCache;
use App\Support\HistoryRows;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating, renaming and deleting departments and changing their managers
 * (dept_manager). Callers check `manage-departments`; every change is written
 * to the audit log in the same transaction. Cached figures are cleared only
 * where they change: managers and department names are read live.
 *
 * dept_manager keeps one row per management period (an employee can manage a
 * department again); the current manager has to_date = 9999-01-01. A change of
 * manager on date D ends the current period on D (to_date = D) and starts the
 * new one on D, like the periods in the imported dataset.
 *
 * A period ends in one place, {@see endPeriods()}, whether a department page or
 * an HR operation (transfer, termination) ends it: same update, same audit entry.
 */
class DepartmentManagementService
{
    public const CURRENT = '9999-01-01';

    /** Error bags of the two manager forms on the department page. */
    public const ASSIGN_BAG = 'assignManager';

    public const END_BAG = 'endManagement';

    public function create(string $deptNo, string $name): Department
    {
        $department = DB::transaction(function () use ($deptNo, $name) {
            $department = Department::create(['dept_no' => $deptNo, 'dept_name' => $name]);

            AuditLogger::log('department.created', ActivityLog::SUBJECT_DEPARTMENT, $department->dept_no, [
                'after' => AuditLogger::values($department->only(['dept_no', 'dept_name'])),
            ]);

            return $department;
        });

        EmployeeDataCache::flush([$department->dept_no]);

        return $department;
    }

    public function rename(Department $department, string $name): void
    {
        DB::transaction(function () use ($department, $name) {
            $before = $department->only(['dept_name']);
            $department->update(['dept_name' => $name]);

            $changes = AuditLogger::diff($before, $department->only(['dept_name']));
            if ($changes !== []) {
                AuditLogger::log('department.renamed', ActivityLog::SUBJECT_DEPARTMENT, $department->dept_no, $changes);
            }
        });

        EmployeeDataCache::flushDepartmentNames();
    }

    /**
     * Why $department cannot be deleted, or null. Only a department that never
     * had employees or managers (and that no manager account is assigned to)
     * can be deleted - deleting would otherwise cascade into the history.
     */
    public function deletionBlockedReason(Department $department): ?string
    {
        return match (true) {
            HistoryRows::active(DB::table('dept_emp'))->where('dept_no', $department->dept_no)->exists() => __('The department has (or had) employees.'),
            HistoryRows::active(DB::table('dept_manager'))->where('dept_no', $department->dept_no)->exists() => __('The department has (or had) managers.'),
            User::query()->where('dept_no', $department->dept_no)->exists() => __('Manager accounts are assigned to this department.'),
            default => null,
        };
    }

    /**
     * @throws ValidationException when the department is in use
     */
    public function delete(Department $department): void
    {
        DB::transaction(function () use ($department) {
            Department::query()->whereKey($department->dept_no)->lockForUpdate()->first();

            if (($reason = $this->deletionBlockedReason($department)) !== null) {
                throw ValidationException::withMessages(['department' => $reason]);
            }

            AuditLogger::log('department.deleted', ActivityLog::SUBJECT_DEPARTMENT, $department->dept_no, [
                'before' => AuditLogger::values($department->only(['dept_no', 'dept_name'])),
            ]);

            $department->delete();
        });

        EmployeeDataCache::flush([$department->dept_no]);
    }

    /**
     * Make $empNo the manager of $department from $fromDate (Y-m-d) on, ending
     * the current manager's period on that date.
     *
     * Rules: the employee is currently in the department and was already in it
     * on $fromDate, is not the current manager, and $fromDate is after the
     * current manager's start date and not before the end of the department's
     * last ended period. A former manager can manage the department again: a new
     * period, the earlier one stays.
     *
     * @return array{emp_no: int, from_date: string, previous_emp_no: ?int}
     *
     * @throws ValidationException (error bag ASSIGN_BAG)
     */
    public function assignManager(Department $department, int $empNo, string $fromDate): array
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message])
            ->errorBag(self::ASSIGN_BAG);

        return DB::transaction(function () use ($department, $empNo, $fromDate, $fail) {
            // Serializes manager changes of this department.
            Department::query()->whereKey($department->dept_no)->lockForUpdate()->first();

            $membership = HistoryRows::active(DB::table('dept_emp'))
                ->where('emp_no', $empNo)
                ->where('dept_no', $department->dept_no)
                ->where('to_date', self::CURRENT)
                ->first();

            if ($membership === null) {
                $fail('emp_no', __('The employee is not a current employee of this department.'));
            }

            $current = $this->currentManagerRow($department->dept_no);

            if ($current !== null && (int) $current->emp_no === $empNo) {
                $fail('emp_no', __('The employee already is the current manager.'));
            }

            if ($fromDate < $membership->from_date) {
                $fail('from_date', __('The date cannot be before the employee joined the department (:date).', ['date' => $membership->from_date]));
            }

            if ($current !== null && $fromDate <= $current->from_date) {
                $fail('from_date', __('The date must be after the start of the current manager\'s period (:date).', ['date' => $current->from_date]));
            }

            // Periods must not overlap: a new one starts at the earliest when the last ended one ended.
            $lastEnd = HistoryRows::active(DB::table('dept_manager'))
                ->where('dept_no', $department->dept_no)
                ->where('to_date', '<>', self::CURRENT)
                ->max('to_date');
            if ($lastEnd !== null && $fromDate < $lastEnd) {
                $fail('from_date', __('The date cannot be before the end of the previous manager\'s period (:date).', ['date' => $lastEnd]));
            }

            foreach ($this->currentPeriods($department->dept_no) as $period) {
                $this->closePeriod($period, $fromDate);
            }

            DB::table('dept_manager')->insert([
                'emp_no' => $empNo,
                'dept_no' => $department->dept_no,
                'from_date' => $fromDate,
                'to_date' => self::CURRENT,
            ]);

            $result = [
                'emp_no' => $empNo,
                'from_date' => $fromDate,
                'previous_emp_no' => $current !== null ? (int) $current->emp_no : null,
            ];

            AuditLogger::log('department.manager_assigned', ActivityLog::SUBJECT_DEPARTMENT, $department->dept_no, $result);

            return $result;
        });
    }

    /**
     * End the current manager's period on $toDate without a successor.
     *
     * @return array{emp_no: int, from_date: string, to_date: string}
     *
     * @throws ValidationException (error bag END_BAG)
     */
    public function endManagement(Department $department, string $toDate): array
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message])
            ->errorBag(self::END_BAG);

        return DB::transaction(function () use ($department, $toDate, $fail) {
            Department::query()->whereKey($department->dept_no)->lockForUpdate()->first();

            $current = $this->currentManagerRow($department->dept_no);

            if ($current === null) {
                $fail('to_date', __('The department has no current manager.'));
            }

            if ($toDate <= $current->from_date) {
                $fail('to_date', __('The date must be after the start of the current manager\'s period (:date).', ['date' => $current->from_date]));
            }

            $this->endPeriods($this->currentPeriods($department->dept_no), $toDate);

            return ['emp_no' => (int) $current->emp_no, 'from_date' => $current->from_date, 'to_date' => $toDate];
        });
    }

    /**
     * End management periods on $date: the one code path and audit entry
     * (`department.manager_ended`) for everything that ends a management. Call it in
     * the transaction of the change; the caller has checked the date.
     *
     * @param  Collection<int, object>  $periods  current dept_manager rows, locked
     * @param  string|null  $cause  the action that ended them, when it was not this screen
     */
    public function endPeriods(Collection $periods, string $date, ?string $cause = null): void
    {
        foreach ($periods as $period) {
            $this->closePeriod($period, $date);

            AuditLogger::log('department.manager_ended', ActivityLog::SUBJECT_DEPARTMENT, $period->dept_no, array_filter([
                'emp_no' => (int) $period->emp_no,
                'from_date' => $period->from_date,
                'to_date' => $date,
                'cause' => $cause,
            ]));
        }
    }

    /**
     * An undo reopened management periods ended by $cause: the counterpart of the
     * `department.manager_ended` entry in the department's history.
     *
     * @param  array<int, array{emp_no: int, dept_no: string, from_date: string}>  $periods
     */
    public function logReopenedPeriods(array $periods, string $cause): void
    {
        foreach ($periods as $period) {
            AuditLogger::log('department.manager_restored', ActivityLog::SUBJECT_DEPARTMENT, $period['dept_no'], [
                'emp_no' => $period['emp_no'],
                'from_date' => $period['from_date'],
                'cause' => $cause,
            ]);
        }
    }

    /**
     * The latest current dept_manager row of a department (the dataset has at most one).
     */
    private function currentManagerRow(string $deptNo): ?object
    {
        return HistoryRows::active(DB::table('dept_manager'))
            ->where('dept_no', $deptNo)
            ->where('to_date', self::CURRENT)
            ->orderByDesc('from_date')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Every current dept_manager row of a department, locked.
     *
     * @return Collection<int, object>
     */
    private function currentPeriods(string $deptNo): Collection
    {
        return HistoryRows::active(DB::table('dept_manager'))
            ->where('dept_no', $deptNo)
            ->where('to_date', self::CURRENT)
            ->lockForUpdate()
            ->get();
    }

    private function closePeriod(object $period, string $date): void
    {
        HistoryRows::active(DB::table('dept_manager'))
            ->where('emp_no', $period->emp_no)
            ->where('dept_no', $period->dept_no)
            ->where('from_date', $period->from_date)
            ->where('to_date', self::CURRENT)
            ->update(['to_date' => $date]);
    }
}
