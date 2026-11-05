# Authorization

Everything except the login form requires a signed-in, **active** user. This page is the
contract for code that adds routes, screens, reports or APIs.

## Building blocks

| What | Where |
|------|-------|
| Roles (`admin`, `hr`, `manager`, `viewer`) | `App\Enums\Role` (`users.role`) |
| Permission matrix (Gate names → roles) | `App\Support\Ability::MATRIX` |
| Gate registration, policies | `App\Providers\AuthorizationServiceProvider` |
| Which employees / columns a user may see | `App\Support\EmployeeVisibility` |
| Single employee | `App\Policies\EmployeePolicy` (`view` uses the visibility) |
| Signed-in + active check | `auth`, `auth.session`, `active` middleware (`routes/web.php`; Livewire updates in `AppServiceProvider`) |

Inactive accounts (`users.is_active = false`) cannot sign in, are signed out on their next
request and have no ability at all.

## Gates

Use the constants in `App\Support\Ability` (or the string names in Blade / route middleware).

| Gate | admin | hr | manager | viewer | Guards |
|------|:-----:|:--:|:-------:|:------:|--------|
| `view-salaries` | ✓ | ✓ | ✓ | – | every salary figure (see below) |
| `manage-employees` | ✓ | ✓ | – | – | creating / editing / deleting employees (`EmployeePolicy::create/update/delete`) |
| `manage-departments` | ✓ | ✓ | – | – | creating / editing departments and department managers |
| `manage-users` | ✓ | – | – | – | Users screen (`/users`) |
| `view-audit-log` | ✓ | ✓ | – | – | audit log |
| `export-data` | ✓ | ✓ | ✓ | – | bulk CSV / PDF export of the directory (`POST /employees/export`) |
| `import-data` | ✓ | ✓ | – | – | data import |
| `view-reports` | ✓ | ✓ | ✓ | – | reports |

```php
Route::get('/reports', ...)->middleware('can:'.Ability::VIEW_REPORTS);   // route
Gate::authorize(Ability::MANAGE_EMPLOYEES);                              // controller / Livewire action
@can('export-data') ... @endcan                                          // Blade
Ability::check($user, Ability::VIEW_SALARIES);                           // any user, e.g. in a job
```

The single-employee PDF (`/employees/{emp_no}/export-pdf`) is the printable version of the
profile, not a bulk export: everyone who may open the profile can download it, with the same
salary rules. Viewers therefore have no bulk export, but can print a profile without salaries.

Rules for new code:

- Every new route goes inside the authenticated group in `routes/web.php`.
- Every mutating action (controller method **and** Livewire action) checks its Gate/policy on
  the server. Hiding a button is not enough. Livewire components can be called directly.
- New abilities are added to `Ability::MATRIX` (and to the table above and
  `tests/Feature/Auth/AuthorizationTest.php`). No role checks (`$user->role === ...`)
  scattered in controllers or views.

## Salaries (`view-salaries`)

Without `view-salaries` (viewers) no salary data leaves the server. Removing it is done
server-side, not by hiding it in the view:

- directory: no salary column, salary range filter and salary sort are dropped
  (`EmployeeVisibility::filters()`, `EmployeeList::sortByField()`);
- profile and profile PDF: no current salary, salary history, earnings, average or chart;
  the history table lists title periods instead (`EmployeeProfileService::build($employee, false)`
  does not query `salaries` at all);
- bulk export: salary and earnings columns are left out (`EmployeeExportService::csv/pdf($query, false)`);
- dashboard: no average salary.

New features that show salary data must check `EmployeeVisibility::canSeeSalaries()` (or the Gate).

## Manager scope (which employees)

A manager (`role = manager`, `users.dept_no`) only sees the employees of their department:

- current employees of the department, and
- former employees whose **last** department it was (latest `to_date`, ties: lowest `dept_no`).

That is exactly the directory's department filter
(`EmployeeSearchQuery::departmentMembership()`). A manager without a department sees nobody.
All other roles see every employee.

It is applied in one place, `App\Support\EmployeeVisibility`, used by the directory list and
filters, profile + profile PDF (403 outside the scope), bulk export (ids outside the scope are
dropped) and the dashboard (figures of the manager's department).

```php
use App\Support\EmployeeVisibility;

$visibility = EmployeeVisibility::for($request->user());   // or EmployeeVisibility::current()

// 1. Directory-style search (filters, sorting, paging): scope and salary rules applied.
$query = $visibility->query($request->only(['search', 'department', 'sortBy', /* ... */]));
$page = $query->paginate(20, $page);

// 2. Any other query over employees - reports, API, statistics:
$rows = $visibility->constrain(
    DB::table('employees as e')->join('salaries as s', 's.emp_no', '=', 'e.emp_no'),
    'e.emp_no'                         // the employee number column of that query
)->get();
$visibility->constrain(Employee::query())->count();   // Eloquent works too (column defaults to emp_no)

// 3. A single employee:
$this->authorize('view', $employee);                  // EmployeePolicy::view
$visibility->canView($empNo);

// 4. Columns:
if ($visibility->canSeeSalaries()) { /* add salary columns */ }

// Console commands / scheduled jobs that are not acting for a user:
EmployeeVisibility::unrestricted();
```

The scope constraint is a semi-join on `dept_emp` (indexed by `dept_no, to_date`), so it stays
fast on the full dataset (a manager's directory renders in about the same time as the
department filter).

## Accounts

- No public registration and no e-mail password reset: administrators create accounts
  (`/users`) and reset passwords. Users change their own name and password under *My account*.
- Administrators cannot delete, deactivate or change the role of their own account, and there is
  always at least one active administrator (`App\Services\UserManagementService`).
- Changing or resetting a password signs out the user's other sessions and invalidates
  "remember me" cookies (`auth.session` middleware, new remember token).
- Login is rate limited: 5 failed attempts per e-mail + IP per minute.
