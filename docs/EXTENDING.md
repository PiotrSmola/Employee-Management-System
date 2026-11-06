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
| `main/40-reports` | Reports (`view-reports`) |
| `main/90-users` | Users (admins) |
| `user/10-account` | My account |
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
  `department.manager_assigned`, `data.imported`, ... Existing ones: `user.created`,
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
  `(subject_type, subject_id, created_at)`, `(action, created_at)`. Entries are never updated.
- The audit log screen is behind `Ability::VIEW_AUDIT_LOG`.

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
`EmployeeDataCache::keys()`, so `flush()` keeps clearing everything.

Reports (`App\Reports`) are cached per figure, department scope and as-of date under a
versioned namespace instead of single keys: `reports:<version>:<figure>:<scope>:<as-of>`
(`EmployeeDataCache::report()`, built by `App\Reports\ReportCache::key()`; scope `all`,
`dept-<no>` or `mgr-<no>`, as-of `current-<today>` or `Y-m-d`). `flush()` forgets
`reports:version` (it is part of `keys()`), which retires every cached report at once;
`EmployeeDataCache::flushReports()` does only that. Writers therefore need nothing extra.
`php artisan reports:warm` (scheduled hourly in `routes/console.php`) recomputes the current
state of the company, of every department and of every manager scope after a flush; options
`--as-of=Y-m-d`, `--department=d005` (repeatable), `--fresh` (recompute cached figures too).
Report code reads `dept_emp`, `titles` and `salaries` only through `App\Reports\ReportSources`,
so a condition for all history rows belongs in `ReportSources::rowFilter()`.

## Tests

- Use factories, never the real dataset. `RefreshDatabase` runs against `ems_<ws>_test`.
- `Tests\Concerns\ActsAsAdmin`: the test runs as an active admin (`$this->user`);
  `$this->signInAs(Role::Hr)` / `signInAs(Role::Manager, $department)` switch roles.
- `User::factory()->admin()|hr()|viewer()|manager($department)|inactive()`.
- `Employee::factory()->current($department, 'Engineer', 50000)->create()`,
  `->former($department, 'Staff', endDate: '1999-06-30')`, `previousDepartment:` /
  `previousTitle:` for a history; `Department::factory()`.
- `Tests\Concerns\BuildsDirectory`: a small fixed directory for query/list/export tests.
- `Tests\Concerns\BuildsReportData`: five employees with an exactly known history
  (transfer, leaver, salary changes) for report figures.
- New abilities go into `tests/Feature/Auth/AuthorizationTest.php`; test every new
  route for an allowed and a forbidden role.
