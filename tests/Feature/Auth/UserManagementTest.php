<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_index_lists_searches_and_filters_users(): void
    {
        User::factory()->hr()->create(['name' => 'Helen Hr', 'email' => 'helen@example.com']);
        User::factory()->viewer()->create(['name' => 'Victor View', 'email' => 'victor@example.com']);

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('Helen Hr')
            ->assertSee('Victor View')
            ->assertSee($this->user->email);

        $this->get(route('users.index', ['search' => 'victor']))
            ->assertSee('Victor View')
            ->assertDontSee('Helen Hr');

        $this->get(route('users.index', ['role' => 'hr']))
            ->assertSee('Helen Hr')
            ->assertDontSee('Victor View');
    }

    public function test_index_is_paginated(): void
    {
        User::factory()->count(20)->create();

        $this->get(route('users.index'))
            ->assertOk()
            ->assertViewHas('users', fn ($users) => $users->count() === 15 && $users->total() === 21);
    }

    public function test_admin_creates_a_user(): void
    {
        $this->get(route('users.create'))->assertOk()->assertSee('New user');

        $this->post(route('users.store'), [
            'name' => 'Nina New',
            'email' => 'Nina@Example.com',
            'role' => 'viewer',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'is_active' => '1',
            'dept_no' => 'd001',
        ])->assertRedirect(route('users.index'))->assertSessionHas('status');

        $user = User::where('email', 'nina@example.com')->firstOrFail();
        $this->assertSame(Role::Viewer, $user->role);
        $this->assertNull($user->dept_no); // only managers keep a department
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('secret-pass', $user->password));
    }

    public function test_create_validates_input(): void
    {
        $this->post(route('users.store'), [
            'name' => '',
            'email' => $this->user->email,
            'role' => 'superuser',
            'password' => 'short',
            'password_confirmation' => 'other',
        ])->assertSessionHasErrors(['name', 'email', 'role', 'password']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_manager_needs_an_existing_department(): void
    {
        $data = ['name' => 'Max', 'email' => 'max@example.com', 'role' => 'manager',
            'password' => 'secret-pass', 'password_confirmation' => 'secret-pass'];

        $this->post(route('users.store'), $data)->assertSessionHasErrors('dept_no');
        $this->post(route('users.store'), $data + ['dept_no' => 'd999'])->assertSessionHasErrors('dept_no');

        $department = Department::factory()->create();
        $this->post(route('users.store'), $data + ['dept_no' => $department->dept_no])->assertSessionHasNoErrors();

        $this->assertSame($department->dept_no, User::where('email', 'max@example.com')->value('dept_no'));
    }

    public function test_admin_edits_a_user(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->viewer()->create();

        $this->get(route('users.edit', $user))->assertOk()->assertSee($user->email);

        $this->put(route('users.update', $user), [
            'name' => 'Renamed',
            'email' => 'renamed@example.com',
            'role' => 'manager',
            'dept_no' => $department->dept_no,
            'is_active' => '0',
        ])->assertRedirect(route('users.index'));

        $user->refresh();
        $this->assertSame('Renamed', $user->name);
        $this->assertSame(Role::Manager, $user->role);
        $this->assertSame($department->dept_no, $user->dept_no);
        $this->assertFalse($user->is_active);

        // Leaving the manager role drops the department.
        $this->put(route('users.update', $user), [
            'name' => 'Renamed', 'email' => 'renamed@example.com', 'role' => 'hr',
            'dept_no' => $department->dept_no, 'is_active' => '1',
        ]);
        $this->assertNull($user->fresh()->dept_no);
    }

    public function test_password_cannot_be_changed_through_the_edit_form(): void
    {
        $user = User::factory()->create();

        $this->put(route('users.update', $user), [
            'name' => $user->name, 'email' => $user->email, 'role' => 'viewer', 'is_active' => '1',
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('password');
    }

    public function test_admin_cannot_demote_or_deactivate_themselves(): void
    {
        $data = ['name' => $this->user->name, 'email' => $this->user->email, 'is_active' => '1'];

        $this->put(route('users.update', $this->user), $data + ['role' => 'hr'])->assertSessionHasErrors('role');
        $this->put(route('users.update', $this->user), ['role' => 'admin', 'is_active' => '0'] + $data)->assertSessionHasErrors('is_active');

        $this->assertTrue($this->user->fresh()->isActiveAdmin());

        // Changing the own name and e-mail is fine.
        $this->put(route('users.update', $this->user), ['role' => 'admin', 'name' => 'Boss'] + $data)->assertSessionHasNoErrors();
        $this->assertSame('Boss', $this->user->fresh()->name);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $this->get(route('users.index'))
            ->assertViewHas('deletionBlocked', fn ($blocked) => $blocked[$this->user->id] !== null);

        $this->delete(route('users.destroy', $this->user))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('error');

        $this->assertModelExists($this->user);
    }

    public function test_admin_deletes_another_user(): void
    {
        $other = User::factory()->admin()->create();

        $this->delete(route('users.destroy', $other))->assertSessionHas('status');

        $this->assertModelMissing($other);
    }

    public function test_the_last_active_admin_is_protected(): void
    {
        $service = app(UserManagementService::class);
        $actor = User::factory()->hr()->create(); // not the admin itself
        $admin = $this->user;
        $data = ['name' => $admin->name, 'email' => $admin->email];

        foreach ([['role' => Role::Hr, 'is_active' => true], ['role' => Role::Admin, 'is_active' => false]] as $change) {
            try {
                $service->update($actor, $admin, $change + $data);
                $this->fail('The last active admin was demoted or deactivated.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('active administrator', collect($e->errors())->flatten()->first());
            }
        }

        $this->assertNotNull($service->deletionBlockedReason($actor, $admin));
        $this->expectException(ValidationException::class);
        $service->delete($actor, $admin);
    }

    public function test_an_admin_can_be_demoted_while_another_active_admin_exists(): void
    {
        $other = User::factory()->admin()->create();
        User::factory()->admin()->inactive()->create(); // inactive admins do not count

        $this->put(route('users.update', $other), [
            'name' => $other->name, 'email' => $other->email, 'role' => 'viewer', 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Role::Viewer, $other->fresh()->role);
    }

    public function test_admin_resets_a_password(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $oldToken = $user->remember_token;

        $this->put(route('users.password', $user), ['password' => 'brand-new-pass', 'password_confirmation' => 'nope'])
            ->assertSessionHasErrorsIn('resetPassword', 'password');

        $this->put(route('users.password', $user), ['password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertRedirect(route('users.edit', $user))
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $user->password));
        $this->assertNotSame($oldToken, $user->remember_token);
    }

    public function test_deactivating_a_user_invalidates_remember_me(): void
    {
        $user = User::factory()->create();
        $oldToken = $user->remember_token;

        $this->put(route('users.update', $user), [
            'name' => $user->name, 'email' => $user->email, 'role' => 'viewer', 'is_active' => '0',
        ]);

        $this->assertNotSame($oldToken, $user->fresh()->remember_token);
    }
}
