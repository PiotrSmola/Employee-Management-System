<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Support\EmployeeDataCache;
use App\Support\EmploymentRules;
use App\Support\HistoryRows;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * HR operations on employees: hire, edit personal data, transfer, title and
 * salary changes, termination, rehire, corrections of the latest salary /
 * title row and undoing the last change (docs/HR_OPERATIONS.md).
 *
 * History is never deleted: a change closes the current row (to_date = the
 * effective date) and opens a new one (from_date = the effective date,
 * to_date = 9999-01-01), the convention of the dataset. Only active rows count
 * here; rows voided by an undo ({@see HistoryRows}) play no part in any rule.
 *
 * Every operation
 *  - runs in one transaction and first locks the employee row (SELECT ... FOR
 *    UPDATE), so two operations on the same employee are serialized and the
 *    date checks below see the committed state;
 *  - checks the business rules that depend on the stored history and throws a
 *    ValidationException (field => message) when one is broken;
 *  - writes an `employee.*` audit entry in the same transaction; the entry keeps
 *    the row changes under `_undo` (see undoLastChange());
 *  - clears the cached dashboard / filter figures of the affected departments
 *    after the commit (EmployeeDataCache::flush()).
 *
 * Effective dates must lie after the start of the row they close and may not be
 * in the future: "current" is to_date = 9999-01-01 everywhere in the app, so a
 * scheduled change would be shown as already in effect.
 */
class EmployeeOperationsService
{
    public const CURRENT = EmploymentRules::CURRENT;

    /** How long the last change of an employee can be undone. */
    public const UNDO_HOURS = 24;

    /** Personal data fields (employees table). */
    public const PERSONAL_FIELDS = ['first_name', 'last_name', 'gender', 'birth_date', 'hire_date'];

    /** Kind of a title change => audit action. */
    public const TITLE_CHANGE_ACTIONS = [
        'promotion' => 'employee.promoted',
        'demotion' => 'employee.demoted',
        'change' => 'employee.title_changed',
    ];

    /** Actions undoLastChange() can reverse. A hire is not one of them: nothing deletes an employee. */
    public const UNDOABLE_ACTIONS = [
        'employee.updated', 'employee.transferred', 'employee.promoted', 'employee.demoted',
        'employee.title_changed', 'employee.salary_changed', 'employee.terminated',
        'employee.rehired', 'employee.salary_corrected', 'employee.title_corrected',
    ];

    /** Columns an undo may restore, per table. */
    private const RESTORABLE_COLUMNS = [
        'employees' => self::PERSONAL_FIELDS,
        'salaries' => ['salary'],
        'titles' => ['title'],
    ];

    /** Primary key columns of the history tables. */
    private const KEYS = [
        'dept_emp' => ['emp_no', 'dept_no', 'from_date'],
        'dept_manager' => ['emp_no', 'dept_no', 'from_date'],
        'titles' => ['emp_no', 'title', 'from_date'],
        'salaries' => ['emp_no', 'from_date'],
    ];

    /** Row changes of the running operation (written to the audit entry as `_undo`). */
    private array $ops = [];

    public function __construct(
        private readonly EmployeeNumberAllocator $numbers,
        private readonly DepartmentManagementService $departments,
    ) {}

    /**
     * Hire a new employee: employees + dept_emp + titles + salaries rows starting on the hire date.
     *
     * An employee with the same name and birth date already existing is reported
     * (field `confirm_duplicate`) unless $allowDuplicate.
     *
     * @param  array{first_name: string, last_name: string, gender: string, birth_date: string, hire_date: string, dept_no: string, title: string, salary: int|string}  $data
     *
     * @throws ValidationException
     */
    public function hire(array $data, bool $allowDuplicate = false): Employee
    {
        $this->assertNotInFuture('hire_date', $data['hire_date']);
        $this->assertPlausibleAge($data['birth_date'], $data['hire_date']);
        $salary = $this->assertSalary('salary', (int) $data['salary']);
        $title = trim($data['title']);
        $first = trim($data['first_name']);
        $last = trim($data['last_name']);

        $employee = $this->numbers->transaction(function () use ($data, $title, $salary, $first, $last, $allowDuplicate) {
            if (! $allowDuplicate) {
                $this->assertNoDuplicate($first, $last, $data['birth_date']);
            }

            $employee = Employee::create([
                'emp_no' => $this->numbers->next(),
                'first_name' => $first,
                'last_name' => $last,
                'gender' => $data['gender'],
                'birth_date' => $data['birth_date'],
                'hire_date' => $data['hire_date'],
            ]);

            $this->openEmployment($employee->emp_no, $data['dept_no'], $title, $salary, $data['hire_date']);
            $this->ops = [];

            // No undo data: a hire is corrected by editing the personal data or terminating.
            AuditLogger::log('employee.hired', ActivityLog::SUBJECT_EMPLOYEE, $employee->emp_no, [
                'after' => AuditLogger::values([
                    ...$employee->only(self::PERSONAL_FIELDS),
                    'dept_no' => $data['dept_no'],
                    'title' => $title,
                    'salary' => $salary,
                ]),
            ]);

            return $employee;
        });

        $this->flushCache([$data['dept_no']]);

        return $employee;
    }

    /**
     * Existing employees with this name and birth date (the hire form's duplicate warning).
     *
     * @return Collection<int, Employee>
     */
    public function duplicates(string $firstName, string $lastName, string $birthDate): Collection
    {
        return Employee::query()
            ->where('first_name', trim($firstName))
            ->where('last_name', trim($lastName))
            ->where('birth_date', $birthDate)
            ->orderBy('emp_no')
            ->limit(5)
            ->get(['emp_no', 'first_name', 'last_name', 'birth_date', 'hire_date']);
    }

