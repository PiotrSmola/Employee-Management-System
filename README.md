# Employee Management System

A comprehensive web-based employee management platform built with Laravel 11, designed to handle employee data, departments, salaries, and job titles with advanced reporting capabilities.

## Features

### Core Functionality
- **Employee Directory**: Browse and search through employee records with pagination
- **Individual Employee Profiles**: Detailed view with employment history, salary progression, and department changes
- **Department Management**: View departmental structure and employee distribution
- **Dashboard Analytics**: Real-time statistics and insights with caching for optimal performance

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

- **Framework**: Laravel 11.x
- **PHP**: 8.2+
- **Database**: MySQL 8.0+ (the directory queries use MySQL optimizer hints and the index migration builds indexes online with `ALGORITHM=INPLACE`; other databases are not supported)
- **Frontend**: Bootstrap 5, Blade templating
- **PDF Generation**: Laravel DomPDF
- **Interactive Components**: Livewire 3.5
- **Query Building**: Spatie Laravel Query Builder
- **Development Tools**: Laravel Sail, Pint (code formatting)

## Requirements

- PHP 8.2 or higher
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
(roughly 45 s on the full dataset, the tables stay usable meanwhile).

> Never run `migrate:fresh`, `migrate:refresh` or `db:wipe` against the Docker
> database – they drop the imported employees dataset.

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

### 6. Seed Demo Data (Optional)
```bash
php artisan db:seed
```
Adds a small demo dataset (9 departments, ~200 employees with histories) – only
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

## Running Tests

The suite uses MySQL and a dedicated database (`testing` by default, see `phpunit.xml`
and `.env.testing`). It is wiped on every run (`RefreshDatabase`); `tests/TestCase.php`
refuses to run unless the database is `testing` or its name ends in `_test` / `_testing`
(and refuses a `DB_URL`). Tests build their data with factories
and never depend on the imported dataset.

Tests need the Composer **dev** dependencies (PHPUnit, Faker, …). The Docker app image is
built with `composer install --no-dev`, so `docker compose exec app php artisan test`
does not work. Run them with a local PHP 8.2+ / Composer against the Docker MySQL:

```bash
composer install
docker compose --env-file .env.docker exec db \
    sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS testing"'
DB_HOST=127.0.0.1 DB_PORT=<FORWARD_DB_PORT> DB_USERNAME=root DB_PASSWORD=root php artisan test
```

Any other MySQL 8 server works the same way – set the `DB_*` variables (environment
variables take precedence over `phpunit.xml` / `.env.testing`).

## API Endpoints

The application provides the following main routes:

- `GET /` - Dashboard
- `GET /employees` - Employee directory
- `GET /employees/{emp_no}` - Individual employee profile
- `POST /employees/export` - Bulk export functionality
- `GET /employees/{emp_no}/export-pdf` - Individual PDF export

## Configuration

### Cache Configuration
Adjust cache settings in `config/cache.php` for production environments.

### PDF Settings
Configure PDF generation options in the DomPDF service provider.

### Database Optimization
For large datasets, consider:
- Database connection pooling
- Read replicas for reporting queries
- Partitioning for historical data
