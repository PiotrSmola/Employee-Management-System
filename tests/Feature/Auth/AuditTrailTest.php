<?php

namespace Tests\Feature\Auth;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/**
 * Account changes and sign-ins end up in the audit log - without passwords.
 */
class AuditTrailTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-42';

    public function test_creating_a_user_is_logged(): void
    {
        $this->post(route('users.store'), [
            'name' => 'Nina New',
            'email' => 'nina@example.com',
            'role' => 'viewer',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'is_active' => '1',
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'nina@example.com')->firstOrFail();
        $entry = $this->onlyEntry('user.created');

        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame('user', $entry->subject_type);
        $this->assertSame((string) $user->id, $entry->subject_id);
        $this->assertEquals(
            ['name' => 'Nina New', 'email' => 'nina@example.com', 'role' => 'viewer', 'dept_no' => null, 'is_active' => true],
            $entry->changes['after'],
        );
        $this->assertNoPasswordLogged();
    }

    public function test_updating_a_user_logs_the_diff_and_the_role_change(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->viewer()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

        $this->put(route('users.update', $user), [
            'name' => 'New Name',
            'email' => 'old@example.com',
            'role' => 'manager',
            'dept_no' => $department->dept_no,
            'is_active' => '1',
        ])->assertRedirect(route('users.index'));

        $this->assertEquals([
            'before' => ['name' => 'Old Name', 'role' => 'viewer', 'dept_no' => null],
            'after' => ['name' => 'New Name', 'role' => 'manager', 'dept_no' => $department->dept_no],
        ], $this->onlyEntry('user.updated')->changes);

        $this->assertEquals(
            ['before' => ['role' => 'viewer'], 'after' => ['role' => 'manager']],
            $this->onlyEntry('user.role_changed')->changes,
        );
    }

    public function test_saving_a_user_without_changes_logs_nothing(): void
    {
        $user = User::factory()->hr()->create();

        $this->put(route('users.update', $user), [
            'name' => $user->name, 'email' => $user->email, 'role' => 'hr', 'is_active' => '1',
        ])->assertRedirect(route('users.index'));

        $this->assertSame(0, ActivityLog::count());
    }

    public function test_a_password_reset_is_logged_without_the_password(): void
    {
        $user = User::factory()->create();

        $this->put(route('users.password', $user), [
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('users.edit', $user));

        $entry = $this->onlyEntry('user.password_reset');
        $this->assertSame((string) $user->id, $entry->subject_id);
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertNull($entry->changes);
        $this->assertNoPasswordLogged();
    }

    public function test_deleting_a_user_is_logged_with_the_last_values(): void
    {
        $user = User::factory()->hr()->create(['name' => 'Gone Soon']);

        $this->delete(route('users.destroy', $user))->assertRedirect(route('users.index'));

        $entry = $this->onlyEntry('user.deleted');
        $this->assertSame((string) $user->id, $entry->subject_id);
        $this->assertSame('Gone Soon', $entry->changes['before']['name']);
        $this->assertSame('hr', $entry->changes['before']['role']);
    }

    public function test_own_account_changes_are_logged(): void
    {
        $oldName = $this->user->name;
        $this->put(route('account.profile'), ['name' => 'Renamed Admin'])->assertRedirect(route('account.edit'));
        $this->assertEquals(
            ['before' => ['name' => $oldName], 'after' => ['name' => 'Renamed Admin']],
            $this->onlyEntry('user.updated')->changes,
        );

        $this->put(route('account.password'), [
            'current_password' => 'password',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('account.edit'));

        $entry = $this->onlyEntry('user.password_changed');
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame((string) $this->user->id, $entry->subject_id);
        $this->assertNoPasswordLogged();
    }

    public function test_a_successful_login_is_logged(): void
    {
        $this->post(route('logout'));
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertRedirect(route('index'));

        $entry = $this->onlyEntry('auth.login');
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame((string) $user->id, $entry->subject_id);
        $this->assertSame('127.0.0.1', $entry->ip);
        $this->assertSame(['remember' => false], $entry->changes);
    }

    public function test_failed_logins_are_logged_with_the_reason_but_not_the_password(): void
    {
        $this->post(route('logout'));
        $jane = User::factory()->create(['email' => 'jane@example.com']);
        User::factory()->inactive()->create(['email' => 'off@example.com']);

        $this->post(route('login.store'), ['email' => 'Jane@Example.com', 'password' => 'wrong-'.self::PASSWORD]);
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => self::PASSWORD]);
        $this->post(route('login.store'), ['email' => 'off@example.com', 'password' => 'password']);

        $entries = ActivityLog::where('action', 'auth.login_failed')->orderBy('id')->get();

        $this->assertEquals([
            ['email' => 'jane@example.com', 'reason' => 'invalid_password'],
            ['email' => 'nobody@example.com', 'reason' => 'unknown_email'],
            ['email' => 'off@example.com', 'reason' => 'inactive'],
        ], $entries->pluck('changes')->all());
        $this->assertSame((string) $jane->id, $entries[0]->subject_id);
        $this->assertNull($entries[1]->subject_id);
        $this->assertTrue($entries->every(fn (ActivityLog $e) => $e->user_id === null));
        $this->assertNoPasswordLogged();
    }

    private function onlyEntry(string $action): ActivityLog
    {
        $entries = ActivityLog::where('action', $action)->get();
        $this->assertCount(1, $entries, "Expected exactly one {$action} entry.");

        return $entries->first();
    }

    private function assertNoPasswordLogged(): void
    {
        foreach (ActivityLog::all() as $entry) {
            $raw = (string) $entry->getRawOriginal('changes');

            $this->assertStringNotContainsString(self::PASSWORD, $raw);
            $this->assertStringNotContainsString('"password"', $raw);
        }
    }
}