    /**
     * Change name, gender, birth date and hire date.
     *
     * The hire date may not lie after the first history row and the age on the
     * hire date must be plausible - both only checked when the birth or hire date
     * is changed, so a name correction is never blocked by quirks of old data.
     * $snapshot (personalSnapshot() of the data the form was built from) rejects
     * an edit made on data someone else changed meanwhile.
     *
     * @param  array{first_name: string, last_name: string, gender: string, birth_date: string, hire_date: string}  $data
     * @return array{before?: array<string, mixed>, after?: array<string, mixed>} the logged diff ([] = nothing changed)
     *
     * @throws ValidationException
     */
    public function updatePersonalData(Employee $employee, array $data, ?string $snapshot = null): array
    {
        [$changes, $departments] = DB::transaction(function () use ($employee, $data, $snapshot) {
            $employee = $this->lock($employee);

            if ($snapshot !== null && ! hash_equals(self::personalSnapshot($employee), $snapshot)) {
                throw ValidationException::withMessages(['snapshot' => __(
                    'The personal data was changed by someone else in the meantime. Reload the page and enter your change again.',
                )]);
            }

            $hireChanged = $data['hire_date'] !== $employee->hire_date;
            $birthChanged = $data['birth_date'] !== $employee->birth_date;

            if ($hireChanged) {
                $this->assertNotInFuture('hire_date', $data['hire_date']);
                $firstHistoryDate = $this->firstHistoryDate($employee->emp_no);
                if ($firstHistoryDate !== null && $data['hire_date'] > $firstHistoryDate) {
                    throw ValidationException::withMessages(['hire_date' => __(
                        'The hire date cannot be after the start of the employment history (:date).',
                        ['date' => EmploymentRules::display($firstHistoryDate)],
                    )]);
                }
            }
            if ($hireChanged || $birthChanged) {
                $this->assertPlausibleAge($data['birth_date'], $data['hire_date']);
            }

            $before = $employee->only(self::PERSONAL_FIELDS);
            $employee->fill([
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'gender' => $data['gender'],
                'birth_date' => $data['birth_date'],
                'hire_date' => $data['hire_date'],
            ])->save();

            $changes = AuditLogger::diff($before, $employee->only(self::PERSONAL_FIELDS));
            if ($changes !== []) {
                $this->ops = [['set', 'employees', ['emp_no' => $employee->emp_no], $changes['before'], $changes['after']]];
                $this->log('employee.updated', $employee->emp_no, $changes);
            }

            return [$changes, $this->currentDepartments($employee->emp_no)];
        });

        if ($changes !== []) {
            $this->flushCache($departments);
        }

        return $changes;
    }

    /**
     * Fingerprint of the personal data, sent with the edit form (optimistic locking).
     */
    public static function personalSnapshot(Employee $employee): string
    {
        return sha1(json_encode(array_map('strval', $employee->only(['emp_no', ...self::PERSONAL_FIELDS]))));
    }

    /**
     * Move a current employee to $deptNo from $date on. A current management of
     * the department being left ends on the same date. A department the employee
     * belonged to before can be chosen again (a new dept_emp period is added;
     * primary key emp_no + dept_no + from_date).
     *
     * @return array{from: string[], to: string, ended_management: string[], returning: bool}
     *
     * @throws ValidationException
     */
    public function transfer(Employee $employee, string $deptNo, string $date): array
    {
        $result = DB::transaction(function () use ($employee, $deptNo, $date) {
            $employee = $this->lock($employee);
            $empNo = $employee->emp_no;

            $current = $this->currentRows('dept_emp', $empNo);
            if ($current->isEmpty()) {
                throw ValidationException::withMessages(['dept_no' => $this->notEmployedMessage($employee)]);
            }

            $from = $current->pluck('dept_no')->all();
            if (in_array($deptNo, $from, true)) {
                throw ValidationException::withMessages(['dept_no' => __(':name already works in this department.', ['name' => $this->name($employee)])]);
            }

            $managed = $this->currentRows('dept_manager', $empNo)->whereIn('dept_no', $from);
            $this->assertEffectiveDate('effective_date', $date, $this->transferMinimum($current, $managed));
            $this->assertPeriodIsFree('effective_date', $empNo, $deptNo, $date);
            $returning = $this->rows('dept_emp')->where('emp_no', $empNo)->where('dept_no', $deptNo)->exists();

            $this->ops = [];
            $this->closeRows('dept_emp', $current, $date);
            $this->endManagement($managed, $date, 'employee.transferred');
            $this->insert('dept_emp', ['emp_no' => $empNo, 'dept_no' => $deptNo, 'from_date' => $date, 'to_date' => self::CURRENT]);

            $endedManagement = $managed->pluck('dept_no')->values()->all();
            $this->log('employee.transferred', $empNo, array_filter([
                'before' => ['dept_no' => implode(', ', $from)],
                'after' => ['dept_no' => $deptNo],
                'effective_date' => $date,
                'returning' => $returning ?: null,
                'ended_management' => $endedManagement,
            ]));

            return ['from' => $from, 'to' => $deptNo, 'ended_management' => $endedManagement, 'returning' => $returning];
        });

        $this->flushCache(array_values(array_unique([...$result['from'], $deptNo])));

        return $result;
    }

