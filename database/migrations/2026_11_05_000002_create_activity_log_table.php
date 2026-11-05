<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log: who did what to which record (App\Services\AuditLogger).
 *
 *  - action: "<subject>.<verb>", e.g. employee.hired, user.created, auth.login;
 *  - subject_type / subject_id: short alias + key of the record (employee/emp_no,
 *    department/dept_no, user/id) - no foreign key, entries outlive their subject;
 *  - changes: {"before": {...}, "after": {...}} for edits, or a payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 32);
            $table->string('subject_id', 64)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
