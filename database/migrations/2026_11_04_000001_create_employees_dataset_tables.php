<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema of the MySQL "employees" sample database (datacharmer/test_db):
 * employees, departments, dept_manager, dept_emp, titles, salaries.
 *
 * On the Docker setup these tables are created and filled by the database
 * container's dataset import, so every table is guarded with hasTable() and this
 * migration is a no-op there. On a fresh database (tests, manual installs) it
 * creates the same structure: column types, primary keys, the unique
 * department name and ON DELETE CASCADE foreign keys (same constraint names).
 * The dataset's helper views (current_dept_emp, dept_emp_latest_date) are not
 * used by the application and are not created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table) {
                $table->integer('emp_no')->primary();
                $table->date('birth_date');
                $table->string('first_name', 14);
                $table->string('last_name', 16);
                $table->enum('gender', ['M', 'F']);
                $table->date('hire_date');
            });
        }

        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table) {
                $table->char('dept_no', 4)->primary();
                $table->string('dept_name', 40)->unique('dept_name');
            });
        }

        foreach (['dept_manager', 'dept_emp'] as $name) {
            if (! Schema::hasTable($name)) {
                Schema::create($name, function (Blueprint $table) use ($name) {
                    $table->integer('emp_no');
                    $table->char('dept_no', 4);
                    $table->date('from_date');
                    $table->date('to_date');
                    $table->primary(['emp_no', 'dept_no']);
                    $table->index('dept_no', 'dept_no');
                    $table->foreign('emp_no', $name.'_ibfk_1')->references('emp_no')->on('employees')->cascadeOnDelete();
                    $table->foreign('dept_no', $name.'_ibfk_2')->references('dept_no')->on('departments')->cascadeOnDelete();
                });
            }
        }

        if (! Schema::hasTable('titles')) {
            Schema::create('titles', function (Blueprint $table) {
                $table->integer('emp_no');
                $table->string('title', 50);
                $table->date('from_date');
                $table->date('to_date')->nullable();
                $table->primary(['emp_no', 'title', 'from_date']);
                $table->foreign('emp_no', 'titles_ibfk_1')->references('emp_no')->on('employees')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('salaries')) {
            Schema::create('salaries', function (Blueprint $table) {
                $table->integer('emp_no');
                $table->integer('salary');
                $table->date('from_date');
                $table->date('to_date');
                $table->primary(['emp_no', 'from_date']);
                $table->foreign('emp_no', 'salaries_ibfk_1')->references('emp_no')->on('employees')->cascadeOnDelete();
            });
        }
    }

    /**
     * Intentionally a no-op: these tables hold the employee dataset, which on
     * existing installations was imported outside of the migrations. Rolling
     * back must never drop it. To remove the tables on a throw-away database
     * use `php artisan db:wipe` (or `migrate:fresh`, which also re-creates them).
     */
    public function down(): void
    {
        //
    }
};