    /**
     * New job title from $date on. $kind (promotion / demotion / change) only
     * selects the audit action (employee.promoted / .demoted / .title_changed).
     * A current employee without a current title row simply gets one.
     *
     * @throws ValidationException
     */
    public function changeTitle(Employee $employee, string $title, string $date, string $kind = 'change'): void
    {
        $title = trim($title);
        $action = self::TITLE_CHANGE_ACTIONS[$kind] ?? self::TITLE_CHANGE_ACTIONS['change'];

        $departments = DB::transaction(function () use ($employee, $title, $date, $action) {
            $employee = $this->lock($employee);
            $empNo = $employee->emp_no;
            $departments = $this->requireCurrentEmployment($employee, 'title');

            $currentRows = $this->currentRows('titles', $empNo);
            $current = $currentRows->sortByDesc('from_date')->first();
            if ($current !== null && mb_strtolower($current->title) === mb_strtolower($title)) {
                throw ValidationException::withMessages(['title' => __('This is already the current job title.')]);
            }

            $this->assertEffectiveDate('effective_date', $date, $this->nextFrom('titles', $empNo) ?? $employee->hire_date,
                $current === null ? __('the latest job title record') : __('the start of the current job title'));

            $this->ops = [];
            $this->closeRows('titles', $currentRows, $date);
            $this->insert('titles', ['emp_no' => $empNo, 'title' => $title, 'from_date' => $date, 'to_date' => self::CURRENT]);

            $this->log($action, $empNo, [
                'before' => ['title' => $current?->title],
                'after' => ['title' => $title],
                'effective_date' => $date,
            ]);

            return $departments;
        });

        $this->flushCache($departments);
    }

    /**
     * New salary from $date on: an amount, or the current salary changed by $percent.
     *
     * A change outside -50 % to +100 % of the current salary needs $confirmUnusual
     * (field `confirm_unusual`). A current employee without a current salary row
     * gets one (amount only). $shownSalary is the salary a percentage was entered for:
     * if it is no longer the current one the change is refused (field `stale`).
     *
     * @return array{before: ?int, after: int}
     *
     * @throws ValidationException
     */
    public function changeSalary(Employee $employee, ?int $amount, ?float $percent, string $date, bool $confirmUnusual = false, ?int $shownSalary = null): array
    {
        if (($amount === null) === ($percent === null)) {
            throw ValidationException::withMessages(['salary' => __('Enter either a new amount or a percentage.')]);
        }

        [$result, $departments] = DB::transaction(function () use ($employee, $amount, $percent, $date, $confirmUnusual, $shownSalary) {
            $employee = $this->lock($employee);
            $empNo = $employee->emp_no;
            $departments = $this->requireCurrentEmployment($employee, 'salary');

            $currentRows = $this->currentRows('salaries', $empNo);
            $current = $currentRows->sortByDesc('from_date')->first();
            if ($current === null && $amount === null) {
                throw ValidationException::withMessages(['percent' => __(':name has no current salary - enter the new amount.', ['name' => $this->name($employee)])]);
            }

            $before = $current === null ? null : (int) $current->salary;
            if ($percent !== null && $shownSalary !== $before) {
                $this->staleForm();
            }

            $field = $amount === null ? 'percent' : 'salary';
            $after = $this->assertSalary($field, $amount ?? EmploymentRules::applyPercent($before, $percent));
            if ($after === $before) {
                throw ValidationException::withMessages([$field => __('The new salary is the same as the current one.')]);
            }
            if ($before !== null && ! $confirmUnusual) {
                $this->assertUsualChange($before, $after);
            }

            $this->assertEffectiveDate('effective_date', $date, $this->nextFrom('salaries', $empNo) ?? $employee->hire_date,
                $current === null ? __('the latest salary record') : __('the start of the current salary'));

            $this->ops = [];
            $this->closeRows('salaries', $currentRows, $date);
            $this->insert('salaries', ['emp_no' => $empNo, 'salary' => $after, 'from_date' => $date, 'to_date' => self::CURRENT]);

            $this->log('employee.salary_changed', $empNo, array_filter([
                'before' => ['salary' => $before],
                'after' => ['salary' => $after],
                'effective_date' => $date,
                'change_percent' => $before === null ? null : EmploymentRules::percentChange($before, $after),
                'requested_percent' => $percent,
                'confirmed_unusual' => $confirmUnusual && $before !== null && EmploymentRules::isUnusualChange($before, $after) ? true : null,
            ], fn ($value) => $value !== null));

            return [['before' => $before, 'after' => $after], $departments];
        });

        $this->flushCache($departments);

        return $result;
    }

    /**
     * End the employment on $date: every current dept_emp, titles, salaries and
     * dept_manager row gets to_date = $date. The optional $reason is kept in the audit log.
     *
     * @return array{departments: string[], ended_management: string[]}
     *
     * @throws ValidationException
     */
    public function terminate(Employee $employee, string $date, ?string $reason = null): array
    {
        $result = DB::transaction(function () use ($employee, $date, $reason) {
            $employee = $this->lock($employee);
            $empNo = $employee->emp_no;

            $departments = $this->currentRows('dept_emp', $empNo);
            if ($departments->isEmpty()) {
                throw ValidationException::withMessages(['end_date' => $this->notEmployedMessage($employee)]);
            }
            $titles = $this->currentRows('titles', $empNo);
            $salaries = $this->currentRows('salaries', $empNo);
            $managed = $this->currentRows('dept_manager', $empNo);

            $this->assertEffectiveDate('end_date', $date, $this->terminationMinimum($departments, $titles, $salaries, $managed));

            $this->ops = [];
            $this->closeRows('dept_emp', $departments, $date);
            $this->closeRows('titles', $titles, $date);
            $this->closeRows('salaries', $salaries, $date);
            $this->endManagement($managed, $date, 'employee.terminated');

            $reason = $reason === null ? null : trim($reason);
            $result = [
                'departments' => $departments->pluck('dept_no')->all(),
                'ended_management' => $managed->pluck('dept_no')->values()->all(),
            ];

            $this->log('employee.terminated', $empNo, array_filter([
                'before' => array_filter([
                    'dept_no' => implode(', ', $result['departments']),
                    'title' => $titles->pluck('title')->implode(', ') ?: null,
                    'salary' => $salaries->isEmpty() ? null : (int) $salaries->sortByDesc('from_date')->first()->salary,
                ], fn ($value) => $value !== null),
                'end_date' => $date,
                'reason' => $reason === '' ? null : $reason,
                'ended_management' => $result['ended_management'],
            ], fn ($value) => $value !== null && $value !== []));

            return $result;
        });

        $this->flushCache(array_values(array_unique([...$result['departments'], ...$result['ended_management']])));

        return $result;
    }

