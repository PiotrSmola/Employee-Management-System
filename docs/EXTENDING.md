# Extending the application

How to add a feature without editing shared files. Authorization rules are in
[AUTHORIZATION.md](AUTHORIZATION.md); this page is the practical checklist.

## Routes: `routes/web/<feature>.php`

`routes/web.php` only holds the login routes and the authenticated group
(`auth`, `auth.session`, `active`). Every file in `routes/web/` is required inside
that group, in file name order. A feature adds its own file:

```php
<?php
// routes/web/reports.php

use App\Http\Controllers\ReportController;
use App\Support\Ability;
use Illuminate\Support\Facades\Route;

Route::middleware('can:'.Ability::VIEW_REPORTS)->prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
});
```

- Do not wrap the file in another `auth` group and do not add routes to `routes/web.php`.
  `tests/Feature/Http/RouteAndNavigationTest.php` fails for any `App\` route outside the group.
- `/employees/{emp_no}` only matches numbers, so `/employees/create`, `/employees/import`, ...
  can live in another file. Use the same `->whereNumber(...)` for your own numeric parameters.
- API routes (Sanctum) are not web routes: register `routes/api.php` in `bootstrap/app.php`.

## Navbar items: `resources/views/layouts/nav/{main,user}/<NN>-<feature>.blade.php`

The navbar includes every partial of `nav/main` (links in the bar) and `nav/user`
(entries of the user dropdown, between the account header and *Log out*) in file name
order. Add a file instead of editing `navbar.blade.php`. Existing slots:

| File | Item |
|------|------|
| `main/10-dashboard` | Dashboard |
| `main/20-employees` | All Employees, Export Data |
| `main/30-departments` | Departments |
| `main/90-users` | Users (admins) |
| `user/10-account` | My account |
| `user/60-audit-log` | Audit log (admins, HR) |
| `user/90-users` | Users (admins) |

Suggested numbers: departments `30`, reports `40`, data tools `50`, audit log `60`
(main or user dropdown). A partial renders complete `<li>` elements and checks its own
ability - match the existing markup so dark mode and the mobile menu keep working:

```blade
{{-- resources/views/layouts/nav/main/40-reports.blade.php --}}
@can('view-reports')
    <li class="nav-item">
        <a class="nav-link orange-after" href="{{ route('reports.index') }}">
            <i class="bi bi-bar-chart me-1"></i>
            {{ __('Reports') }}
        </a>
    </li>
@endcan
```

Dropdown entries use `<li><a class="dropdown-item" href="..."><i class="bi bi-... me-2"></i>...</a></li>`.
Pages have no shared layout: each view includes `layouts.head` and `layouts.navbar` itself
(copy `resources/views/account/edit.blade.php` as a starting point).

## Authorization

Abilities live in `App\Support\Ability` (see AUTHORIZATION.md for the matrix). Check them on
the server for every route **and** every mutating controller / Livewire action:

```php
Route::post(...)->middleware('can:'.Ability::MANAGE_EMPLOYEES);   // route
Gate::authorize(Ability::MANAGE_DEPARTMENTS);                     // controller / Livewire action
@can('import-data') ... @endcan                                   // Blade
Ability::check($user, Ability::EXPORT_DATA);                      // any user, e.g. in a queued job
```

Any query over employees goes through `EmployeeVisibility`, which applies the manager's
department scope and the salary rule:

```php
use App\Support\EmployeeVisibility;

$visibility = EmployeeVisibility::for($request->user());   // in a job: for($user) of the requester

$rows = $visibility->constrain(
    DB::table('employees as e')->join('dept_emp as de', 'de.emp_no', '=', 'e.emp_no'),
    'e.emp_no',
)->get();

if ($visibility->canSeeSalaries()) { /* salary columns */ }
$this->authorize('view', $employee);                       // a single employee
```

`EmployeeVisibility::unrestricted()` is only for system code that acts for nobody.

## Audit log: `App\Services\AuditLogger`

Every change to employees, departments, users and every import should be logged:

```php
use App\Models\ActivityLog;
use App\Services\AuditLogger;

// created: the new values
AuditLogger::log('employee.hired', ActivityLog::SUBJECT_EMPLOYEE, $employee->emp_no, [
    'after' => AuditLogger::values($employee->only(['first_name', 'last_name', 'hire_date'])),
]);

// changed: only the differing fields, as {"before": {...}, "after": {...}}
$before = $employee->only($fields);
// ... save ...
$changes = AuditLogger::diff($before, $employee->only($fields));
if ($changes !== []) {
    AuditLogger::log('employee.updated', ActivityLog::SUBJECT_EMPLOYEE, $employee->emp_no, $changes);
}

