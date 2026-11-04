<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the original `users` table (name, last_name, phone_number, email,
 * char(60) password, role SET('employee','admin')) into the application's
 * login accounts:
 *
 *  - name holds the full name (last_name is merged into it), phone_number is dropped;
 *  - role becomes one of admin / hr / manager / viewer (old "admin" stays admin,
 *    everything else becomes viewer) - see App\Enums\Role;
 *  - dept_no: department a manager is limited to (no foreign key: the imported
 *    departments table uses a different collation; validated by the application);
 *  - is_active, last_login_at, remember_token and timestamps.
 *
 * Every step checks the current structure, so the migration also works on a
 * users table that already has some of these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->change();
            $table->string('email')->change();
            $table->string('password')->change();
        });

        if (Schema::hasColumn('users', 'last_name')) {
            DB::table('users')->update(['name' => DB::raw("TRIM(CONCAT(name, ' ', COALESCE(last_name, '')))")]);
        }

        foreach (['last_name', 'phone_number'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role')) {
                $table->string('role', 20)->default('viewer')->change();
            } else {
                $table->string('role', 20)->default('viewer')->after('password');
            }
        });

        DB::table('users')->whereRaw("FIND_IN_SET('admin', role) > 0")->update(['role' => 'admin']);
        DB::table('users')->whereNotIn('role', ['admin', 'hr', 'manager', 'viewer'])->update(['role' => 'viewer']);

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'dept_no')) {
                $table->char('dept_no', 4)->nullable()->after('role')->index();
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('dept_no');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('users', 'remember_token')) {
                $table->rememberToken();
            }
            if (! Schema::hasColumn('users', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['dept_no']);
            $table->dropColumn(['dept_no', 'is_active', 'last_login_at', 'remember_token', 'created_at', 'updated_at']);
        });

        DB::table('users')->where('role', '<>', 'admin')->update(['role' => 'employee']);

        Schema::table('users', function (Blueprint $table) {
            $table->set('role', ['employee', 'admin'])->default('employee')->change();
            $table->string('last_name', 30)->default('')->after('name');
            $table->string('phone_number', 15)->default('')->after('last_name');
        });
    }
};