    /**
     * Employ a former employee again from $date on (after the end of the last
     * employment) with a department (also a previous one), title and salary. The
     * hire date stays the original one. Stray current title, salary or
     * management rows of the former employee (old data) are closed on the end of
     * the last employment first.
     *
     * @throws ValidationException
     */
    public function rehire(Employee $employee, string $deptNo, string $title, int $salary, string $date): void
    {
        $title = trim($title);
        $salary = $this->assertSalary('salary', $salary);

        DB::transaction(function () use ($employee, $deptNo, $title, $salary, $date) {
            $employee = $this->lock($employee);
            $empNo = $employee->emp_no;

            if ($this->currentRows('dept_emp', $empNo)->isNotEmpty()) {
                throw ValidationException::withMessages(['dept_no' => __(':name is currently employed.', ['name' => $this->name($employee)])]);
            }

            $previousEnd = $this->employmentEnd($empNo);
            $minimum = $previousEnd === null ? $employee->hire_date : EmploymentRules::dayAfter($previousEnd);
            $this->assertEffectiveDate('effective_date', $date, $minimum, $previousEnd === null ? __('the hire date') : __('the end of the last employment'));
            $this->assertPeriodIsFree('effective_date', $empNo, $deptNo, $date);

            $this->ops = [];
            $stray = [];
            foreach (['titles', 'salaries', 'dept_manager'] as $table) {
                $rows = $this->currentRows($table, $empNo);
                if ($rows->isNotEmpty()) {
                    $table === 'dept_manager'
                        ? $this->endManagement($rows, $previousEnd, 'employee.rehired')
                        : $this->closeRows($table, $rows, $previousEnd);
                    $stray[$table] = $rows;
                }
            }

            $this->openEmployment($empNo, $deptNo, $title, $salary, $date);

            $this->log('employee.rehired', $empNo, array_filter([
                'after' => ['dept_no' => $deptNo, 'title' => $title, 'salary' => $salary],
                'effective_date' => $date,
                'previous_end_date' => $previousEnd,
                'returning' => $this->rows('dept_emp')->where('emp_no', $empNo)->where('dept_no', $deptNo)->count() > 1 ?: null,
                'closed_stray_records' => array_keys($stray),
                'ended_management' => isset($stray['dept_manager']) ? $stray['dept_manager']->pluck('dept_no')->values()->all() : [],
            ], fn ($value) => $value !== null && $value !== []));
        });

        $this->flushCache([$deptNo]);
    }

    /**
     * Fix a typo in the amount of the most recent salary row (dates unchanged).
     * Only for current employees; a $reason is required and logged, a change
     * outside -50 % to +100 % needs $confirmUnusual. $rowFromDate and $rowSalary
     * identify the row the form showed; if another one is the latest now (or it was
     * changed), the correction is refused (field `stale`).
     *
     * @throws ValidationException
     */
    public function correctLatestSalary(Employee $employee, int $salary, string $reason, string $rowFromDate, int $rowSalary, bool $confirmUnusual = false): void
    {
        $salary = $this->assertSalary('salary', $salary);

        $departments = DB::transaction(function () use ($employee, $salary, $reason, $rowFromDate, $rowSalary, $confirmUnusual) {
            $employee = $this->lock($employee);
            $empNo = $employee->emp_no;
            $departments = $this->requireCurrentEmployment($employee, 'salary', __('Corrections are only possible for current employees.'));

            $latest = $this->rows('salaries')->where('emp_no', $empNo)->orderByDesc('from_date')->lockForUpdate()->first();
            if ($latest === null) {
                throw ValidationException::withMessages(['salary' => __(':name has no salary history.', ['name' => $this->name($employee)])]);
            }
            if ($latest->from_date !== $rowFromDate || (int) $latest->salary !== $rowSalary) {
                $this->staleForm();
            }
            if ((int) $latest->salary === $salary) {
                throw ValidationException::withMessages(['salary' => __('The amount is unchanged.')]);
            }
            if (! $confirmUnusual) {
                $this->assertUsualChange((int) $latest->salary, $salary);
            }

            $key = ['emp_no' => $empNo, 'from_date' => $latest->from_date];
            $this->rows('salaries')->where($key)->update(['salary' => $salary]);
            $this->ops = [['set', 'salaries', $key, ['salary' => (int) $latest->salary], ['salary' => $salary]]];

            $this->log('employee.salary_corrected', $empNo, [
                'before' => ['salary' => (int) $latest->salary],
                'after' => ['salary' => $salary],
                'from_date' => $latest->from_date,
                'to_date' => $latest->to_date,
                'reason' => trim($reason),
            ]);

            return $departments;
        });

        $this->flushCache($departments);
    }

