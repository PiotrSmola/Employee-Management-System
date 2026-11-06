<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_every_role_can_create_a_token_and_sees_it_once(): void
    {
        foreach (Role::cases() as $role) {
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

        $created = ActivityLog::where('action', 'user.api_token_created')->sole();
        $revoked = ActivityLog::where('action', 'user.api_token_revoked')->sole();
        $this->assertEquals(['token_id' => $id, 'name' => 'CI'], array_intersect_key($created->changes, ['token_id' => 1, 'name' => 1]));
        $this->assertSame((string) $this->user->id, $revoked->subject_id);
        $this->assertStringNotContainsString(explode('|', $plain)[1], json_encode($created->changes));
    }
}
