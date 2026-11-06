# Employee Management System

A web-based employee management platform built with Laravel 12 on the MySQL "employees" sample dataset (~300k employees, ~2.8M salary rows): directory, HR operations with a full audit trail, departments and managers, reports, bulk data tools and a REST API, with role-based access throughout.

## Features

### Core Functionality
- **Employee Directory**: Browse and search through employee records with pagination
- **Individual Employee Profiles**: Detailed view with employment history, salary progression, and department changes
- **Departments**: Department pages with headcount, gender split, tenure, salary figures, job titles, headcount per year and manager history; admins and HR create, rename and delete departments and assign department managers (see [Department Pages and Managers](#department-pages-and-managers))
- **Dashboard Analytics**: Company and department statistics, cached for optimal performance
- **Accounts & Roles**: Sign-in, four roles (admin, HR, manager, viewer), department-scoped managers, salary data only for authorized roles, admin user management
- **HR Operations**: Hire, personal data, transfer, title and salary changes, termination, rehire, corrections and undo of the last change (see [HR Operations and Audit Log](#hr-operations-and-audit-log))
- **Audit Log**: Every change with its before and after values, filterable, and as change history on each profile

### Advanced Features
- **Reports**: Workforce and compensation reports as charts, tables and CSV, for any department and any past month (see [Reports & Analytics](#reports--analytics))
- **Export Capabilities**: Generate PDF reports for individual employees and bulk CSV / Excel (XLSX) / PDF exports with a column chooser; large exports run in the background ("My exports")
- **Import**: New employees from CSV / XLSX with a validation preview
- **REST API**: Read-only JSON API with personal access tokens ([docs/API.md](docs/API.md))
- **Historical Tracking**: Complete employment history including department transfers, title changes, and salary adjustments
- **Status Management**: Track active vs. former employees
- **Performance**: Index-ordered list queries and precomputed figures (see [Performance Notes](#performance-notes))

### Technical Highlights
- **Responsive Design**: Mobile-friendly interface built with Bootstrap
- **Optimized Queries**: Efficient database operations with Laravel Eloquent relationships
- **PDF Generation**: Professional reports using DomPDF
- **Data Filtering**: Search, filtering and sorting through a shared query object (`App\Queries\EmployeeSearchQuery`)
- **Interactive UI**: Enhanced user experience with Livewire components

## Technology Stack

- **Framework**: Laravel 12.x
- **PHP**: 8.3+
- **Database**: MySQL 8.0+ (the directory queries use MySQL optimizer hints and the index migration builds indexes online with `ALGORITHM=INPLACE`; other databases are not supported)
- **Frontend**: Bootstrap 5.3 and Chart.js from a CDN, Blade templating
- **PDF Generation**: Laravel DomPDF
- **Spreadsheets**: OpenSpout (XLSX)
- **API**: Laravel Sanctum (personal access tokens)
- **Interactive Components**: Livewire 3.8
- **Development Tools**: Pint (code formatting)

## Requirements

- PHP 8.3 or higher
- Composer
- MySQL 8.0+
- Web server (Apache/Nginx)
- A queue worker and the scheduler for large exports and precomputed figures (the Docker setup includes both)

There is no asset build: CSS and JavaScript are plain files under `public/`.

## Quick Start with Docker (recommended)

The only requirement is [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or Docker Engine with Compose v2). PHP, Composer and MySQL run inside containers.

```bash
git clone https://github.com/PiotrSmola/Employee-Management-System.git
cd Employee-Management-System
```

**Windows (PowerShell):**
```powershell
.\docker-up.ps1
```
> If script execution is blocked, run: `powershell -ExecutionPolicy Bypass -File .\docker-up.ps1`

**Linux / macOS / Git Bash:**
```bash
./docker-up.sh
```

The script:
- picks free host ports – the app prefers `8000`, MySQL prefers `3306`; if a port is already taken it uses the next free one (it never stops anything that is already running),
- generates an `APP_KEY` and saves the ports in `.env.docker` (git-ignored, reused on the next run),
- builds the images and starts the containers,
- on the first start imports the MySQL [employees sample database](https://github.com/datacharmer/test_db) (~300k employees, ~2.8M salary rows) – this takes a few minutes,
- waits until the app responds and prints its URL.

Preferred ports can be overridden: `.\docker-up.ps1 -AppPort 8080 -DbPort 3310` or `./docker-up.sh 8080 3310`.

| Service | Container | Host port | Credentials |
|---------|-----------|-----------|-------------|
| App (Apache + PHP 8.3) | `app` | `APP_PORT` (default 8000) | – |
| Queue worker (`queue:work`, large exports) | `worker` | – | – |
| Scheduler (`schedule:work`: `reports:warm` and `departments:warm` hourly, `exports:prune` daily) | `scheduler` | – | – |
| MySQL 8.0 (InnoDB buffer pool 1 GB, `DB_BUFFER_POOL_SIZE`) | `db` | `FORWARD_DB_PORT` (default 3306) | db `employees_management`, user `laravel` / `secret`, root / `root` |

`worker` and `scheduler` use the app image (as `www-data`, without the app's entrypoint) and
share two volumes with `app`: `exports-data` (`storage/app/exports`) and `cache-data`
(`storage/framework/cache`), so that figures warmed by the scheduler reach the app, and a flush
in the app reaches them. Follow them with `docker compose --env-file .env.docker logs -f worker scheduler`.

Useful commands:
```bash
docker compose --env-file .env.docker logs -f        # follow logs
docker compose --env-file .env.docker stop           # stop containers
docker compose --env-file .env.docker down           # remove containers (data is kept)
docker compose --env-file .env.docker down -v        # remove containers AND the database volume
docker compose --env-file .env.docker exec app php artisan <command>
```

After code changes, run the start script again – it rebuilds the app image.

On start the app container runs `php artisan migrate`. On the imported dataset the
schema migrations are no-ops; the first run adds the directory indexes and rebuilds the
history tables' primary keys for `void_log_id` online (roughly 45 s and 2.5 minutes on the
full dataset, the tables stay usable meanwhile). It then runs the
`UserSeeder`, which creates the initial accounts if they do not exist yet (see
[Accounts and Roles](#accounts-and-roles)). Set `ADMIN_EMAIL` / `ADMIN_PASSWORD`
(and `SEED_DEMO_USERS=false` to skip the demo accounts) in `.env.docker` before the first start.
On the first start it also computes the department and report figures in the background: the
app is already usable, and the first visitors find them cached.

> Never run `migrate:fresh`, `migrate:refresh` or `db:wipe` against the Docker
> database – they drop the imported employees dataset.

## Accounts and Roles

Every page requires signing in (`/login`). There is no public registration and no e-mail
password reset – administrators create accounts and reset passwords under **Users**; everyone
can change their own name and password under **My account**.

| Role | Sees | Salaries | Can also |
|------|------|:--------:|----------|
| **Admin** | all employees | ✓ | manage users, employees and departments, export, import, reports, audit log |
| **HR** | all employees | ✓ | manage employees and departments, export, import, reports, audit log |
| **Manager** | employees of their own department (current, and former ones whose last department it was) | ✓ | export (own department), reports |
| **Viewer** | all employees | – | read-only, no salary data anywhere, no bulk export |

Details, the exact Gate names and how to apply the manager scope in new code:
[docs/AUTHORIZATION.md](docs/AUTHORIZATION.md).

### Default accounts

Created by `php artisan db:seed --class=UserSeeder` (also part of `php artisan db:seed` and of
every Docker start). The seeder only acts on a fresh installation and never changes or
re-creates accounts:

- the **initial administrator** is created only while no administrator account exists at all
  (a renamed or deleted admin is not brought back);
- the **demo accounts** are created only when `APP_ENV=local`, `SEED_DEMO_USERS=true` and the
  users table was empty – deleted demo accounts do not come back.

| E-mail | Password | Role |
|--------|----------|------|
| `ADMIN_EMAIL` (default `admin@example.com`) | `ADMIN_PASSWORD`; if empty: `password` when `APP_ENV=local`, otherwise a random password printed once to the console / container log (`docker compose logs app`) | Admin |
| `hr@example.com` (local only) | `password` | HR |
| `manager@example.com` (local only) | `password` | Manager of department `d005` (Development) |
| `viewer@example.com` (local only) | `password` | Viewer |

> **Change these passwords** (or delete the demo accounts) right after the first sign-in.

### Production settings

The Docker stack is a local development setup (`APP_ENV=local`, `APP_DEBUG=true`, demo
accounts). For anything reachable by other people set, e.g. in `.env.docker`:

```env
APP_ENV=production
APP_DEBUG=false
ADMIN_EMAIL=you@your-company.com
ADMIN_PASSWORD=<a long, unique password>   # or leave empty and read the generated one from the log
SEED_DEMO_USERS=false
SESSION_SECURE_COOKIE=true                 # when served over HTTPS (the session cookie is then HTTPS only)
```

Behind a reverse proxy / load balancer configure the trusted proxies
(`$middleware->trustProxies(at: [...])` in `bootstrap/app.php`), otherwise every request appears
to come from the proxy's IP address – the login rate limits are per e-mail + IP and per IP, so all
users would share one limit.

### Creating users

Sign in as an administrator → **Users** → **New user**: name, e-mail, role, department (managers
only, required for them), initial password and the active flag. Give the password to the user,
who can change it under **My account**. Deactivated users cannot sign in and are signed out
on their next request; resetting a password signs the user out everywhere. Administrators cannot
delete, deactivate or demote themselves, and the last active administrator is protected.

## HR Operations and Audit Log

Administrators and HR staff (`manage-employees`) hire employees (**Add employee** in the
directory) and, from the **HR actions** card of a profile, edit personal data, transfer
employees, change titles (promotion / demotion) and salaries (amount or %), terminate and
rehire employees, and correct a typo in the latest salary or title. History is never deleted:
a change closes the current record and starts a new one on the effective date (today or
earlier, after the start of the current record); a return to a department the employee belonged
to before adds a new period. Transferring or terminating a department manager ends the
management. Unusual salary changes (beyond -50 % / +100 %) and possible duplicate hires need an
explicit confirmation, and the most recent change of an employee (except a hire) can be undone
within 24 hours while nothing else changed.

Every change is recorded in the audit log. Administrators and HR (`view-audit-log`) see it under
**Audit log** in the user menu (filters: action, subject, user, date range) and as **Change
history** on each profile. Rules, decisions and the logged payloads:
[docs/HR_OPERATIONS.md](docs/HR_OPERATIONS.md).

## Data Tools

### Directory links
Search, filters, sort order and page of the employee directory are kept in the URL
(`/employees?search=smith&department=d005&status=current&sort=hire_date&direction=desc&page=3`),
so a filtered list can be bookmarked or shared, and *Back* returns to it (also from a profile;
every change is one browser history entry). The search box accepts names or an employee number
(digits only, optionally `#10001`: exact match on the primary key).

### Bulk export (`export-data`: admin, HR, manager)
*All Employees* → select employees (all matching, minus unticked ones, or individual ones) →
*Export Selected*: CSV, Excel (XLSX) or PDF, with the columns to include (the choice is
remembered in the browser). Salary columns are only offered - and only ever written - with
`view-salaries`; managers export their own department only.

- Up to 1,000 rows (PDF) / 20,000 rows (CSV, XLSX; `EXPORTS_QUEUE_THRESHOLD`) the file is
  downloaded directly. Larger exports are generated by the **queue worker** and listed under
  **Data tools → My exports** (status, rows, size, download, delete; the page refreshes while
  an export is running). A queued PDF holds at most 5,000 rows (`EXPORTS_QUEUED_PDF_LIMIT`, the
  PDF says so when cut off) - use CSV / XLSX for more.
- A user may have 3 unfinished exports at a time (`EXPORTS_MAX_ACTIVE_PER_USER`), and the export
  endpoint takes 10 requests per minute.
- Every user only sees their own exports. Files are kept in `storage/app/exports`; finished
  exports are deleted after 7 days (`EXPORTS_RETENTION_DAYS`) by
  `php artisan exports:prune [--days=N]` (at least 1), which the scheduler runs daily at 03:00.
  It also marks exports as failed that ran past the job's timeout or waited for more than
  24 hours (`EXPORTS_PENDING_TIMEOUT_HOURS`); unfinished exports are never deleted.
- XLSX files are written row by row with openspout (~170 MB peak for all 300k employees, most of
  it the list of employee numbers); numbers and dates are
  typed cells, text is never turned into a formula. CSV cells starting with `= + - @` are
  prefixed with `'`.
- Every export is audited (`data.exported`: format, rows, columns, filters, queued or not), and
  so are downloads from My exports (`data.export_downloaded`).

### Queue worker
`QUEUE_CONNECTION=database` in Docker; the `worker` service runs
`php artisan queue:work --tries=1 --timeout=1800`. The queue's `retry_after`
(`DB_QUEUE_RETRY_AFTER`, 1900 s in Docker) must stay above the timeout. Without Docker run
`php artisan queue:work --tries=1 --timeout=1800` and `php artisan schedule:work` (or a cron
entry for `schedule:run`) next to the web server, all with the same cache. Failed exports are shown as *Failed* with a
short reason (details in the log); a crashed or timed-out job is marked failed as well.

### Import (`import-data`: admin, HR)
**Data tools → Import employees**: download the CSV or Excel template, fill in one row per new
employee (`first_name, last_name, birth_date, gender, hire_date, department, title, salary`),
upload it. The preview lists every row with its errors (required fields, dates `YYYY-MM-DD`,
gender `M`/`F`, existing department code and job title, whole salary > 0, at least 14 years old
at the hire date, duplicates within the file or of existing employees by name + birth date).
Only a file without errors can be confirmed; then all employees are created in one
transaction with a current department, title and salary from the hire date, the next free
employee numbers (allocated under a lock), the dashboard caches are flushed and the import
is audited (`data.imported`: count, file name, employee number range). Limits: 5,000 rows
(blank ones included) and 5 MB per file (`IMPORTS_MAX_ROWS`, `IMPORTS_MAX_FILE_KB`), 100 MB
unpacked for XLSX (`IMPORTS_MAX_UNPACKED_KB`), one staged upload per user. CSV files may use `,`
or `;`, in UTF-8, UTF-16 with a byte order mark, or Windows-1250 (a Polish Excel's "CSV").

### REST API
Read-only JSON API (`/api/v1/employees`, `/api/v1/employees/{emp_no}`, `/api/v1/departments`)
with personal access tokens created under **My account → API tokens**. The API requires
`export-data`, so viewers have no access. A token has its owner's role, department scope and
salary access, and is revoked when the password or role changes or the account is deactivated;
60 requests per minute per user. See [docs/API.md](docs/API.md) for parameters, responses and
curl examples.

## Manual Installation

### 1. Clone the Repository
```bash
git clone https://github.com/PiotrSmola/Employee-Management-System.git
cd employee-management-system
```

### 2. Install Dependencies
```bash
# Install PHP dependencies
composer install

# Install Node.js dependencies (if applicable)
npm install
```

### 3. Environment Configuration
```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

### 4. Database Setup
Configure your database connection in the `.env` file:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=employees_management
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### 5. Run Migrations
```bash
php artisan migrate
```
The migrations create the dataset tables (`employees`, `departments`, `dept_emp`,
`dept_manager`, `titles`, `salaries`) with the same structure as the sample database.
If the tables already exist (e.g. the dataset was imported), they are left untouched.

### 6. Seed Accounts and Demo Data
```bash
php artisan db:seed
```
Creates the initial accounts (see [Default accounts](#default-accounts); only
`php artisan db:seed --class=UserSeeder` for just the accounts) and adds a small demo
dataset (9 departments, ~200 employees with histories) – the latter only
when the `employees` table is empty. For the full dataset import
[test_db](https://github.com/datacharmer/test_db) instead.

### 7. Start Development Server
```bash
php artisan serve
```

The application will be available at `http://localhost:8000`

## Database Structure

The system uses the following core entities:

### Employees
- Employee number (primary key)
- Personal information (name, birth date, gender)
- Hire date
- Current and historical relationships

### Departments
- Department code and name
- Employee assignments with date ranges

### Titles
- Job titles with validity periods
- Historical tracking of position changes

### Salaries
- Salary history with effective date ranges
- Current and historical compensation data

## Key Features Explained

### Employee Directory
- Paginated employee listings
- Real-time search and filtering
- Department and status-based filtering
- Quick access to employee details

### Employee Profiles
- Comprehensive employment timeline
- Department transfer history
- Salary progression tracking
- Title change documentation
- PDF export functionality

### Dashboard Analytics
- Total employee count
- Department distribution
- Average salary calculations
- Recently hired employees
- Cached data for optimal performance

### Department Pages and Managers
- **Index** (`/departments`): every department with its code, current headcount, current manager,
  number of distinct current job titles and (with `view-salaries`) the average current salary.
  Managers only see their own department; other department pages answer 403.
- **Department page** (`/departments/{dept_no}`): headcount, women/men, average years with the
  company, average/min/max salary (`view-salaries` only), job titles, headcount at the end of each
  year up to the last year with data (the imported dataset ends in 2002), recent joiners and
  leavers, current manager and the full manager history, and a link to the directory filtered to
  the department (`/employees?department=d005`). Headcounts count assignments (`dept_emp` rows). A
  manager sees the history of their department without the names of people outside their scope.
- **Managing** (`manage-departments`: admin, HR): create (code `d` + three digits, unique name),
  rename, delete (only a department that never had employees, managers or manager accounts).
  *Assign manager*: a current employee of the department (searchable) and an effective date - the
  current manager's period ends on that date, the new one starts on it; the date must be after
  the current manager's start and not before the end of the last ended period, not before the
  employee joined the department and not in the future. *End management* closes the current period
  without a successor. A former manager can manage the department again, in a new period.
  Transferring or terminating a manager ends the management the same way. Every change is in
  the audit log (`department.*`).
- **Employee profile**: the manager of the employee's current department and a badge with the
  employee's own management periods.

### Reports & Analytics
`/reports` (admin, HR, managers; a manager only sees their own department):
- **Workforce**: headcount on 31 December of each year (stacked per department), hires,
  leavers, transfers and turnover rate per year, average tenure, gender distribution
  (overall, per department, per title) and age distribution
- **Compensation** (`view-salaries`): average / median / minimum / maximum salary per
  department and per title, salary distribution, gender pay gap per department and title,
  average salary per year
- **As-of date**: every report shows the state of the company at the end of a chosen month
  (history rows with `from_date <= day < to_date`); the yearly series end on that day
- Department filter (admins, HR), Chart.js charts (light and dark mode), every report as a
  table and as a CSV download
- Figures are precomputed by `php artisan reports:warm`, scheduled hourly (the `scheduler` service;
  without Docker `php artisan schedule:work`). After a change of the data, and at midnight for
  the current state, the next visitor gets the last computed figures at once, with a note,
  and they are refreshed in the background
- A figure that was never computed (e.g. a new as-of month) is computed once: while one
  request does that, others asking for it get a "being prepared" page (503 with `Retry-After`).
  Report routes allow 60 requests per minute per user

## Performance Notes

The directory has to stay fast on ~300k employees / ~2.8M salary rows:

- **Shared query object**: `App\Queries\EmployeeSearchQuery` (used by the Livewire list
  and the export) applies every filter as a semi-join on `employees`, selects only the
  `emp_no` values of the requested page and loads the display columns for those rows.
  Department/title/salary sorts are read as index-ordered segments; former employees
  are still filtered and sorted by their last department and title.
- **Counts**: the filtered total is computed once per filter change and reused for
  paging, sorting and the selection count.
- **Indexes**: migration `add_directory_indexes_to_history_tables` adds indexes on the
  `to_date` / `dept_no` / `title` / `salary` / `hire_date` columns (idempotent, built online).
- **Export**: the selection is sent as "all matching except …" / "only these ids", the CSV
  is streamed in chunks and XLSX written row by row; PDFs above 1,000 rows and CSV / XLSX
  above 20,000 rows are generated by the queue worker.
- **Employee number search**: a numeric search term is a primary-key lookup, not a name scan.
- **Caching**: 15-minute cache for dashboard statistics, 30-minute cache for department data;
  department figures and reports are precomputed (below).

Timings below were measured on the full dataset on an idle server with the default 128 MB
InnoDB buffer pool (`php artisan serve`). The Docker setup has 1 GB, so these are upper bounds:

| Page | First visit, nothing cached | Next visits | After a change of the data |
|------|-----------------------------|-------------|----------------------------|
| Dashboard | 1.7 s | 0.1 s | – |
| Directory | 0.6 s | 0.4-0.5 s | – |
| Profile | 0.6 s | 0.2-0.4 s | – |
| Departments | 10.6 s | 0.1 s | 0.15 s, last good figures |
| Department page | 6.8 s | 0.1 s | 0.25 s, last good figures |
| Workforce report | 21.5 s | 0.1 s | 0.2 s, last good figures |
| Compensation report | 35.5 s | 0.1 s | 0.15 s, last good figures |

The first-visit column is what the precomputing avoids: on the first start and then hourly the
figures are computed ahead of the visitors. `php artisan departments:warm` takes 25 s for all
departments, `php artisan reports:warm` about 4 minutes for all 19 scopes (the company, each
department, each manager's department). Only a report for a month nobody asked for before
is computed in the request. Department salary figures are read from the `(to_date, salary)`
index instead of through each employee's salary history.

After a change of the data, and at midnight for the current state, the next visitor gets the
last good figures with a note, and a background process refreshes them. Computing the
company-wide compensation figures needs about 105 MB of PHP memory in total.

Data tools: XLSX of all 300k employees 2 minutes, ~190 MB, 21 MB file; XLSX of a manager's 77k
employees 30 s, ~80 MB, 5 MB file (the same ~0.4 ms per row); PDF ~75 s per 1,000 rows, 5,000
rows 5.5-8 minutes and ~1.3 GB (why it is queued and capped). REST API: list 0.4 s, employee
0.1 s, departments 0.3 s; combined filters up to ~2 s for the first request (counting), then less
while the total is reused for a minute.

The role restrictions do not change this: a viewer's list (salary data stripped) renders like an
administrator's. A manager's list is the department filter on their department (0.6-1 s for the
largest one, d005 with ~77k current and former employees; its total is counted on `dept_emp`
alone).

## Running Tests

The suite uses MySQL and a dedicated database (`testing` by default, see `phpunit.xml`
and `.env.testing`). It is wiped on every run (`RefreshDatabase`); `tests/TestCase.php`
refuses to run unless the database is `testing` or its name ends in `_test` / `_testing`
(and refuses a `DB_URL`). Tests build their data with factories
and never depend on the imported dataset. Feature tests that open pages or Livewire components
use `tests/Concerns/ActsAsAdmin.php` (signed in as an active administrator; `signInAs(Role::Viewer)`
etc. switches the role); the authorization rules are covered in `tests/Feature/Auth`.

Tests need the Composer **dev** dependencies (PHPUnit, Faker, …). The Docker app image is
built with `composer install --no-dev`, so `docker compose exec app php artisan test`
does not work. Run them with a local PHP 8.3+ / Composer against the Docker MySQL:

```bash
composer install
docker compose --env-file .env.docker exec db \
    sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS testing"'
DB_HOST=127.0.0.1 DB_PORT=<FORWARD_DB_PORT> DB_USERNAME=root DB_PASSWORD=root php artisan test
```

Any other MySQL 8 server works the same way – set the `DB_*` variables (environment
variables take precedence over `phpunit.xml` / `.env.testing`).

## Security

### Dependency check

`composer audit` compares the installed packages with the public security advisories:

```bash
composer audit
# without a local PHP / Composer (reads composer.lock only):
docker run --rm -v "$PWD":/app -w /app composer:2 audit --locked
```

It has to report no advisories. Update policy:

- Run it before every release and whenever `composer.lock` changes, at least once a month.
- Critical and high advisories are fixed right away, the others with the next release.
- An advisory that cannot be fixed yet is listed here with the reason and the planned fix.
- Stay on a Laravel version that still gets security fixes. Laravel 12 gets them until
  February 2027; plan the upgrade to the next major version before that.
- Update with `composer update`, then run the test suite. Keep `composer.lock` committed.
- CDN files are pinned to an exact version with an `integrity` hash
  (`resources/views/layouts/head.blade.php`, `layouts/chartjs.blade.php`). A new version needs a
  new hash: `curl -sL <url> | openssl dgst -sha384 -binary | openssl base64 -A`.

### Protections in the application

- Every route requires a signed-in, active user and checks its Gate or policy on the server,
  and so do Livewire actions and API routes ([docs/AUTHORIZATION.md](docs/AUTHORIZATION.md)).
- SQL uses bindings or whitelisted values only; Blade output is escaped, forms carry CSRF tokens;
  CSV cells cannot start a formula.
- Login, export, import, reports and the API are rate limited. API tokens act as their owner
  and are revoked with a password or role change.
- Every change of data, and every export, is in the audit log.
- `App\Http\Middleware\SecurityHeaders` sets the security headers on every response. Its
  Content-Security-Policy allows inline scripts and `eval` (inline `onclick` handlers, Livewire's
  Alpine), so it does not stop an inline script; it limits where pages load from and send data
  to. Inline handlers have to move to `public/js` before a stricter policy is possible.
- Sessions are encrypted (`SESSION_ENCRYPT`), the cookie is `HttpOnly` and `SameSite=Lax`.
  Set `SESSION_SECURE_COOKIE=true` when the site is served over HTTPS.
- PDFs are rendered without PHP, JavaScript or remote files (`config/dompdf.php`).

## API Endpoints

The application provides the following main routes:

- `GET|POST /login`, `POST /logout` - Sign in / out (everything else requires a signed-in, active user)
- `GET /` - Dashboard (department figures for managers)
- `GET /employees` - Employee directory
- `GET /employees/{emp_no}` - Individual employee profile (managers: own department only)
- `POST /employees/export` - Bulk export functionality (`export-data`)
- `GET /exports`, `GET /exports/{id}/download`, `DELETE /exports/{id}` - My exports (`export-data`, own exports only)
- `GET|POST /employees/import`, `GET|POST|DELETE /employees/import/{token}`, `GET /employees/import/template.{csv|xlsx}` - Import (`import-data`)
- `GET /employees/{emp_no}/export-pdf` - Individual PDF export
- `GET /departments`, `GET /departments/{dept_no}` - Departments index and page (managers: own department only)
- `GET /departments/create`, `POST /departments`, `GET /departments/{dept_no}/edit`, `PUT|DELETE /departments/{dept_no}` - Create, rename, delete (`manage-departments`)
- `GET /departments/{dept_no}/manager-candidates?q=` (60 requests per minute), `POST /departments/{dept_no}/managers`, `POST /departments/{dept_no}/managers/end` - Department managers (`manage-departments`)
- `GET /reports`, `GET /reports/workforce`, `GET /reports/compensation`, `GET /reports/{report}/csv` - Reports (`view-reports`; compensation also `view-salaries`)
- `GET /account`, `PUT /account/profile`, `PUT /account/password` - My account
- `GET /employees/create`, `POST /employees` - Hire an employee (`manage-employees`)
- `PUT /employees/{emp_no}`, `POST /employees/{emp_no}/transfer|title|salary|terminate|rehire|undo`,
  `PUT /employees/{emp_no}/salary/latest|title/latest` - HR operations (`manage-employees`, see docs/HR_OPERATIONS.md)
- `GET /audit-log` - Audit log (`view-audit-log`)
- `POST /account/tokens`, `DELETE /account/tokens/{id}` - API tokens of My account
- `GET /api/v1/employees`, `GET /api/v1/employees/{emp_no}`, `GET /api/v1/departments` - REST API (token, [docs/API.md](docs/API.md))
- `/users` (resource without `show`), `PUT /users/{user}/password` - User management (`manage-users`)

## Configuration

### Cache Configuration
The web server, the queue worker and the scheduler must share one cache store that supports
locks (`CACHE_DRIVER`; Docker: `file` on the shared `cache-data` volume).

### PDF Settings
PDF generation options live in `config/dompdf.php`. Remote files, PHP and JavaScript are disabled there; exports must not replace them with `setOptions()` (a single `setOption()` is fine).

### Database
Give InnoDB a buffer pool that holds the dataset (`innodb_buffer_pool_size`, 1 GB in Docker,
`DB_BUFFER_POOL_SIZE`): the default of 128 MB is much smaller than the tables.
