<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        foreach (['/', '/employees', '/employees/10001', '/account', '/users'] as $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }

        $this->post(route('employees.export'), ['format' => 'csv'])->assertRedirect(route('login'));
    }

    public function test_livewire_updates_require_a_signed_in_user(): void
    {
        $response = $this->postJson('/livewire/update', ['components' => []]);

        $response->assertUnauthorized();
    }

    public function test_login_page_renders_without_registration_or_password_reset_links(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Employee Management System')
            ->assertSee('name="email"', false)
            ->assertSee('name="remember"', false)
            ->assertDontSee('register', false)
            ->assertDontSee('forgot-password', false);
    }

    public function test_user_can_sign_in(): void
    {
        $user = User::factory()->viewer()->create(['email' => 'jane@example.com']);

        $this->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertRedirect(route('index'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    /**
     * The auth.session middleware keeps a fingerprint of the password under
     * "password_hash_web" and only stores it when missing, so a value left by a
     * previous account (deleted while signed in, without a logout) has to be replaced
     * by SessionGuard::login(), or the new user is signed out right away.
     * The next test proves that the very same stale value is really checked.
     */
    public function test_sign_in_works_in_a_session_left_over_from_another_account(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->withSession(['password_hash_web' => 'hash-of-a-deleted-account'])
            ->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertRedirect(route('index'));

        $this->assertSame(
            Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()),
            session('password_hash_web'),
        );

        $this->get(route('index'))->assertOk();
        $this->assertAuthenticated();
    }

    public function test_session_with_the_password_hash_of_another_account_is_signed_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['password_hash_web' => 'hash-of-a-deleted-account'])
            ->get(route('index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_sign_in_redirects_to_the_page_that_was_requested(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->get('/employees')->assertRedirect(route('login'));

        $this->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertRedirect('/employees');
    }

    public function test_remember_me_sets_the_recaller_cookie(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->post(route('login.store'), [
            'email' => 'jane@example.com', 'password' => 'password', 'remember' => '1',
        ]);

        $response->assertCookie(Auth::guard('web')->getRecallerName());
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->from(route('login'))
            ->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'wrong'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_sign_in(): void
    {
        User::factory()->inactive()->create(['email' => 'jane@example.com']);

        $this->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('deactivated', session('errors')->first('email'));
    }

    public function test_login_is_throttled_after_too_many_failed_attempts(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS; $i++) {
            $this->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'wrong']);
        }

        // Even the correct password is refused while locked.
        $this->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
    }

    public function test_throttling_one_email_does_not_lock_other_accounts_or_addresses(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);
        User::factory()->create(['email' => 'bob@example.com']);

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS; $i++) {
            $this->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'wrong']);
        }

        // Another account from the same address still works ...
        $this->post(route('login.store'), ['email' => 'bob@example.com', 'password' => 'password'])
            ->assertRedirect(route('index'));
        $this->post(route('logout'));

        // ... and so does the locked e-mail from another address.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertRedirect(route('index'));
    }

    public function test_one_address_is_throttled_across_all_emails(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS_PER_IP; $i++) {
            $this->post(route('login.store'), ['email' => "guess{$i}@example.com", 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));

        // Other addresses are not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])
            ->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertRedirect(route('index'));
    }

    public function test_inactive_account_with_a_wrong_password_gets_the_generic_message(): void
    {
        User::factory()->inactive()->create(['email' => 'jane@example.com']);

        $this->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertStringNotContainsString('deactivated', session('errors')->first('email'));
    }

    public function test_unknown_email_still_checks_a_password_hash(): void
    {
        $key = 'auth:login-dummy-hash:'.config('hashing.driver');
        User::factory()->create(['email' => 'jane@example.com']);

        // A known e-mail checks the account's own hash ...
        $this->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'wrong']);
        $this->assertFalse(Cache::has($key));

        // ... an unknown one checks against a dummy hash made with the same settings.
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'whatever'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertTrue(Cache::has($key));
        $this->assertTrue(Hash::isHashed(Cache::get($key)));
        $this->assertFalse(Hash::needsRehash(Cache::get($key)));
    }

    public function test_signed_in_user_is_redirected_away_from_the_login_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->assertRedirect(route('index'));
    }

    public function test_user_can_sign_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_logout_requires_post(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/logout')
            ->assertMethodNotAllowed();
    }

    public function test_user_deactivated_while_signed_in_is_signed_out_on_the_next_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->update(['is_active' => false]);

        $this->get(route('employees.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
