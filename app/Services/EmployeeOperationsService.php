<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Support\EmployeeDataCache;
use App\Support\EmploymentRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR operations on employees: hire, edit personal data, transfer, title and
 * salary changes, termination, rehire and corrections of the latest salary /
 * title row (docs/HR_OPERATIONS.md).
 *
 * History is never deleted: a change closes the current row (to_date = the
 * effective date) and opens a new one (from_date = the effective date,
 * to_date = 9999-01-01), the convention of the dataset.
 *
 * Every operation
 *  - runs in one transaction and first locks the employee row (SELECT ... FOR
 *    UPDATE), so two operations on the same employee are serialized and the
 *    date checks below see the committed state;
 *  - checks the business rules that depend on the stored history and throws a
 *    ValidationException (field => message) when one is broken;
 *  - writes an `employee.*` audit entry in the same transaction;
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

    /** Attempts of a hire when the chosen employee number was taken meanwhile. */
    public const HIRE_ATTEMPTS = 3;

    /** Personal data fields (employees table). */
    public const PERSONAL_FIELDS = ['first_name', 'last_name', 'gender', 'birth_date', 'hire_date'];

    /** Kind of a title change => audit action. */
    public const TITLE_CHANGE_ACTIONS = [
        'promotion' => 'employee.promoted',
        'demotion' => 'employee.demoted',
        'change' => 'employee.title_changed',
    ];

    public function __construct(private readonly EmployeeNumberAllocator $numbers)
    {
    }

    /**
     * Hire a new employee: employees + dept_emp + titles + salaries rows starting on the hire date.
     *
     * @param  array{first_name: string, last_name: string, gender: string, birth_date: string, hire_date: string, dept_no: string, title: string, salary: int|string}  $data
     *
     * @throws ValidationException
     */
    public function hire(array $data): Employee
    {
        $this->assertNotInFuture('hire_date', $data['hire_date']);
        $this->assertPlausibleAge($data['birth_date'], $data['hire_date']);
        $salary = $this->assertSalary('salary', (int) $data['salary']);
        $title = trim($data['title']);

        for ($attempt = 1; ; $attempt++) {
            try {
                $employee = DB::transaction(function () use ($data, $title, $salary) {
                    $employee = Employee::create([
                        'emp_no' => $this->numbers->next(),
                        'first_name' => trim($data['first_name']),
                        'last_name' => trim($data['last_name']),
                        'gender' => $data['gender'],
                        'birth_date' => $data['birth_date'],
                        'hire_date' => $data['hire_date'],
                    ]);

                    $this->openEmployment($employee->emp_no, $data['dept_no'], $title, $salary, $data['hire_date']);

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

                break;
            } catch (UniqueConstraintViolationException $e) {
                // Another writer took the number between our read and insert (e.g. an import
                // inserting explicit numbers): try again with the new maximum.
                if ($attempt >= self::HIRE_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        EmployeeDataCache::flush([$data['dept_no']]);

        return $employee;
    }

    /**
     * Change name, gender, birth date and hire date. The hire date may not lie
     * after the first history row and the age on the hire date must be plausible.
     *
     * @param  array{first_name: string, last_name: string, gender: string, birth_date: string, hire_date: string}  $data
     * @return array{before?: array<string, mixed>, after?: array<string, mixed>} the logged diff ([] = nothing changed)
     *
     * @throws ValidationException
     */
    public function updatePersonalData(Employee $employee, array $data): array
    {
        $result = DB::transaction(function () use ($employee, $data) {
            $employee = $this->lock($employee);

            $this->assertNotInFuture('hire_date', $data['hire_date']);
            $firstHistoryDate = $this->firstHistoryDate($employee->emp_no);
            if ($firstHistoryDate !== null && $data['hire_date'] > $firstHistoryDate) {
                throw ValidationException::withMessages(['hire_date' => __(
                    'The hire date cannot be after the start of the employment history (:date).',
                    ['date' => EmploymentRules::display($firstHistoryDate)],
                )]);
            }
            $this->assertPlausibleAge($data['birth_date'], $data['hire_date']);

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
                AuditLogger::log('employee.updated', ActivityLog::SUBJECT_EMPLOYEE, $employee->emp_no, $changes);
            }

            return [$changes, $this->currentDepartments($employee->emp_no)];
        });

        [$changes, $departments] = $result;
        if ($changes !== []) {
            EmployeeDataCache::flush($departments);
        }

        return $changes;
    }

    /**
     * Move a current employee to $deptNo from $date on. A current management of
     * the department being left ends on the same date.
     *
     * A department the employee already belonged to cannot be chosen: dept_emp
     * has the primary key (emp_no, dept_no), so it holds one period per
     * employee and department, and re-opening the old row would rewrite history.
     *
     * @return array{from: string[], to: string, ended_management: string[]}
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
            $this->assertNotPreviouslyHeld($employee, $deptNo);

            $managed = $this->currentRows('dept_manager', $empNo)->whereIn('dept_no', $from);
            $this->assertEffectiveDate('effective_date', $date, $this->transferMinimum($current, $managed));

            DB::table('dept_emp')->where('emp_no', $empNo)->where('to_date', self::CURRENT)->update(['to_date' => $date]);
            if ($managed->isNotEmpty()) {
                DB::table('dept_manager')->where('emp_no', $empNo)->where('to_date', self::CURRENT)
                    ->whereIn('dept_no', $managed->pluck('dept_no'))->update(['to_date' => $date]);
            }
            DB::table('dept_emp')->insert(['emp_no' => $empNo, 'dept_no' => $deptNo, 'from_date' => $date, 'to_date' => self::CURRENT]);

            $endedManagement = $managed->pluck('dept_no')->values()->all();
            AuditLogger::log('employee.transferred', ActivityLog::SUBJECT_EMPLOYEE, $empNo, array_filter([
                'before' => ['dept_no' => implode(', ', $from)],
                'after' => ['dept_no' => $deptNo],
                'effective_date' => $date,
                'ended_management' => $endedManagement,
            ]));

            return ['from' => $from, 'to' => $deptNo, 'ended_management' => $endedManagement];
        });

        EmployeeDataCache::flush(array_values(array_unique([...$result['from'], $deptNo])));

        return $result;
    }

    /**
     * New job title from $date on. $kind (promotion / demotion / change) only
     * selects the audit action (employee.promoted / .demoted / .title_changed).
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

            $current = $this->currentRows('titles', $empNo)->sortByDesc('from_date')->first();
            if ($current === null) {
                throw ValidationException::withMessages(['title' => __(':name has no current job title.', ['name' => $this->name($employee)])]);
            }
            if (mb_strtolower($current->title) === mb_strtolower($title)) {
                throw ValidationException::withMessages(['title' => __('This is already the current job title.')]);
            }

            $this->assertEffectiveDate('effective_date', $date, $this->nextFrom('titles', $empNo), __('the start of the current job title'));

            DB::table('titles')->where('emp_no', $empNo)->where('to_date', self::CURRENT)->update(['to_date' => $date]);
            DB::table('titles')->insert(['emp_no' => $empNo, 'title' => $title, 'from_date' => $date, 'to_date' => self::CURRENT]);

            AuditLogger::log($action, ActivityLog::SUBJECT_EMPLOYEE, $empNo, [
                'before' => ['title' => $current->title],
                'after' => ['title' => $title],
                'effective_date' => $date,
            ]);

            return $departments;
        });

        EmployeeDataCache::flush($departments);
    }

    /**
     * New salary from $date on: an amount, or the current salary changed by $percent.
     *
     * @return array{before: int, after: int}
     *
     * @throws ValidationException
     */
    public function changeSalary(Employee $employee, ?int $amount, ?float $percent, string $date): array
    {
        if (($amount === null) === ($percent === null)) {
            throw ValidationException::withMessages(['salary' => __('Enter either a new amount or a percentage.')]);
        }

        [$result, $departments] = DB::transaction(function () use ($employee, $amount, $percent, $date) {
            $employee = $this->lock($employee);
            $empNo = $employee->emp_no;
            $departments = $this->requireCurrentEmployment($employee, 'salary');

            $current = $this->currentRows('salaries', $empNo)->sortByDesc('from_date')->first();
            if ($current === null) {
                throw ValidationException::withMessages(['salary' => __(':name has no current salary.', ['name' => $this->name($employee)])]);
            }

            $before = (int) $current->salary;
            $field = $amount === null ? 'percent' : 'salary';
            $after = $this->assertSalary($field, $amount ?? EmploymentRules::applyPercent($before, $percent));
            if ($after === $before) {
                throw ValidationException::withMessages([$field => __('The new salary is the same as the current one.')]);
            }

            $this->assertEffectiveDate('effective_date', $date, $this->nextFrom('salaries', $empNo), __('the start of the current salary'));

            DB::table('salaries')->where('emp_no', $empNo)->where('to_date', self::CURRENT)->update(['to_date' => $date]);
            DB::table('salaries')->insert(['emp_no' => $empNo, 'salary' => $after, 'from_date' => $date, 'to_date' => self::CURRENT]);

            AuditLogger::log('employee.salary_changed', ActivityLog::SUBJECT_EMPLOYEE, $empNo, array_filter([
                'before' => ['salary' => $before],
                'after' => ['salary' => $after],
                'effective_date' => $date,
                'change_percent' => EmploymentRules::percentChange($before, $after),
                'requested_percent' => $percent,
            ], fn ($value) => $value !== null));

            return [['before' => $before, 'after' => $after], $departments];
        });

        EmployeeDataCache::flush($departments);

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

            foreach (['dept_emp', 'titles', 'salaries', 'dept_manager'] as $table) {
                DB::table($table)->where('emp_no', $empNo)->where('to_date', self::CURRENT)->update(['to_date' => $date]);
            }

            $reason = $reason === null ? null : trim($reason);
            $result = [
                'departments' => $departments->pluck('dept_no')->all(),
                'ended_management' => $managed->pluck('dept_no')->values()->all(),
            ];

            AuditLogger::log('employee.terminated', ActivityLog::SUBJECT_EMPLOYEE, $empNo, array_filter([
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

        EmployeeDataCache::flush(array_values(array_unique([...$result['departments'], ...$result['ended_management']])));

        return $result;
    }

    /**
     * Employ a former employee again from $date on (after the end of the last
     * employment) with a new department, title and salary. The hire date stays the
     * original one. A department the employee belonged to before cannot be chosen
     * (see transfer()).
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
            if ($this->currentRows('titles', $empNo)->isNotEmpty() || $this->currentRows('salaries', $empNo)->isNotEmpty()) {
                throw ValidationException::withMessages(['dept_no' => __(
                    ':name still has a current job title or salary without a department. Correct the data before rehiring.',
                    ['name' => $this->name($employee)],
                )]);
            }
            $this->assertNotPreviouslyHeld($employee, $deptNo);

            $previousEnd = $this->employmentEnd($empNo);
            $minimum = $previousEnd === null ? $employee->hire_date : EmploymentRules::dayAfter($previousEnd);
            $this->assertEffectiveDate('effective_date', $date, $minimum, $previousEnd === null ? __('the hire date') : __('the end of the last employment'));

            $this->openEmployment($empNo, $deptNo, $title, $salary, $date);

            AuditLogger::log('employee.rehired', ActivityLog::SUBJECT_EMPLOYEE, $empNo, array_filter([
                'after' => ['dept_no' => $deptNo, 'title' => $title, 'salary' => $salary],
                'effective_date' => $date,
                'previous_end_date' => $previousEnd,
            ], fn ($value) => $value !== null));
        });

        EmployeeDataCache::flush([$deptNo]);
    }

    /**
     * Fix a typo in the amount of the most recent salary row (dates unchanged).
     *
     * @throws ValidationException
     */
    public function correctLatestSalary(Employee $employee, int $salary): void
    {
        $salary = $this->assertSalary('salary', $salary);

        $departments = DB::transaction(function () use ($employee, $salary) {
            $employee = $this->lock($employee);
            $empNo = $employee->emp_no;

            $latest = DB::table('salaries')->where('emp_no', $empNo)->orderByDesc('from_date')->lockForUpdate()->first();
            if ($latest === null) {
                throw ValidationException::withMessages(['salary' => __(':name has no salary history.', ['name' => $this->name($employee)])]);
            }
            if ((int) $latest->salary === $salary) {
                throw ValidationException::withMessages(['salary' => __('The amount is unchanged.')]);
            }

            DB::table('salaries')->where('emp_no', $empNo)->where('from_date', $latest->from_date)->update(['salary' => $salary]);

            AuditLogger::log('employee.salary_corrected', ActivityLog::SUBJECT_EMPLOYEE, $empNo, [
                'before' => ['salary' => (int) $latest->salary],
                'after' => ['salary' => $salary],
                'from_date' => $latest->from_date,
                'to_date' => $latest->to_date,
            ]);

            return $this->currentDepartments($empNo);
        });

        EmployeeDataCache::flush($departments);
    }

    /**
     * Fix the wording of the most recent job title row (dates unchanged).
     *
     * @throws ValidationException
     */
    public function correctLatestTitle(Employee $employee, string $title): void
    {
        $title = trim($title);

        $departments = DB::transaction(function () use ($employee, $title) {
            $employee = $this->lock($employee);
            $empNo = $employee->emp_no;

            $latest = $this->latestTitle($empNo, lock: true);
            if ($latest === null) {
                throw ValidationException::withMessages(['title' => __(':name has no job title history.', ['name' => $this->name($employee)])]);
            }
            if ($latest->title === $title) {
                throw ValidationException::withMessages(['title' => __('The job title is unchanged.')]);
            }
            $taken = DB::table('titles')->where('emp_no', $empNo)->where('from_date', $latest->from_date)
                ->where('title', $title)->exists();
            if ($taken && mb_strtolower($latest->title) !== mb_strtolower($title)) {
                throw ValidationException::withMessages(['title' => __('A job title row with this title and start date already exists.')]);
            }

            DB::table('titles')->where('emp_no', $empNo)->where('from_date', $latest->from_date)
                ->where('title', $latest->title)->update(['title' => $title]);

            AuditLogger::log('employee.title_corrected', ActivityLog::SUBJECT_EMPLOYEE, $empNo, [
                'before' => ['title' => $latest->title],
                'after' => ['title' => $title],
                'from_date' => $latest->from_date,
                'to_date' => $latest->to_date,
            ]);

            return $this->currentDepartments($empNo);
        });

        EmployeeDataCache::flush($departments);
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
        $history = DB::table('dept_emp')->where('emp_no', $empNo)->orderBy('from_date')->get();
        $current = $history->where('to_date', self::CURRENT)->values();
        $managed = DB::table('dept_manager')->where('emp_no', $empNo)->where('to_date', self::CURRENT)->get();
        $titles = DB::table('titles')->where('emp_no', $empNo)->where('to_date', self::CURRENT)->get();
        $salaries = DB::table('salaries')->where('emp_no', $empNo)->where('to_date', self::CURRENT)->get();
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
            'latestSalary' => DB::table('salaries')->where('emp_no', $empNo)->orderByDesc('from_date')->first(),
            'titleOptions' => $this->titleOptions(),
            'employmentEnd' => $employmentEnd,
            'today' => EmploymentRules::today(),
            'minDates' => [
                'transfer' => $isCurrent ? $this->transferMinimum($current, $managedHere) : null,
                'title' => $this->nextFrom('titles', $empNo),
                'salary' => $this->nextFrom('salaries', $empNo),
                'terminate' => $isCurrent ? $this->terminationMinimum($current, $titles, $salaries, $managed) : null,
                'rehire' => $isCurrent ? null : ($employmentEnd === null ? $employee->hire_date : EmploymentRules::dayAfter($employmentEnd)),
                'hire' => $this->firstHistoryDate($empNo),
            ],
        ];
    }

    /**
     * Distinct job titles of the dataset (loose index scan on titles(title, to_date)).
     *
     * @return string[]
     */
    public function titleOptions(): array
    {
        return DB::table('titles')->distinct()->orderBy('title')->pluck('title')->all();
    }

    // ------------------------------------------------------------------ internals

    private function lock(Employee $employee): Employee
    {
        return Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Current (to_date = 9999-01-01) rows of a history table, locked.
     */
    private function currentRows(string $table, int $empNo): Collection
    {
        return DB::table($table)->where('emp_no', $empNo)->where('to_date', self::CURRENT)->lockForUpdate()->get();
    }

    /**
     * @return string[]
     */
    private function currentDepartments(int $empNo): array
    {
        return DB::table('dept_emp')->where('emp_no', $empNo)->where('to_date', self::CURRENT)->pluck('dept_no')->all();
    }

    /**
     * @return string[] the current departments
     *
     * @throws ValidationException
     */
    private function requireCurrentEmployment(Employee $employee, string $field): array
    {
        $departments = $this->currentRows('dept_emp', $employee->emp_no)->pluck('dept_no')->all();

        if ($departments === []) {
            throw ValidationException::withMessages([$field => $this->notEmployedMessage($employee)]);
        }

        return $departments;
    }

    private function openEmployment(int $empNo, string $deptNo, string $title, int $salary, string $from): void
    {
        DB::table('dept_emp')->insert(['emp_no' => $empNo, 'dept_no' => $deptNo, 'from_date' => $from, 'to_date' => self::CURRENT]);
        DB::table('titles')->insert(['emp_no' => $empNo, 'title' => $title, 'from_date' => $from, 'to_date' => self::CURRENT]);
        DB::table('salaries')->insert(['emp_no' => $empNo, 'salary' => $salary, 'from_date' => $from, 'to_date' => self::CURRENT]);
    }

    /**
     * The day after the latest start date in $table: a new row must start later,
     * which also rules out primary key collisions on (emp_no, [title,] from_date).
     */
    private function nextFrom(string $table, int $empNo): ?string
    {
        $latest = DB::table($table)->where('emp_no', $empNo)->max('from_date');

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
            fn (string $table) => DB::table($table)->where('emp_no', $empNo)->min('from_date'),
            ['dept_emp', 'titles', 'salaries', 'dept_manager'],
        );
        $dates = array_filter($dates);

        return $dates === [] ? null : min($dates);
    }

    /**
     * Latest date of the (closed) history - the end of the last employment.
     */
    private function employmentEnd(int $empNo): ?string
    {
        $dates = [];
        foreach (['dept_emp', 'titles', 'salaries', 'dept_manager'] as $table) {
            $dates[] = DB::table($table)->where('emp_no', $empNo)->where('to_date', '<>', self::CURRENT)->max('to_date');
            $dates[] = DB::table($table)->where('emp_no', $empNo)->max('from_date');
        }

        return EmploymentRules::latest(...$dates);
    }

    private function latestTitle(int $empNo, bool $lock = false): ?object
    {
        return DB::table('titles')->where('emp_no', $empNo)
            ->orderByDesc('from_date')
            ->orderByRaw('to_date = ? desc', [self::CURRENT])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * @throws ValidationException
     */
    private function assertNotPreviouslyHeld(Employee $employee, string $deptNo): void
    {
        $previous = DB::table('dept_emp')->where('emp_no', $employee->emp_no)->where('dept_no', $deptNo)->first();

        if ($previous !== null) {
            throw ValidationException::withMessages(['dept_no' => __(
                ':name already belonged to :department (:from - :to). The department history keeps one period per employee and department, so a return to a previous department cannot be recorded. Choose another department.',
                [
                    'name' => $this->name($employee),
                    'department' => Department::query()->whereKey($deptNo)->value('dept_name') ?? $deptNo,
                    'from' => EmploymentRules::display($previous->from_date),
                    'to' => EmploymentRules::display($previous->to_date),
                ],
            )]);
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
