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
next one starts. Every operation keeps that shape and never deletes a row:

- a change closes the current row (`to_date` = effective date) and inserts a new one
  (`from_date` = effective date, `to_date` = 9999-01-01);
- every operation runs in one transaction that first locks the employee row
  (`SELECT ... FOR UPDATE`), so two people acting on the same employee are serialized and
  the date checks see the committed state; the audit entry is written in the same transaction;
- after the commit the cached dashboard / filter figures of the affected departments are
  cleared (`EmployeeDataCache::flush([...])`).

| Operation | Route | Writes | Audit action |
|-----------|-------|--------|--------------|
| Hire | `GET /employees/create`, `POST /employees` | `employees` + `dept_emp`, `titles`, `salaries` from the hire date | `employee.hired` |
| Personal data | `PUT /employees/{emp_no}` | `employees` (name, gender, birth date, hire date) | `employee.updated` (diff) |
| Transfer | `POST /employees/{emp_no}/transfer` | closes `dept_emp` (and the management of the department being left), opens the new `dept_emp` | `employee.transferred` |
| Title change | `POST /employees/{emp_no}/title` | closes / opens `titles` | `employee.promoted`, `employee.demoted` or `employee.title_changed` (chosen in the form) |
| Salary change | `POST /employees/{emp_no}/salary` | closes / opens `salaries`; new amount or a change in % (-50 to +100) | `employee.salary_changed` |
| Termination | `POST /employees/{emp_no}/terminate` | closes every current `dept_emp`, `titles`, `salaries`, `dept_manager` row; optional reason | `employee.terminated` (reason in `changes`) |
| Rehire | `POST /employees/{emp_no}/rehire` | new `dept_emp`, `titles`, `salaries` rows for a former employee | `employee.rehired` |
| Correct latest salary | `PUT /employees/{emp_no}/salary/latest` | amount of the most recent salary row | `employee.salary_corrected` |
| Correct latest title | `PUT /employees/{emp_no}/title/latest` | wording of the most recent title row | `employee.title_corrected` |

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
- **No return to a previous department.** `dept_emp` (like `dept_manager`) has the primary key
  `(emp_no, dept_no)`: it can hold only one period per employee and department. Re-opening
  the old row would make it cover the time spent elsewhere (or the time between two
  employments), i.e. rewrite history, so a transfer or rehire into a department the employee
  already belonged to is refused with an explanation; such departments are disabled in the
  forms. Allowing it needs a schema change, e.g. the primary key
  `(emp_no, dept_no, from_date)` (an online `ALTER TABLE` on ~330k rows; every query of the app
  filters by `to_date`, so readers would not need changes).
- **Managers.** Transferring the current manager of a department ends that management on the
  transfer date (the form warns about it, the flash message and the audit entry
  (`ended_management`) say so); the department has no current manager until one is assigned.
  A termination ends every current management of the employee.
- **Rehire** needs a former employee (no current department, title or salary) and a start date
  after the end of the last employment. The original `hire_date` is kept (it is the first
  hire); the gap stays visible in the history.
- **Personal data**: the hire date may not be after the first history row, the age on the
  hire date must be 16 to 80, the birth date not before 1900; names are limited to the column
  sizes (14 / 16 characters), titles to 50.
- **Corrections instead of undo.** History rows are never deleted. The amount of the most
  recent salary row and the wording of the most recent title row can be corrected (for typos);
  dates are not changed, and the correction is logged with the old and new value. Anything
  else is fixed with a new, dated change.
- **Employee numbers** are the next free number: `EmployeeNumberAllocator::next()` reads the
  highest number with a locking read (`ORDER BY emp_no DESC LIMIT 1 FOR UPDATE`, a next-key lock
  that makes concurrent hires wait), and a hire is retried (3 attempts) if the number was
  taken meanwhile by a writer that does not use the allocator.

## Audit entries (`changes`)

Values are stored as written (department numbers, `Y-m-d` dates, amounts as integers):

```json
employee.hired          {"after": {"first_name": ..., "last_name": ..., "gender": ..., "birth_date": ..., "hire_date": ..., "dept_no": "d005", "title": "Engineer", "salary": 65000}}
employee.updated        {"before": {"last_name": "Old"}, "after": {"last_name": "New"}}
employee.transferred    {"before": {"dept_no": "d004"}, "after": {"dept_no": "d005"}, "effective_date": "2024-05-01", "ended_management": ["d004"]}
employee.promoted       {"before": {"title": "Engineer"}, "after": {"title": "Senior Engineer"}, "effective_date": "2024-01-01"}
employee.salary_changed {"before": {"salary": 60000}, "after": {"salary": 63000}, "effective_date": "2024-01-01", "change_percent": 5, "requested_percent": 5}
employee.terminated     {"before": {"dept_no": "d005", "title": "Engineer", "salary": 63000}, "end_date": "2024-09-30", "reason": "...", "ended_management": [...]}
employee.rehired        {"after": {"dept_no": "d007", "title": "Staff", "salary": 50000}, "effective_date": "2025-01-01", "previous_end_date": "2024-09-30"}
employee.salary_corrected / employee.title_corrected
                        {"before": {...}, "after": {...}, "from_date": "...", "to_date": "..."}
```

Optional keys (`ended_management`, `reason`, `requested_percent`, ...) are left out when empty.

## Audit log screen (`/audit-log`)

Newest first, 25 entries per page, filters: action (exact, or `employee.*` for all actions of a
subject), subject type and id, user (or "no user" for system entries / failed sign-ins) and a
date range (`from` / `to`, inclusive). Invalid filter values are ignored. The `changes` are
shown as *field: before → after* rows (departments with their name, amounts with separators,
dates as `d.m.Y`); employees link to their profile, users to the Users screen (administrators),
departments to `departments.show` once that route exists.

It stays fast on large logs: each filter maps onto one of the indexes (`created_at`,
`(action, created_at)`, `(subject_type, subject_id, created_at)`, `user_id`), a subject id
without a type is searched with the list of known types (so the subject index is still used),
and the page uses simple pagination - no `COUNT(*)` over the whole log. The profile's
**Change history** reads the latest 10 entries of the employee with the subject index and
links to the filtered audit log.

Salary figures in audit entries are only rendered for users with `view-salaries`
(`AuditLogPresenter`), should the audit log ever be opened to other roles.