// any payload
AuditLogger::log('department.manager_assigned', ActivityLog::SUBJECT_DEPARTMENT, 'd005', ['emp_no' => 10001]);
```

Signature: `AuditLogger::log(string $action, string $subjectType, string|int|null $subjectId, array $changes = [], ?User $user = null): ActivityLog`.

- `action` is `<subject>.<verb>` in snake case: `employee.hired`, `employee.transferred`,
  `employee.promoted`, `employee.salary_changed`, `employee.terminated`, `department.created`,
  `department.manager_assigned`, `data.imported`, ... HR operations write `employee.hired`,
  `employee.updated`, `employee.transferred`, `employee.promoted`, `employee.demoted`,
  `employee.title_changed`, `employee.salary_changed`, `employee.terminated`,
  `employee.rehired`, `employee.salary_corrected`, `employee.title_corrected`,
  `employee.change_undone` (payloads in [HR_OPERATIONS.md](HR_OPERATIONS.md); their `_undo`
  key is internal data of the undo, keys starting with `_` are never displayed). Existing ones: `user.created`,
  `user.updated`, `user.role_changed`, `user.password_reset`, `user.password_changed`,
  `user.deleted`, `auth.login`, `auth.login_failed` (`changes`: `email`, `reason`).
- `subjectType` is a short alias (`ActivityLog::SUBJECT_EMPLOYEE|DEPARTMENT|USER`; add a
  constant for a new one), `subjectId` the emp_no / dept_no / user id (stored as a string).
- The acting user defaults to the signed-in user (pass it explicitly in queued jobs), the IP to
  the request's (null on the console). Keys such as `password`, `password_confirmation`,
  `current_password`, `remember_token`, `token` are dropped from `changes` at any depth.
- `diff()` and `values()` store enums by value and dates as `Y-m-d` (or `Y-m-d H:i:s`);
  `diff()` compares scalars as strings, so `5` and `"5"` are equal.
- Log inside the same DB transaction as the change. MySQL JSON columns do not keep key
  order, so compare `changes` with `assertEquals` in tests.
- Reading: `ActivityLog::forSubject('employee', 10001)->latestFirst()->paginate()`, relation
  `user` (null when the account was deleted). Indexes: `created_at`,
  `(subject_type, subject_id, created_at)`, `(action, created_at)`, `(action_group, created_at)`
  (`action_group` = the part before the dot, a generated column), `(user_id, created_at)`.
  Entries are never updated.
- The audit log screen (`/audit-log`, route `audit-log.index`) is behind `Ability::VIEW_AUDIT_LOG`.
  It renders any `changes` payload (`App\Support\AuditLogPresenter`): `before`/`after` pairs
  as *before → after*, other keys as values, `dept_no` with the department name. Departments link to
  `route('departments.show', $dept_no)` as soon as that route exists.

## Reading the history tables: `App\Support\HistoryRows`

`dept_emp`, `dept_manager`, `titles` and `salaries` can hold **voided rows**: undoing an HR
operation never deletes anything, it sets `void_log_id` on the rows the operation added
(0 = active row). Voided rows do not exist for the application, so **every query over these
four tables must exclude them**:

```php
use App\Support\HistoryRows;