    /**
     * Fix the wording of the most recent job title row (dates unchanged). Only for
     * current employees; a $reason is required and logged. $rowFromDate and $rowTitle
     * identify the row the form showed; if another one is the latest now (or it was
     * changed), the correction is refused (field `stale`).
     *
     * @throws ValidationException
     */
    public function correctLatestTitle(Employee $employee, string $title, string $reason, string $rowFromDate, string $rowTitle): void
    {
        $title = trim($title);

        $departments = DB::transaction(function () use ($employee, $title, $reason, $rowFromDate, $rowTitle) {
            $employee = $this->lock($employee);
            $empNo = $employee->emp_no;
            $departments = $this->requireCurrentEmployment($employee, 'title', __('Corrections are only possible for current employees.'));

            $latest = $this->latestTitle($empNo, lock: true);
            if ($latest === null) {
                throw ValidationException::withMessages(['title' => __(':name has no job title history.', ['name' => $this->name($employee)])]);
            }
            if ($latest->from_date !== $rowFromDate || $latest->title !== $rowTitle) {
                $this->staleForm();
            }
            if ($latest->title === $title) {
                throw ValidationException::withMessages(['title' => __('The job title is unchanged.')]);
            }
            $taken = $this->rows('titles')->where('emp_no', $empNo)->where('from_date', $latest->from_date)
                ->where('title', $title)->exists();
            if ($taken && mb_strtolower($latest->title) !== mb_strtolower($title)) {
                throw ValidationException::withMessages(['title' => __('A job title row with this title and start date already exists.')]);
            }

            $this->rows('titles')->where('emp_no', $empNo)->where('from_date', $latest->from_date)
                ->where('title', $latest->title)->update(['title' => $title]);
            $this->ops = [['set', 'titles', ['emp_no' => $empNo, 'title' => $title, 'from_date' => $latest->from_date], ['title' => $latest->title], ['title' => $title]]];

            $this->log('employee.title_corrected', $empNo, [
                'before' => ['title' => $latest->title],
                'after' => ['title' => $title],
                'from_date' => $latest->from_date,
                'to_date' => $latest->to_date,
                'reason' => trim($reason),
            ]);

            return $departments;
        });

        $this->flushCache($departments);
    }

    /**
     * The audit entry undoLastChange() would reverse for this employee, or null.
     */
    public function undoableEntry(Employee $employee): ?ActivityLog
    {
        $entry = $this->latestEntry($employee->emp_no);

        return $entry !== null && $this->undoBlockedReason($entry, $employee->emp_no) === null ? $entry : null;
    }

    /**
     * Reverse the most recent HR operation on the employee ($entryId, the entry
     * the user saw): only within UNDO_HOURS and only if nothing changed the
     * employee's records since. Closed rows are re-opened, corrected values restored
     * and the rows the operation added are voided (void_log_id = the undo's audit
     * entry): nothing is deleted. The entry, employee.change_undone, records every
     * row it touched; the original entry stays as it is. A hire cannot be undone.
     *
     * @return string the action that was undone
     *
     * @throws ValidationException
     */
    public function undoLastChange(Employee $employee, int $entryId): string
    {
        $action = DB::transaction(function () use ($employee, $entryId) {
            $employee = $this->lock($employee);
            $empNo = $employee->emp_no;

            $entry = $this->latestEntry($empNo);
            if ($entry === null || $entry->id !== $entryId) {
                throw ValidationException::withMessages(['undo' => __('Only the most recent change of this employee can be undone, and it is not the one shown. Reload the page.')]);
            }
            if (($reason = $this->undoBlockedReason($entry, $empNo)) !== null) {
                throw ValidationException::withMessages(['undo' => $reason]);
            }

            $ops = $entry->changes['_undo']['ops'];

            // Written first: the voided rows point at this entry.
            $undo = AuditLogger::log(ActivityLog::ACTION_CHANGE_UNDONE, ActivityLog::SUBJECT_EMPLOYEE, $empNo, array_filter([
                'undone_entry_id' => $entry->id,
                'undone_action' => $entry->action,
                'undone_at' => $entry->created_at->format('Y-m-d H:i:s'),
                'before' => $entry->changes['after'] ?? null,
                'after' => $entry->changes['before'] ?? null,
                ...$this->undoDetails($ops),
            ], fn ($value) => $value !== null && $value !== []));

            foreach (array_reverse($ops) as $change) {
                $this->reverse($change, $undo->id);
            }

            $this->departments->logReopenedPeriods(
                array_map(fn (array $change) => $change[2], array_filter($ops, fn (array $change) => $change[0] === 'close' && $change[1] === 'dept_manager')),
                $entry->action,
            );

            return $entry->action;
        });

        // An undo removes rows that figures of any scope may already contain.
        $this->flushCache(null);

        return $action;
    }

    /**
     * What the profile's action forms need: current state, the earliest allowed
     * dates (same rules as the operations) and the choices.
     *
     * @return array<string, mixed>
     */
    public function formContext(Employee $employee): array
    {
        $empNo = $employee->emp_no;

        $departments = Department::query()->orderBy('dept_name')->get(['dept_no', 'dept_name']);
        $history = $this->rows('dept_emp')->where('emp_no', $empNo)->orderBy('from_date')->get();
        $current = $history->where('to_date', self::CURRENT)->values();
        $managed = $this->rows('dept_manager')->where('emp_no', $empNo)->where('to_date', self::CURRENT)->get();
        $titles = $this->rows('titles')->where('emp_no', $empNo)->where('to_date', self::CURRENT)->get();
        $salaries = $this->rows('salaries')->where('emp_no', $empNo)->where('to_date', self::CURRENT)->get();
        $managedHere = $managed->whereIn('dept_no', $current->pluck('dept_no'));
        $isCurrent = $current->isNotEmpty();
        $employmentEnd = $isCurrent ? null : $this->employmentEnd($empNo);

        return [
            'isCurrent' => $isCurrent,
            'departments' => $departments,
            'departmentNames' => $departments->pluck('dept_name', 'dept_no'),
            'currentDepartments' => $current->pluck('dept_no')->all(),
            'heldDepartments' => $history->pluck('dept_no')->unique()->values()->all(),
            'managedDepartments' => $managedHere->pluck('dept_no')->values()->all(),
            'allManagedDepartments' => $managed->pluck('dept_no')->values()->all(),
            'currentTitle' => $titles->sortByDesc('from_date')->first()?->title,
            'currentSalary' => ($salary = $salaries->sortByDesc('from_date')->first()) ? (int) $salary->salary : null,
            'latestTitle' => $this->latestTitle($empNo),
            'latestSalary' => $this->rows('salaries')->where('emp_no', $empNo)->orderByDesc('from_date')->first(),
            'titleOptions' => $this->titleOptions(),
            'employmentEnd' => $employmentEnd,
            'today' => EmploymentRules::today(),
            'personalSnapshot' => self::personalSnapshot($employee),
            'undoable' => $this->undoableEntry($employee),
            'minDates' => [
                'transfer' => $isCurrent ? $this->transferMinimum($current, $managedHere) : null,
                'title' => $this->nextFrom('titles', $empNo) ?? $employee->hire_date,
                'salary' => $this->nextFrom('salaries', $empNo) ?? $employee->hire_date,
                'terminate' => $isCurrent ? $this->terminationMinimum($current, $titles, $salaries, $managed) : null,
                'rehire' => $isCurrent ? null : ($employmentEnd === null ? $employee->hire_date : EmploymentRules::dayAfter($employmentEnd)),
                'hire' => $this->firstHistoryDate($empNo),
            ],
        ];
    }

