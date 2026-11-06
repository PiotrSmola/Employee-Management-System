# HR operations and the audit log

Administrators and HR (`manage-employees`) change employee data from the directory
(**Add employee**) and from the **HR actions** card of an employee profile. Every change is
written to the audit log, which administrators and HR (`view-audit-log`) read under
**Audit log** (user menu) and in the **Change history** section of a profile.

Code: `App\Services\EmployeeOperationsService` (rules and writes),
`App\Http\Controllers\EmployeeOperationsController` + `App\Http\Requests\Employees\*`
(routes in `routes/web/hr-operations.php`), the profile parts
`App\View\Components\EmployeeActions` / `EmployeeChangeHistory`, and
`App\Http\Controllers\AuditLogController` with `App\Support\AuditLogPresenter`.

## How history is written

The dataset marks current rows with `to_date = 9999-01-01`, and a period ends on the day the
next one starts. Every operation keeps that shape:

- a change closes the current row (`to_date` = effective date) and inserts a new one
  (`from_date` = effective date, `to_date` = 9999-01-01). No row is ever deleted, neither from
  the history nor from `employees`: **Undo last change** (below) voids rows instead;
- every operation runs in one transaction that first locks the employee row
  (`SELECT ... FOR UPDATE`), so two people acting on the same employee are serialized and
  the date checks see the committed state; the audit entry is written in the same transaction;
- after the commit (`DB::afterCommit`) the cached dashboard / filter figures of the affected
  departments are cleared (`EmployeeDataCache::flush([...])`); a cache failure is logged and
  never fails the committed change.

| Operation | Route | Writes | Audit action |
|-----------|-------|--------|--------------|
| Hire | `GET /employees/create`, `POST /employees` | `employees` + `dept_emp`, `titles`, `salaries` from the hire date | `employee.hired` |
| Personal data | `PUT /employees/{emp_no}` | `employees` (name, gender, birth date, hire date) | `employee.updated` (diff) |
| Transfer | `POST /employees/{emp_no}/transfer` | closes `dept_emp` (and the management of the department being left), opens the new `dept_emp` | `employee.transferred` |
| Title change | `POST /employees/{emp_no}/title` | closes / opens `titles` | `employee.promoted`, `employee.demoted` or `employee.title_changed` (chosen in the form) |
| Salary change | `POST /employees/{emp_no}/salary` | closes / opens `salaries`; new amount or a change in % | `employee.salary_changed` |
| Termination | `POST /employees/{emp_no}/terminate` | closes every current `dept_emp`, `titles`, `salaries`, `dept_manager` row; optional reason | `employee.terminated` (reason in `changes`) |
| Rehire | `POST /employees/{emp_no}/rehire` | new `dept_emp`, `titles`, `salaries` rows for a former employee | `employee.rehired` |
| Correct latest salary | `PUT /employees/{emp_no}/salary/latest` | amount of the most recent salary row | `employee.salary_corrected` |
| Correct latest title | `PUT /employees/{emp_no}/title/latest` | wording of the most recent title row | `employee.title_corrected` |
| Undo last change | `POST /employees/{emp_no}/undo` | reverses the rows of the last operation | `employee.change_undone` |

All routes are behind `can:manage-employees` (403 for managers and viewers) and the
controller authorizes `EmployeePolicy::create/update` again.

## Rules and decisions

- **Effective dates must lie after the start of the row they close** (at least the next day):
  a transfer after the start of the current department assignment *and* of the current
  management of that department, a title or salary change after the latest title / salary
  row, a termination after the start of every current row. This rules out primary key
  collisions (`salaries (emp_no, from_date)`, `titles (emp_no, title, from_date)`), periods
  that end before they start and empty periods.
- **No future dates.** "Current" means `to_date = 9999-01-01` everywhere in the app (directory,
  dashboard, filters, manager scope, exports), so a change scheduled for next month would be
  shown as already in effect and a future termination would make the employee a former
  employee today. Scheduled changes would need an own table and a job that applies them;
  until then all dates are today or earlier.
- **Returns to a previous department** (transfer or rehire) add a new period: `from_date` is part
  of the primary key of `dept_emp` and `dept_manager` (see *Schema*); the earlier period stays
  unchanged. The queries of the app pick the current row by
  `to_date = 9999-01-01` and the last department of a former employee by the latest `to_date`
  (ties: lowest `dept_no`), so they handle several periods in one department without changes.
  The forms mark such departments with "return - worked here before", the audit entry has
  `"returning": true`.
