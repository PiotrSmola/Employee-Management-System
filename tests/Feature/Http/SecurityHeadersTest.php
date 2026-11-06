<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_pages_have_the_security_headers(): void
    {
        $this->assertSecurityHeaders($this->get(route('login'))->assertOk());
    }

    public function test_pages_of_a_signed_in_user_have_the_security_headers(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertSecurityHeaders($this->get('/employees')->assertOk());
    }

    public function test_error_pages_have_the_security_headers(): void
    {
        $this->assertSecurityHeaders($this->get('/this-page-does-not-exist')->assertNotFound());
    }

    public function test_content_security_policy_only_allows_the_site_and_the_cdn(): void
    {
        $policy = $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("connect-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);

        preg_match_all('/https?:\/\/[^\s;]+/', $policy, $hosts);
        $this->assertSame(['https://cdn.jsdelivr.net'], array_values(array_unique($hosts[0])));
    }

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy')
            ->assertHeader('Content-Security-Policy');
    }
}
