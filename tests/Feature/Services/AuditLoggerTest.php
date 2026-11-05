<?php

namespace Tests\Feature\Services;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_the_signed_in_user_and_the_request_ip(): void
    {
        $user = User::factory()->hr()->create();
        $this->actingAs($user);

        $entry = AuditLogger::log('employee.hired', ActivityLog::SUBJECT_EMPLOYEE, 10001, ['after' => ['first_name' => 'Ann']]);

        $entry = $entry->fresh();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('employee.hired', $entry->action);
        $this->assertSame('employee', $entry->subject_type);
        $this->assertSame('10001', $entry->subject_id);
        $this->assertSame(['after' => ['first_name' => 'Ann']], $entry->changes);
        $this->assertSame('127.0.0.1', $entry->ip);
        $this->assertNotNull($entry->created_at);
        $this->assertTrue($entry->user->is($user));
    }

    public function test_an_explicit_user_wins_and_empty_changes_are_stored_as_null(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $other = User::factory()->create();

        $entry = AuditLogger::log('department.manager_assigned', ActivityLog::SUBJECT_DEPARTMENT, 'd005', [], $other)->fresh();

        $this->assertSame($other->id, $entry->user_id);
        $this->assertSame('d005', $entry->subject_id);
        $this->assertNull($entry->changes);
    }

    public function test_without_a_signed_in_user_the_actor_is_empty(): void
    {
        $entry = AuditLogger::log('auth.login_failed', ActivityLog::SUBJECT_USER, null, ['email' => 'x@example.com'])->fresh();

        $this->assertNull($entry->user_id);
        $this->assertNull($entry->subject_id);
    }

    public function test_secrets_are_removed_at_any_depth(): void
    {
        $entry = AuditLogger::log('user.created', ActivityLog::SUBJECT_USER, 1, [
            'password' => 'secret-pass-42',
            'after' => ['name' => 'Ann', 'password' => 'secret-pass-42', 'remember_token' => 'abc'],
            'nested' => ['deeper' => ['current_password' => 'old', 'password_confirmation' => 'x', 'kept' => 1]],
        ])->fresh();

        // MySQL JSON columns do not keep the key order.
        $this->assertEquals(['after' => ['name' => 'Ann'], 'nested' => ['deeper' => ['kept' => 1]]], $entry->changes);
        $this->assertStringNotContainsString('secret-pass-42', json_encode($entry->getAttributes()));
    }

    public function test_the_actor_is_kept_null_when_the_user_is_deleted(): void
    {
        $user = User::factory()->create();
        $entry = AuditLogger::log('auth.login', ActivityLog::SUBJECT_USER, $user->id, [], $user);

        $user->delete();

        $this->assertNull($entry->fresh()->user_id);
    }

    public function test_diff_returns_only_changed_values(): void
    {
        $this->assertSame([], AuditLogger::diff(['a' => 1, 'b' => 'x'], ['a' => '1', 'b' => 'x']));

        $this->assertSame([
            'before' => ['b' => 'x', 'd' => 5, 'c' => null],
            'after' => ['b' => 'y', 'd' => null, 'c' => 3],
        ], AuditLogger::diff(['a' => 1, 'b' => 'x', 'd' => 5], ['a' => 1, 'b' => 'y', 'c' => 3, 'd' => null]));
    }

    public function test_diff_and_values_store_enums_and_dates_as_plain_values(): void
    {
        $this->assertSame([
            'before' => ['role' => 'viewer', 'to_date' => '9999-01-01'],
            'after' => ['role' => 'manager', 'to_date' => '2026-11-05'],
        ], AuditLogger::diff(
            ['role' => Role::Viewer, 'to_date' => Carbon::parse('9999-01-01')],
            ['role' => Role::Manager, 'to_date' => Carbon::parse('2026-11-05')],
        ));

        $this->assertSame(
            ['role' => 'hr', 'at' => '2026-11-05 10:30:00', 'active' => true],
            AuditLogger::values(['role' => Role::Hr, 'at' => Carbon::parse('2026-11-05 10:30:00'), 'active' => true]),
        );
    }

    public function test_scopes_find_a_subjects_history_newest_first(): void
    {
        $first = AuditLogger::log('employee.hired', 'employee', 10001);
        AuditLogger::log('employee.hired', 'employee', 10002);
        $this->travel(1)->minutes();
        $second = AuditLogger::log('employee.promoted', 'employee', 10001);

        $this->assertSame(
            [$second->id, $first->id],
            ActivityLog::forSubject('employee', 10001)->latestFirst()->pluck('id')->all(),
        );
    }
}