- **Managers.** Transferring the current manager of a department ends that management on the
  transfer date (the form warns about it, the flash message and the audit entry
  (`ended_management`) say so); the department has no current manager until one is assigned.
  Managing *another* department is not affected by a transfer. A termination ends every
  current management of the employee. These go through the same code as *End management* on
  the department page (`DepartmentManagementService::endPeriods()`), so the department's
  history gets the same `department.manager_ended` entry, with `cause` set to the operation.
- **Several current departments** (old data): a transfer and a termination close all of them.
- **Rehire** needs a former employee and a start date after the end of the last employment.
  Stray current title, salary or management rows of the former employee (old data) are closed
  on the end of the last employment and listed in the audit entry (`closed_stray_records`).
  The original `hire_date` is kept (it is the first hire); the gap stays visible in the history.
- **Missing current title or salary** (old data): a title or salary change simply opens the
  row (a salary then only as an amount); the earliest date is the day after the latest
  title / salary row, or the hire date.
- **Unusual salary changes.** A change by percent is limited to -50 % to +100 %. A new amount
  outside that range of the current salary - and a salary correction outside that range of the
  recorded amount - needs the box *I confirm an unusual change* (logged as
  `"confirmed_unusual": true`).
- **Personal data**: names are limited to the column sizes (14 / 16 characters), titles to 50.
  The hire date may not be after the first history row and the age on the hire date must be
  16 to 80 - both checked only when the birth or hire date is changed, so a name correction is
  never blocked by quirks of old data. The form carries a fingerprint of the data it showed
  (`snapshot`); if someone else changed the employee meanwhile the edit is rejected
  (optimistic locking).
- **Double hires.** The hire form has a one-time token: a second submit of the same form
  (double click, reload) does not hire again but leads to the employee hired with it. While a
  submit is running the token is "pending" for at most two minutes, so a request that died
  half way does not block the form for long. An
  existing employee with the same first name, last name and birth date is reported and needs
  the explicit confirmation *This is a different person - hire anyway*; the check reads the index
  `(birth_date, last_name, first_name)` of `employees`.
- **Corrections** fix typos in the amount of the most recent salary row and the wording of the
  most recent title row of a **current** employee (the history of former employees is closed;
  rehire them or undo instead). Dates are not changed; a reason is required and logged.
- **Stale forms.** Corrections post the row the form showed (`row_from_date`, `row_salary` /
  `row_title`) and a change in percent the salary it was entered for (`shown_salary`). If the
  locked latest row differs at submit time the form is refused: "The data changed in the
  meantime". Personal data does the same with its `snapshot`.
- **Salaries and HR rights.** `manage-employees` does not imply `view-salaries`: without it the
  profile gets no figures and no salary forms, and the salary routes answer 403.
- **Undo last change.** The most recent HR operation on an employee - except a hire - can be
  reversed exactly, within 24 hours and only while nothing else changed that employee's records:
  - the audit entry of every operation keeps its row changes (closed rows, inserted rows,
    changed values) and a fingerprint of all of the employee's active rows afterwards
    (`changes._undo`, not shown in the UI);
  - the undo checks that the entry is still the employee's latest `employee.*` entry, not
    older than 24 h, and that the fingerprint still matches (any later change - also one by
    another screen such as a department manager assignment - blocks the undo);
  - **nothing is deleted**: the rows the operation opened get `void_log_id` = the id of the
    undo's audit entry (who, when and why come from that entry), the rows it closed are
    re-opened with an `UPDATE` of `to_date`, changed values are restored. Each step must hit
    exactly one row, all in one transaction;
  - it is logged as `employee.change_undone` with `undone_entry_id` / `undone_action` and every
    row it touched (`voided_rows` with their values, `reopened_rows` with the previous end date,
    `restored_values`). The original entry stays untouched. Only one level: the undo entry is
    now the latest one, and it cannot be undone itself, so neither the undo nor the operation
    before it can be reversed;
  - a management the operation ended is reopened (`department.manager_restored`), unless
    another employee has managed the department since: then the undo is refused, the
    department would have two current managers;
  - all cached figures are flushed, also the departments overview: an undo removes rows that
    any of them may contain;
  - voided rows are hidden everywhere (see *Voided rows*) and stay visible through the audit log:
    the undo entry lists them, and the original entry shows that it was undone, by whom and when;
  - **a hire cannot be undone** (its entry has no `_undo`, the service refuses it and the profile
    offers no undo right after a hire): a wrongly entered person is handled by editing the
    personal data or by terminating the employment. Entries written without `_undo` cannot be
    undone either.