HistoryRows::active(DB::table('salaries'))->where('emp_no', $empNo)->get();
HistoryRows::active(DB::table('titles as t'), 't')->...;                                // alias
HistoryRows::active($query->from('dept_emp as de'), 'de');                              // sub-query
->leftJoin('titles as t', fn ($join) => HistoryRows::active($join->on('t.emp_no', '=', 'e.emp_no'), 't'))
DB::raw('(SELECT ... FROM salaries s WHERE s.emp_no = e.emp_no AND '.HistoryRows::sql('s').')')
->belongsToMany(...)->wherePivot(HistoryRows::COLUMN, HistoryRows::ACTIVE)
```

The models `Salary`, `Title`, `DeptEmp` and `DeptManager` apply it with a global scope, and
`EmployeeSearchQuery::departmentMembership()` applies it itself. Never `DELETE` from these
tables, never use `withoutGlobalScope(s)` on these models, and never truncate `activity_log`
(`void_log_id` holds its ids). `tests/Feature/HrOperations/VoidedRowsGuardTest.php` fails for
code under `app/` that reads them without the filter (a reasoned exception goes into its
allowlist). `VoidedRowsRuntimeGuardTest` checks the SQL of the read paths: **add your new
screen or report to its `readPaths()`**.

## Changing employees: `App\Services\EmployeeOperationsService`

Hire, transfer, title / salary changes, termination and rehire go through this service (it
keeps the history shape, locks the employee, audits and flushes the cache). `dept_emp` and
`dept_manager` have `from_date` in the primary key: an employee can have several periods in one
department - pick the current one by `to_date = 9999-01-01` and the last one by
the latest `to_date`, never by `(emp_no, dept_no)` alone. Code that inserts
employees itself (e.g. an import) takes the number from `EmployeeNumberAllocator::next()`
inside its transaction. See [HR_OPERATIONS.md](HR_OPERATIONS.md).

## Cached figures: `App\Support\EmployeeDataCache`

The dashboard (company totals, employees per department, a manager's department totals and
titles) and a manager's job title filter are cached for 15-30 minutes. After changing
employees, departments, titles, salaries or department assignments call:

```php
EmployeeDataCache::flush();                     // everything
EmployeeDataCache::flush(['d001', 'd005']);     // company-wide keys + only these departments (e.g. a transfer)
```

Keys: `dashboard_stats`, `employees_by_department`, `dashboard_stats:dept:<no>`,
`employees_by_title:dept:<no>`, `job_titles:dept:<no>` (`-` = manager without a department).
New cached figures derived from employee data get a key method there and are added to
`EmployeeDataCache::keys()`, so `flush()` keeps clearing everything. Figures that hold department
names are also cleared in `EmployeeDataCache::flushDepartmentNames()`, which runs on a rename.

### Figures that are expensive to compute: `App\Support\FigureCache`

The department figures take far longer to compute than a request should wait, so they use
`FigureCache` instead of `Cache::remember()`: `departments_overview` and
`department_details:dept:<no>`.

```php
['value' => $figures, 'status' => $status] = FigureCache::read($key, $ttl, $compute, $refreshLater);
FigureCache::invalidate($key);
FigureCache::refresh($key, $ttl, $compute);
```

- `status` is `FRESH`; `STALE`, the last good value, with `$refreshLater` called to dispatch a
  refresh after the response; or `PREPARING`, value null: nothing cached yet and another process
  is computing it. A reader waits two seconds for that process, then the page says so and reloads.
- One compute per key at a time (`Cache::lock`, so the cache store must support locks).
- `invalidate()` changes the generation in the key: a compute already running cannot make the
  value fresh again. The stale copy stays for a week.
- `php artisan departments:warm` recomputes all of them, hourly in `routes/console.php`. That
  needs the scheduler (`php artisan schedule:run` every minute); without it readers still get
  the stale copy and the refresh runs after their response.
- A full `EmployeeDataCache::flush()` invalidates the overview and every department;
  `flush(['d005'])` only those departments. The overview covers all departments and expires
  after 15 minutes, so it is not recomputed for each change of one employee.
- Cached payloads are shared between users: apply salary and scope rules when reading, as
  `DepartmentStatistics` does, never before caching.

## Departments and department managers

- Changes go through `App\Services\DepartmentManagementService` (`create`, `rename`, `delete`,
  `assignManager($department, $empNo, 'Y-m-d')`, `endManagement($department, 'Y-m-d')`): they
  validate, log `department.created|renamed|deleted|manager_assigned|manager_ended`. Callers check
  `manage-departments`. Managers and department names are read live, so changing them leaves
  the department figures cached.
- The current manager has `to_date = 9999-01-01`. A change of manager on day D ends the old
  period on D and starts the new one on D; a new period never starts before the department's
  last ended period. Rows are picked by their dates, not by `(emp_no, dept_no)`. While that pair is
  `dept_manager`'s primary key, a former manager cannot manage the same department again; the
  rule is in `DepartmentManagementService::reappointmentBlockingPeriod()` (return null once the key
  includes `from_date`).
- Code that moves a current department manager out of the department or terminates them
  should end the management too: `endManagement($department, $date)` (the date must be after
  the period's start).
- Read-only figures: `App\Services\DepartmentStatistics` (`overview()`, `details()`,
  `currentManagers()`, `managerHistory()`, `forEmployee()`). `overview()` and `details()` take an
  `EmployeeVisibility`: salary figures only with `view-salaries`, and employees outside a scoped
  user's view come back with `hidden` set and no name or number.
- Headcounts count current `dept_emp` rows (assignments), not distinct employees; the dataset has
  one per employee. Headcount by year runs to the last year with data for every department; the
  imported dataset ends in 2002.
- The directory accepts an initial department: `route('employees.index', ['department' => 'd005'])`
  (the manager scope still wins).

## Tests

- Use factories, never the real dataset. `RefreshDatabase` runs against `ems_<ws>_test`.
- `Tests\Concerns\ActsAsAdmin`: the test runs as an active admin (`$this->user`);
  `$this->signInAs(Role::Hr)` / `signInAs(Role::Manager, $department)` switch roles.
- `User::factory()->admin()|hr()|viewer()|manager($department)|inactive()`.
- `Employee::factory()->current($department, 'Engineer', 50000)->create()`,
  `->former($department, 'Staff', endDate: '1999-06-30')`, `previousDepartment:` /
  `previousTitle:` for a history; `Department::factory()`.
- `Tests\Concerns\BuildsDirectory`: a small fixed directory for query/list/export tests.
- New abilities go into `tests/Feature/Auth/AuthorizationTest.php`; test every new
  route for an allowed and a forbidden role.
