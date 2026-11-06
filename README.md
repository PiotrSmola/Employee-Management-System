# Employee Management System

A comprehensive web-based employee management platform built with Laravel 12, designed to handle employee data, departments, salaries, and job titles with advanced reporting capabilities.

## Features

### Core Functionality
- **Employee Directory**: Browse and search through employee records with pagination
- **Individual Employee Profiles**: Detailed view with employment history, salary progression, and department changes
- **Departments**: Department pages with headcount, gender split, tenure, salary figures, job titles, headcount per year and manager history; admins and HR create, rename and delete departments and assign department managers (see [Department Pages and Managers](#department-pages-and-managers))
- **Dashboard Analytics**: Real-time statistics and insights with caching for optimal performance
- **Accounts & Roles**: Sign-in, four roles (admin, HR, manager, viewer), department-scoped managers, salary data only for authorized roles, admin user management

### Advanced Features
- **Export Capabilities**: Generate PDF reports for individual employees and bulk data exports
- **Historical Tracking**: Complete employment history including department transfers, title changes, and salary adjustments
- **Status Management**: Track active vs. former employees
- **Performance Optimization**: Intelligent caching system for frequently accessed data

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
- **Frontend**: Bootstrap 5, Blade templating
- **PDF Generation**: Laravel DomPDF
- **Interactive Components**: Livewire 3.8
- **Development Tools**: Laravel Sail, Pint (code formatting)

## Requirements

- PHP 8.3 or higher
- Composer
- Node.js & NPM (for asset compilation)
- MySQL 8.0+
- Web server (Apache/Nginx)

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
| MySQL 8.0 | `db` | `FORWARD_DB_PORT` (default 3306) | db `employees_management`, user `laravel` / `secret`, root / `root` |

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
schema migrations are no-ops; the first run adds the directory indexes online
(roughly 45 s on the full dataset, the tables stay usable meanwhile). It then runs the
`UserSeeder`, which creates the initial accounts if they do not exist yet (see
[Accounts and Roles](#accounts-and-roles)). Set `ADMIN_EMAIL` / `ADMIN_PASSWORD`
(and `SEED_DEMO_USERS=false` to skip the demo accounts) in `.env.docker` before the first start.

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
  without a successor. A former manager cannot (yet) manage the same department again. Every
  change is in the audit log (`department.*`).
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
- Figures are cached (24 h, cleared by `EmployeeDataCache::flush()`) and precomputed by
  `php artisan reports:warm`, scheduled hourly - run the scheduler (`php artisan schedule:work`
  or a cron entry for `schedule:run`) in production
- Requests compute uncached figures one at a time: while one runs, others that need an uncached
  figure get a "try again" page (503 with `Retry-After`), cached reports stay available. Report
  routes allow 60 requests per minute per user

### Export System
- Individual employee PDF reports
- Bulk data export capabilities
- Formatted reports with company branding
- Pagination support for large datasets

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
  is streamed in chunks; PDF export is limited to 1,000 rows.
- **Caching**: 15-minute cache for dashboard statistics, 30-minute cache for department data.

Typical timings on the full dataset (Docker, warm-ish cache): unfiltered list ~0.1 s,
single filters and sorts 0.1–0.6 s, combined filters up to ~2 s, full CSV export of all
300k employees ~50 s (streamed).

Department figures are cached (15 minutes for the index, 30 for a department), so the pages
render in about 0.1 s. They are refreshed by `php artisan departments:warm` (hourly, needs the
scheduler: `php artisan schedule:run` every minute) and otherwise after the response of a reader,
who gets the last good copy meanwhile. Only the very first visit, with nothing cached, computes
them or says they are being prepared. Salary figures are read from the `(to_date, salary)` index
instead of through each employee's salary history.

Reports are served from the cache (about 0.1 s per page). A page whose figures are not cached
yet computes them in the request: on the full dataset 5-11 s for a workforce page and 9-22 s for a
compensation page on an idle server, several times that while the database is busy (the default
128 MB InnoDB buffer pool is smaller than the dataset, so a larger pool helps most).
`php artisan reports:warm` computes the current state of all 19 scopes ahead of the visitors, in
about 3.5 minutes on an idle server. Computing
the company-wide compensation figures needs about 105 MB of PHP memory in total.

The role restrictions do not change this: a viewer's list (salary data stripped) renders like an
administrator's (~0.1–0.2 s). A manager's list is the department filter on their department
(~0.2–0.4 s for the largest one, d005 with ~77k current and former employees; its total is
counted on `dept_emp` alone). The department dashboard of a manager takes ~2 s for the largest
department on a cache miss and is cached for 15 minutes per department.

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
- `GET /employees/{emp_no}/export-pdf` - Individual PDF export
- `GET /departments`, `GET /departments/{dept_no}` - Departments index and page (managers: own department only)
- `GET /departments/create`, `POST /departments`, `GET /departments/{dept_no}/edit`, `PUT|DELETE /departments/{dept_no}` - Create, rename, delete (`manage-departments`)
- `GET /departments/{dept_no}/manager-candidates?q=` (60 requests per minute), `POST /departments/{dept_no}/managers`, `POST /departments/{dept_no}/managers/end` - Department managers (`manage-departments`)
- `GET /account`, `PUT /account/profile`, `PUT /account/password` - My account
- `GET /employees/create`, `POST /employees` - Hire an employee (`manage-employees`)
- `PUT /employees/{emp_no}`, `POST /employees/{emp_no}/transfer|title|salary|terminate|rehire|undo`,
  `PUT /employees/{emp_no}/salary/latest|title/latest` - HR operations (`manage-employees`, see docs/HR_OPERATIONS.md)
- `GET /audit-log` - Audit log (`view-audit-log`)
- `/users` (resource without `show`), `PUT /users/{user}/password` - User management (`manage-users`)

## Configuration

### Cache Configuration
Adjust cache settings in `config/cache.php` for production environments.

### PDF Settings
PDF generation options live in `config/dompdf.php`. Remote files, PHP and JavaScript are disabled there; exports must not replace them with `setOptions()` (a single `setOption()` is fine).

### Database Optimization
For large datasets, consider:
- Database connection pooling
- Read replicas for reporting queries
- Partitioning for historical data
