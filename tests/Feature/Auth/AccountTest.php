<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAs(Role::Viewer);
    }

    public function test_account_page_shows_the_account(): void
    {
        $this->get(route('account.edit'))
            ->assertOk()
            ->assertSee($this->user->email)
            ->assertSee('Viewer')
            ->assertSee('Change password');
    }

    public function test_user_changes_their_name(): void
    {
        $this->put(route('account.profile'), ['name' => 'New Name'])
            ->assertRedirect(route('account.edit'))
            ->assertSessionHas('status');

        $this->assertSame('New Name', $this->user->fresh()->name);
    }

    public function test_name_is_required(): void
    {
        $this->put(route('account.profile'), ['name' => ''])->assertSessionHasErrorsIn('profile', 'name');
    }

    public function test_only_the_name_can_be_changed(): void
    {
        $this->put(route('account.profile'), ['name' => 'X', 'role' => 'admin', 'email' => 'evil@example.com', 'is_active' => '1']);

        $user = $this->user->fresh();
        $this->assertSame(Role::Viewer, $user->role);
        $this->assertNotSame('evil@example.com', $user->email);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $this->put(route('account.password'), [
            'current_password' => 'wrong',
            'password' => 'another-pass',
            'password_confirmation' => 'another-pass',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_new_password_must_be_confirmed_and_long_enough(): void
    {
        $this->put(route('account.password'), [
            'current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrorsIn('password', 'password');

        $this->put(route('account.password'), [
            'current_password' => 'password', 'password' => 'another-pass', 'password_confirmation' => 'different',
        ])->assertSessionHasErrorsIn('password', 'password');
    }

    public function test_user_changes_their_password_and_stays_signed_in(): void
    {
        $this->put(route('account.password'), [
            'current_password' => 'password',
            'password' => 'another-pass',
            'password_confirmation' => 'another-pass',
        ])->assertRedirect(route('account.edit'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('another-pass', $this->user->fresh()->password));
        $this->assertAuthenticatedAs($this->user);
        $this->get(route('account.edit'))->assertOk();
    }
}
