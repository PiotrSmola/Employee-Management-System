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
- **Data Filtering**: Advanced search and filtering capabilities with Spatie Query Builder
- **Interactive UI**: Enhanced user experience with Livewire components

## Technology Stack

- **Framework**: Laravel 11.x
- **PHP**: 8.2+
- **Database**: MySQL/PostgreSQL compatible
- **Frontend**: Bootstrap 5, Blade templating
- **PDF Generation**: Laravel DomPDF
- **Interactive Components**: Livewire 3.5
- **Query Building**: Spatie Laravel Query Builder
- **Development Tools**: Laravel Sail, Pint (code formatting)

## Requirements

- PHP 8.2 or higher
- Composer
- Node.js & NPM (for asset compilation)
- MySQL 8.0+ or PostgreSQL 13+
- Web server (Apache/Nginx)

## Installation

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
DB_DATABASE=employee_management
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### 5. Run Migrations
```bash
php artisan migrate
```

### 6. Seed Database (Optional)
```bash
php artisan db:seed
```

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

## Performance Optimizations

- **Query Optimization**: Efficient eager loading of relationships
- **Caching Strategy**: 15-minute cache for dashboard statistics, 30-minute cache for department data
- **Database Indexing**: Optimized indexes for frequent queries
- **Lazy Loading**: Strategic use of lazy loading for related data

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
