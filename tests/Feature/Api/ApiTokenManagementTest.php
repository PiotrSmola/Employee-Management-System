<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Export;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/**
 * "My account" -> API tokens.
 */
class ApiTokenManagementTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_the_account_page_has_the_token_section(): void
    {
        $this->get(route('account.edit'))
            ->assertOk()
            ->assertSee('API tokens')
            ->assertSee('No API tokens yet.')
            ->assertSee(route('account.tokens.store'));
    }

    public function test_viewers_cannot_create_tokens_and_see_no_section_without_any(): void
    {
        $viewer = $this->signInAs(Role::Viewer);

        $this->get(route('account.edit'))->assertOk()->assertDontSee('API tokens');
        $this->post(route('account.tokens.store'), ['token_name' => 'Mine'])->assertForbidden();
        $this->assertSame(0, $viewer->tokens()->count());
    }

    public function test_a_viewer_can_still_revoke_an_existing_token(): void
    {
        $viewer = $this->signInAs(Role::Viewer);
        $token = $viewer->createToken('Old');

        $this->get(route('account.edit'))
            ->assertOk()
            ->assertSee('Old')
            ->assertDontSee('Create token');

        $this->delete(route('account.tokens.destroy', $token->accessToken->id))->assertRedirect();
        $this->assertSame(0, $viewer->tokens()->count());
    }

    public function test_the_page_showing_a_new_token_is_not_cached(): void
    {
        $this->post(route('account.tokens.store'), ['token_name' => 'CI']);

        $response = $this->get(route('account.edit'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->assertStringNotContainsString('no-store', (string) $this->get(route('account.edit'))->headers->get('Cache-Control'));
    }

    // ---------------------------------------------------------- revocation

    private function tokenOf(User $user): void
    {
        $user->createToken('a', ['read']);
        $user->createToken('b', ['read']);
    }

    private function assertRevoked(User $user, string $reason): void
    {
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $user->id)->count());

        $entry = ActivityLog::where('action', 'data.api_token_revoked')->where('subject_id', (string) $user->id)->sole();
        $this->assertEquals(['count' => 2, 'reason' => $reason], $entry->changes);
    }

    private function userPayload(User $user, array $overrides = []): array
    {
        return $overrides + [
            'name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'is_active' => '1',
        ];
    }

    public function test_changing_the_own_password_revokes_the_tokens(): void
    {
        $this->tokenOf($this->user);

        $this->put(route('account.password'), [
            'current_password' => 'password', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123',
        ])->assertSessionHasNoErrors();

        $this->assertRevoked($this->user, 'password_changed');
    }

    public function test_an_administrators_password_reset_revokes_the_tokens(): void
    {
        $other = User::factory()->hr()->create();
        $this->tokenOf($other);

        $this->put(route('users.password', $other), ['password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
            ->assertSessionHasNoErrors();

        $this->assertRevoked($other, 'password_reset');
    }

    public function test_deactivation_revokes_the_tokens(): void
    {
        $other = User::factory()->hr()->create();
        $this->tokenOf($other);

        $this->put(route('users.update', $other), $this->userPayload($other, ['is_active' => '0']))->assertSessionHasNoErrors();

        $this->assertRevoked($other, 'deactivated');
    }

    public function test_a_role_change_revokes_the_tokens(): void
    {
        $other = User::factory()->hr()->create();
        $this->tokenOf($other);

        $this->put(route('users.update', $other), $this->userPayload($other, ['role' => 'admin']))->assertSessionHasNoErrors();

        $this->assertRevoked($other, 'role_changed');
    }

    public function test_other_account_changes_keep_the_tokens(): void
    {
        $other = User::factory()->hr()->create();
        $this->tokenOf($other);

        $this->put(route('users.update', $other), $this->userPayload($other, ['name' => 'New Name']))->assertSessionHasNoErrors();

        $this->assertSame(2, $other->tokens()->count());
        $this->assertSame(0, ActivityLog::where('action', 'data.api_token_revoked')->count());
    }

    public function test_deleting_a_user_removes_the_tokens_and_export_files(): void
    {
        Storage::fake('local');
        $other = User::factory()->hr()->create();
        $this->tokenOf($other);
        Storage::disk('local')->put('exports/9_x.csv', 'x');
        Storage::disk('local')->put('exports/mine.csv', 'x');
        Export::factory()->for($other)->completed('exports/9_x.csv')->create();
        Export::factory()->for($other)->create();
        Export::factory()->for($this->user)->completed('exports/mine.csv')->create();

        $this->delete(route('users.destroy', $other))->assertRedirect(route('users.index'));

        $this->assertModelMissing($other);
        $this->assertRevoked($other, 'user_deleted');
        $this->assertSame(1, Export::count());
        Storage::disk('local')->assertMissing('exports/9_x.csv');
        Storage::disk('local')->assertExists('exports/mine.csv');
    }

    // ------------------------------------------------------------ creating

    public function test_roles_with_export_data_can_create_a_token_and_see_it_once(): void
    {
        foreach ([Role::Admin, Role::Hr, Role::Manager] as $role) {
            $user = $this->signInAs($role, $role === Role::Manager ? 'd001' : null);

            $response = $this->post(route('account.tokens.store'), ['token_name' => 'Script '.$role->value, 'expires_in' => '30']);

            $response->assertRedirect(route('account.edit').'#api-tokens')->assertSessionHas('api_token_plain');
            $plain = session('api_token_plain');
            $this->assertStringStartsWith('ems_', explode('|', $plain)[1] ?? '');

            $token = $user->tokens()->sole();
            $this->assertSame('Script '.$role->value, $token->name);
            $this->assertSame(['read'], $token->abilities);
            $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $token->expires_at->getTimestamp(), 5);
            $this->assertSame($token->id, PersonalAccessToken::findToken($plain)?->id);
            $this->assertStringNotContainsString(explode('|', $plain)[1], (string) $token->token, 'only the hash is stored');

            // Shown on the redirect target once, then gone.
            $this->get(route('account.edit'))->assertSee($plain)->assertSee('Script '.$role->value);
            $this->get(route('account.edit'))->assertDontSee($plain);
        }
    }

    public function test_tokens_without_expiry_and_validation(): void
    {
        $this->post(route('account.tokens.store'), ['token_name' => 'Forever', 'expires_in' => ''])->assertRedirect();
        $this->assertNull($this->user->tokens()->sole()->expires_at);

        $this->post(route('account.tokens.store'), ['token_name' => '', 'expires_in' => '7'])
            ->assertSessionHasErrorsIn('apiToken', ['token_name', 'expires_in']);
        $this->assertSame(1, $this->user->tokens()->count());
    }

    public function test_the_number_of_tokens_is_limited(): void
    {
        config(['data_tools.api.max_tokens' => 2]);
        $this->user->createToken('a');
        $this->user->createToken('b');

        $this->post(route('account.tokens.store'), ['token_name' => 'c'])->assertSessionHasErrorsIn('apiToken', 'token_name');
        $this->assertSame(2, $this->user->tokens()->count());
    }

    public function test_revoking_an_own_token(): void
    {
        $token = $this->user->createToken('old');

        $this->delete(route('account.tokens.destroy', $token->accessToken->id))
            ->assertRedirect(route('account.edit').'#api-tokens');

        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertNull(PersonalAccessToken::findToken($token->plainTextToken));
    }

    public function test_other_users_tokens_cannot_be_revoked(): void
    {
        $other = User::factory()->hr()->create();
        $token = $other->createToken('theirs');

        $this->delete(route('account.tokens.destroy', $token->accessToken->id))->assertNotFound();
        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_the_page_lists_only_own_tokens(): void
    {
        $this->user->createToken('Mine');
        User::factory()->hr()->create()->createToken('Theirs');

        $this->get(route('account.edit'))->assertSee('Mine')->assertDontSee('Theirs');
    }

    public function test_token_changes_are_audited_without_the_secret(): void
    {
        $this->post(route('account.tokens.store'), ['token_name' => 'CI']);
        $plain = session('api_token_plain');
        $id = $this->user->tokens()->sole()->id;
        $this->delete(route('account.tokens.destroy', $id));

        $created = ActivityLog::where('action', 'data.api_token_created')->sole();
        $revoked = ActivityLog::where('action', 'data.api_token_revoked')->sole();
        $this->assertEquals(['token_id' => $id, 'name' => 'CI'], array_intersect_key($created->changes, ['token_id' => 1, 'name' => 1]));
        $this->assertSame((string) $this->user->id, $revoked->subject_id);
        $this->assertStringNotContainsString(explode('|', $plain)[1], json_encode($created->changes));
    }
}