- **Employee numbers** are the next free number, the same way for a hire and an import:
  `EmployeeNumberAllocator::next()` reads the highest number with a locking read (`ORDER BY emp_no
  DESC LIMIT 1 FOR UPDATE`, a next-key lock that makes concurrent allocations wait), tested with
  two real connections. `EmployeeNumberAllocator::transaction()` repeats the whole transaction up
  to 5 times with a short random pause when the number was taken meanwhile by a writer that does
  not use the lock, and - when it is not part of an outer transaction - on a deadlock (1213) or
  lock wait timeout (1205).

## Schema

- `dept_emp`, `dept_manager`, `titles`, `salaries` have the column `void_log_id` (`BIGINT UNSIGNED
  NOT NULL DEFAULT 0`: 0 = active row, otherwise the `activity_log.id` of the undo that voided it)
  and it is part of the primary key, so a voided row never collides with a corrected row of the
  same date while active rows stay unique:

  | Table | Primary key |
  |-------|-------------|
  | `dept_emp`, `dept_manager` | `(emp_no, dept_no, from_date, void_log_id)` |
  | `titles` | `(emp_no, title, from_date, void_log_id)` |
  | `salaries` | `(emp_no, from_date, void_log_id)` |

  Migration `add_void_log_id_to_history_tables` reads the current key from `information_schema`,
  changes only what is missing and runs one `ALTER TABLE ... ALGORITHM=INPLACE, LOCK=NONE` per
  table (online: the table is rebuilt while reads and writes continue). The foreign keys on
  `emp_no` keep an `emp_no`-leading index (the primary key), the one on `dept_no` its `dept_no`
  index. InnoDB secondary indexes contain the primary key columns, so the directory indexes
  stay usable as covering indexes with the `void_log_id = 0` condition and none of them needed
  the column appended (checked with EXPLAIN on the full dataset). The one exception is
  `SELECT DISTINCT title`, which lost its loose index scan; `titles (title, void_log_id)`
  (migration `add_active_title_index_to_titles`) brings it back. `down()` refuses the whole rollback if any
  table has voided rows (the column is their only mark); otherwise it drops the column and
  restores the old key (`dept_emp` / `dept_manager` keep `from_date` if an employee has several
  periods in one department). The migration needs MySQL.
- `employees`: index `(birth_date, last_name, first_name)` for the duplicate check of the hire
  form (migration `add_duplicate_check_index_to_employees`, idempotent, built online).
- `activity_log`: virtual generated column `action_group` (`employee` for `employee.hired`)
  with the index `(action_group, created_at)`, and the index `(user_id, created_at)`
  (migration `add_filter_indexes_to_activity_log`).

**Never truncate `activity_log`.** `void_log_id` holds audit entry ids: after a truncate the ids
are handed out again and voided rows would point at unrelated entries.

## Voided rows

`dept_emp`, `dept_manager`, `titles` and `salaries` can hold rows that do not exist for the
application. **Every query over these tables excludes them** with `App\Support\HistoryRows`
(`HistoryRows::active($query, 'alias')`, `HistoryRows::sql('alias')` for raw SQL); the models
`DeptEmp`, `DeptManager`, `Title`, `Salary` do it with a global scope, the relations of `Employee`
and `Department` with `wherePivot`. Voided rows play no part in the rules of the HR operations
(a new row may start on the date of a voided one). `VoidedRowsTest` checks list, filters, sorts,
counts, profile, charts, PDF, CSV, dashboard, manager scope, departments, reports, API, exports
and import. `VoidedRowsGuardTest` scans
`app/` and fails for a reference to these tables without its own filter, for a `DELETE` or
`TRUNCATE` on them and for `withoutGlobalScope(s)` / `newQueryWithoutScopes`.
`VoidedRowsRuntimeGuardTest` runs the read paths with `DB::listen` and fails for SQL that touches
these tables without `void_log_id`; a new screen adds its path to `readPaths()` there.