    /**
     * Distinct job titles of the dataset (loose index scan on titles(title, to_date)),
     * cached until EmployeeDataCache::flush().
     *
     * @return string[]
     */
    public function titleOptions(): array
    {
        return Cache::remember(EmployeeDataCache::JOB_TITLES, 3600,
            fn () => $this->rows('titles')->distinct()->orderBy('title')->pluck('title')->all());
    }

    // ------------------------------------------------------------------ internals

    /**
     * Active rows of a history table.
     */
    private function rows(string $table): Builder
    {
        return HistoryRows::active(DB::table($table));
    }

    private function lock(Employee $employee): Employee
    {
        return Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Current (to_date = 9999-01-01) rows of a history table, locked.
     */
    private function currentRows(string $table, int $empNo): Collection
    {
        return $this->rows($table)->where('emp_no', $empNo)->where('to_date', self::CURRENT)->lockForUpdate()->get();
    }

    /**
     * Set to_date = $date on $rows (current rows of $table) and remember it for an undo.
     */
    private function closeRows(string $table, Collection $rows, string $date): void
    {
        foreach ($rows as $row) {
            $key = $this->key($table, $row);
            $this->rows($table)->where($key)->where('to_date', self::CURRENT)->update(['to_date' => $date]);
            $this->ops[] = ['close', $table, $key, $date];
        }
    }

    /**
     * End management periods the way every other screen does, and remember it for an undo.
     *
     * @param  Collection<int, object>  $periods  current dept_manager rows, locked
     * @param  string  $cause  the action that ended them
     */
    private function endManagement(Collection $periods, string $date, string $cause): void
    {
        $this->departments->endPeriods($periods, $date, $cause);

        foreach ($periods as $period) {
            $this->ops[] = ['close', 'dept_manager', $this->key('dept_manager', $period), $date];
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insert(string $table, array $row): void
    {
        DB::table($table)->insert($row);
        $this->ops[] = ['insert', $table, $this->key($table, (object) $row)];
    }

    /**
     * @return array<string, mixed>
     */
    private function key(string $table, object $row): array
    {
        $key = [];
        foreach (self::KEYS[$table] as $column) {
            $key[$column] = $row->{$column};
        }

        return $key;
    }

    /**
     * @return string[]
     */
    private function currentDepartments(int $empNo): array
    {
        return $this->rows('dept_emp')->where('emp_no', $empNo)->where('to_date', self::CURRENT)->pluck('dept_no')->all();
    }

    /**
     * @return string[] the current departments
     *
     * @throws ValidationException
     */
    private function requireCurrentEmployment(Employee $employee, string $field, ?string $message = null): array
    {
        $departments = $this->currentRows('dept_emp', $employee->emp_no)->pluck('dept_no')->all();

        if ($departments === []) {
            throw ValidationException::withMessages([$field => $message ?? $this->notEmployedMessage($employee)]);
        }

        return $departments;
    }

    private function openEmployment(int $empNo, string $deptNo, string $title, int $salary, string $from): void
    {
        $this->insert('dept_emp', ['emp_no' => $empNo, 'dept_no' => $deptNo, 'from_date' => $from, 'to_date' => self::CURRENT]);
        $this->insert('titles', ['emp_no' => $empNo, 'title' => $title, 'from_date' => $from, 'to_date' => self::CURRENT]);
        $this->insert('salaries', ['emp_no' => $empNo, 'salary' => $salary, 'from_date' => $from, 'to_date' => self::CURRENT]);
    }

    /**
     * Write the audit entry of the running operation, with its row changes and a
     * fingerprint of the employee's records afterwards (for undoLastChange()).
     *
     * @param  array<string, mixed>  $changes
     */
    private function log(string $action, int $empNo, array $changes): void
    {
        $changes['_undo'] = ['ops' => $this->ops, 'state' => $this->stateHash($empNo)];
        $this->ops = [];

        AuditLogger::log($action, ActivityLog::SUBJECT_EMPLOYEE, $empNo, $changes);
    }

    /**
     * Fingerprint of everything the application sees of the employee (employees row
     * and all active history rows); voided rows are not part of it.
     */
    private function stateHash(int $empNo): string
    {
        $state = ['employees' => (array) DB::table('employees')->where('emp_no', $empNo)->first()];
        foreach (self::KEYS as $table => $columns) {
            $state[$table] = $this->rows($table)->where('emp_no', $empNo)->orderBy('from_date')->orderBy($columns[1])
                ->get()->map(fn ($row) => array_map('strval', (array) $row))->all();
        }

        return sha1(json_encode($state));
    }

    private function latestEntry(int $empNo): ?ActivityLog
    {
        return ActivityLog::forSubject(ActivityLog::SUBJECT_EMPLOYEE, $empNo)
            ->where('action', 'like', 'employee.%')
            ->latestFirst()
            ->first();
    }

    private function undoBlockedReason(ActivityLog $entry, int $empNo): ?string
    {
        return match (true) {
            $entry->action === 'employee.hired' => __('A hire cannot be undone. Correct the personal data, or terminate the employment.'),
            ! in_array($entry->action, self::UNDOABLE_ACTIONS, true) || ! $this->validUndoData($entry->changes['_undo'] ?? null, $empNo) => __('The last change cannot be undone.'),
            $entry->created_at->lt(now()->subHours(self::UNDO_HOURS)) => __('Changes can only be undone within :hours hours.', ['hours' => self::UNDO_HOURS]),
            ! hash_equals($entry->changes['_undo']['state'], $this->stateHash($empNo)) => __('The records of this employee were changed after the last change, so it cannot be undone any more.'),
            $this->managementTakenOver($entry->changes['_undo']['ops']) => __('The department got another manager after this change, so it cannot be undone any more.'),
            default => null,
        };
    }

    /**
     * Would reopening a management period of the operation overlap a period
     * another employee started since? Their records are not part of the fingerprint.
     *
     * @param  array<int, array<int, mixed>>  $ops
     */
    private function managementTakenOver(array $ops): bool
    {
        foreach ($ops as $change) {
            [$op, $table, $key] = $change;

            if ($op === 'close' && $table === 'dept_manager'
                && $this->rows('dept_manager')->where('dept_no', $key['dept_no'])->where('emp_no', '<>', $key['emp_no'])->where('from_date', '>=', $change[3])->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the stored undo data have the exact shape operations write? Only rows of
     * this employee, known tables and columns: nothing else is ever acted on.
     */
    private function validUndoData(mixed $undo, int $empNo): bool
    {
        if (! is_array($undo) || ! is_string($undo['state'] ?? null) || ! is_array($undo['ops'] ?? null) || $undo['ops'] === []) {
            return false;
        }

        foreach ($undo['ops'] as $change) {
            if (! $this->validUndoChange($change, $empNo)) {
                return false;
            }
        }

        return true;
    }

    private function validUndoChange(mixed $change, int $empNo): bool
    {
        if (! is_array($change) || ! array_is_list($change) || count($change) < 3) {
            return false;
        }

        [$op, $table, $key] = $change;
        $keyColumns = $table === 'employees' ? ['emp_no'] : (is_string($table) ? (self::KEYS[$table] ?? null) : null);

        if ($keyColumns === null || ! is_array($key) || ($key['emp_no'] ?? null) !== $empNo || ! $this->sameColumns($key, $keyColumns)) {
            return false;
        }

        return match ($op) {
            'close' => $table !== 'employees' && count($change) === 4 && is_string($change[3]),
            'insert' => $table !== 'employees' && count($change) === 3,
            'set' => count($change) === 5 && is_array($change[3]) && is_array($change[4])
                && $change[3] !== [] && $this->sameColumns($change[3], array_keys($change[4]))
                && array_diff(array_keys($change[3]), self::RESTORABLE_COLUMNS[$table] ?? []) === [],
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  string[]  $columns
     */
    private function sameColumns(array $values, array $columns): bool
    {
        return array_diff(array_keys($values), $columns) === [] && array_diff($columns, array_keys($values)) === [];
    }

    /**
     * What an undo does to each row, with the values of the rows it voids, for its audit entry.
     *
     * @param  array<int, array<int, mixed>>  $ops
     * @return array{voided_rows: array<int, array<string, mixed>>, reopened_rows: array<int, array<string, mixed>>, restored_values: array<int, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private function undoDetails(array $ops): array
    {
        $details = ['voided_rows' => [], 'reopened_rows' => [], 'restored_values' => []];

        foreach ($ops as $change) {
            [$op, $table, $key] = $change;
            $where = ['table' => $table, ...array_diff_key($key, ['emp_no' => true])];

            if ($op === 'insert') {
                $row = $this->rows($table)->where($key)->first();
                if ($row === null) {
                    $this->undoMismatch();
                }
                $details['voided_rows'][] = ['table' => $table, ...array_diff_key((array) $row, ['emp_no' => true, HistoryRows::COLUMN => true])];
            } elseif ($op === 'close') {
                $details['reopened_rows'][] = [...$where, 'previous_to_date' => $change[3]];
            } else {
                $details['restored_values'][] = [...$where, 'replaced' => $change[4], 'restored' => $change[3]];
            }
        }

        return $details;
    }

    /**
     * Reverse one recorded row change; it must hit exactly one row. Nothing is
     * deleted: a row the operation added is voided with $voidLogId.
     *
     * @param  array<int, mixed>  $change
     *
     * @throws ValidationException
     */
    private function reverse(array $change, int $voidLogId): void
    {
        [$op, $table, $key] = $change;
        $query = $table === 'employees' ? DB::table('employees')->where($key) : $this->rows($table)->where($key);

        $affected = match ($op) {
            'close' => $query->where('to_date', $change[3])->update(['to_date' => self::CURRENT]),
            'insert' => $query->update([HistoryRows::COLUMN => $voidLogId]),
            'set' => $query->update($change[3]),
        };

        if ($affected !== 1) {
            $this->undoMismatch();
        }
    }

    /**
     * The form was built from data that changed before it was submitted.
     *
     * @throws ValidationException
     */
    private function staleForm(): never
    {
        throw ValidationException::withMessages(['stale' => __('The data changed in the meantime. Reload the page and try again.')]);
    }

    /**
     * @throws ValidationException
     */
    private function undoMismatch(): never
    {
        throw ValidationException::withMessages(['undo' => __('The records of this employee do not match the change any more, so it cannot be undone.')]);
    }

    /**
     * The day after the latest start date in $table: a new row must start later,
     * which also rules out primary key collisions on (emp_no, [title,] from_date).
     */
    private function nextFrom(string $table, int $empNo): ?string
    {
        $latest = $this->rows($table)->where('emp_no', $empNo)->max('from_date');

        return $latest === null ? null : EmploymentRules::dayAfter($latest);
    }

    private function transferMinimum(Collection $currentDepartments, Collection $managed): string
    {
        return EmploymentRules::dayAfter(EmploymentRules::latest(
            $currentDepartments->max('from_date'),
            $managed->max('from_date'),
        ));
    }

    private function terminationMinimum(Collection ...$currentRows): string
    {
        return EmploymentRules::dayAfter(EmploymentRules::latest(
            ...array_map(fn (Collection $rows) => $rows->max('from_date'), $currentRows),
        ));
    }

    /**
     * Earliest start date of the employee's history (null without history).
     */
    private function firstHistoryDate(int $empNo): ?string
    {
        $dates = array_map(
            fn (string $table) => $this->rows($table)->where('emp_no', $empNo)->min('from_date'),
            ['dept_emp', 'titles', 'salaries', 'dept_manager'],
        );
        $dates = array_filter($dates);

        return $dates === [] ? null : min($dates);
    }

    /**
     * Latest date of the history - the end of the last employment.
     */
    private function employmentEnd(int $empNo): ?string
    {
        $dates = [];
        foreach (['dept_emp', 'titles', 'salaries', 'dept_manager'] as $table) {
            $dates[] = $this->rows($table)->where('emp_no', $empNo)->where('to_date', '<>', self::CURRENT)->max('to_date');
            $dates[] = $this->rows($table)->where('emp_no', $empNo)->max('from_date');
        }

        return EmploymentRules::latest(...$dates);
    }

    private function latestTitle(int $empNo, bool $lock = false): ?object
    {
        return $this->rows('titles')->where('emp_no', $empNo)
            ->orderByDesc('from_date')
            ->orderByRaw('to_date = ? desc', [self::CURRENT])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * Clear the cached figures once the surrounding transaction (if any) has been
     * committed. A failing cache never fails the (already committed) operation.
     *
     * @param  string[]|null  $departments  null: all of them
     */
    private function flushCache(?array $departments): void
    {
        DB::afterCommit(function () use ($departments) {
            try {
                EmployeeDataCache::flush($departments);
            } catch (Throwable $e) {
                Log::warning('Could not clear the cached employee figures.', ['departments' => $departments, 'exception' => $e]);
            }
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertNoDuplicate(string $firstName, string $lastName, string $birthDate): void
    {
        $duplicates = $this->duplicates($firstName, $lastName, $birthDate);

        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages(['confirm_duplicate' => __(
                'An employee with the same name and birth date already exists (employee no. :numbers). Check that this is not the same person, then confirm to hire anyway.',
                ['numbers' => $duplicates->pluck('emp_no')->implode(', ')],
            )]);
        }
    }

    /**
     * A new period of $deptNo cannot start on the day an existing one did (primary key).
     *
     * @throws ValidationException
     */
    private function assertPeriodIsFree(string $field, int $empNo, string $deptNo, string $date): void
    {
        if ($this->rows('dept_emp')->where(['emp_no' => $empNo, 'dept_no' => $deptNo, 'from_date' => $date])->exists()) {
            throw ValidationException::withMessages([$field => __('A period in this department already starts on this date.')]);
        }
    }

    /**
     * $date must be on or after $minimum and not in the future.
     *
     * @throws ValidationException
     */
    private function assertEffectiveDate(string $field, string $date, ?string $minimum, ?string $reason = null): void
    {
        $this->assertNotInFuture($field, $date);

        if ($minimum !== null && $date < $minimum) {
            throw ValidationException::withMessages([$field => $reason === null
                ? __('The date must be :date or later (after the start of the current records).', ['date' => EmploymentRules::display($minimum)])
                : __('The date must be :date or later (after :reason).', ['date' => EmploymentRules::display($minimum), 'reason' => $reason]),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNotInFuture(string $field, string $date): void
    {
        if ($date > EmploymentRules::today()) {
            throw ValidationException::withMessages([$field => __('The date cannot be in the future.')]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertPlausibleAge(string $birthDate, string $hireDate): void
    {
        if (! EmploymentRules::isPlausibleAgeAtHire($birthDate, $hireDate)) {
            throw ValidationException::withMessages(['birth_date' => __(
                'The employee must be between :min and :max years old on the hire date.',
                ['min' => EmploymentRules::MIN_AGE_AT_HIRE, 'max' => EmploymentRules::MAX_AGE_AT_HIRE],
            )]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertUsualChange(int $before, int $after): void
    {
        if (EmploymentRules::isUnusualChange($before, $after)) {
            throw ValidationException::withMessages(['confirm_unusual' => __(
                'This changes the salary by :percent %, outside the usual :min % to +:max %. Tick "I confirm an unusual change" to save it.',
                [
                    'percent' => (EmploymentRules::percentChange($before, $after) > 0 ? '+' : '').EmploymentRules::percentChange($before, $after),
                    'min' => EmploymentRules::MIN_PERCENT,
                    'max' => EmploymentRules::MAX_PERCENT,
                ],
            )]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertSalary(string $field, int $salary): int
    {
        if ($salary < EmploymentRules::MIN_SALARY || $salary > EmploymentRules::MAX_SALARY) {
            throw ValidationException::withMessages([$field => __(
                'The salary must be between :min and :max.',
                ['min' => number_format(EmploymentRules::MIN_SALARY), 'max' => number_format(EmploymentRules::MAX_SALARY)],
            )]);
        }

        return $salary;
    }

    private function notEmployedMessage(Employee $employee): string
    {
        return __(':name is not currently employed.', ['name' => $this->name($employee)]);
    }

    private function name(Employee $employee): string
    {
        return trim($employee->first_name.' '.$employee->last_name);
    }
}