## Audit entries (`changes`)

Values are stored as written (department numbers, `Y-m-d` dates, amounts as integers):

```json
employee.hired          {"after": {"first_name": ..., "last_name": ..., "gender": ..., "birth_date": ..., "hire_date": ..., "dept_no": "d005", "title": "Engineer", "salary": 65000}}
employee.updated        {"before": {"last_name": "Old"}, "after": {"last_name": "New"}}
employee.transferred    {"before": {"dept_no": "d004"}, "after": {"dept_no": "d005"}, "effective_date": "2024-05-01", "returning": true, "ended_management": ["d004"]}
employee.promoted       {"before": {"title": "Engineer"}, "after": {"title": "Senior Engineer"}, "effective_date": "2024-01-01"}
employee.salary_changed {"before": {"salary": 60000}, "after": {"salary": 63000}, "effective_date": "2024-01-01", "change_percent": 5, "requested_percent": 5, "confirmed_unusual": true}
employee.terminated     {"before": {"dept_no": "d005", "title": "Engineer", "salary": 63000}, "end_date": "2024-09-30", "reason": "...", "ended_management": [...]}
employee.rehired        {"after": {"dept_no": "d007", "title": "Staff", "salary": 50000}, "effective_date": "2025-01-01", "previous_end_date": "2024-09-30", "returning": true, "closed_stray_records": ["dept_manager"], "ended_management": ["d007"]}
employee.salary_corrected / employee.title_corrected
                        {"before": {...}, "after": {...}, "from_date": "...", "to_date": "...", "reason": "..."}
employee.change_undone  {"undone_entry_id": 123, "undone_action": "employee.transferred", "undone_at": "...", "before": {...}, "after": {...},
                         "voided_rows": [{"table": "dept_emp", "dept_no": "d005", "from_date": "...", "to_date": "9999-01-01"}],
                         "reopened_rows": [{"table": "dept_emp", "dept_no": "d004", "from_date": "...", "previous_to_date": "..."}],
                         "restored_values": [{"table": "salaries", "from_date": "...", "replaced": {"salary": 61000}, "restored": {"salary": 60000}}]}
```

Optional keys (`ended_management`, `reason`, `requested_percent`, ...) are left out when empty.
Every operation's entry except `employee.hired` and `employee.change_undone` also has `_undo`
(`ops`: the row changes, `state`: the fingerprint).

## Audit log screen (`/audit-log`)

Newest first, 25 entries per page, filters: action (exact, or `employee.*` for all actions of a
group), subject type and id, user (or "no user" for system entries / failed sign-ins) and a
date range (`from` / `to`, inclusive, 1970-01-01 to 9999-12-30). Invalid filter values are
ignored. The `changes` are shown as *field: before → after* rows (departments with their name,
amounts with separators, dates as `d.m.Y`); employees link to their profile, users to the Users
screen (administrators), departments to `departments.show` once that route exists. Names and
values are escaped like all Blade output. An undone entry shows when and by whom it was undone;
the undo entry lists the voided, reopened and restored records.

Sign-in entries (`auth.*`: e-mail addresses of failed sign-ins, IP addresses) are account
security data and only listed for administrators (`manage-users`).

It stays fast on large logs:

- each filter maps onto one index: `created_at`, `(action, created_at)`,
  `(action_group, created_at)` for `employee.*` (read in index order, no sort of all matches),
  `(subject_type, subject_id, created_at)` (a subject id without a type is searched with the
  list of known types, so this index is still used) and `(user_id, created_at)`;
- cursor pagination - no `COUNT(*)` over the whole log and no `OFFSET`: the next page starts after the `(created_at, id)` of the last entry shown, so page 2000 costs the same as page 1;
- the action and subject type lists of the filters are cached for 5 minutes (a new action
  appears in the list after at most that time);
- the profile's **Change history** reads the latest 10 entries of the employee with the subject
  index and links to the filtered audit log.

Salary figures in audit entries (keys with `salary` / `percent`, at any depth) are only rendered
for users with `view-salaries` (`AuditLogPresenter`), should the audit log ever be opened to
other roles.
